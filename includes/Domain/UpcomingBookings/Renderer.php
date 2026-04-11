<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Renderer — HTML renderer for the Upcoming Bookings digest.
 *
 * Produces table-based HTML that works in both:
 *  - The admin dashboard page (wrapped in standard wp-admin chrome)
 *  - Email clients (inline styles, no external CSS)
 *
 * Two entry points:
 *  - render_report() → full page (summary + card list)
 *  - render_email()  → full HTML document ready for wp_mail() html content-type
 *
 * @since 1.x.x
 */
final class Renderer {

	/**
	 * Render the full report for dashboard display (inline, no <html> wrapper).
	 *
	 * @param BookingRecord[]    $records
	 * @param \DateTimeInterface $date
	 * @return string HTML
	 */
	public static function render_report( array $records, \DateTimeInterface $date ) : string {
		$out  = self::render_summary_block( $records, $date );
		$out .= self::render_cards( $records );
		return $out;
	}

	/**
	 * Render a full standalone HTML document for email delivery.
	 *
	 * @param BookingRecord[]    $records
	 * @param \DateTimeInterface $date
	 * @return string Full HTML document
	 */
	public static function render_email( array $records, \DateTimeInterface $date ) : string {
		$inner = self::render_report( $records, $date );
		$title = sprintf(
			/* translators: %s: date */
			__( 'Upcoming Bookings Briefing — %s', 'tc-booking-flow' ),
			$date->format( 'Y-m-d' )
		);

		ob_start();
		?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( $title ); ?></title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">
<div style="max-width:900px;margin:0 auto;">
<?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
</body>
</html><?php
		return (string) ob_get_clean();
	}

	/* =========================================================
	 * Sections
	 * ========================================================= */

	/**
	 * Top summary block — counts + attention summary.
	 *
	 * @param BookingRecord[] $records
	 */
	private static function render_summary_block( array $records, \DateTimeInterface $date ) : string {
		$total      = count( $records );
		$starts_on  = 0;
		$active_on  = 0;
		$attention  = 0;
		$unpaid     = 0;
		$no_phone   = 0;
		$no_confirm = 0;

		foreach ( $records as $r ) {
			if ( $r->category === BookingRecord::CATEGORY_STARTS_ON ) $starts_on++;
			if ( $r->category === BookingRecord::CATEGORY_ACTIVE_ON ) $active_on++;
			if ( ! empty( $r->issues ) ) $attention++;
			foreach ( $r->issues as $issue ) {
				$code = $issue['code'] ?? '';
				if ( $code === Issue_Detector::ISSUE_UNPAID ) $unpaid++;
				if ( $code === Issue_Detector::ISSUE_MISSING_PHONE ) $no_phone++;
				if ( $code === Issue_Detector::ISSUE_NO_CONFIRMATION ) $no_confirm++;
			}
		}

		$date_label = date_i18n( get_option( 'date_format' ), $date->getTimestamp() );

		$h = '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin-bottom:16px;">';
		$h .= '<div style="font-size:12px;text-transform:uppercase;color:#646970;letter-spacing:0.5px;">' . esc_html__( 'Briefing for', 'tc-booking-flow' ) . '</div>';
		$h .= '<div style="font-size:22px;font-weight:600;margin:4px 0 12px 0;color:#1d2327;">' . esc_html( $date_label ) . '</div>';
		$h .= '<div style="font-size:14px;line-height:1.6;">';
		$h .= '<strong>' . sprintf( esc_html__( 'Total: %d bookings', 'tc-booking-flow' ), $total ) . '</strong> ';
		$h .= '(' . sprintf( esc_html__( '%d starting', 'tc-booking-flow' ), $starts_on );
		$h .= ', ' . sprintf( esc_html__( '%d active', 'tc-booking-flow' ), $active_on );
		$h .= ')';
		if ( $attention > 0 ) {
			$h .= '<br><span style="color:#b32d2e;">⚠ ' . sprintf( esc_html__( '%d need attention', 'tc-booking-flow' ), $attention ) . '</span>';
			$bits = [];
			if ( $unpaid > 0 )     $bits[] = sprintf( esc_html__( '%d unpaid', 'tc-booking-flow' ), $unpaid );
			if ( $no_phone > 0 )   $bits[] = sprintf( esc_html__( '%d missing phone', 'tc-booking-flow' ), $no_phone );
			if ( $no_confirm > 0 ) $bits[] = sprintf( esc_html__( '%d no confirmation', 'tc-booking-flow' ), $no_confirm );
			if ( ! empty( $bits ) ) {
				$h .= ' &middot; <span style="color:#646970;">' . esc_html( implode( ' · ', $bits ) ) . '</span>';
			}
		}
		$h .= '</div>';
		$h .= '</div>';
		return $h;
	}

