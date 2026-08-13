<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * Woo_ForbiddenPickup — block rental START dates (pickup) on configured dates
 *
 * Operational rule, separate from availability: on certain dates the shop
 * cannot hand out bicycles, but rentals that started earlier may continue
 * through those dates and may end (return) on them. Only the booking start
 * date is checked; availability rules, duration, end date, pricing and
 * collision detection are untouched.
 *
 * Config lives on the settings page (General tab):
 * - Settings::OPT_FORBIDDEN_PICKUP_DATES — textarea, one entry per line:
 *     2026-09-01
 *     2026-08-17 - 2026-08-19
 *   Lines starting with # are comments. The format is strict: a line must be
 *   exactly one date or exactly two dates (reversed ranges are auto-swapped);
 *   anything else invalidates the line and is rejected at save time by the
 *   settings sanitizer (see Settings::register_settings()).
 * - Settings::OPT_RENTAL_CATEGORY_IDS — CSV of product_cat term IDs the
 *   restriction applies to (bike rental categories only).
 *
 * Hook mechanics — read before extending:
 * 'woocommerce_add_to_cart_validation' is applied by WooCommerce's request
 * handlers (WC_Form_Handler::add_to_cart_action, WC_AJAX::add_to_cart)
 * BEFORE WC_Cart::add_to_cart() is called; WC_Cart::add_to_cart() itself
 * never runs this filter, and the handlers pass at most
 * ($passed, $product_id, $quantity, $variation_id, $variations) — never
 * $cart_item_data. Therefore direct programmatic adds — the GF event flow
 * (Plugin::gf_after_submission_add_to_cart) and Woo_Transport — bypass this
 * validator entirely; that is what keeps those flows unaffected, not the
 * _tc_scope check below. The _tc_scope check is retained purely as defensive
 * protection for any future/custom code that invokes the filter manually
 * with prebuilt cart item data.
 *
 * We register at priority 20, after WC Bookings'
 * WC_Booking_Cart_Manager::validate_add_cart_item (10), so Bookings' own
 * date/availability validation has already passed. A rejection here happens
 * before WC_Cart::add_to_cart() runs, so WC Bookings' in-cart booking
 * (created on woocommerce_add_cart_item_data) is never created — a blocked
 * attempt leaves no orphan booking behind.
 *
 * For a targeted rental with restrictions configured, an unresolvable start
 * date fails CLOSED: at that point Woo validation passed, the product is a
 * rental in a restricted category and no exemption applies, so accepting a
 * booking whose pickup date we cannot verify would defeat the rule exactly
 * when it matters. All earlier guards still pass untouched.
 */
final class Woo_ForbiddenPickup {

	const MSG_BLOCKED = '[:en]Bike pickup is not available on this date. Please select an earlier or later pickup date. Returns are still possible.[:es]La recogida de la bicicleta no está disponible en esta fecha. Por favor, selecciona una fecha de recogida anterior o posterior. Las devoluciones siguen siendo posibles.[:]';

	const MSG_UNRESOLVED = '[:en]We couldn\'t validate the selected pickup date. Please select the rental dates again.[:es]No hemos podido validar la fecha de recogida seleccionada. Por favor, selecciona de nuevo las fechas de alquiler.[:]';

	/** @var array<int,array{start:string,end:string}>|null */
	private static $ranges_cache = null;

	public static function init() : void {
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate' ], 20, 6 );
	}

	/**
	 * Veto adding a rental to the cart when its start date is a forbidden pickup date.
	 *
	 * Args 4-6 have defaults: WC's handlers invoke this filter with as few as
	 * ($passed, $product_id, $quantity) for simple and booking products.
	 *
	 * @param bool  $passed
	 * @param int   $product_id
	 * @param int   $quantity
	 * @param int   $variation_id
	 * @param array $variations
	 * @param array $cart_item_data
	 * @return bool
	 */
	public static function validate( $passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
		if ( ! $passed ) return $passed;

		if ( ! function_exists( 'is_wc_booking_product' ) ) return $passed;
		$product = wc_get_product( $product_id );
		if ( ! $product || ! is_wc_booking_product( $product ) ) return $passed;

		// Defensive exemption for custom invocations passing prebuilt booking
		// data (the standard WC handlers never pass $cart_item_data — see class doc).
		if ( is_array( $cart_item_data )
			&& ! empty( $cart_item_data['booking'][ \TC_BF\Plugin::BK_SCOPE ] ) ) {
			return $passed;
		}

		$ranges = self::get_ranges();
		if ( ! $ranges ) return $passed;

		if ( ! has_term( self::get_rental_category_ids(), 'product_cat', $product_id ) ) return $passed;

		$ymd = self::resolve_start_ymd( $cart_item_data );
		if ( $ymd === '' ) {
			// Fail closed: targeted rental, restrictions configured, no exemption —
			// a pickup date we cannot verify must not be accepted.
			wc_add_notice( Woo::translate( self::MSG_UNRESOLVED ), 'error' );
			\TC_BF\Support\Logger::log( 'forbidden_pickup.start_date_unresolved', [
				'product_id' => (int) $product_id,
			], 'warning' );
			return false;
		}

		if ( self::is_forbidden( $ymd, $ranges ) ) {
			wc_add_notice( Woo::translate( self::MSG_BLOCKED ), 'error' );
			\TC_BF\Support\Logger::log( 'forbidden_pickup.blocked', [
				'product_id' => (int) $product_id,
				'start_date' => $ymd,
			] );
			return false;
		}

		return $passed;
	}

