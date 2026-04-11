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
	 * Iterate order items and populate participant/transport/event data on $record.
	 */
	private static function hydrate_items( \WC_Order $order, BookingRecord $record ) : void {
		$event_ids_seen = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$scope = (string) $item->get_meta( '_tc_scope', true );
			if ( $scope === '' ) {
				$scope = (string) $item->get_meta( 'tc_scope', true );
			}

			// Event title / id fallback: many items carry _event_title.
			$event_id    = (int) $item->get_meta( '_event_id', true );
			$event_title = (string) $item->get_meta( '_event_title', true );

			if ( $event_id > 0 && ! in_array( $event_id, $event_ids_seen, true ) ) {
				$event_ids_seen[] = $event_id;
				if ( empty( $record->event['id'] ) ) {
					$record->event['id']    = $event_id;
					$record->event['title'] = $event_title !== '' ? $event_title : get_the_title( $event_id );
				}
			}

			if ( $scope === 'transport' ) {
				self::hydrate_transport_item( $item, $record );
				continue;
			}

			// Participant / rental item
			$participant = self::extract_participant_from_item( $item );
			if ( $participant ) {
				$record->participants[] = $participant;
			}
		}
	}

	/**
	 * Extract a participant info block from a WC order item.
	 *
	 * @return array|null
	 */
	private static function extract_participant_from_item( \WC_Order_Item_Product $item ) : ?array {
		// Name: several possible meta keys (depends on form version).
		$name = (string) $item->get_meta( '_tcbf_participant_name', true );
		if ( $name === '' ) {
			$name = (string) $item->get_meta( 'participant', true );
		}
		if ( $name === '' ) {
			$name = (string) $item->get_meta( '_participant_name', true );
		}

		// Bike
		$bike = (string) $item->get_meta( '_bicycle', true );
		if ( $bike === '' ) {
			$bike = (string) $item->get_meta( 'bicycle', true );
		}

		// Rental type (ROAD/MTB/eMTB/GRAVEL)
		$rental_type = (string) $item->get_meta( '_rental_type', true );

		// Pedals / helmet — live in GF lead stored on _gravity_forms_history.
		$gf_lead = self::get_gf_lead_from_item( $item );
		$pedals  = isset( $gf_lead[60] ) ? (string) $gf_lead[60] : '';
		$helmet  = isset( $gf_lead[61] ) ? (string) $gf_lead[61] : '';

		// If the item has literally no participant info, skip it.
		if ( $name === '' && $bike === '' && $pedals === '' && empty( $gf_lead ) ) {
			return null;
		}

		return [
			'name'        => $name,
			'bike'        => $bike,
			'bike_size'   => '',
			'pedals'      => $pedals,
			'helmet'      => $helmet,
			'rental_type' => $rental_type,
		];
	}

	/**
	 * Populate $record->transport from a transport-scope item.
	 */
	private static function hydrate_transport_item( \WC_Order_Item_Product $item, BookingRecord $record ) : void {
		$type    = (string) $item->get_meta( '_tcbf_transport_type', true );
		$date    = (string) $item->get_meta( '_tcbf_transport_service_date', true );
		$window  = (string) $item->get_meta( '_tcbf_transport_window', true );
		$zone    = (string) $item->get_meta( '_tcbf_transport_zone_name', true );
		$address = (string) $item->get_meta( '_tcbf_transport_address', true );
		$price   = (float) $item->get_meta( '_tcbf_transport_price', true );

		// If a previous transport item already set the record, merge; otherwise initialize.
		if ( ! is_array( $record->transport ) ) {
			$record->transport = [
				'present' => true,
				'type'    => $type,
				'date'    => $date,
				'window'  => $window,
				'zone'    => $zone,
				'address' => $address,
				'price'   => $price,
			];
		} else {
			// Append — pipe-separated for multi-leg transport (delivery + return).
			$record->transport['type']    = self::merge_string( $record->transport['type'], $type );
			$record->transport['date']    = self::merge_string( $record->transport['date'], $date );
			$record->transport['window']  = self::merge_string( $record->transport['window'], $window );
			$record->transport['price'] += $price;
		}
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
	 * Fetch the GF lead array from an order item's _gravity_forms_history meta.
	 *
	 * Mirrors Woo_OrderMeta::get_gf_lead_from_item() to avoid a cross-class private call.
	 *
	 * @return array GF lead (field_id => value) or empty array.
	 */
	private static function get_gf_lead_from_item( \WC_Order_Item_Product $item ) : array {
		$history = $item->get_meta( '_gravity_forms_history', true );
		if ( is_array( $history ) && ! empty( $history['_gravity_form_lead'] ) && is_array( $history['_gravity_form_lead'] ) ) {
			return $history['_gravity_form_lead'];
		}
		return [];
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
