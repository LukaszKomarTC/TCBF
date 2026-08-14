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
		// Mimics qTranslate language selection: with $GLOBALS['__test']['lang']
		// set ('en'/'es'), returns that segment; otherwise the raw text.
		public static function translate( $text ) {
			$text = (string) $text;
			$lang = $GLOBALS['__test']['lang'] ?? '';
			if ( $lang && preg_match( '/\[:' . $lang . '\](.*?)\[:/s', $text, $m ) ) {
				return $m[1];
			}
			return $text;
		}
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
function get_term_children( $term_id, $taxonomy ) {
	return $GLOBALS['__test']['children'][ $term_id ] ?? [];
}
function date_i18n( $format, $ts ) { return gmdate( $format, (int) $ts ); }

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
		'children' => [],   // parent term id => descendant term ids (all depths)
		'lang'     => '',   // '', 'en' or 'es' — see Woo::translate stub
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

// ------------- Part 2b: descendant categories & specific notices -----------

function last_notice() : string {
	$n = $GLOBALS['__test']['notices'];
	return $n ? (string) $n[ count( $n ) - 1 ][0] : '';
}

// Parent category selection covers children and grandchildren; siblings don't.
const CHILD_PROD  = 201; // in child category 211 of configured parent 200
const GRAND_PROD  = 202; // in grandchild category 221 of configured parent 200
const SIBLING_PROD = 203; // in unrelated category 300

function env_with_parent_cat() {
	reset_env( [
		'options'  => [
			'tcbf_forbidden_pickup_dates' => "2026-08-17 - 2026-08-19",
			'tcbf_rental_category_ids'    => '200',
		],
		'products' => [
			CHILD_PROD   => [ 'bookable' => true ],
			GRAND_PROD   => [ 'bookable' => true ],
			SIBLING_PROD => [ 'bookable' => true ],
		],
		'terms'    => [ CHILD_PROD => [ 211 ], GRAND_PROD => [ 221 ], SIBLING_PROD => [ 300 ] ],
		// get_term_children() returns ALL descendants at any depth.
		'children' => [ 200 => [ 211, 221 ] ],
	] );
}

env_with_parent_cat();
post_start_date( 2026, 8, 18 );
check( 'descendants: product in CHILD of configured parent blocked', FP::validate( true, CHILD_PROD, 1 ) === false );

env_with_parent_cat();
post_start_date( 2026, 8, 18 );
check( 'descendants: product in GRANDCHILD of configured parent blocked', FP::validate( true, GRAND_PROD, 1 ) === false );

env_with_parent_cat();
post_start_date( 2026, 8, 18 );
check( 'descendants: product in unrelated sibling category unaffected', FP::validate( true, SIBLING_PROD, 1 ) === true );

env_with_parent_cat();
$GLOBALS['__test']['options']['tcbf_rental_category_ids'] = '';
FP::clear_cache();
post_start_date( 2026, 8, 18 );
check( 'descendants: empty selection still disables restriction', FP::validate( true, CHILD_PROD, 1 ) === true );

// Specific blocked notice: derived from the range that matched, with the
// next allowed day = range end + 1.
function env_multi_range( string $lang = 'en' ) {
	env_with_config();
	$GLOBALS['__test']['options']['tcbf_forbidden_pickup_dates'] = "2026-07-01\n2026-08-17 - 2026-08-19";
	$GLOBALS['__test']['lang'] = $lang;
	FP::clear_cache();
}

env_multi_range( 'en' );
post_start_date( 2026, 8, 18 );
FP::validate( true, RENTAL, 1 );
$msg = last_notice();
check( 'notice: range message names matched boundaries', strpos( $msg, '17 August 2026' ) !== false && strpos( $msg, '19 August 2026' ) !== false );
check( 'notice: range message names next allowed day (end + 1)', strpos( $msg, '20 August 2026' ) !== false );
check( 'notice: only the HIT range is reported, not other configured ranges', strpos( $msg, 'July' ) === false );
check( 'notice: no unfilled placeholders or qTranslate tags leak', strpos( $msg, '%1$s' ) === false && strpos( $msg, '[:' ) === false );

env_multi_range( 'es' );
post_start_date( 2026, 8, 18 );
FP::validate( true, RENTAL, 1 );
$msg = last_notice();
check( 'notice: ES output selected and filled', strpos( $msg, 'La recogida de bicicletas' ) !== false && strpos( $msg, '17 August 2026' ) !== false && strpos( $msg, '[:' ) === false );

