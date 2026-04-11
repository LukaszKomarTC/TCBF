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
	 * then group them by rider (tc_group_id or _gf_entry_id) so one row in the digest
	 * corresponds to one real person, not one line item.
	 */
	private static function hydrate_items( \WC_Order $order, BookingRecord $record ) : void {
		$item_records = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$ir = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::build_item_record( $order, (int) $item_id, $item );
			$item_records[] = $ir;
		}

		if ( empty( $item_records ) ) {
			return;
		}

		// Hoist the event title/id from the first item that has one (usually a participation item).
		foreach ( $item_records as $ir ) {
			if ( empty( $record->event['id'] ) && ! empty( $ir['event_id'] ) ) {
				$record->event['id']    = (int) $ir['event_id'];
				$record->event['title'] = $ir['event_title'] !== '' ? $ir['event_title'] : (string) $ir['product_name'];
			}
		}

		// Process transport items into a single consolidated transport block.
		foreach ( $item_records as $ir ) {
			if ( ! empty( $ir['is_transport'] ) ) {
				self::merge_transport( $record, $ir );
			}
		}

		// Group non-transport items into rider groups for the participant table.
		$groups = self::group_items_by_rider( $item_records );

		foreach ( $groups as $group_items ) {
			$participant = self::merge_group_into_participant( $group_items );
			if ( $participant ) {
				$record->participants[] = $participant;
			}
		}
	}

	/**
	 * Group item records by rider. Preference order:
	 *   1. tc_group_id (TCBF's canonical grouping: participation + rental of same rider)
	 *   2. _gf_entry_id (same form submission = same rider)
	 *   3. individual item (fallback)
	 *
	 * Transport items are excluded.
	 *
	 * @param array $item_records Item records from build_item_record()
	 * @return array<string, array[]> Groups, keyed by a synthetic group key
	 */
	private static function group_items_by_rider( array $item_records ) : array {
		$groups = [];
		$fallback_counter = 0;

		foreach ( $item_records as $ir ) {
			if ( ! empty( $ir['is_transport'] ) ) {
				continue;
			}
			// Skip items with no identifying info at all.
			if ( empty( $ir['participant'] ) && empty( $ir['bicycle'] ) && empty( $ir['pedals'] ) && empty( $ir['helmet'] ) && empty( $ir['product_name'] ) ) {
				continue;
			}

			$group_id = (int) ( $ir['group_id'] ?? 0 );
			$entry_id = (int) ( $ir['entry_id'] ?? 0 );

			if ( $group_id > 0 ) {
				$key = 'g:' . $group_id;
			} elseif ( $entry_id > 0 ) {
				$key = 'e:' . $entry_id . ':' . ( $ir['participant'] ?: 'unknown' );
			} else {
				$key = 'i:' . ( $fallback_counter++ );
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [];
			}
			$groups[ $key ][] = $ir;
		}

		return $groups;
	}

	/**
	 * Merge a group of item records (all belonging to the same rider) into one
	 * participant entry. Fields are picked from whichever item has them.
	 *
	 * @param array $group Array of item_record arrays
	 * @return array|null Participant record, or null if nothing useful.
	 */
	private static function merge_group_into_participant( array $group ) : ?array {
		$name   = '';
		$bike   = '';
		$size   = '';
		$pedals = '';
		$helmet = '';

		foreach ( $group as $ir ) {
			if ( $name === '' && ! empty( $ir['participant'] ) ) {
				$name = (string) $ir['participant'];
			}
			// Bike model: prefer the rental item's _bicycle, then any non-empty.
			if ( $bike === '' && ! empty( $ir['bicycle'] ) ) {
				$bike = (string) $ir['bicycle'];
			}
			if ( $size === '' && ! empty( $ir['size'] ) ) {
				$size = (string) $ir['size'];
			}
			if ( $pedals === '' && ! empty( $ir['pedals'] ) ) {
				$pedals = (string) $ir['pedals'];
			}
			if ( $helmet === '' && ! empty( $ir['helmet'] ) ) {
				$helmet = (string) $ir['helmet'];
			}
		}

		// Combine bike + size for display.
		$bike_display = $bike;
		if ( $bike !== '' && $size !== '' && stripos( $bike, $size ) === false ) {
			$bike_display = $bike . ' — ' . $size;
		}

		// If literally nothing was gathered, skip the row.
		if ( $name === '' && $bike === '' && $pedals === '' && $helmet === '' ) {
			return null;
		}

		return [
			'name'        => $name,
			'bike'        => $bike_display,
			'bike_size'   => $size,
			'pedals'      => $pedals,
			'helmet'      => $helmet,
			'rental_type' => '',
		];
	}

	/**
	 * Merge a transport item record into the record's transport block.
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

		// Multi-leg transport (delivery + return). Merge each field.
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
