<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * Woo_BookingsCompat — guards against WooCommerce Bookings' unguarded cart reads
 *
 * WC Bookings' WC_Booking_Cart_Manager assumes every cart item that carries a
 * 'booking' array also has a '_booking_id' key and reads it without a guard
 * (cart_loaded_from_session, cart_item_removed, cart_item_restored,
 * order_item_meta). TCBF creates cart items where that key can be absent:
 *
 * - Transport child items (Woo_Transport): a simple product that carries TCBF
 *   scope/cost metadata under 'booking' but never has a WC booking record.
 * - Participation/rental items whose configure_cart_item_data() call failed
 *   (Plugin::gf_after_submission_add_to_cart catches and continues).
 * - Items persisted in customer sessions / persistent carts by older TCBF
 *   versions that did not create in-cart bookings at all.
 *
 * On PHP 8 each such item emits "Warning: Undefined array key '_booking_id'"
 * on every page load. Backfilling the key with NULL is behavior-identical to
 * the missing key everywhere it is read (isset/empty stay false, and WC
 * Bookings' get_wc_booking(null) result is unchanged) but silences the
 * warning.
 *
 * Both hooks run at priority 99 — after WC_Booking_Cart_Manager::add_cart_item_data
 * (10), which sets the real _booking_id for normal product-page bookings, and
 * after WC_Booking_Cart_Manager::get_cart_item_from_session (10), which copies
 * the 'booking' array out of the stored session values.
 */
final class Woo_BookingsCompat {

	public static function init() : void {

		// New cart items (covers transport items and failed configure calls).
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'ensure_booking_id_key' ], 99, 1 );

		// Items restored from WC session / persistent cart (covers carts that
		// already exist in the wild, saved before this guard was deployed).
		add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'ensure_booking_id_key' ], 99, 1 );
	}

	/**
	 * Make sure a cart item's 'booking' array always has a '_booking_id' key.
	 *
	 * NULL keeps the exact runtime semantics of a missing key: every consumer
	 * checks it with isset()/empty() or passes it to get_wc_booking(), and all
	 * of those treat NULL and "absent" identically — only the PHP 8 warning
	 * goes away. Do NOT use 0 or '' here: order_item_meta() gates on
	 * isset( $booking_id ) and would start processing transport items.
	 *
	 * @param array $cart_item Cart item data (add-to-cart) or cart item (session restore).
	 * @return array
	 */
	public static function ensure_booking_id_key( $cart_item ) {
		if ( is_array( $cart_item )
			&& isset( $cart_item['booking'] )
			&& is_array( $cart_item['booking'] )
			&& ! array_key_exists( '_booking_id', $cart_item['booking'] ) ) {
			$cart_item['booking']['_booking_id'] = null;
		}
		return $cart_item;
	}
}
