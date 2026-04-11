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

		// Try both invocation styles — show actual IDs to detect signature/filter quirks.
		$ids_from_data_store = [];
		if ( class_exists( '\WC_Booking_Data_Store' ) && method_exists( '\WC_Booking_Data_Store', 'get_bookings_in_date_range' ) ) {
			try {
				$ids_null_false = \WC_Booking_Data_Store::get_bookings_in_date_range( $range['start'], $range['end'], null, false );
				$ids_null_false = is_array( $ids_null_false ) ? array_map( 'intval', $ids_null_false ) : [];
				$h .= '• Query(null, false): returned <strong>' . count( $ids_null_false ) . '</strong> IDs → <code>' . esc_html( implode( ', ', array_slice( $ids_null_false, 0, 30 ) ) ) . '</code><br>';
				$ids_from_data_store = $ids_null_false;
			} catch ( \Throwable $e ) {
				$h .= '• ⚠ Query(null, false) exception: ' . esc_html( $e->getMessage() ) . '<br>';
			}

			try {
				$ids_zero_true = \WC_Booking_Data_Store::get_bookings_in_date_range( $range['start'], $range['end'], 0, true );
				$ids_zero_true = is_array( $ids_zero_true ) ? array_map( 'intval', $ids_zero_true ) : [];
				$h .= '• Query(0, true): returned <strong>' . count( $ids_zero_true ) . '</strong> IDs → <code>' . esc_html( implode( ', ', array_slice( $ids_zero_true, 0, 30 ) ) ) . '</code><br>';
			} catch ( \Throwable $e ) {
				$h .= '• ⚠ Query(0, true) exception: ' . esc_html( $e->getMessage() ) . '<br>';
			}

			try {
				$ids_zero_false = \WC_Booking_Data_Store::get_bookings_in_date_range( $range['start'], $range['end'], 0, false );
				$ids_zero_false = is_array( $ids_zero_false ) ? array_map( 'intval', $ids_zero_false ) : [];
				$h .= '• Query(0, false): returned <strong>' . count( $ids_zero_false ) . '</strong> IDs → <code>' . esc_html( implode( ', ', array_slice( $ids_zero_false, 0, 30 ) ) ) . '</code><br>';
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

		// 7. Pipeline trace — walk each booking ID through Service's hydration
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">7. Pipeline trace for target date</h3>';
		$trace_ids = isset( $raw_ids ) && is_array( $raw_ids ) ? array_map( 'intval', $raw_ids ) : [];
		if ( empty( $trace_ids ) ) {
			$h .= '• (no booking IDs from section 5 to trace)<br>';
		} else {
			$h .= '<table style="width:100%;border-collapse:collapse;font-size:11px;margin-top:4px;">';
			$h .= '<tr style="background:#f6f7f7;">';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">Booking ID</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">Booking load</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">Booking status</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">get_order_id()</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">post_parent</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">_booking_order_item_id</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">wc_get_order</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">Items</th>';
			$h .= '<th style="padding:4px;border:1px solid #ccc;text-align:left;">Scopes seen</th>';
			$h .= '</tr>';

			foreach ( $trace_ids as $bid ) {
				$load_ok = '?';
				$bstatus = '?';
				$oid     = '?';
				$pparent = '?';
				$oimeta  = '?';
				$order_ok = '?';
				$items_n  = '?';
				$scopes   = '?';

				try {
					$booking = new \WC_Booking( $bid );
					if ( $booking && $booking->get_id() ) {
						$load_ok = '✅';
						$bstatus = (string) $booking->get_status();

						// WC_Booking::get_order_id()
						if ( method_exists( $booking, 'get_order_id' ) ) {
							$oid = (int) $booking->get_order_id();
						}
					} else {
						$load_ok = '❌';
					}
				} catch ( \Throwable $e ) {
					$load_ok = '❌ ' . substr( $e->getMessage(), 0, 60 );
				}

				// Raw post_parent
				$post = get_post( $bid );
				if ( $post ) {
					$pparent = (int) $post->post_parent;
				}

				// Raw meta _booking_order_item_id
				$oimeta = (string) get_post_meta( $bid, '_booking_order_item_id', true );
				if ( $oimeta === '' ) $oimeta = '—';

				// Attempt wc_get_order using the best available order ID
				$candidate_order_id = is_int( $oid ) && $oid > 0 ? $oid : ( is_int( $pparent ) && $pparent > 0 ? $pparent : 0 );
				if ( $candidate_order_id > 0 && function_exists( 'wc_get_order' ) ) {
					try {
						$order = wc_get_order( $candidate_order_id );
						if ( $order && is_a( $order, 'WC_Order' ) ) {
							$order_ok = '✅ ' . $order->get_status();
							$items    = $order->get_items();
							$items_n  = (string) count( $items );
							$seen     = [];
							foreach ( $items as $item ) {
								if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
								$sc = (string) $item->get_meta( '_tc_scope', true );
								if ( $sc === '' ) $sc = (string) $item->get_meta( 'tc_scope', true );
								if ( $sc === '' ) $sc = '(none)';
								$seen[] = $sc;
							}
							$scopes = esc_html( implode( ', ', $seen ) );
						} else {
							$order_ok = '❌ wc_get_order null';
						}
					} catch ( \Throwable $e ) {
						$order_ok = '❌ ' . substr( $e->getMessage(), 0, 60 );
					}
				} else {
					$order_ok = '❌ no order id';
				}

				$h .= '<tr>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $bid ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $load_ok ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $bstatus ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $oid ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $pparent ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $oimeta ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $order_ok ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . esc_html( (string) $items_n ) . '</td>';
				$h .= '<td style="padding:4px;border:1px solid #ccc;">' . $scopes . '</td>';
				$h .= '</tr>';
			}
			$h .= '</table>';
		}

		// 8. End-to-end Service trace — call the real pipeline and report counts at each stage.
		$h .= '<h3 style="margin:12px 0 4px 0;font-size:13px;">8. Service::build_report_for_date trace</h3>';
		try {
			$starts_on_ids      = Query::find_bookings_starting_on( $date );
			$starts_on_by_order = Query::group_by_order( $starts_on_ids );
			$active_on_ids      = Query::find_bookings_active_on( $date );
			$active_by_order    = Query::group_by_order( $active_on_ids );

			$h .= '• Query::find_bookings_starting_on returned: <strong>' . count( $starts_on_ids ) . '</strong> IDs → <code>' . esc_html( implode( ', ', array_slice( $starts_on_ids, 0, 30 ) ) ) . '</code><br>';
			$h .= '• Query::group_by_order (starts-on) produced: <strong>' . count( $starts_on_by_order ) . '</strong> orders<br>';
			if ( ! empty( $starts_on_by_order ) ) {
				foreach ( $starts_on_by_order as $oid => $bids ) {
					$h .= '&nbsp;&nbsp;• Order <code>' . esc_html( $oid ) . '</code> → bookings <code>' . esc_html( implode( ', ', $bids ) ) . '</code><br>';
				}
			}
			$h .= '• Query::find_bookings_active_on returned: <strong>' . count( $active_on_ids ) . '</strong> IDs → <code>' . esc_html( implode( ', ', array_slice( $active_on_ids, 0, 30 ) ) ) . '</code><br>';
			$h .= '• Query::group_by_order (active-on) produced: <strong>' . count( $active_by_order ) . '</strong> orders<br>';

			$records = Service::build_report_for_date( $date, true );
			$h .= '• Service::build_report_for_date returned: <strong>' . count( $records ) . '</strong> BookingRecord objects<br>';
			if ( ! empty( $records ) ) {
				foreach ( $records as $rec ) {
					$h .= '&nbsp;&nbsp;• Order ' . esc_html( $rec->order_number ) . ' category=' . esc_html( $rec->category ) . ' participants=' . count( $rec->participants ) . ' transport=' . ( is_array( $rec->transport ) ? 'yes' : 'no' ) . ' issues=' . count( $rec->issues ) . '<br>';
				}
			}
		} catch ( \Throwable $e ) {
			$h .= '• ⚠ Exception: ' . esc_html( $e->getMessage() ) . '<br>';
			$h .= '• File: ' . esc_html( $e->getFile() ) . ':' . esc_html( (string) $e->getLine() ) . '<br>';
		}

		$h .= '</div>';
		return $h;
	}
}
