<?php
/**
 * Standalone tests for Woo_ForbiddenPickup (no WordPress/WooCommerce needed).
 *
 * Run: php tests/test-forbidden-pickup.php
 * Exit code 0 = all pass, 1 = failures.
 *
 * Part 1 exercises the pure config parser and date matcher.
 * Part 2 stubs the WP/WC functions the validator touches and exercises
 * validate() end-to-end for the invocation shapes WooCommerce actually uses,
 * including the 3-argument call for simple products.
 */

// Namespaced dependencies referenced by the class under test.

namespace TC_BF {
	class Plugin { const BK_SCOPE = '_tc_scope'; }
}

namespace TC_BF\Support {
	class Logger { public static function log( $context, $data = [], $level = 'info' ) {} }
}

namespace TC_BF\Admin {
	class Settings {
		const OPT_FORBIDDEN_PICKUP_DATES = 'tcbf_forbidden_pickup_dates';
		const OPT_RENTAL_CATEGORY_IDS    = 'tcbf_rental_category_ids';
	}
}

namespace TC_BF\Integrations\WooCommerce {
	class Woo {
		public static function translate( $text ) { return (string) $text; }
	}
}

// WP/WC stubs + test runner (global namespace).

namespace {

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );

$GLOBALS['__test'] = [
	'options'  => [],   // option name => value
	'products' => [],   // product id => ['bookable' => bool]
	'terms'    => [],   // product id => product_cat term ids
	'notices'  => [],   // [message, type]
];

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__test']['options'] ) ? $GLOBALS['__test']['options'][ $name ] : $default;
}
function wc_get_product( $id ) {
	return isset( $GLOBALS['__test']['products'][ $id ] ) ? (object) [ 'id' => $id ] : false;
}
function is_wc_booking_product( $product ) {
	return ! empty( $GLOBALS['__test']['products'][ $product->id ]['bookable'] );
}
function has_term( $terms, $taxonomy, $object_id ) {
	$assigned = $GLOBALS['__test']['terms'][ $object_id ] ?? [];
	return (bool) array_intersect( (array) $terms, $assigned );
}
function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['__test']['notices'][] = [ $message, $type ];
}
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { /* no-op for tests */ }

require __DIR__ . '/../includes/Integrations/WooCommerce/Woo_ForbiddenPickup.php';

use TC_BF\Integrations\WooCommerce\Woo_ForbiddenPickup as FP;

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	if ( $cond ) { echo "PASS  $label\n"; } else { $fails++; echo "FAIL  $label\n"; }
}
function reset_env( array $opts = [] ) {
	$GLOBALS['__test'] = array_merge( [
		'options'  => [],
		'products' => [],
		'terms'    => [],
		'notices'  => [],
	], $opts );
	$_POST = [];
	FP::clear_cache();
}

// ------------------------- Part 1: parser matrix ---------------------------

$p = FP::parse_config( "2026-09-01" );
check( 'parser: valid single day', $p === [ 'ranges' => [ [ 'start' => '2026-09-01', 'end' => '2026-09-01' ] ], 'invalid' => [] ] );

$p = FP::parse_config( "2026-08-17 - 2026-08-19" );
check( 'parser: valid range', $p['ranges'] === [ [ 'start' => '2026-08-17', 'end' => '2026-08-19' ] ] && $p['invalid'] === [] );

$p = FP::parse_config( "2026-08-19 - 2026-08-17" );
check( 'parser: reversed range auto-swaps', $p['ranges'] === [ [ 'start' => '2026-08-17', 'end' => '2026-08-19' ] ] );

$p = FP::parse_config( "2026-08-17 – 2026-08-19" );
check( 'parser: en-dash separator', $p['ranges'] === [ [ 'start' => '2026-08-17', 'end' => '2026-08-19' ] ] );

$p = FP::parse_config( "2026-08-17 — 2026-08-19" );
check( 'parser: em-dash separator', $p['ranges'] === [ [ 'start' => '2026-08-17', 'end' => '2026-08-19' ] ] );

$p = FP::parse_config( "2026-08-17 to 2026-08-19" );
check( 'parser: "to" separator', $p['ranges'] === [ [ 'start' => '2026-08-17', 'end' => '2026-08-19' ] ] );

$p = FP::parse_config( "2026-02-31" );
check( 'parser: impossible date rejected as invalid', $p['ranges'] === [] && $p['invalid'] === [ '2026-02-31' ] );

$p = FP::parse_config( "2026-08-17 - 2026-02-31" );
check( 'parser: one-valid/one-invalid range rejects WHOLE line (never a single-day rule)', $p['ranges'] === [] && $p['invalid'] === [ '2026-08-17 - 2026-02-31' ] );

$p = FP::parse_config( "garbage line" );
check( 'parser: garbage rejected', $p['ranges'] === [] && $p['invalid'] === [ 'garbage line' ] );

$p = FP::parse_config( "2026-08-17 - 2026-08-19 - 2026-08-21" );
check( 'parser: three-date line rejected', $p['ranges'] === [] && count( $p['invalid'] ) === 1 );

$p = FP::parse_config( "2026-09-01 extra text" );
check( 'parser: trailing garbage rejects line', $p['ranges'] === [] && count( $p['invalid'] ) === 1 );

$p = FP::parse_config( "# a comment\n\n2026-08-17 - 2026-08-19\n" );
check( 'parser: blank + comment lines ignored, valid kept', $p['ranges'] === [ [ 'start' => '2026-08-17', 'end' => '2026-08-19' ] ] && $p['invalid'] === [] );

$p = FP::parse_config( "" );
check( 'parser: empty config', $p['ranges'] === [] && $p['invalid'] === [] );

// ------------------------- Part 1b: matcher --------------------------------

