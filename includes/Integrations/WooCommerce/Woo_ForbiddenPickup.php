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
 *   Lines starting with # and unparseable lines are ignored.
 * - Settings::OPT_RENTAL_CATEGORY_IDS — CSV of product_cat term IDs the
 *   restriction applies to (bike rental categories only).
 *
 * Runs on woocommerce_add_to_cart_validation at priority 20, after
 * WC_Booking_Cart_Manager::validate_add_cart_item (10), so Bookings' own
 * date/availability validation has already passed. Programmatic GF event-flow
 * adds (participation/rental packs) carry booking._tc_scope and are exempt —
 * that flow has no error path and Pack_Grouping expects both legs to land.
 */
final class Woo_ForbiddenPickup {

	const MSG_BLOCKED = '[:en]Bike pickup is not available on this date. Please select an earlier or later pickup date. Returns are still possible.[:es]La recogida de la bicicleta no está disponible en esta fecha. Por favor, selecciona una fecha de recogida anterior o posterior. Las devoluciones siguen siendo posibles.[:]';

	/** @var array<int,array{start:string,end:string}>|null */
	private static $ranges_cache = null;

	public static function init() : void {
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate' ], 20, 6 );
	}

	/**
	 * Veto adding a rental to the cart when its start date is a forbidden pickup date.
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

		// Programmatic GF event-flow adds (participation + rental pack legs) are exempt.
		if ( is_array( $cart_item_data )
			&& ! empty( $cart_item_data['booking'][ \TC_BF\Plugin::BK_SCOPE ] ) ) {
			return $passed;
		}

		$ranges = self::get_ranges();
		if ( ! $ranges ) return $passed;

		if ( ! has_term( self::get_rental_category_ids(), 'product_cat', $product_id ) ) return $passed;

		$ymd = self::resolve_start_ymd( $cart_item_data );
		if ( $ymd === '' ) {
			// Fail-open: never lose a sale on a flow this guard doesn't recognise.
			\TC_BF\Support\Logger::log( 'forbidden_pickup.start_date_unresolved', [
				'product_id' => (int) $product_id,
			], 'warning' );
			return $passed;
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

		// Programmatic callers passing a prebuilt booking array: WC Bookings builds
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
			self::$ranges_cache = self::parse_ranges(
				(string) get_option( \TC_BF\Admin\Settings::OPT_FORBIDDEN_PICKUP_DATES, '' )
			);
		}
		return self::$ranges_cache;
	}

	/**
	 * Parse the textarea config into inclusive Y-m-d ranges.
	 *
	 * One Y-m-d token on a line = single forbidden day; two tokens = inclusive
	 * range (auto-swapped if reversed). Any separator between the two dates is
	 * accepted. Blank lines, #-comments and invalid dates are skipped.
	 *
	 * @param string $raw
	 * @return array<int,array{start:string,end:string}>
	 */
	public static function parse_ranges( string $raw ) : array {
		$ranges = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) continue;
			if ( ! preg_match_all( '/\d{4}-\d{2}-\d{2}/', $line, $m ) ) continue;

			$valid = array_values( array_filter( $m[0], function( $d ) {
				$dt = \DateTime::createFromFormat( 'Y-m-d', $d );
				return $dt && $dt->format( 'Y-m-d' ) === $d;
			} ) );

			if ( count( $valid ) === 1 ) {
				$ranges[] = [ 'start' => $valid[0], 'end' => $valid[0] ];
			} elseif ( count( $valid ) >= 2 ) {
				$ranges[] = [
					'start' => min( $valid[0], $valid[1] ),
					'end'   => max( $valid[0], $valid[1] ),
				];
			}
		}
		return $ranges;
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
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', $csv ) ) ) );
		return $ids ? $ids : [ 207, 208, 209, 219 ];
	}

	/** Reset the per-request config cache (for tests). */
	public static function clear_cache() : void {
		self::$ranges_cache = null;
	}
}