env_with_config();
$GLOBALS['__test']['options']['tcbf_forbidden_pickup_dates'] = '2026-09-01';
$GLOBALS['__test']['lang'] = 'en';
FP::clear_cache();
post_start_date( 2026, 9, 1 );
FP::validate( true, RENTAL, 1 );
$msg = last_notice();
check( 'notice: single-day message uses singular wording', strpos( $msg, 'unavailable on 1 September 2026' ) !== false );
check( 'notice: single-day next alternative is the following day', strpos( $msg, '2 September 2026' ) !== false );

// Month rollover: restriction ending on the last day of a month.
env_with_config();
$GLOBALS['__test']['options']['tcbf_forbidden_pickup_dates'] = '2026-08-29 - 2026-08-31';
$GLOBALS['__test']['lang'] = 'en';
FP::clear_cache();
post_start_date( 2026, 8, 31 );
FP::validate( true, RENTAL, 1 );
check( 'notice: next-day boundary rolls over the month', strpos( last_notice(), '1 September 2026' ) !== false );

// ------------------- Part 3: settings tab/group isolation -------------------
// wp-admin/options.php nulls out EVERY option registered in the submitted
// group that is absent from $_POST. The pickup fields therefore must live in
// their own settings group (tc_bf_pickup_settings), and each tab's form must
// post exactly the group containing the options it renders. These static
// checks guard that boundary against regressions.

$settings_src = file_get_contents( __DIR__ . '/../includes/Admin/class-tc-bf-admin-settings.php' );
check( 'isolation: settings source readable', is_string( $settings_src ) && $settings_src !== '' );

preg_match_all( "/register_setting\\(\\s*'([^']+)'\\s*,\\s*self::(\\w+)/", $settings_src, $m, PREG_SET_ORDER );
$groups = [];
foreach ( $m as $reg ) { $groups[ $reg[2] ][] = $reg[1]; }

check( 'isolation: forbidden dates registered ONLY in tc_bf_pickup_settings',
	( $groups['OPT_FORBIDDEN_PICKUP_DATES'] ?? [] ) === [ 'tc_bf_pickup_settings' ] );
check( 'isolation: rental categories registered ONLY in tc_bf_pickup_settings',
	( $groups['OPT_RENTAL_CATEGORY_IDS'] ?? [] ) === [ 'tc_bf_pickup_settings' ] );
check( 'isolation: general options stay in tc_bf_settings (sample: form id)',
	( $groups['OPT_FORM_ID'] ?? [] ) === [ 'tc_bf_settings' ] );
check( 'isolation: general options stay in tc_bf_settings (sample: debug)',
	( $groups['OPT_DEBUG'] ?? [] ) === [ 'tc_bf_settings' ] );
check( 'isolation: no general option registered in the pickup group',
	! array_filter( $groups, function( $g, $opt ) {
		return in_array( 'tc_bf_pickup_settings', $g, true )
			&& ! in_array( $opt, [ 'OPT_FORBIDDEN_PICKUP_DATES', 'OPT_RENTAL_CATEGORY_IDS' ], true );
	}, ARRAY_FILTER_USE_BOTH ) );

function extract_method( string $src, string $name ) : string {
	$pos = strpos( $src, "function {$name}(" );
	if ( $pos === false ) return '';
	$next = preg_match( '/function \w+\(/', $src, $mm, PREG_OFFSET_CAPTURE, $pos + 10 )
		? $mm[0][1] : strlen( $src );
	return substr( $src, $pos, $next - $pos );
}

$pickup_tab  = extract_method( $settings_src, 'render_pickup_tab' );
$general_tab = extract_method( $settings_src, 'render_general_tab' );

check( 'isolation: pickup tab posts the pickup group',
	strpos( $pickup_tab, "settings_fields('tc_bf_pickup_settings')" ) !== false );
check( 'isolation: pickup tab does NOT post the general group',
	strpos( $pickup_tab, "settings_fields('tc_bf_settings')" ) === false );
check( 'isolation: general tab still posts the general group',
	strpos( $general_tab, "settings_fields('tc_bf_settings')" ) !== false );
check( 'isolation: pickup fields no longer rendered in the general tab',
	strpos( $general_tab, 'OPT_FORBIDDEN_PICKUP_DATES' ) === false
	&& strpos( $general_tab, 'OPT_RENTAL_CATEGORY_IDS' ) === false );

echo "\n" . ( $fails ? "$fails FAILURE(S)\n" : "All tests passed.\n" );
exit( $fails ? 1 : 0 );

}