	/**
	 * Render the list of cards, with section headers.
	 *
	 * @param BookingRecord[] $records
	 */
	private static function render_cards( array $records ) : string {
		if ( empty( $records ) ) {
			return '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:32px;text-align:center;color:#646970;">'
				. esc_html__( 'No bookings found for this date.', 'tc-booking-flow' )
				. '</div>';
		}

		// Split by category to render section headers.
		$starts_on = [];
		$active_on = [];
		foreach ( $records as $r ) {
			if ( $r->category === BookingRecord::CATEGORY_ACTIVE_ON ) {
				$active_on[] = $r;
			} else {
				$starts_on[] = $r;
			}
		}

		$h = '';
		if ( ! empty( $starts_on ) ) {
			$h .= self::render_section_header( __( 'Starts on this date', 'tc-booking-flow' ), count( $starts_on ) );
			foreach ( $starts_on as $r ) {
				$h .= self::render_card( $r );
			}
		}
		if ( ! empty( $active_on ) ) {
			$h .= self::render_section_header( __( 'Active on this date', 'tc-booking-flow' ), count( $active_on ) );
			foreach ( $active_on as $r ) {
				$h .= self::render_card( $r );
			}
		}
		return $h;
	}

	private static function render_section_header( string $label, int $count ) : string {
		return '<div style="font-size:13px;font-weight:600;text-transform:uppercase;color:#646970;letter-spacing:0.5px;margin:24px 0 8px 0;">'
			. esc_html( $label ) . ' (' . (int) $count . ')'
			. '</div>';
	}

