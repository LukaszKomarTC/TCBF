<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Diagnostics — Debugging helper for the Upcoming Bookings digest.
 *
 * Shows what's actually in the DB and what the query is doing. Output
 * is only rendered when the admin page is accessed with ?tcbf_debug=1.
 *
 * Intended to be removed (or hidden behind debug-mode) once the
 * digest query is proven to work against real data.
 *
 * @since 1.x.x
 */
final class Diagnostics {

	/**
	 * Render a diagnostic HTML block for the given date.
	 */
	public static function render( \DateTimeInterface $date ) : string {
		global $wpdb;

		$h  = '<div style="background:#fff;border:2px solid #dba617;border-radius:6px;padding:16px;margin:16px 0;font-family:monospace;font-size:12px;">';
		$h .= '<h2 style="margin:0 0 12px 0;font-size:14px;">🔧 TCBF Diagnostics</h2>';

		// 1. WC Bookings presence
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">1. WC Bookings</h3>';
		$has_ds = class_exists( '\WC_Booking_Data_Store' );
		$h .= '• <code>WC_Booking_Data_Store</code> exists: <strong>' . ( $has_ds ? '✅ yes' : '❌ NO' ) . '</strong><br>';

		if ( $has_ds ) {
			$methods = get_class_methods( '\WC_Booking_Data_Store' );
			$has_method = is_array( $methods ) && in_array( 'get_bookings_in_date_range', $methods, true );
			$h .= '• <code>get_bookings_in_date_range</code> method: <strong>' . ( $has_method ? '✅ yes' : '❌ NO' ) . '</strong><br>';

			if ( $has_method ) {
				try {
					$reflection = new \ReflectionMethod( '\WC_Booking_Data_Store', 'get_bookings_in_date_range' );
					$sig_parts = [];
					foreach ( $reflection->getParameters() as $param ) {
						$sig_parts[] = '$' . $param->getName()
							. ( $param->isDefaultValueAvailable() ? '=' . var_export( $param->getDefaultValue(), true ) : '' );
					}
					$h .= '• Method signature: <code>get_bookings_in_date_range(' . esc_html( implode( ', ', $sig_parts ) ) . ')</code><br>';
				} catch ( \Throwable $e ) {
					$h .= '• ⚠ Could not reflect method: ' . esc_html( $e->getMessage() ) . '<br>';
				}
			}
		}

		// 2. Raw booking count
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">2. Bookings in database</h3>';
		$total_bookings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wc_booking'" );
		$h .= '• Total <code>wc_booking</code> posts: <strong>' . $total_bookings . '</strong><br>';

		// Breakdown by status
		$by_status = $wpdb->get_results( "SELECT post_status, COUNT(*) as c FROM {$wpdb->posts} WHERE post_type = 'wc_booking' GROUP BY post_status" );
		if ( $by_status ) {
			$h .= '• By status: ';
			$bits = [];
			foreach ( $by_status as $row ) {
				$bits[] = $row->post_status . '=' . $row->c;
			}
			$h .= esc_html( implode( ', ', $bits ) ) . '<br>';
		}

		// 3. Most recent bookings with their start dates
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">3. 20 most recent bookings</h3>';
		$recent = $wpdb->get_results(
			"SELECT p.ID, p.post_status,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_booking_start' LIMIT 1) AS start_raw,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_booking_end' LIMIT 1) AS end_raw,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_booking_order_item_id' LIMIT 1) AS order_item_id,
				(SELECT post_parent FROM {$wpdb->posts} WHERE ID = p.ID LIMIT 1) AS order_id_via_parent
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'wc_booking'
			 ORDER BY p.ID DESC
			 LIMIT 20"
		);
		if ( $recent ) {
			$h .= '<table style="width:100%;border-collapse:collapse;font-size:11px;margin-top:4px;">';
			$h .= '<tr style="background:#f6f7f7;"><th style="padding:4px;border:1px solid #ccc;text-align:left;">Booking ID</th><th style="padding:4px;border:1px solid #ccc;text-align:left;">Status</th><th style="padding:4px;border:1px solid #ccc;text-align:left;">_booking_start (raw)</th><th style="padding:4px;border:1px solid #ccc;text-align:left;">_booking_end (raw)</th><th style="padding:4px;border:1px solid #ccc;text-align:left;">Parent (order)</th></tr>';
			foreach ( $recent as $row ) {
				$h .= '<tr>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->ID ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->post_status ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->start_raw ?: '—' ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->end_raw ?: '—' ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->order_id_via_parent ?: '—' ) . '</td>';
				$h .= '</tr>';
			}
			$h .= '</table>';
		} else {
			$h .= '• ⚠ No bookings returned from raw query<br>';
		}

		// 4. The query TCBF is running
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">4. TCBF Query execution</h3>';
		$range = Query::day_range_timestamps( $date );
		$h .= '• Target date: <strong>' . esc_html( $date->format( 'Y-m-d' ) ) . '</strong><br>';
		$h .= '• Site timezone: <strong>' . esc_html( wp_timezone_string() ) . '</strong><br>';
		$h .= '• Day range (UTC timestamps): <code>' . $range['start'] . ' → ' . $range['end'] . '</code><br>';
		$h .= '• Day range (human): <code>' . date_i18n( 'Y-m-d H:i:s', $range['start'] ) . ' → ' . date_i18n( 'Y-m-d H:i:s', $range['end'] ) . '</code><br>';

		// Try both invocation styles
		if ( class_exists( '\WC_Booking_Data_Store' ) && method_exists( '\WC_Booking_Data_Store', 'get_bookings_in_date_range' ) ) {
			try {
				$ids_null_false = \WC_Booking_Data_Store::get_bookings_in_date_range( $range['start'], $range['end'], null, false );
				$h .= '• Query(null, false): returned <strong>' . count( (array) $ids_null_false ) . '</strong> IDs<br>';
			} catch ( \Throwable $e ) {
				$h .= '• ⚠ Query(null, false) exception: ' . esc_html( $e->getMessage() ) . '<br>';
			}

			try {
				$ids_zero_true = \WC_Booking_Data_Store::get_bookings_in_date_range( $range['start'], $range['end'], 0, true );
				$h .= '• Query(0, true): returned <strong>' . count( (array) $ids_zero_true ) . '</strong> IDs<br>';
			} catch ( \Throwable $e ) {
				$h .= '• ⚠ Query(0, true) exception: ' . esc_html( $e->getMessage() ) . '<br>';
			}

			try {
				$ids_zero_false = \WC_Booking_Data_Store::get_bookings_in_date_range( $range['start'], $range['end'], 0, false );
				$h .= '• Query(0, false): returned <strong>' . count( (array) $ids_zero_false ) . '</strong> IDs<br>';
			} catch ( \Throwable $e ) {
				$h .= '• ⚠ Query(0, false) exception: ' . esc_html( $e->getMessage() ) . '<br>';
			}
		}

		// 5. Raw meta-based query for the same range
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">5. Raw SQL fallback for same date</h3>';
		$start_ymd = date( 'YmdHis', $range['start'] );
		$end_ymd   = date( 'YmdHis', $range['end'] );
		$h .= '• YmdHis range: <code>' . esc_html( $start_ymd ) . ' → ' . esc_html( $end_ymd ) . '</code><br>';

		$raw_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_booking_start'
			 AND meta_value BETWEEN %s AND %s",
			$start_ymd,
			$end_ymd
		) );
		$h .= '• Raw meta query found: <strong>' . count( (array) $raw_ids ) . '</strong> booking IDs';
		if ( $raw_ids ) {
			$h .= ' → ' . esc_html( implode( ', ', array_slice( $raw_ids, 0, 20 ) ) );
		}
		$h .= '<br>';

		// 6. Upcoming bookings (next 14 days via raw query, ignore target date)
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">6. Upcoming bookings (next 14 days, raw)</h3>';
		$now_ymd = date( 'YmdHis', time() );
		$plus_14 = date( 'YmdHis', time() + ( 14 * DAY_IN_SECONDS ) );
		$upcoming = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_status, pm.meta_value AS start_raw
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'wc_booking'
			 AND pm.meta_key = '_booking_start'
			 AND pm.meta_value BETWEEN %s AND %s
			 ORDER BY pm.meta_value ASC
			 LIMIT 20",
			$now_ymd,
			$plus_14
		) );
		if ( $upcoming ) {
			$h .= '<table style="width:100%;border-collapse:collapse;font-size:11px;margin-top:4px;">';
			$h .= '<tr style="background:#f6f7f7;"><th style="padding:4px;border:1px solid #ccc;text-align:left;">Booking ID</th><th style="padding:4px;border:1px solid #ccc;text-align:left;">Status</th><th style="padding:4px;border:1px solid #ccc;text-align:left;">Start (raw YmdHis)</th></tr>';
			foreach ( $upcoming as $row ) {
				$h .= '<tr>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->ID ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->post_status ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( $row->start_raw ) . '</td>';
				$h .= '</tr>';
			}
			$h .= '</table>';
		} else {
			$h .= '• ⚠ No upcoming bookings found in the next 14 days via raw query<br>';
		}

		$h .= '</div>';
		return $h;
	}
}
