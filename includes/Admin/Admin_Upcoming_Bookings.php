<?php
namespace TC_BF\Admin;

use TC_BF\Domain\UpcomingBookings\Service;
use TC_BF\Domain\UpcomingBookings\Renderer;
use TC_BF\Domain\UpcomingBookings\Diagnostics;
use TC_BF\Domain\UpcomingBookings\Report_Job;

if ( ! defined('ABSPATH') ) exit;

/**
 * Admin_Upcoming_Bookings — Admin dashboard for the Upcoming Bookings digest.
 *
 * Tools submenu page where staff can:
 *  - Pick a target date and generate the report inline
 *  - Send the report by email (to default list or a custom address)
 *  - Configure daily briefing settings (recipients, send hour, enable/disable)
 *
 * @since 1.x.x
 */
final class Admin_Upcoming_Bookings {

	const MENU_SLUG  = 'tcbf-upcoming-bookings';
	const CAPABILITY = 'manage_options';

	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_post_tcbf_send_digest', [ __CLASS__, 'handle_send' ] );
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
	 * Handle the "Send report" form submission.
	 */
	public static function handle_send() : void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'tcbf_send_digest' );

		$date_str     = isset( $_POST['tcbf_date'] ) ? sanitize_text_field( wp_unslash( $_POST['tcbf_date'] ) ) : '';
		$custom_email = isset( $_POST['tcbf_custom_email'] ) ? sanitize_email( wp_unslash( $_POST['tcbf_custom_email'] ) ) : '';
		$send_to      = isset( $_POST['tcbf_send_to'] ) ? sanitize_text_field( wp_unslash( $_POST['tcbf_send_to'] ) ) : 'default';

		$date_obj = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date_str, wp_timezone() );
		if ( ! $date_obj ) {
			$date_obj = new \DateTimeImmutable( 'tomorrow', wp_timezone() );
			$date_str = $date_obj->format( 'Y-m-d' );
		}

		$recipients = null; // null = use default list
		if ( $send_to === 'custom' && is_email( $custom_email ) ) {
			$recipients = [ $custom_email ];
		}

		$sent = Report_Job::send_report( $date_obj, $recipients );

		$redirect_url = add_query_arg( [
			'page'           => self::MENU_SLUG,
			'tcbf_generated' => '1',
			'tcbf_date'      => $date_str,
			'tcbf_include_active' => '1',
			'tcbf_sent'      => $sent ? '1' : '0',
		], admin_url( 'tools.php' ) );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render the full admin page.
	 */
	public static function render_page() : void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tc-booking-flow' ) );
		}

		// Read inputs
		$default_date = ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		$date_str = isset( $_GET['tcbf_date'] ) ? sanitize_text_field( wp_unslash( $_GET['tcbf_date'] ) ) : $default_date;

		$date_obj = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date_str, wp_timezone() );
		if ( ! $date_obj ) {
			$date_obj = new \DateTimeImmutable( 'tomorrow', wp_timezone() );
			$date_str = $date_obj->format( 'Y-m-d' );
		}

		$include_active = ! isset( $_GET['tcbf_generated'] ) || ! empty( $_GET['tcbf_include_active'] );
		$generated      = isset( $_GET['tcbf_generated'] );
		$just_sent      = isset( $_GET['tcbf_sent'] );
		$send_ok        = ! empty( $_GET['tcbf_sent'] );

		$is_debug = \TC_BF\Admin\Settings::is_debug();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Upcoming Bookings Briefing', 'tc-booking-flow' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Operational digest of bookings for a chosen date.', 'tc-booking-flow' ); ?></p>

			<?php if ( $just_sent ) : ?>
				<div class="notice notice-<?php echo $send_ok ? 'success' : 'error'; ?> is-dismissible">
					<p>
						<?php
						if ( $send_ok ) {
							esc_html_e( 'Report sent successfully.', 'tc-booking-flow' );
						} else {
							esc_html_e( 'Failed to send report. Check that recipients are configured and wp_mail is working.', 'tc-booking-flow' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- ========== GENERATE REPORT ========== -->
			<form method="get" action="" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0;border-radius:4px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="tcbf_generated" value="1">

				<table class="form-table" style="margin:0;">
					<tr>
						<th scope="row" style="padding:8px 10px 8px 0;"><label for="tcbf_date"><?php esc_html_e( 'Target date', 'tc-booking-flow' ); ?></label></th>
						<td style="padding:8px 0;">
							<input type="date" id="tcbf_date" name="tcbf_date" value="<?php echo esc_attr( $date_str ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Options', 'tc-booking-flow' ); ?></th>
						<td style="padding:8px 0;">
							<label>
								<input type="checkbox" name="tcbf_include_active" value="1" <?php checked( $include_active ); ?>>
								<?php esc_html_e( 'Include bookings that started earlier and are still active on this date', 'tc-booking-flow' ); ?>
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
				<!-- ========== SEND BY EMAIL ========== -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:0 0 16px 0;border-radius:4px;">
					<?php wp_nonce_field( 'tcbf_send_digest' ); ?>
					<input type="hidden" name="action" value="tcbf_send_digest">
					<input type="hidden" name="tcbf_date" value="<?php echo esc_attr( $date_str ); ?>">

					<h3 style="margin:0 0 8px 0;font-size:14px;"><?php esc_html_e( 'Send this report by email', 'tc-booking-flow' ); ?></h3>

					<fieldset style="margin-bottom:8px;">
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="tcbf_send_to" value="default" checked>
							<?php
							$recipients = Report_Job::get_recipients();
							if ( ! empty( $recipients ) ) {
								printf(
									/* translators: %s: comma-separated list of emails */
									esc_html__( 'Default recipients: %s', 'tc-booking-flow' ),
									'<code>' . esc_html( implode( ', ', $recipients ) ) . '</code>'
								);
							} else {
								esc_html_e( 'Default recipients (none configured — set them in Daily Briefing Settings below)', 'tc-booking-flow' );
							}
							?>
						</label>
						<label style="display:block;">
							<input type="radio" name="tcbf_send_to" value="custom">
							<?php esc_html_e( 'Custom email:', 'tc-booking-flow' ); ?>
							<input type="email" name="tcbf_custom_email" value="" placeholder="someone@example.com" style="width:250px;">
						</label>
					</fieldset>

					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Send report', 'tc-booking-flow' ); ?></button>
				</form>

				<!-- ========== REPORT OUTPUT ========== -->
				<div id="tcbf-report" style="margin-top:16px;">
					<?php
					$records = Service::build_report_for_date( $date_obj, $include_active );
					echo Renderer::render_report( $records, $date_obj ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</div>
			<?php endif; ?>

			<!-- ========== DAILY BRIEFING SETTINGS ========== -->
			<form method="post" action="options.php" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:24px 0 16px 0;border-radius:4px;">
				<?php settings_fields( 'tcbf_digest_settings' ); ?>
				<h3 style="margin:0 0 8px 0;font-size:14px;"><?php esc_html_e( 'Daily Briefing Settings', 'tc-booking-flow' ); ?></h3>

				<table class="form-table" style="margin:0;">
					<tr>
						<th scope="row" style="padding:8px 10px 8px 0;"><?php esc_html_e( 'Enable daily briefing', 'tc-booking-flow' ); ?></th>
						<td style="padding:8px 0;">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Report_Job::OPT_ENABLED ); ?>" value="1" <?php checked( Report_Job::is_enabled() ); ?>>
								<?php esc_html_e( 'Send an automatic daily digest email', 'tc-booking-flow' ); ?>
							</label>
							<?php
							if ( Report_Job::is_enabled() && function_exists( 'as_has_scheduled_action' ) ) {
								if ( as_has_scheduled_action( Report_Job::HOOK ) ) {
									$next = as_next_scheduled_action( Report_Job::HOOK );
									if ( $next ) {
										echo '<p class="description" style="margin-top:4px;">';
										printf(
											/* translators: %s: date/time of next scheduled run */
											esc_html__( 'Next scheduled run: %s', 'tc-booking-flow' ),
											'<strong>' . esc_html( date_i18n( 'Y-m-d H:i:s', $next ) ) . '</strong>'
										);
										echo '</p>';
									}
								}
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row" style="padding:8px 10px 8px 0;"><label for="tcbf_digest_recipients"><?php esc_html_e( 'Recipients', 'tc-booking-flow' ); ?></label></th>
						<td style="padding:8px 0;">
							<textarea id="tcbf_digest_recipients" name="<?php echo esc_attr( Report_Job::OPT_RECIPIENTS ); ?>" rows="3" class="regular-text" style="width:350px;"><?php echo esc_textarea( (string) get_option( Report_Job::OPT_RECIPIENTS, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One email per line. Used for both the daily briefing and the "Send to default list" button above.', 'tc-booking-flow' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row" style="padding:8px 10px 8px 0;"><label for="tcbf_digest_send_hour"><?php esc_html_e( 'Send time', 'tc-booking-flow' ); ?></label></th>
						<td style="padding:8px 0;">
							<select id="tcbf_digest_send_hour" name="<?php echo esc_attr( Report_Job::OPT_SEND_HOUR ); ?>">
								<?php
								$current_hour = Report_Job::get_send_hour();
								for ( $h = 0; $h <= 23; $h++ ) {
									printf(
										'<option value="%d"%s>%02d:00</option>',
										$h,
										selected( $current_hour, $h, false ),
										$h
									);
								}
								?>
							</select>
							<span class="description"><?php echo esc_html( sprintf( __( '(site timezone: %s)', 'tc-booking-flow' ), wp_timezone_string() ) ); ?></span>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'tc-booking-flow' ), 'primary', 'submit', true ); ?>
			</form>

			<?php if ( $is_debug ) : ?>
				<!-- ========== DIAGNOSTICS (debug only) ========== -->
				<?php if ( $generated ) : ?>
					<details style="margin:16px 0;">
						<summary style="cursor:pointer;font-weight:600;color:#646970;"><?php esc_html_e( 'Diagnostics (debug mode)', 'tc-booking-flow' ); ?></summary>
						<?php echo Diagnostics::render( $date_obj ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</details>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
