<?php
namespace TC_BF\Domain\UpcomingBookings;

use TC_BF\Domain\PartnerResolver;
use TC_BF\Domain\EventMeta;

if ( ! defined('ABSPATH') ) exit;

/**
 * Service — Orchestrator for the Upcoming Bookings digest.
 *
 * Entry point: build_report_for_date()
 *
 * Responsibilities:
 *  - Use Query to discover bookings starting-on and active-on the target date
 *  - Group them by order
 *  - Hydrate each order into a BookingRecord (customer, participants,
 *    transport, partner, payment, flags)
 *  - Run Issue_Detector and Priority_Scorer on each record
 *  - Return a sorted array of BookingRecord objects
 *
 * Pure data layer — no rendering, no email, no HTTP.
 *
 * @since 1.x.x
 */
final class Service {

	/**
	 * Build the report for a single date.
	 *
	 * @param \DateTimeInterface $date           Target date (time ignored).
	 * @param bool               $include_active Whether to include bookings that
	 *                                           started earlier but are active on this date.
	 * @return BookingRecord[] Sorted by priority desc, then order_id asc.
	 */
	public static function build_report_for_date( \DateTimeInterface $date, bool $include_active = true ) : array {
		$records = [];

		// -- Step 1: starts-on bookings ------------------------------------
		$starts_on_ids = Query::find_bookings_starting_on( $date );
		$starts_on_by_order = Query::group_by_order( $starts_on_ids );

		foreach ( $starts_on_by_order as $order_id => $booking_ids ) {
			$record = self::hydrate_order_record( (int) $order_id, $booking_ids );
			if ( $record ) {
				$record->category = BookingRecord::CATEGORY_STARTS_ON;
				$records[]        = $record;
			}
		}

		// -- Step 2: active-on bookings ------------------------------------
		if ( $include_active ) {
			$active_on_ids = Query::find_bookings_active_on( $date );
			$active_by_order = Query::group_by_order( $active_on_ids );

			// Don't duplicate orders that already appear in starts-on.
			foreach ( $active_by_order as $order_id => $booking_ids ) {
				if ( isset( $starts_on_by_order[ (int) $order_id ] ) ) {
					continue;
				}
				$record = self::hydrate_order_record( (int) $order_id, $booking_ids );
				if ( $record ) {
					$record->category = BookingRecord::CATEGORY_ACTIVE_ON;
					$records[]        = $record;
				}
			}
		}

		// -- Step 3: detect issues and score -------------------------------
		foreach ( $records as $record ) {
			Issue_Detector::detect( $record );
			Priority_Scorer::score( $record );
		}

		// -- Step 4: sort by priority desc, then order id asc --------------
		usort( $records, function( $a, $b ) {
			if ( $a->priority === $b->priority ) {
				return $a->order_id <=> $b->order_id;
			}
			return $b->priority <=> $a->priority;
		} );

		\TC_BF\Support\Logger::log( 'upcoming_bookings.report_built', [
			'date'           => $date->format( 'Y-m-d' ),
			'total'          => count( $records ),
			'starts_on'      => count( $starts_on_by_order ),
			'include_active' => $include_active ? 1 : 0,
		] );

		return $records;
	}

	/**
	 * Hydrate a single order into a BookingRecord.
	 *
	 * @param int   $order_id
	 * @param int[] $booking_ids Booking IDs attached to this order that matched the query.
	 * @return BookingRecord|null Null on failure (order missing/corrupt).
	 */
	private static function hydrate_order_record( int $order_id, array $booking_ids ) : ?BookingRecord {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return null;
		}

		$record               = new BookingRecord();
		$record->order_id     = $order_id;
		$record->order_number = '#' . $order->get_order_number();
		$record->order_status = (string) $order->get_status();
		$record->booking_ids  = array_values( array_unique( array_map( 'intval', $booking_ids ) ) );

