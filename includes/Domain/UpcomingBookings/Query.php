<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Query — Booking discovery for the Upcoming Bookings digest.
 *
 * Primary source of truth: WC Bookings data store. We deliberately
 * query bookings by service/start date, not order creation date,
 * because a booking placed weeks ago may be the one we need tomorrow.
 *
 * Returns arrays of WC_Booking IDs grouped by category:
 *   - starts_on: booking->get_start() falls within the target day
 *   - active_on: booking->get_start() <= target AND booking->get_end() >= target,
 *                and booking is not already in "starts_on"
 *
 * @since 1.x.x
 */
final class Query {

	/**
	 * Find all WC Booking IDs that start on the given date (site timezone).
	 *
	 * @param \DateTimeInterface $target_date Target day (time portion ignored).
	 * @return int[] Booking IDs
	 */
	public static function find_bookings_starting_on( \DateTimeInterface $target_date ) : array {
		$range = self::day_range_timestamps( $target_date );
		return self::query_bookings_in_range( $range['start'], $range['end'] );
	}

	/**
	 * Find all WC Booking IDs that are active on the given date — meaning
	 * they started before target midnight and end on or after target midnight.
	 *
	 * Excludes bookings that START on the target date (those belong to "starts_on").
	 *
	 * @param \DateTimeInterface $target_date Target day.
	 * @return int[] Booking IDs
	 */
	public static function find_bookings_active_on( \DateTimeInterface $target_date ) : array {
		$range       = self::day_range_timestamps( $target_date );
		$day_start   = $range['start'];
		$day_end     = $range['end'];

		// Scan a reasonable lookback window — 60 days back should cover any realistic rental.
		$lookback_start = $day_start - ( 60 * DAY_IN_SECONDS );

		$ids = self::query_bookings_in_range( $lookback_start, $day_end );
		if ( empty( $ids ) ) {
			return [];
		}

		$active = [];
		foreach ( $ids as $booking_id ) {
			try {
				$booking = new \WC_Booking( (int) $booking_id );
				if ( ! $booking || $booking->get_product_id() <= 0 ) {
					continue;
				}
				$start = (int) $booking->get_start();
				$end   = (int) $booking->get_end();
				if ( $start <= 0 ) {
					continue;
				}

				// Starts strictly before target day AND ends on/after target day start.
				if ( $start < $day_start && $end >= $day_start ) {
					$active[] = (int) $booking_id;
				}
			} catch ( \Throwable $e ) {
				// Skip broken bookings silently.
			}
		}

		return $active;
	}

	/**
	 * Query WC Bookings by timestamp range.
	 *
	 * Uses WC_Booking_Data_Store::get_bookings_in_date_range() which is the
	 * canonical WC Bookings API for date-range queries.
	 *
	 * @param int $start_ts Unix timestamp (inclusive)
	 * @param int $end_ts   Unix timestamp (inclusive)
	 * @return int[] Booking IDs
	 */
	private static function query_bookings_in_range( int $start_ts, int $end_ts ) : array {
		if ( ! class_exists( '\WC_Booking_Data_Store' ) ) {
			\TC_BF\Support\Logger::log( 'upcoming_bookings.query.no_data_store', [], 'warning' );
			return [];
		}

		try {
			// WC_Booking_Data_Store::get_bookings_in_date_range( $start, $end, $product_ids, $check_in_cart )
			$ids = \WC_Booking_Data_Store::get_bookings_in_date_range( $start_ts, $end_ts, null, false );
			if ( ! is_array( $ids ) ) {
				return [];
			}
			return array_map( 'intval', $ids );
		} catch ( \Throwable $e ) {
			\TC_BF\Support\Logger::log( 'upcoming_bookings.query.exception', [
				'message' => $e->getMessage(),
				'start'   => $start_ts,
				'end'     => $end_ts,
			], 'error' );
			return [];
		}
	}

	/**
	 * Compute [start, end] unix timestamps for the site-timezone day of $date.
	 *
	 * @return array{start:int,end:int}
	 */
	public static function day_range_timestamps( \DateTimeInterface $date ) : array {
		$tz    = wp_timezone();
		$day   = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date->format( 'Y-m-d' ), $tz );
		if ( ! $day ) {
			$day = new \DateTimeImmutable( 'now', $tz );
		}
		$start = $day->setTime( 0, 0, 0 )->getTimestamp();
		$end   = $day->setTime( 23, 59, 59 )->getTimestamp();
		return [ 'start' => $start, 'end' => $end ];
	}

	/**
	 * Group a flat list of booking IDs by their parent order ID.
	 *
	 * @param int[] $booking_ids
	 * @return array<int, int[]> [ order_id => [booking_id, ...] ]
	 */
	public static function group_by_order( array $booking_ids ) : array {
		$grouped = [];
		foreach ( $booking_ids as $bid ) {
			try {
				$booking  = new \WC_Booking( (int) $bid );
				$order_id = (int) $booking->get_order_id();
				if ( $order_id <= 0 ) {
					continue;
				}
				if ( ! isset( $grouped[ $order_id ] ) ) {
					$grouped[ $order_id ] = [];
				}
				$grouped[ $order_id ][] = (int) $bid;
			} catch ( \Throwable $e ) {
				// Skip.
			}
		}
		return $grouped;
	}
}
