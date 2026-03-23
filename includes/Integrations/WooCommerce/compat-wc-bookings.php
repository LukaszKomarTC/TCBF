<?php
/**
 * Compatibility shims for WooCommerce Bookings functions.
 *
 * Some WC Bookings functions have been removed or renamed across versions.
 * This file defines safe stubs for any that are missing, preventing fatal
 * errors in WC Bookings templates that still reference them.
 *
 * This file is intentionally NOT namespaced so functions are defined globally.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wc_should_convert_timezone' ) ) {
	/**
	 * Stub for wc_should_convert_timezone() removed in newer WC Bookings versions.
	 *
	 * @param mixed $booking WC_Booking object.
	 * @return bool Always false (no timezone conversion needed).
	 */
	function wc_should_convert_timezone( $booking ) {
		return false;
	}
}