	/**
	 * Resolve the booking start date as a Y-m-d calendar string, or '' if unknown.
	 *
	 * @param array $cart_item_data
	 * @return string
	 */
	private static function resolve_start_ymd( $cart_item_data ) : string {
		// Normal product-page flow: posted Bookings fields, used verbatim (no TZ math).
		if ( isset( $_POST['wc_bookings_field_start_date_year'],
					$_POST['wc_bookings_field_start_date_month'],
					$_POST['wc_bookings_field_start_date_day'] ) ) {
			$y = absint( wp_unslash( $_POST['wc_bookings_field_start_date_year'] ) );
			$m = absint( wp_unslash( $_POST['wc_bookings_field_start_date_month'] ) );
			$d = absint( wp_unslash( $_POST['wc_bookings_field_start_date_day'] ) );
			if ( $y && $m && $d && checkdate( $m, $d, $y ) ) {
				return sprintf( '%04d-%02d-%02d', $y, $m, $d );
			}
			return '';
		}

		// Custom invocations passing a prebuilt booking array: WC Bookings builds
		// _start_date with mktime() under WP's forced-UTC runtime, so gmdate()
		// round-trips the calendar date exactly.
		if ( is_array( $cart_item_data ) && ! empty( $cart_item_data['booking']['_start_date'] ) ) {
			return gmdate( 'Y-m-d', (int) $cart_item_data['booking']['_start_date'] );
		}

		return '';
	}

	/** @return array<int,array{start:string,end:string}> */
	public static function get_ranges() : array {
		if ( self::$ranges_cache === null ) {
			$parsed = self::parse_config(
				(string) get_option( \TC_BF\Admin\Settings::OPT_FORBIDDEN_PICKUP_DATES, '' )
			);
			self::$ranges_cache = $parsed['ranges'];
		}
		return self::$ranges_cache;
	}

	/**
	 * Parse the textarea config with strict per-line validation.
	 *
	 * A non-empty, non-comment line is valid only if it is exactly one date
	 * (single forbidden day) or exactly two dates separated by -, –, — or
	 * "to" (inclusive range; reversed ranges are auto-swapped), with every
	 * date a real calendar date. Any other line — partially invalid ranges,
	 * extra dates, trailing text — is rejected whole and reported, never
	 * reinterpreted as a different rule.
	 *
	 * @param string $raw
	 * @return array{ranges: array<int,array{start:string,end:string}>, invalid: string[]}
	 */
	public static function parse_config( string $raw ) : array {
		$ranges  = [];
		$invalid = [];

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) continue;

			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})$/', $line, $m ) ) {
				if ( self::is_real_date( $m[1] ) ) {
					$ranges[] = [ 'start' => $m[1], 'end' => $m[1] ];
				} else {
					$invalid[] = $line;
				}
				continue;
			}

			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})\s*(?:-|–|—|to)\s*(\d{4}-\d{2}-\d{2})$/u', $line, $m ) ) {
				if ( self::is_real_date( $m[1] ) && self::is_real_date( $m[2] ) ) {
					$ranges[] = [
						'start' => min( $m[1], $m[2] ),
						'end'   => max( $m[1], $m[2] ),
					];
				} else {
					$invalid[] = $line;
				}
				continue;
			}

			$invalid[] = $line;
		}

		return [ 'ranges' => $ranges, 'invalid' => $invalid ];
	}

	/** @return array<int,array{start:string,end:string}> */
	public static function parse_ranges( string $raw ) : array {
		return self::parse_config( $raw )['ranges'];
	}

	private static function is_real_date( string $ymd ) : bool {
		$dt = \DateTime::createFromFormat( 'Y-m-d', $ymd );
		return $dt && $dt->format( 'Y-m-d' ) === $ymd;
	}

	/**
	 * @param string $ymd Y-m-d calendar date.
	 * @param array<int,array{start:string,end:string}> $ranges
	 * @return bool
	 */
	public static function is_forbidden( string $ymd, array $ranges ) : bool {
		foreach ( $ranges as $r ) {
			if ( $ymd >= $r['start'] && $ymd <= $r['end'] ) return true;
		}
		return false;
	}

	/** @return int[] product_cat term IDs the restriction applies to. */
	private static function get_rental_category_ids() : array {
		$csv = (string) get_option( \TC_BF\Admin\Settings::OPT_RENTAL_CATEGORY_IDS, '207,208,209,219' );
		return array_values( array_filter( array_map( 'absint', explode( ',', $csv ) ) ) );
	}

	/** Reset the per-request config cache (for tests). */
	public static function clear_cache() : void {
		self::$ranges_cache = null;
	}
}
