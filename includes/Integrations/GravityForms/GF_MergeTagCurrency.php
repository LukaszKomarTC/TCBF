<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GF Merge Tag Currency Formatter
 *
 * Adds a custom :tcbf_eur modifier for GF merge tags that formats
 * raw numeric values with European currency formatting (e.g. "49,00 €").
 *
 * Usage in notification templates:
 *   {S:173:tcbf_eur}           → "49,00 €"
 *   {Total client:168:tcbf_eur} → "36,75 €"
 *
 * Merge tags WITHOUT the modifier keep their raw value, so conditional
 * logic like [gravityforms condition="greater_than" value="0"] still works.
 *
 * @since 0.7.0
 */
final class GF_MergeTagCurrency {

	/**
	 * Initialize the merge tag filter
	 */
	public static function init(): void {
		add_filter( 'gform_merge_tag_filter', [ __CLASS__, 'format_currency_modifier' ], 10, 6 );
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
}
