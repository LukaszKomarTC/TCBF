<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GF Merge Tag Formatters
 *
 * Adds custom merge tag features for notification templates:
 *
 * 1. :tcbf_eur modifier — formats raw numeric values as "49,00 €"
 *    Usage: {S:173:tcbf_eur}, {Total client:168:tcbf_eur}
 *
 * 2. {tcbf:duration} custom merge tag — computes booking duration in days
 *    from start_date_stamp (field 132) and end_date_stamp (field 134).
 *    Usage: {tcbf:duration} → "3 días" / "3 days"
 *
 * Merge tags WITHOUT the :tcbf_eur modifier keep their raw value, so
 * conditional logic like [gravityforms condition="greater_than" value="0"]
 * still works.
 *
 * @since 0.7.0
 */
final class GF_MergeTagCurrency {

	/**
	 * Initialize merge tag filters
	 */
	public static function init(): void {
		add_filter( 'gform_merge_tag_filter', [ __CLASS__, 'format_currency_modifier' ], 10, 6 );
		add_filter( 'gform_replace_merge_tags', [ __CLASS__, 'replace_custom_merge_tags' ], 10, 7 );
	}

	/**
	 * Format merge tag value when :tcbf_eur modifier is used
	 *
	 * @param string          $value     The current merge tag value
	 * @param string          $merge_tag The full merge tag (e.g. {S:173:tcbf_eur})
	 * @param string          $modifier  The modifier string (e.g. "tcbf_eur")
	 * @param \GF_Field|mixed $field     The GF field object
	 * @param string          $raw_value The raw/unprocessed value from the entry
	 * @param string          $format    Output format ('html' or 'text')
	 * @return string Formatted or original value
	 */
	public static function format_currency_modifier( $value, $merge_tag, $modifier, $field, $raw_value, $format = 'html' ): string {
		if ( $modifier !== 'tcbf_eur' ) {
			return $value;
		}

		$numeric = (float) $raw_value;

		// Zero or empty → show "0,00 €" (keeps table alignment consistent)
		return number_format( $numeric, 2, ',', '.' ) . ' €';
	}

	/**
	 * Replace custom {tcbf:*} merge tags in notification content
	 *
	 * Supported tags:
	 *   {tcbf:duration} — booking duration computed from date stamps
	 *
	 * @param string $text       The text with merge tags
	 * @param mixed  $form       GF form array
	 * @param mixed  $entry      GF entry array
	 * @param bool   $url_encode Whether to URL-encode
	 * @param bool   $esc_html   Whether to escape HTML
	 * @param bool   $nl2br      Whether to convert newlines to <br>
	 * @param string $format     Output format ('html' or 'text')
	 * @return string Text with custom merge tags replaced
	 */
	public static function replace_custom_merge_tags( $text, $form, $entry, $url_encode = false, $esc_html = false, $nl2br = false, $format = 'html' ): string {
		if ( ! is_string( $text ) || strpos( $text, '{tcbf:' ) === false ) {
			return $text;
		}

		if ( ! is_array( $entry ) ) {
			return $text;
		}

		// {tcbf:duration} — compute from start_date_stamp (132) and end_date_stamp (134)
		if ( strpos( $text, '{tcbf:duration}' ) !== false ) {
			$form_id  = (int) ( $form['id'] ?? $entry['form_id'] ?? 0 );
			$start_ts = 0;
			$end_ts   = 0;

			// Resolve field IDs via semantic keys
			$start_field = GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_START_DATE_STAMP );
			$end_field   = GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_END_DATE_STAMP );

			if ( $start_field ) {
				$start_ts = (int) ( $entry[ (string) $start_field ] ?? 0 );
			}
			if ( $end_field ) {
				$end_ts = (int) ( $entry[ (string) $end_field ] ?? 0 );
			}

			$duration_str = '';
			if ( $start_ts > 0 && $end_ts > 0 && $end_ts > $start_ts ) {
				$days = (int) ceil( ( $end_ts - $start_ts ) / DAY_IN_SECONDS );
				if ( $days > 0 ) {
					// Bilingual: Spanish primary, English fallback
					$label = $days === 1 ? 'día' : 'días';
					if ( function_exists( 'tc_sc_event_tr' ) ) {
						$label = tc_sc_event_tr(
							$days === 1
								? '[:es]día[:en]day[:]'
								: '[:es]días[:en]days[:]'
						);
					}
					$duration_str = $days . ' ' . $label;
				}
			}

			$text = str_replace( '{tcbf:duration}', $duration_str, $text );
		}

		return $text;
	}
}
