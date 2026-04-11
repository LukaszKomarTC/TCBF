<?php
namespace TC_BF\Admin;

use TC_BF\Domain\UpcomingBookings\Service;
use TC_BF\Domain\UpcomingBookings\Renderer;

if ( ! defined('ABSPATH') ) exit;

/**
 * Admin_Upcoming_Bookings — Admin dashboard for the Upcoming Bookings digest.
 *
 * Registers a Tools submenu page where staff can:
 *  - Pick a target date (defaults to tomorrow)
 *  - Toggle "include active on this date"
 *  - Click "Generate" to render the report inline
 *  - (Batch 3) Send the report by email
 *
 * All state lives in the URL query string — no AJAX for batch 2.
 *
 * @since 1.x.x
 */
final class Admin_Upcoming_Bookings {

	const MENU_SLUG = 'tcbf-upcoming-bookings';
	const CAPABILITY = 'manage_options';

	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
	}

	public static function register_menu() : void {
		add_submenu_page(
			'tools.php',
			__( 'Upcoming Bookings Briefing', 'tc-booking-flow' ),
			__( 'TCBF: Upcoming Bookings', 'tc-booking-flow' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Render the full admin page.
	 */
	public static function render_page() : void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tc-booking-flow' ) );
		}

		// Read inputs (date + include_active)
		$default_date = ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		$date_str = isset( $_GET['tcbf_date'] ) ? sanitize_text_field( wp_unslash( $_GET['tcbf_date'] ) ) : $default_date;

		// Validate date format
		$date_obj = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date_str, wp_timezone() );
		if ( ! $date_obj ) {
			$date_obj = new \DateTimeImmutable( 'tomorrow', wp_timezone() );
			$date_str = $date_obj->format( 'Y-m-d' );
		}

		$include_active = ! isset( $_GET['tcbf_generated'] ) || ! empty( $_GET['tcbf_include_active'] );
		$generated      = isset( $_GET['tcbf_generated'] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Upcoming Bookings Briefing', 'tc-booking-flow' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Operational digest of bookings for a chosen date. Shows starts-on and active-on bookings with detected issues and priority ranking.', 'tc-booking-flow' ); ?></p>

			<form method="get" action="" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0;border-radius:4px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="tcbf_generated" value="1">

				<table class="form-table" style="margin:0;">
					<tr>
						<th scope="row" style="padding:8px 10px 8px 0;"><label for="tcbf_date"><?php esc_html_e( 'Target date', 'tc-booking-flow' ); ?></label></th>
						<td style="padding:8px 0;">
							<input type="date" id="tcbf_date" name="tcbf_date" value="<?php echo esc_attr( $date_str ); ?>" class="regular-text">
							<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Defaults to tomorrow.', 'tc-booking-flow' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Include active bookings', 'tc-booking-flow' ); ?></th>
						<td style="padding:8px 0;">
							<label>
								<input type="checkbox" name="tcbf_include_active" value="1" <?php checked( $include_active ); ?>>
								<?php esc_html_e( 'Also include bookings that started earlier and are still active on this date', 'tc-booking-flow' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit" style="margin:12px 0 0 0;padding:0;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate report', 'tc-booking-flow' ); ?></button>
					<?php if ( $generated ) : ?>
						<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'tc-booking-flow' ); ?></a>
					<?php endif; ?>
				</p>
			</form>

			<?php if ( $generated ) : ?>
				<div id="tcbf-report" style="margin-top:20px;">
					<?php
					$records = Service::build_report_for_date( $date_obj, $include_active );
					// Renderer returns pre-escaped HTML built with esc_html/esc_attr.
					echo Renderer::render_report( $records, $date_obj ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
