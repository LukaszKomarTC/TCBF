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

	// sprintf templates; translated first (qTranslate), then filled with
	// localized dates. Range: %1$s = range start, %2$s = range end,
	// %3$s = first allowed pickup day (end + 1). Single day: %1$s = the
	// forbidden day, %2$s = the following day.
	const MSG_BLOCKED_RANGE = '[:en]Bike pickup is unavailable from %1$s to %2$s. If your plans allow, start your rental before %1$s or from %3$s onward. Rentals that start earlier may continue through these dates, and returns are still possible.[:es]La recogida de bicicletas no está disponible del %1$s al %2$s. Si tus planes lo permiten, inicia el alquiler antes del %1$s o a partir del %3$s. Los alquileres iniciados antes pueden continuar durante estas fechas y las devoluciones siguen siendo posibles.[:]';

	const MSG_BLOCKED_SINGLE = '[:en]Bike pickup is unavailable on %1$s. If your plans allow, start your rental before this date or from %2$s onward. Rentals that start earlier may continue through this date, and returns are still possible.[:es]La recogida de bicicletas no está disponible el %1$s. Si tus planes lo permiten, inicia el alquiler antes de esta fecha o a partir del %2$s. Los alquileres iniciados antes pueden continuar durante esta fecha y las devoluciones siguen siendo posibles.[:]';

	const MSG_UNRESOLVED = '[:en]We couldn\'t validate the selected pickup date. Please select the rental dates again.[:es]No hemos podido validar la fecha de recogida seleccionada. Por favor, selecciona de nuevo las fechas de alquiler.[:]';

	// Per-language PHP date format for the notice dates, resolved through the
	// same qTranslate selection as the message so they cannot diverge:
	// EN "17 August 2026", ES "17 de agosto de 2026" (\d\e = literal "de").
	const DATE_FORMAT = '[:en]j F Y[:es]j \d\e F \d\e Y[:]';

	// Shown above the visually gated details form while the selected start
	// date is forbidden (frontend advisory only).
	const MSG_GATE = '[:en]Please change the pickup date to continue with your booking details.[:es]Por favor, cambia la fecha de recogida para continuar con los datos de tu reserva.[:]';

	/** @var array<int,array{start:string,end:string}>|null */
	private static $ranges_cache = null;

	/** @var int[]|null configured categories expanded with all descendants */
	private static $effective_cats_cache = null;

	public static function init() : void {
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate' ], 20, 6 );

		// Early frontend warning on eligible standalone rental product pages
		// (advisory only — this filter above remains the enforcement boundary).
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_frontend' ], 20 );
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

		// Defensive exemption for custom invocations passing prebuilt booking
		// data (the standard WC handlers never pass $cart_item_data — see class doc).
		if ( is_array( $cart_item_data )
			&& ! empty( $cart_item_data['booking'][ \TC_BF\Plugin::BK_SCOPE ] ) ) {
			return $passed;
		}

		$ranges = self::get_ranges();
		if ( ! $ranges ) return $passed;

		if ( ! self::applies_to_product( (int) $product_id ) ) return $passed;

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

		$hit = self::find_forbidden_range( $ymd, $ranges );
		if ( $hit !== null ) {
			wc_add_notice( self::build_blocked_message( $hit ), 'error' );
			\TC_BF\Support\Logger::log( 'forbidden_pickup.blocked', [
				'product_id'  => (int) $product_id,
				'start_date'  => $ymd,
				'range_start' => $hit['start'],
				'range_end'   => $hit['end'],
			] );
			return false;
		}

		return $passed;
	}

	/**
	 * Whether the pickup restriction targets this product: a WooCommerce
	 * Bookings product in the configured rental categories (parents include
	 * all descendants). Shared by server validation and the frontend enqueue
	 * so targeting semantics cannot drift.
	 *
	 * @param int $product_id
	 * @return bool
	 */
	public static function applies_to_product( int $product_id ) : bool {
		if ( ! function_exists( 'is_wc_booking_product' ) ) return false;
		$product = wc_get_product( $product_id );
		if ( ! $product || ! is_wc_booking_product( $product ) ) return false;

		$cat_ids = self::get_effective_category_ids();
		return $cat_ids && has_term( $cat_ids, 'product_cat', $product_id );
	}

	/**
	 * Config payload for the frontend early warning: the configured ranges
	 * in config order (JS first-hit matches find_forbidden_range()), each
	 * with its customer message pre-built by the SAME server-side builder in
	 * the current page language — wording, Spanish date phrasing, single-day
	 * vs range grammar and the end+1 calculation cannot drift from PHP.
	 *
	 * @return array<int,array{start:string,end:string,message:string}>
	 */
	public static function get_frontend_payload() : array {
		$payload = [];
		foreach ( self::get_ranges() as $r ) {
			$payload[] = [
				'start'   => $r['start'],
				'end'     => $r['end'],
				'message' => self::build_blocked_message( $r ),
			];
		}
		return $payload;
	}

	/**
	 * Enqueue the early-warning assets only on an eligible standalone rental
	 * product page: single product, targeted bookable product, non-empty
	 * ranges. Everything else (simple products, non-targeted bookables,
	 * event/transport flows, empty config) loads nothing.
	 */
	public static function maybe_enqueue_frontend() : void {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) return;

		$product_id = (int) get_queried_object_id();
		if ( ! $product_id ) return;

		$ranges = self::get_ranges();
		if ( ! $ranges ) return;
		if ( ! self::applies_to_product( $product_id ) ) return;

		wp_enqueue_style(
			'tcbf-forbidden-pickup',
			TC_BF_URL . 'assets/css/tcbf-forbidden-pickup.css',
			[],
			TC_BF_VERSION
		);
		wp_enqueue_script(
			'tcbf-forbidden-pickup',
			TC_BF_URL . 'assets/js/tcbf-forbidden-pickup.js',
			[ 'jquery' ],
			TC_BF_VERSION,
			true
		);

		$config = [
			'ranges'   => self::get_frontend_payload(),
			'formId'   => (int) \TC_BF\Admin\Settings::get_booking_form_id(),
			'gateText' => Woo::translate( self::MSG_GATE ),
		];
		wp_add_inline_script(
			'tcbf-forbidden-pickup',
			'window.tcbfForbiddenPickup = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Customer-facing notice for a blocked pickup, built from the specific
	 * range that matched the attempted start date: states the restriction
	 * boundaries and the first allowed pickup day (range end + 1), in the
	 * current site language with localized human-readable dates.
	 *
	 * @param array{start:string,end:string} $range
	 * @return string
	 */
	public static function build_blocked_message( array $range ) : string {
		$date_format = Woo::translate( self::DATE_FORMAT );
		if ( strpos( $date_format, '[:' ) !== false ) {
			// qTranslate absent: fall back to a plain format rather than
			// feeding raw language tags into date_i18n.
			$date_format = 'j F Y';
		}
		// Noon UTC keeps the calendar date stable under date_i18n for any
		// realistic site timezone offset.
		$fmt = function( string $ymd ) use ( $date_format ) : string {
			return date_i18n( $date_format, (int) strtotime( $ymd . ' 12:00:00 UTC' ) );
		};
		$next = ( new \DateTime( $range['end'] . ' 12:00:00', new \DateTimeZone( 'UTC' ) ) )
			->modify( '+1 day' )->format( 'Y-m-d' );

		if ( $range['start'] === $range['end'] ) {
			return sprintf( Woo::translate( self::MSG_BLOCKED_SINGLE ), $fmt( $range['start'] ), $fmt( $next ) );
		}
		return sprintf( Woo::translate( self::MSG_BLOCKED_RANGE ), $fmt( $range['start'] ), $fmt( $range['end'] ), $fmt( $next ) );
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
	 * First configured range containing the date, or null.
	 *
	 * @param string $ymd Y-m-d calendar date.
	 * @param array<int,array{start:string,end:string}> $ranges
	 * @return array{start:string,end:string}|null
	 */
	public static function find_forbidden_range( string $ymd, array $ranges ) : ?array {
		foreach ( $ranges as $r ) {
			if ( $ymd >= $r['start'] && $ymd <= $r['end'] ) return $r;
		}
		return null;
	}

	/**
	 * @param string $ymd Y-m-d calendar date.
	 * @param array<int,array{start:string,end:string}> $ranges
	 * @return bool
	 */
	public static function is_forbidden( string $ymd, array $ranges ) : bool {
		return self::find_forbidden_range( $ymd, $ranges ) !== null;
	}

	/** @return int[] product_cat term IDs as configured by the admin. */
	private static function get_rental_category_ids() : array {
		$csv = (string) get_option( \TC_BF\Admin\Settings::OPT_RENTAL_CATEGORY_IDS, '207,208,209,219' );
		return array_values( array_filter( array_map( 'absint', explode( ',', $csv ) ) ) );
	}

	/**
	 * Configured category IDs expanded with all descendant terms, so a parent
	 * rental category covers its children/grandchildren at any depth. The
	 * stored option keeps only what the admin selected; expansion happens
	 * here at match time. An empty selection stays empty (restriction off).
	 *
	 * @return int[]
	 */
	public static function get_effective_category_ids() : array {
		if ( self::$effective_cats_cache === null ) {
			$ids = self::get_rental_category_ids();
			$all = $ids;
			if ( $ids && function_exists( 'get_term_children' ) ) {
				foreach ( $ids as $id ) {
					$children = get_term_children( $id, 'product_cat' );
					if ( is_array( $children ) && $children ) {
						$all = array_merge( $all, array_map( 'absint', $children ) );
					}
				}
			}
			self::$effective_cats_cache = array_values( array_unique( array_filter( $all ) ) );
		}
		return self::$effective_cats_cache;
	}

	/** Reset the per-request config caches (for tests). */
	public static function clear_cache() : void {
		self::$ranges_cache         = null;
		self::$effective_cats_cache = null;
	}
}
