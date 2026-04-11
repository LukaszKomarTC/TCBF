<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Query — Booking discovery for the Upcoming Bookings digest.
 *
 * Uses raw postmeta SQL against _booking_start / _booking_end for
 * version-independent, precise filtering. We deliberately do NOT use
 * WC_Booking_Data_Store::get_bookings_in_date_range() because on some
 * WC Bookings versions it returns an unrecognizable shape (bookings
 * whose values intval to 1), filters out cancelled bookings silently,
 * and includes bookings that merely overlap the range instead of
 * strictly matching start-in-range semantics.
 *
 * Date comparison is lexicographic on YmdHis strings, which matches
 * how WC Bookings stores _booking_start / _booking_end internally.
 *
 * @since 1.x.x
 */
final class Query {

	/**
	 * Find all WC Booking IDs that start on the given date (site timezone).
	 *
	 * Strict semantics: booking._booking_start ∈ [day_start, day_end]
	 *
	 * @param \DateTimeInterface $target_date Target day (time portion ignored).
	 * @return int[] Booking IDs
	 */
	public static function find_bookings_starting_on( \DateTimeInterface $target_date ) : array {
		global $wpdb;

		$range     = self::day_range_timestamps( $target_date );
		$start_ymd = gmdate( 'YmdHis', $range['start'] );
		$end_ymd   = gmdate( 'YmdHis', $range['end'] );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'wc_booking'
			 AND pm.meta_key = '_booking_start'
			 AND pm.meta_value BETWEEN %s AND %s",
			$start_ymd,
			$end_ymd
		) );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/**
	 * Find all WC Booking IDs that are ACTIVE on the given date —
	 * i.e. started before target midnight AND end on or after target midnight.
	 *
	 * Strict semantics:
	 *   _booking_start < day_start  AND  _booking_end >= day_start
	 *
	 * Excludes bookings that START on the target date (those belong to starts_on).
	 *
	 * @param \DateTimeInterface $target_date Target day.
	 * @return int[] Booking IDs
	 */
	public static function find_bookings_active_on( \DateTimeInterface $target_date ) : array {
		global $wpdb;

		$range     = self::day_range_timestamps( $target_date );
		$day_start_ymd = gmdate( 'YmdHis', $range['start'] );

		// Two-join query: _booking_start strictly before day_start,
		// _booking_end >= day_start.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} ps ON ps.post_id = p.ID AND ps.meta_key = '_booking_start'
			 INNER JOIN {$wpdb->postmeta} pe ON pe.post_id = p.ID AND pe.meta_key = '_booking_end'
			 WHERE p.post_type = 'wc_booking'
			 AND ps.meta_value < %s
			 AND pe.meta_value >= %s",
			$day_start_ymd,
			$day_start_ymd
		) );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
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