	/**
	 * Render a single booking card.
	 */
	private static function render_card( BookingRecord $r ) : string {
		$h  = '<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid ' . self::status_color( $r ) . ';border-radius:6px;padding:16px;margin-bottom:12px;">';

		// Header row: order number + event title + status
		$h .= '<div style="display:block;margin-bottom:8px;">';
		$h .= '<span style="font-size:16px;font-weight:600;">' . esc_html( $r->order_number ) . '</span>';
		if ( ! empty( $r->event['title'] ) ) {
			$h .= ' &middot; <span style="font-size:15px;">' . esc_html( $r->event['title'] ) . '</span>';
		}
		$h .= '<span style="float:right;font-size:11px;background:#f0f0f1;padding:2px 8px;border-radius:10px;color:#50575e;text-transform:uppercase;letter-spacing:0.3px;">';
		$h .= esc_html( $r->payment['status_label'] );
		$h .= '</span>';
		$h .= '</div>';

		// Issues badges
		if ( ! empty( $r->issues ) ) {
			$h .= '<div style="margin:8px 0;">';
			foreach ( $r->issues as $issue ) {
				$h .= '<span style="display:inline-block;background:#fcf0f1;color:#b32d2e;font-size:11px;padding:3px 8px;border-radius:10px;margin-right:4px;margin-bottom:4px;font-weight:600;">⚠ ' . esc_html( $issue['label'] ) . '</span>';
			}
			$h .= '</div>';
		}

		// Date/time — prefer a compact, full-day-friendly rendering.
		if ( ! empty( $r->event['start'] ) ) {
			$h .= '<div style="font-size:13px;color:#50575e;margin-bottom:6px;"><strong>' . esc_html__( 'When:', 'tc-booking-flow' ) . '</strong> ' . esc_html( self::format_when( (int) $r->event['start'], (int) $r->event['end'] ) ) . '</div>';
		}

		// Customer
		$h .= '<div style="font-size:13px;color:#1d2327;margin-bottom:6px;">';
		$h .= '<strong>' . esc_html__( 'Customer:', 'tc-booking-flow' ) . '</strong> ';
		$h .= esc_html( $r->customer['name'] !== '' ? $r->customer['name'] : '—' );
		if ( ! empty( $r->customer['email'] ) ) {
			$h .= ' &middot; <a href="mailto:' . esc_attr( $r->customer['email'] ) . '" style="color:#2271b1;">' . esc_html( $r->customer['email'] ) . '</a>';
		}
		if ( ! empty( $r->customer['phone'] ) ) {
			$h .= ' &middot; ' . esc_html( $r->customer['phone'] );
		}
		$h .= '</div>';

		// Participants table
		if ( ! empty( $r->participants ) ) {
			$h .= '<div style="margin-top:10px;">';
			$h .= '<div style="font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;margin-bottom:4px;">' . esc_html__( 'Participants', 'tc-booking-flow' ) . '</div>';
			$h .= '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
			$h .= '<tr style="background:#f6f7f7;text-align:left;">';
			$h .= '<th style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html__( 'Name', 'tc-booking-flow' ) . '</th>';
			$h .= '<th style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html__( 'Bike', 'tc-booking-flow' ) . '</th>';
			$h .= '<th style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html__( 'Pedals', 'tc-booking-flow' ) . '</th>';
			$h .= '<th style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html__( 'Helmet', 'tc-booking-flow' ) . '</th>';
			$h .= '</tr>';
			foreach ( $r->participants as $p ) {
				$h .= '<tr>';
				$h .= '<td style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html( $p['name'] !== '' ? $p['name'] : '—' ) . '</td>';
				$h .= '<td style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html( $p['bike'] !== '' ? $p['bike'] : '—' ) . '</td>';
				$h .= '<td style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html( $p['pedals'] !== '' ? $p['pedals'] : '—' ) . '</td>';
				$h .= '<td style="padding:6px 8px;border:1px solid #dcdcde;">' . esc_html( $p['helmet'] !== '' ? $p['helmet'] : '—' ) . '</td>';
				$h .= '</tr>';
			}
			$h .= '</table>';
			$h .= '</div>';
		}

		// Transport
		if ( is_array( $r->transport ) && ! empty( $r->transport['present'] ) ) {
			$h .= '<div style="margin-top:10px;background:#f0f6fc;border:1px solid #c3dcf4;border-radius:4px;padding:10px;">';
			$h .= '<div style="font-size:12px;font-weight:600;color:#2271b1;text-transform:uppercase;margin-bottom:4px;">🚚 ' . esc_html__( 'Transport', 'tc-booking-flow' ) . '</div>';
			$h .= '<div style="font-size:13px;color:#1d2327;">';
			$bits = [];
			if ( ! empty( $r->transport['type'] ) )   $bits[] = esc_html( $r->transport['type'] );
			if ( ! empty( $r->transport['date'] ) )   $bits[] = esc_html( $r->transport['date'] );
			if ( ! empty( $r->transport['window'] ) ) $bits[] = esc_html( $r->transport['window'] );
			if ( ! empty( $r->transport['zone'] ) )   $bits[] = esc_html( $r->transport['zone'] );
			$h .= implode( ' &middot; ', $bits );
			if ( ! empty( $r->transport['address'] ) ) {
				$h .= '<br><strong>' . esc_html__( 'Address:', 'tc-booking-flow' ) . '</strong> ' . esc_html( $r->transport['address'] );
			}
			$h .= '</div>';
			$h .= '</div>';
		}

		// Partner
		if ( is_array( $r->partner ) && ! empty( $r->partner['code'] ) ) {
			$h .= '<div style="margin-top:8px;font-size:12px;color:#50575e;">';
			$h .= '<strong>' . esc_html__( 'Partner:', 'tc-booking-flow' ) . '</strong> ' . esc_html( $r->partner['code'] );
			$h .= '</div>';
		}

		// Customer note
		if ( $r->customer_note !== '' ) {
			$h .= '<div style="margin-top:10px;background:#fcf9e8;border-left:3px solid #dba617;padding:8px 10px;font-size:12px;">';
			$h .= '<strong>' . esc_html__( 'Customer note:', 'tc-booking-flow' ) . '</strong> ';
			$h .= nl2br( esc_html( $r->customer_note ) );
			$h .= '</div>';
		}

		// Footer: admin link + booking IDs
		$admin_url = admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' );
		$h .= '<div style="margin-top:10px;padding-top:8px;border-top:1px solid #f0f0f1;font-size:11px;color:#8c8f94;">';
		$h .= '<a href="' . esc_url( $admin_url ) . '" style="color:#2271b1;text-decoration:none;">' . esc_html__( 'Open in admin', 'tc-booking-flow' ) . ' →</a>';
		if ( ! empty( $r->booking_ids ) ) {
			$h .= ' &middot; ' . esc_html__( 'Booking IDs:', 'tc-booking-flow' ) . ' ' . esc_html( implode( ', ', $r->booking_ids ) );
		}
		$h .= ' &middot; ' . esc_html__( 'Priority:', 'tc-booking-flow' ) . ' ' . (int) $r->priority;
		$h .= '</div>';

		$h .= '</div>';
		return $h;
	}