$ranges = FP::parse_ranges( "2026-08-17 - 2026-08-19" );
check( 'matcher: 16 Aug allowed', ! FP::is_forbidden( '2026-08-16', $ranges ) );
check( 'matcher: 17 Aug blocked (boundary)', FP::is_forbidden( '2026-08-17', $ranges ) );
check( 'matcher: 18 Aug blocked', FP::is_forbidden( '2026-08-18', $ranges ) );
check( 'matcher: 19 Aug blocked (boundary)', FP::is_forbidden( '2026-08-19', $ranges ) );
check( 'matcher: 20 Aug allowed', ! FP::is_forbidden( '2026-08-20', $ranges ) );

// ------------------------- Part 2: validate() harness ----------------------

const RENTAL = 101;   // bookable product in rental category 207
const TOUR   = 102;   // bookable product NOT in a rental category
const SIMPLE = 103;   // ordinary non-booking product

function env_with_config() {
	reset_env( [
		'options'  => [
			'tcbf_forbidden_pickup_dates' => "2026-08-17 - 2026-08-19",
			'tcbf_rental_category_ids'    => '207,208,209,219',
		],
		'products' => [
			RENTAL => [ 'bookable' => true ],
			TOUR   => [ 'bookable' => true ],
			SIMPLE => [ 'bookable' => false ],
		],
		'terms'    => [ RENTAL => [ 207 ], TOUR => [ 999 ] ],
	] );
}
function post_start_date( $y, $m, $d ) {
	$_POST['wc_bookings_field_start_date_year']  = (string) $y;
	$_POST['wc_bookings_field_start_date_month'] = (string) $m;
	$_POST['wc_bookings_field_start_date_day']   = (string) $d;
}

// Regression test requested in review: WC handlers call the filter with only
// ($passed, $product_id, $quantity) for simple products — must not crash, must pass.
env_with_config();
check( 'validate: 3-arg invocation with simple product passes', FP::validate( true, SIMPLE, 1 ) === true );
check( 'validate: 3-arg simple product adds no notice', $GLOBALS['__test']['notices'] === [] );

// Forbidden start date on targeted rental -> blocked with notice.
env_with_config();
post_start_date( 2026, 8, 18 );
check( 'validate: forbidden start date blocked', FP::validate( true, RENTAL, 1 ) === false );
check( 'validate: blocked notice is an error', ( $GLOBALS['__test']['notices'][0][1] ?? '' ) === 'error' );

// Allowed date -> passes.
env_with_config();
post_start_date( 2026, 8, 20 );
check( 'validate: allowed start date passes', FP::validate( true, RENTAL, 1 ) === true );

// Boundary dates.
env_with_config(); post_start_date( 2026, 8, 17 );
check( 'validate: boundary 17 Aug blocked', FP::validate( true, RENTAL, 1 ) === false );
env_with_config(); post_start_date( 2026, 8, 16 );
check( 'validate: 16 Aug passes', FP::validate( true, RENTAL, 1 ) === true );

// Non-rental bookable product unaffected even on forbidden date.
env_with_config();
post_start_date( 2026, 8, 18 );
check( 'validate: non-rental bookable passes on forbidden date', FP::validate( true, TOUR, 1 ) === true );

// Upstream failure short-circuits (no extra notice on top of Bookings' own).
env_with_config();
post_start_date( 2026, 8, 18 );
check( 'validate: upstream $passed=false untouched', FP::validate( false, RENTAL, 1 ) === false );
check( 'validate: upstream failure adds no extra notice', $GLOBALS['__test']['notices'] === [] );

// Defensive exemption: custom invocation with _tc_scope cart data passes.
env_with_config();
$cart_data = [ 'booking' => [ '_tc_scope' => 'rental', '_start_date' => strtotime( '2026-08-18 00:00:00 UTC' ) ] ];
check( 'validate: _tc_scope cart data exempt', FP::validate( true, RENTAL, 1, 0, [], $cart_data ) === true );

// Custom invocation WITHOUT scope but with booking._start_date: date resolved from cart data.
env_with_config();
$cart_data = [ 'booking' => [ '_start_date' => strtotime( '2026-08-18 00:00:00 UTC' ) ] ];
check( 'validate: prebuilt booking data on forbidden date blocked', FP::validate( true, RENTAL, 1, 0, [], $cart_data ) === false );

// FAIL-CLOSED: targeted rental, restrictions configured, no resolvable date.
env_with_config();
check( 'validate: unresolved start date on targeted rental FAILS CLOSED', FP::validate( true, RENTAL, 1 ) === false );
check( 'validate: unresolved failure adds error notice', ( $GLOBALS['__test']['notices'][0][1] ?? '' ) === 'error' );

// Malformed posted date (Feb 31) also fails closed.
env_with_config();
post_start_date( 2026, 2, 31 );
check( 'validate: impossible posted date fails closed', FP::validate( true, RENTAL, 1 ) === false );

// Empty config: everything passes, including unresolved dates.
env_with_config();
$GLOBALS['__test']['options']['tcbf_forbidden_pickup_dates'] = '';
FP::clear_cache();
check( 'validate: empty config passes without date resolution', FP::validate( true, RENTAL, 1 ) === true );
check( 'validate: empty config adds no notice', $GLOBALS['__test']['notices'] === [] );

// Empty category selection: restriction targets nothing.
env_with_config();
$GLOBALS['__test']['options']['tcbf_rental_category_ids'] = '';
post_start_date( 2026, 8, 18 );
check( 'validate: empty category selection disables restriction', FP::validate( true, RENTAL, 1 ) === true );

echo "\n" . ( $fails ? "$fails FAILURE(S)\n" : "All tests passed.\n" );
exit( $fails ? 1 : 0 );

}
