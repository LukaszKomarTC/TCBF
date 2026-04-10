<?php
namespace TC_BF\Domain\UpcomingBookings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Priority_Scorer — Assigns a numeric priority score to a BookingRecord.
 *
 * Higher score = more urgent / more important. The Renderer uses the
 * score to sort cards (descending) so staff see the most critical
 * bookings first.
 *
 * Scoring is additive. Adjust constants to tune urgency weighting.
 *
 * @since 1.x.x
 */
final class Priority_Scorer {

	const SCORE_STARTS_TOMORROW    = 50;
	const SCORE_ISSUE_UNPAID       = 40;
	const SCORE_ISSUE_MISSING_INFO = 20;
	const SCORE_HAS_TRANSPORT      = 10;
	const SCORE_GMAIL_HIT          = 30;
	const SCORE_PARTICIPANT        = 2;

	/**
	 * Compute and assign a priority score to the given record.
	 *
	 * @param BookingRecord $record Record to score (mutated in place).
	 */
	public static function score( BookingRecord $record ) : void {
		$score = 0;

		// Category — starting today/tomorrow is more urgent than ongoing.
		if ( $record->category === BookingRecord::CATEGORY_STARTS_ON ) {
			$score += self::SCORE_STARTS_TOMORROW;
		}

		// Each issue contributes to priority.
		foreach ( $record->issues as $issue ) {
			$code = $issue['code'] ?? '';
			if ( $code === Issue_Detector::ISSUE_UNPAID ) {
				$score += self::SCORE_ISSUE_UNPAID;
			} else {
				$score += self::SCORE_ISSUE_MISSING_INFO;
			}
		}

		// Transport adds complexity → small bump.
		if ( is_array( $record->transport ) && ! empty( $record->transport['present'] ) ) {
			$score += self::SCORE_HAS_TRANSPORT;
		}

		// Gmail enrichment hits signal client communication → review needed.
		if ( ! empty( $record->gmail_hits ) ) {
			$score += self::SCORE_GMAIL_HIT * count( $record->gmail_hits );
		}

		// More participants = more prep work.
		$score += self::SCORE_PARTICIPANT * max( 0, count( $record->participants ) - 1 );

		$record->priority = $score;
	}
}