		// Customer
		$record->customer = [
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email' => (string) $order->get_billing_email(),
			'phone' => (string) $order->get_billing_phone(),
		];

		// Customer note
		$record->customer_note = (string) $order->get_customer_note();

		// Payment status (TCBF uses processing/completed/invoiced as paid-equivalent)
		$paid_statuses = [ 'processing', 'completed', 'invoiced' ];
		$record->payment = [
			'paid'         => in_array( $record->order_status, $paid_statuses, true ),
			'status_label' => wc_get_order_status_name( $record->order_status ),
		];

		// Event details (from first matched booking)
		if ( ! empty( $record->booking_ids ) ) {
			try {
				$first_booking = new \WC_Booking( $record->booking_ids[0] );
				if ( $first_booking && $first_booking->get_product_id() > 0 ) {
					$record->event['start']     = (int) $first_booking->get_start();
					$record->event['end']       = (int) $first_booking->get_end();
					$record->event['start_fmt'] = $record->event['start']
						? date_i18n( get_option( 'date_format' ) . ' H:i', $record->event['start'] )
						: '';
					$record->event['end_fmt']   = $record->event['end']
						? date_i18n( get_option( 'date_format' ) . ' H:i', $record->event['end'] )
						: '';
				}
			} catch ( \Throwable $e ) {}
		}

		// Iterate order items to pull participant + transport + event metadata.
		self::hydrate_items( $order, $record );

		// Partner
		self::hydrate_partner( $order, $record );

		// Confirmation flag — check if any item has the notify-participant meta set to "sent"
		// or if WC has recorded the customer email being sent.
		$record->confirmation_sent = self::detect_confirmation_sent( $order );

