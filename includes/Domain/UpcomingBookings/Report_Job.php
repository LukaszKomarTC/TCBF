<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Report_Job — Action Scheduler daily cron for the Upcoming Bookings digest.
 *
 * Schedules a daily recurring action at the configured hour (default 18:00
 * site timezone). When fired, builds the report for "tomorrow" and sends
 * it to the configured recipients.
 *
 * Uses Action Scheduler (bundled with WooCommerce) instead of WP-Cron for
 * reliable timing. Self-registers on `init`; cleans up on plugin deactivation.
 *
 * @since 1.x.x
 */
final class Report_Job {

	/** Action Scheduler hook name */
	const HOOK = 'tcbf_upcoming_bookings_daily_briefing';

	/** Option keys */
	const OPT_ENABLED    = 'tcbf_digest_enabled';
	const OPT_RECIPIENTS = 'tcbf_digest_recipients';
	const OPT_SEND_HOUR  = 'tcbf_digest_send_hour';

	/** Transient lock to prevent concurrent runs */
	const LOCK_KEY     = 'tcbf_digest_job_lock';
	const LOCK_TIMEOUT = 300;

	/**
	 * Initialize — register hooks and schedule if enabled.
	 */
	public static function init() : void {
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ], 20 );
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	/**
	 * Register WP settings for the digest options.
	 */
	public static function register_settings() : void {
		register_setting( 'tcbf_digest_settings', self::OPT_ENABLED, [
			'type'              => 'boolean',
			'sanitize_callback' => function( $v ) { return (int) ! empty( $v ); },
			'default'           => 0,
		] );
		register_setting( 'tcbf_digest_settings', self::OPT_RECIPIENTS, [
			'type'              => 'string',
			'sanitize_callback' => [ __CLASS__, 'sanitize_recipients' ],
			'default'           => '',
		] );
		register_setting( 'tcbf_digest_settings', self::OPT_SEND_HOUR, [
			'type'              => 'integer',
			'sanitize_callback' => function( $v ) { return max( 0, min( 23, absint( $v ) ) ); },
			'default'           => 18,
		] );
	}

	/**
	 * Schedule the daily action if enabled and not already scheduled.
	 */
	public static function maybe_schedule() : void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return; // Action Scheduler not available.
		}
		if ( ! self::is_enabled() ) {
			// If disabled, unschedule any existing action.
			as_unschedule_all_actions( self::HOOK );
			return;
		}
		if ( as_has_scheduled_action( self::HOOK ) ) {
			return; // Already scheduled.
		}

		$next_run = self::compute_next_run_timestamp();
		as_schedule_recurring_action( $next_run, DAY_IN_SECONDS, self::HOOK, [], 'tcbf' );

		\TC_BF\Support\Logger::log( 'digest_job.scheduled', [
			'next_run'     => date_i18n( 'Y-m-d H:i:s', $next_run ),
			'next_run_utc' => gmdate( 'Y-m-d H:i:s', $next_run ),
		] );
	}

	/**
	 * Execute the daily digest — build report for tomorrow and send it.
	 */
	public static function run() : void {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Transient-based lock to prevent concurrent runs.
		if ( get_transient( self::LOCK_KEY ) ) {
			\TC_BF\Support\Logger::log( 'digest_job.skipped_locked', [] );
			return;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TIMEOUT );

		try {
			$tz       = wp_timezone();
			$tomorrow = new \DateTimeImmutable( 'tomorrow', $tz );
			$records  = Service::build_report_for_date( $tomorrow, true );
			$html     = Renderer::render_email( $records, $tomorrow );

			$recipients = self::get_recipients();
			if ( empty( $recipients ) ) {
				\TC_BF\Support\Logger::log( 'digest_job.no_recipients', [], 'warning' );
				delete_transient( self::LOCK_KEY );
				return;
			}

			$subject = sprintf(
				'[TCBF] Briefing for %s — %d bookings',
				$tomorrow->format( 'Y-m-d' ),
				count( $records )
			);

			$sent = self::send_html_email( $recipients, $subject, $html );

			\TC_BF\Support\Logger::log( 'digest_job.completed', [
				'date'       => $tomorrow->format( 'Y-m-d' ),
				'records'    => count( $records ),
				'recipients' => count( $recipients ),
				'sent'       => $sent ? 1 : 0,
			] );
		} catch ( \Throwable $e ) {
			\TC_BF\Support\Logger::log( 'digest_job.exception', [
				'message' => $e->getMessage(),
			], 'error' );
		}

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Send the report for a given date to the specified recipients (or default list).
	 *
	 * Used by both the cron job and the manual "Send" button.
	 *
	 * @param \DateTimeInterface $date
	 * @param string[]|null      $to   Override recipients; null uses the stored list.
	 * @return bool Whether wp_mail succeeded.
	 */
	public static function send_report( \DateTimeInterface $date, ?array $to = null ) : bool {
		$records = Service::build_report_for_date( $date, true );
		$html    = Renderer::render_email( $records, $date );

		$recipients = $to ?? self::get_recipients();
		if ( empty( $recipients ) ) {
			return false;
		}

		$subject = sprintf(
			'[TCBF] Briefing for %s — %d bookings',
			$date->format( 'Y-m-d' ),
			count( $records )
		);

		return self::send_html_email( $recipients, $subject, $html );
	}

	/**
	 * Send an HTML email via wp_mail.
	 *
	 * @param string[] $to
	 * @param string   $subject
	 * @param string   $html
	 * @return bool
	 */
	private static function send_html_email( array $to, string $subject, string $html ) : bool {
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		// Capture wp_mail errors for logging.
		$mail_error = null;
		$error_handler = function( $wp_error ) use ( &$mail_error ) {
			$mail_error = $wp_error;
		};
		add_action( 'wp_mail_failed', $error_handler );

		$result = wp_mail( $to, $subject, $html, $headers );

		remove_action( 'wp_mail_failed', $error_handler );

		if ( ! $result && $mail_error instanceof \WP_Error ) {
			\TC_BF\Support\Logger::log( 'digest.wp_mail_failed', [
				'error_code'    => $mail_error->get_error_code(),
				'error_message' => $mail_error->get_error_message(),
				'to'            => implode( ', ', $to ),
				'subject'       => $subject,
			], 'error' );
		}

		return (bool) $result;
	}

	/* =========================================================
	 * Helpers
	 * ========================================================= */

	public static function is_enabled() : bool {
		return (bool) get_option( self::OPT_ENABLED, 0 );
	}

	public static function get_recipients() : array {
		$raw = (string) get_option( self::OPT_RECIPIENTS, '' );
		if ( $raw === '' ) {
			return [];
		}
		$emails = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		return array_filter( $emails, 'is_email' );
	}

	public static function get_send_hour() : int {
		return (int) get_option( self::OPT_SEND_HOUR, 18 );
	}

	/**
	 * Compute the next run timestamp for the configured hour in site timezone.
	 */
	private static function compute_next_run_timestamp() : int {
		$tz   = wp_timezone();
		$hour = self::get_send_hour();
		$now  = new \DateTimeImmutable( 'now', $tz );
		$run  = $now->setTime( $hour, 0, 0 );

		// If the target time already passed today, schedule for tomorrow.
		if ( $run <= $now ) {
			$run = $run->modify( '+1 day' );
		}

		return $run->getTimestamp();
	}

	/**
	 * Sanitize the recipients textarea — one email per line.
	 */
	public static function sanitize_recipients( $value ) : string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$lines = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		$valid = array_filter( $lines, 'is_email' );
		return implode( "\n", $valid );
	}

	/**
	 * Unschedule everything on plugin deactivation.
	 */
	public static function deactivate() : void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
	}
}
