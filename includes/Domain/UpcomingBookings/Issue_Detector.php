<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Issue_Detector — Flags operational problems on a BookingRecord.
 *
 * Runs a battery of checks and populates BookingRecord::$issues with
 * a human-readable list of problems that staff need to resolve before
 * the booking starts.
 *
 * @since 1.x.x
 */
final class Issue_Detector {

	/** Issue codes — stable identifiers for programmatic checks */
	const ISSUE_UNPAID             = 'unpaid';
	const ISSUE_MISSING_PHONE      = 'missing_phone';
	const ISSUE_MISSING_EMAIL      = 'missing_email';
	const ISSUE_NO_CONFIRMATION    = 'no_confirmation';
	const ISSUE_MISSING_PEDALS     = 'missing_pedals';

	/**
	 * Inspect a record and populate its $issues array.
	 *
	 * @param BookingRecord $record Record to inspect (mutated in place).
	 */
	public static function detect( BookingRecord $record ) : void {
		$issues = [];

		// 1. Payment status
		if ( ! $record->payment['paid'] ) {
			$issues[] = [
				'code'  => self::ISSUE_UNPAID,
				'label' => __( 'Not paid', 'tc-booking-flow' ),
			];
		}

		// 2. Missing phone
		if ( empty( $record->customer['phone'] ) ) {
			$issues[] = [
				'code'  => self::ISSUE_MISSING_PHONE,
				'label' => __( 'Missing phone', 'tc-booking-flow' ),
			];
		}

		// 3. Missing email
		if ( empty( $record->customer['email'] ) ) {
			$issues[] = [
				'code'  => self::ISSUE_MISSING_EMAIL,
				'label' => __( 'Missing email', 'tc-booking-flow' ),
			];
		}

		// 4. Customer confirmation not sent
		if ( ! $record->confirmation_sent ) {
			$issues[] = [
				'code'  => self::ISSUE_NO_CONFIRMATION,
				'label' => __( 'Confirmation not sent', 'tc-booking-flow' ),
			];
		}

		// 5. At least one rider missing pedal type (across tour packs and standalone rentals)
		$missing_pedals = false;
		foreach ( $record->tour_packs as $pack ) {
			if ( empty( $pack['pedals'] ) ) {
				$missing_pedals = true;
				break;
			}
		}
		if ( ! $missing_pedals ) {
			foreach ( $record->standalone_rentals as $rental ) {
				if ( empty( $rental['pedals'] ) ) {
					$missing_pedals = true;
					break;
				}
			}
		}
		if ( $missing_pedals ) {
			$issues[] = [
				'code'  => self::ISSUE_MISSING_PEDALS,
				'label' => __( 'Pedal type missing', 'tc-booking-flow' ),
			];
		}

		$record->issues = $issues;
	}
}