		return $record;
	}

	/**
	 * Iterate order items, build per-item records via Woo_OrderMeta::build_item_record,
	 * categorize them into pack groups / standalone rentals / transport, then materialize
	 * BookingRecord::$tour_packs, $standalone_rentals, $transport.
	 *
	 * Mirrors the categorization logic in Woo_AdminOrder::render_meta_box() so the
	 * digest visually matches the admin order page.
	 */
	private static function hydrate_items( \WC_Order $order, BookingRecord $record ) : void {
		$item_records      = [];
		$pack_groups       = []; // tc_group_id => [item records]
		$standalone_rental = []; // standalone WC Bookings rentals
		$transport_records = [];

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			$rec = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::build_item_record( $order, (int) $item_id, $item );
			$item_records[] = $rec;

			if ( ! empty( $rec['is_transport'] ) ) {
				$transport_records[] = $rec;
			} elseif ( (int) $rec['group_id'] > 0 ) {
				$pack_groups[ (int) $rec['group_id'] ][] = $rec;
			} else {
				$standalone_rental[] = $rec;
			}
		}

		if ( empty( $item_records ) ) {
			return;
		}

		// Hoist a primary event title for the card header from the first pack with one.
		foreach ( $pack_groups as $pack_items ) {
			foreach ( $pack_items as $rec ) {
				if ( empty( $record->event['id'] ) && ! empty( $rec['event_id'] ) ) {
					$record->event['id']    = (int) $rec['event_id'];
					$record->event['title'] = $rec['event_title'] !== '' ? (string) $rec['event_title'] : (string) $rec['product_name'];
					break 2;
				}
			}
		}

		// Match transports to standalone rentals — same logic as Woo_AdminOrder.
		$rental_transport_map = []; // rental item_id => [transport records]
		$claimed_transport    = [];
		foreach ( $standalone_rental as $rental ) {
			foreach ( $transport_records as $ti => $transport ) {
				if ( isset( $claimed_transport[ $ti ] ) ) {
					continue;
				}
				$match = false;
				if ( (int) $rental['entry_id'] > 0 && (int) $transport['entry_id'] === (int) $rental['entry_id'] ) {
					$match = true;
				} elseif ( (int) $transport['transport_parent_product_id'] > 0
					&& (int) $transport['transport_parent_product_id'] === (int) $rental['product_id'] ) {
					$match = true;
				} elseif ( (int) $rental['event_id'] > 0
					&& (int) $transport['event_id'] === (int) $rental['event_id']
					&& $transport['participant'] !== ''
					&& $transport['participant'] === $rental['participant'] ) {
					$match = true;
				} elseif ( (int) $transport['transport_parent_product_id'] <= 0
					&& (int) $rental['event_id'] <= 0
					&& $transport['participant'] !== ''
					&& $transport['participant'] === $rental['participant'] ) {
					$match = true;
				}
				if ( $match ) {
					$rental_transport_map[ (int) $rental['item_id'] ][] = $transport;
					$claimed_transport[ $ti ] = true;
				}
			}
		}

		// === Build $tour_packs ===
		foreach ( $pack_groups as $gid => $pack_items ) {
			$parent   = null;
			$children = [];
			foreach ( $pack_items as $rec ) {
				if ( $rec['role'] === 'parent' ) {
					$parent = $rec;
				} else {
					$children[] = $rec;
				}
			}
			$ref = $parent ?: $pack_items[0];

			$pack = [
				'event_id'    => (int) $ref['event_id'],
				'event_title' => (string) $ref['event_title'],
				'event_date'  => (string) $ref['booking_date'],
				'participant' => (string) $ref['participant'],
				'rental_bike' => '',
				'rental_size' => '',
				'pedals'      => (string) $ref['pedals'],
				'helmet'      => (string) $ref['helmet'],
			];

			// Pull bike/size/pedals/helmet from the rental child.
			foreach ( $children as $child ) {
				if ( $child['scope'] === 'rental' ) {
					$pack['rental_bike'] = (string) $child['product_name'];
					$pack['rental_size'] = (string) $child['size'];
					if ( $pack['pedals'] === '' && $child['pedals'] !== '' ) {
						$pack['pedals'] = (string) $child['pedals'];
					}
					if ( $pack['helmet'] === '' && $child['helmet'] !== '' ) {
						$pack['helmet'] = (string) $child['helmet'];
					}
					break;
				}
			}

			$record->tour_packs[] = $pack;
		}

		// === Build $standalone_rentals ===
		foreach ( $standalone_rental as $rental ) {
			$dates = (string) $rental['booking_date'];
			if ( ! empty( $rental['end_date'] ) && $rental['end_date'] !== $dates ) {
				$dates .= ' → ' . $rental['end_date'];
			}
			if ( ! empty( $rental['duration'] ) ) {
				$dates .= ' (' . $rental['duration'] . ')';
			}

			$transports = $rental_transport_map[ (int) $rental['item_id'] ] ?? [];
			$transport_legs = [];
			foreach ( $transports as $t ) {
				$transport_legs[] = self::format_transport_leg( $t );
			}

			$record->standalone_rentals[] = [
				'product_name' => (string) $rental['product_name'],
				'customer'     => (string) $rental['participant'],
				'dates'        => $dates,
				'size'         => (string) $rental['size'],
				'pedals'       => (string) $rental['pedals'],
				'helmet'       => (string) $rental['helmet'],
				'transports'   => $transport_legs,
			];
		}

		// === Build $transport (only unmatched) ===
		$unmatched_transport = [];
		foreach ( $transport_records as $ti => $t ) {
			if ( ! isset( $claimed_transport[ $ti ] ) ) {
				$unmatched_transport[] = $t;
			}
		}
		foreach ( $unmatched_transport as $t ) {
			self::merge_transport( $record, $t );
		}
	}

	/**
	 * Format a single transport leg into a compact array for rendering.
	 */
	private static function format_transport_leg( array $t ) : array {
		$type   = (string) ( $t['transport_type'] ?? '' );
		$label  = ( $type === 'delivery' ) ? __( 'Delivery', 'tc-booking-flow' )
			: ( ( $type === 'return' ) ? __( 'Return pickup', 'tc-booking-flow' ) : ucfirst( $type ) );
		return [
			'type'    => $type,
			'label'   => $label,
			'date'    => (string) ( $t['transport_date'] ?? '' ),
			'window'  => (string) ( $t['transport_window'] ?? '' ),
			'zone'    => (string) ( $t['transport_zone'] ?? '' ),
			'address' => (string) ( $t['transport_address'] ?? '' ),
		];
	}

	/**
	 * Merge a single (unmatched) transport item record into the record's transport block.
	 */
	private static function merge_transport( BookingRecord $record, array $ir ) : void {
		if ( ! is_array( $record->transport ) ) {
			$record->transport = [
				'present' => true,
				'type'    => (string) ( $ir['transport_type'] ?? '' ),
				'date'    => (string) ( $ir['transport_date'] ?? '' ),
				'window'  => (string) ( $ir['transport_window'] ?? '' ),
				'zone'    => (string) ( $ir['transport_zone'] ?? '' ),
				'address' => (string) ( $ir['transport_address'] ?? '' ),
				'price'   => 0.0,
			];
			return;
		}

		$record->transport['type']    = self::merge_string( $record->transport['type'],    (string) ( $ir['transport_type'] ?? '' ) );
		$record->transport['date']    = self::merge_string( $record->transport['date'],    (string) ( $ir['transport_date'] ?? '' ) );
		$record->transport['window']  = self::merge_string( $record->transport['window'],  (string) ( $ir['transport_window'] ?? '' ) );
		$record->transport['zone']    = self::merge_string( $record->transport['zone'],    (string) ( $ir['transport_zone'] ?? '' ) );
		$record->transport['address'] = self::merge_string( $record->transport['address'], (string) ( $ir['transport_address'] ?? '' ) );
	}

	/**
	 * Populate $record->partner from order meta, using PartnerResolver if needed.
	 */
	private static function hydrate_partner( \WC_Order $order, BookingRecord $record ) : void {
		$partner_code    = (string) $order->get_meta( 'partner_code', true );
		$partner_user_id = (int) $order->get_meta( 'partner_id', true );

		if ( $partner_code === '' && $partner_user_id <= 0 ) {
			return;
		}

		$partner_email = '';
		if ( $partner_user_id > 0 ) {
			$user = get_user_by( 'id', $partner_user_id );
			if ( $user && ! is_wp_error( $user ) ) {
				$partner_email = (string) $user->user_email;
			}
		}

		$record->partner = [
			'code'          => $partner_code,
			'user_id'       => $partner_user_id,
			'partner_email' => $partner_email,
		];
	}

	/**
	 * Best-effort detection of whether the customer confirmation email was sent.
	 */
	private static function detect_confirmation_sent( \WC_Order $order ) : bool {
		// TCBF sets _tcbf_confirmation_sent on orders when the customer email goes out.
		$flag = (string) $order->get_meta( '_tcbf_confirmation_sent', true );
		if ( $flag === '1' || $flag === 'yes' ) {
			return true;
		}

		// Fallback: scan order notes for the WC customer-processing-email marker.
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id(), 'limit' => 20 ] );
		if ( is_array( $notes ) ) {
			foreach ( $notes as $note ) {
				$content = isset( $note->content ) ? (string) $note->content : '';
				if ( stripos( $content, 'customer-processing-email' ) !== false
					|| stripos( $content, 'Order email' ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Merge two strings for multi-leg transport display ("delivery | return").
	 */
	private static function merge_string( string $a, string $b ) : string {
		$a = trim( $a );
		$b = trim( $b );
		if ( $a === '' ) return $b;
		if ( $b === '' ) return $a;
		if ( stripos( $a, $b ) !== false ) return $a;
		return $a . ' | ' . $b;
	}
}
