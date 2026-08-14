<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * URL Coupon Handler
 *
 * Catches coupon codes passed via URL query parameter (e.g. ?apply_coupon=PARTNER_CODE)
 * and applies them to the WooCommerce session/cart.
 *
 * This supports the partner portal workflow where external sites link to service pages
 * with the partner coupon in the URL. Once applied, the existing PartnerResolver
 * picks it up via "Way 1" (coupon already in session/cart).
 *
 * Flow:
 * 1. Client clicks button on partner portal → arrives at e.g. /rental/?apply_coupon=HOTEL_ABC
 * 2. This handler reads the param, stores it in a cookie + WC session
 * 3. On redirect (clean URL), WC session has the coupon → PartnerResolver Way 1 detects it
 *
 * @since 1.x.x
 */
final class UrlCouponHandler {

	/**
	 * URL query parameter name.
	 */
	const QUERY_PARAM = 'apply_coupon';

	/**
	 * Cookie name for coupon persistence across session init.
	 */
	const COOKIE_NAME = 'tcbf_partner_coupon';

	/**
	 * Cookie lifetime in seconds (24 hours).
	 */
	const COOKIE_TTL = 86400;

	/**
	 * Register hooks.
	 */
	public static function init() : void {
		// Run on template_redirect so WC is fully initialized and we can redirect cleanly.
		add_action( 'template_redirect', [ __CLASS__, 'handle_url_coupon' ], 5 );

		// Also try to apply from cookie on wp_loaded (after WC cart init).
		add_action( 'wp_loaded', [ __CLASS__, 'apply_from_cookie' ], 25 );

		// Clear cookie when user removes the coupon via WC cart UI (including WC AJAX).
		add_action( 'woocommerce_removed_coupon', [ __CLASS__, 'on_coupon_removed' ] );
	}

	/**
	 * When a coupon is removed via the WC cart, clear our cookie so it doesn't re-apply.
	 *
	 * @param string $code The coupon code that was removed.
	 */
	public static function on_coupon_removed( $code ) : void {
		$code = PartnerResolver::normalize_partner_code( (string) $code );
		$cookie_code = isset( $_COOKIE[ self::COOKIE_NAME ] )
			? PartnerResolver::normalize_partner_code( $_COOKIE[ self::COOKIE_NAME ] )
			: '';

		if ( $code !== '' && $code === $cookie_code ) {
			self::clear_cookie();
		}
	}

	/**
	 * Handle incoming ?apply_coupon= parameter.
	 *
	 * Stores the code in a cookie, then redirects to the clean URL
	 * (so bookmarks/back-button don't re-trigger application).
	 */
	public static function handle_url_coupon() : void {
		if ( is_admin() || wp_doing_ajax() || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
			return;
		}

		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		$raw_code = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );
		$code     = PartnerResolver::normalize_partner_code( $raw_code );

		if ( $code === '' ) {
			return;
		}

		// Validate coupon exists before setting cookie.
		if ( function_exists( 'wc_get_coupon_id_by_code' ) ) {
			$coupon_id = (int) wc_get_coupon_id_by_code( $code );
			if ( $coupon_id <= 0 ) {
				// Try raw form (some stores save uppercase).
				$coupon_id = (int) wc_get_coupon_id_by_code( $raw_code );
			}
			if ( $coupon_id <= 0 ) {
				\TC_BF\Support\Logger::log( 'url_coupon.invalid', [
					'raw' => $raw_code,
					'normalized' => $code,
				], 'warning' );
				return;
			}
		}

		// Set cookie so coupon persists even if WC session isn't ready yet.
		setcookie( self::COOKIE_NAME, $code, time() + self::COOKIE_TTL, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

		// Try to apply immediately if WC cart is available.
		self::apply_coupon_to_cart( $code );

		\TC_BF\Support\Logger::log( 'url_coupon.captured', [
			'code' => $code,
			'referer' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( $_SERVER['HTTP_REFERER'] ) : '',
		] );

		// Redirect to clean URL (strip the query param).
		$clean_url = remove_query_arg( self::QUERY_PARAM );
		wp_safe_redirect( $clean_url, 302 );
		exit;
	}

	/**
	 * On wp_loaded, check for a persisted cookie and apply to cart if not already applied.
	 */
	public static function apply_from_cookie() : void {
		if ( is_admin() || wp_doing_ajax() || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
			return;
		}

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return;
		}

		$code = PartnerResolver::normalize_partner_code( sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ) );
		if ( $code === '' ) {
			return;
		}

		self::apply_coupon_to_cart( $code );
	}

	/**
	 * Apply coupon code to WC cart/session if not already applied.
	 *
	 * @param string $code Normalized coupon code.
	 * @return bool Whether the coupon was newly applied.
	 */
	private static function apply_coupon_to_cart( string $code ) : bool {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		// Store in session even if cart doesn't exist yet.
		if ( WC()->session ) {
			$session_coupons = WC()->session->get( 'applied_coupons', [] );
			if ( ! is_array( $session_coupons ) ) {
				$session_coupons = [];
			}
			$normalized_session = array_map( [ PartnerResolver::class, 'normalize_partner_code' ], $session_coupons );
			if ( ! in_array( $code, $normalized_session, true ) ) {
				$session_coupons[] = $code;
				WC()->session->set( 'applied_coupons', $session_coupons );
			}
		}

		// Apply to cart if available.
		if ( WC()->cart ) {
			$applied = array_map( [ PartnerResolver::class, 'normalize_partner_code' ], (array) WC()->cart->get_applied_coupons() );
			if ( ! in_array( $code, $applied, true ) ) {
				$result = WC()->cart->add_discount( $code );
				\TC_BF\Support\Logger::log( 'url_coupon.applied', [
					'code'   => $code,
					'result' => $result ? 1 : 0,
				] );
				return (bool) $result;
			}
		}

		return false;
	}

	/**
	 * Clear the partner coupon cookie.
	 *
	 * Call this after checkout completion if you want single-use behavior.
	 */
	public static function clear_cookie() : void {
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			setcookie( self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			unset( $_COOKIE[ self::COOKIE_NAME ] );
		}
	}
}