	/**
	 * Format a compact human "When:" string from start/end timestamps.
	 *
	 * Rules:
	 *  - Full-day booking (00:00:00 → 23:59:59 same day or consecutive full days): hide times.
	 *  - Same day, partial time: "25 Apr 2026 09:00 → 17:00"
	 *  - Multi-day: "24 Apr 2026 → 26 Apr 2026"
	 *  - Single timestamp: "25 Apr 2026 09:00"
	 */
	private static function format_when( int $start, int $end ) : string {
		if ( $start <= 0 ) {
			return '';
		}
		$fmt_date = get_option( 'date_format' );

		if ( $end <= 0 || $end === $start ) {
			return date_i18n( $fmt_date . ' H:i', $start );
		}

		$start_y_m_d = date_i18n( 'Y-m-d', $start );
		$end_y_m_d   = date_i18n( 'Y-m-d', $end );
		$start_his   = date_i18n( 'His', $start );
		$end_his     = date_i18n( 'His', $end );

		$is_full_day = ( $start_his === '000000' && $end_his === '235959' );

		if ( $is_full_day ) {
			if ( $start_y_m_d === $end_y_m_d ) {
				return date_i18n( $fmt_date, $start );
			}
			return date_i18n( $fmt_date, $start ) . ' → ' . date_i18n( $fmt_date, $end );
		}

		// Partial times.
		if ( $start_y_m_d === $end_y_m_d ) {
			return date_i18n( $fmt_date . ' H:i', $start ) . ' → ' . date_i18n( 'H:i', $end );
		}
		return date_i18n( $fmt_date . ' H:i', $start ) . ' → ' . date_i18n( $fmt_date . ' H:i', $end );
	}

	/**
	 * Choose a left-border accent color based on issue/status severity.
	 */
	private static function status_color( BookingRecord $r ) : string {
		if ( ! $r->payment['paid'] ) {
			return '#b32d2e'; // red — unpaid
		}
		if ( ! empty( $r->issues ) ) {
			return '#dba617'; // amber — has issues
		}
		return '#46b450'; // green — ready
	}
}
