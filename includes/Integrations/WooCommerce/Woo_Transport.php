<?php
namespace TC_BF\Integrations\WooCommerce;

use TC_BF\Domain\TransportZones;
use TC_BF\Domain\TransportPricing;
use TC_BF\Support\Logger;
use TC_BF\Support\Money;

if ( ! defined('ABSPATH') ) exit;

/**
 * Woo_Transport — Cart-level transport addon for bike rentals
 *
 * Architecture:
 * - Transport is a per-pack toggle on rental child items in the cart
 * - ONE delivery address per order (stored in WC session)
 * - First toggle ON → opens address picker modal; subsequent toggles inherit the same address
 * - Each toggled-on pack gets a transport child item (scope = 'transport')
 * - Mixed orders allowed: some bikes transported, others not
 * - Price is per-bike based on zone (delivery type)
 *
 * Data flow:
 * - Address stored in WC()->session as 'tcbf_transport_address' (order-level)
 * - Transport child items carry tc_group_id + tc_group_role=child + tcbf_scope=transport
 * - AJAX endpoints: tcbf_transport_toggle, tcbf_transport_set_address, tcbf_transport_quote
 */
final class Woo_Transport {

	/** Session key for the shared transport address */
	const SESSION_ADDRESS = 'tcbf_transport_address';

	/** Transport scope identifier */
	const SCOPE_TRANSPORT = 'transport';

	/**
	 * Initialize transport hooks
	 */
	public static function init() : void {

		// AJAX: toggle transport on/off for a pack
		add_action( 'wp_ajax_tcbf_transport_toggle', [ __CLASS__, 'ajax_toggle_transport' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_toggle', [ __CLASS__, 'ajax_toggle_transport' ] );

		// AJAX: set/update the shared transport address
		add_action( 'wp_ajax_tcbf_transport_set_address', [ __CLASS__, 'ajax_set_address' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_set_address', [ __CLASS__, 'ajax_set_address' ] );

		// AJAX: get a transport price quote
		add_action( 'wp_ajax_tcbf_transport_quote', [ __CLASS__, 'ajax_get_quote' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_quote', [ __CLASS__, 'ajax_get_quote' ] );

		// Cart display: show transport info on transport child items
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_transport_cart_item_data' ], 25, 2 );

		// Cart pricing: set transport item price from session quote
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'set_transport_prices' ], 25, 1 );

		// Persist transport meta to order items
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_transport_order_meta' ], 15, 4 );

		// Persist transport address to order meta
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'persist_transport_order_address' ], 20, 2 );

		// Enqueue frontend assets on cart/checkout pages
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );

		// Render transport toggle UI after rental cart item names
		add_action( 'woocommerce_after_cart_item_name', [ __CLASS__, 'render_transport_toggle' ], 20, 2 );

		// Clean up transport items when their parent rental is removed
		add_action( 'woocommerce_remove_cart_item', [ __CLASS__, 'cleanup_transport_on_removal' ], 3, 2 );

		// Clear transport address from session when cart is emptied
		add_action( 'woocommerce_cart_emptied', [ __CLASS__, 'clear_transport_session' ], 5 );
	}

	/* ================================================================
	 * AJAX: Toggle transport on/off for a pack
	 * ================================================================ */

	/**
	 * Toggle transport for a specific pack (group_id)
	 *
	 * POST params:
	 * - group_id: int (GF entry ID / pack group)
	 * - enabled: '1' or '0'
	 * - nonce: security nonce
	 *
	 * When enabling:
	 * - If no address set yet, returns needs_address=true (frontend shows modal)
	 * - If address exists, adds transport child item and returns updated cart fragment
	 *
	 * When disabling:
	 * - Removes transport child item from the pack
	 * - If no more transport items remain, optionally clears the address
	 */
	public static function ajax_toggle_transport() : void {

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		$group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		$enabled  = isset( $_POST['enabled'] ) ? (string) $_POST['enabled'] : '0';

		if ( $group_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid group_id' ] );
		}

		if ( ! WC() || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'Cart not available' ] );
		}

		$cart = WC()->cart;

		if ( $enabled === '1' ) {
			// Enable transport for this pack
			$address = self::get_session_address();

			if ( empty( $address ) ) {
				// No address yet — tell frontend to show the address picker modal
				wp_send_json_success( [
					'action'        => 'needs_address',
					'group_id'      => $group_id,
					'message'       => 'Address required',
				] );
			}

			// Address exists — add transport child item
			$result = self::add_transport_item( $group_id, $address );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			wp_send_json_success( [
				'action'    => 'added',
				'group_id'  => $group_id,
				'price'     => $result['price'],
				'zone'      => $result['zone_name'] ?? '',
				'fragments' => self::get_cart_fragments(),
			] );

		} else {
			// Disable transport for this pack
			self::remove_transport_item( $group_id );

			// If no transport items remain, clear the session address
			if ( ! self::cart_has_any_transport() ) {
				self::clear_session_address();
			}

			wp_send_json_success( [
				'action'    => 'removed',
				'group_id'  => $group_id,
				'fragments' => self::get_cart_fragments(),
			] );
		}
	}

	/* ================================================================
	 * AJAX: Set/update transport address (order-level)
	 * ================================================================ */

	/**
	 * Set the shared transport delivery address
	 *
	 * POST params:
	 * - address: string (formatted address from Google Places)
	 * - lat: float
	 * - lng: float
	 * - group_id: int (the pack that triggered the address picker)
	 * - nonce: security nonce
	 *
	 * After setting address:
	 * - Resolves zone and calculates quote
	 * - Adds transport child item for the triggering pack
	 * - Recalculates prices for any existing transport items (same address for all)
	 */
	public static function ajax_set_address() : void {

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		$address_text = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$lat          = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : 0.0;
		$lng          = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : 0.0;
		$group_id     = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;

		if ( $address_text === '' || ( $lat == 0.0 && $lng == 0.0 ) ) {
			wp_send_json_error( [ 'message' => 'Invalid address' ] );
		}

		// Resolve zone
		$zone = TransportZones::resolve_zone( $lat, $lng );

		// Build address data
		$address_data = [
			'address'   => $address_text,
			'lat'       => $lat,
			'lng'       => $lng,
			'zone_id'   => $zone ? ( $zone['id'] ?? '' ) : '',
			'zone_name' => $zone ? ( $zone['name'] ?? '' ) : '',
		];

		// Store in session
		self::set_session_address( $address_data );

		// Calculate quote for display
		$quote = TransportPricing::calculate_quote( [
			'type'       => 'delivery',
			'dropoff_lat' => $lat,
			'dropoff_lng' => $lng,
		] );

		// If a group_id was provided, add transport item for that pack
		if ( $group_id > 0 ) {
			$result = self::add_transport_item( $group_id, $address_data );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}
		}

		// Recalculate prices for ALL existing transport items (address changed)
		self::recalculate_all_transport_prices( $address_data );

		wp_send_json_success( [
			'action'      => 'address_set',
			'address'     => $address_data,
			'quote'       => $quote,
			'group_id'    => $group_id,
			'fragments'   => self::get_cart_fragments(),
		] );
	}

	/* ================================================================
	 * AJAX: Get transport quote (preview, no cart changes)
	 * ================================================================ */

	public static function ajax_get_quote() : void {

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		$lat = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : 0.0;
		$lng = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : 0.0;

		if ( $lat == 0.0 && $lng == 0.0 ) {
			wp_send_json_error( [ 'message' => 'Invalid coordinates' ] );
		}

		$zone = TransportZones::resolve_zone( $lat, $lng );

		$quote = TransportPricing::calculate_quote( [
			'type'        => 'delivery',
			'dropoff_lat' => $lat,
			'dropoff_lng' => $lng,
		] );

		wp_send_json_success( [
			'quote'     => $quote,
			'zone'      => $zone,
			'zone_name' => $zone ? ( $zone['name'] ?? '' ) : null,
		] );
	}

	/* ================================================================
	 * Cart item management
	 * ================================================================ */

	/**
	 * Add a transport child item to a pack
	 *
	 * @param int   $group_id     Pack group ID (GF entry ID)
	 * @param array $address_data Address data from session
	 * @return array|\WP_Error Result with price/zone info, or error
	 */
	private static function add_transport_item( int $group_id, array $address_data ) {

		if ( ! WC() || ! WC()->cart ) {
			return new \WP_Error( 'no_cart', 'Cart not available' );
		}

		$cart = WC()->cart;

		// Check if transport already exists for this pack
		if ( self::pack_has_transport( $group_id ) ) {
			// Update the existing transport item's address data instead
			self::update_transport_item_address( $group_id, $address_data );

			$quote = self::calculate_pack_transport_quote( $address_data );
			return [
				'price'     => $quote['price_total'],
				'zone_name' => $address_data['zone_name'] ?? '',
				'updated'   => true,
			];
		}

		// Find the rental item in this pack to get booking data
		$rental_item = self::find_pack_rental( $group_id );
		if ( ! $rental_item ) {
			return new \WP_Error( 'no_rental', 'No rental found in pack' );
		}

		// Get transport product ID
		$transport_product_id = TransportPricing::get_transport_product_id();
		if ( $transport_product_id <= 0 ) {
			return new \WP_Error( 'no_product', 'Transport product not configured' );
		}

		$transport_product = wc_get_product( $transport_product_id );
		if ( ! $transport_product ) {
			return new \WP_Error( 'invalid_product', 'Transport product not found' );
		}

		// Calculate quote
		$quote = self::calculate_pack_transport_quote( $address_data );

		// Build cart item meta for transport child
		$rental_booking = isset( $rental_item['booking'] ) ? (array) $rental_item['booking'] : [];

		$cart_item_meta = [
			'booking' => [
				\TC_BF\Plugin::BK_EVENT_ID    => $rental_booking[ \TC_BF\Plugin::BK_EVENT_ID ] ?? 0,
				\TC_BF\Plugin::BK_EVENT_TITLE => $rental_booking[ \TC_BF\Plugin::BK_EVENT_TITLE ] ?? '',
				\TC_BF\Plugin::BK_ENTRY_ID    => $group_id,
				\TC_BF\Plugin::BK_SCOPE       => self::SCOPE_TRANSPORT,
				\TC_BF\Plugin::BK_CUSTOM_COST => wc_format_decimal( $quote['price_total'], 2 ),
			],
			Pack_Grouping::META_GROUP_ID   => $group_id,
			Pack_Grouping::META_GROUP_ROLE => Pack_Grouping::ROLE_CHILD,
			Pack_Grouping::META_SCOPE      => self::SCOPE_TRANSPORT,
			'_tcbf_scope'                  => self::SCOPE_TRANSPORT,
			'_tcbf_event_id'               => $rental_booking[ \TC_BF\Plugin::BK_EVENT_ID ] ?? 0,
			'_tcbf_gf_entry_id'            => $group_id,
			'_tcbf_transport_address'      => $address_data['address'] ?? '',
			'_tcbf_transport_lat'          => $address_data['lat'] ?? 0,
			'_tcbf_transport_lng'          => $address_data['lng'] ?? 0,
			'_tcbf_transport_zone_id'      => $address_data['zone_id'] ?? '',
			'_tcbf_transport_zone_name'    => $address_data['zone_name'] ?? '',
			'_tcbf_transport_price'        => $quote['price_total'],
		];

		// Carry participant name from rental
		if ( isset( $rental_item['_tcbf_participant_name'] ) ) {
			$cart_item_meta['_tcbf_participant_name'] = $rental_item['_tcbf_participant_name'];
		}

		$added = $cart->add_to_cart( $transport_product_id, 1, 0, [], $cart_item_meta );

		if ( ! $added ) {
			return new \WP_Error( 'add_failed', 'Failed to add transport to cart' );
		}

		Logger::log( 'transport.cart.added', [
			'group_id'   => $group_id,
			'cart_key'   => $added,
			'price'      => $quote['price_total'],
			'zone'       => $address_data['zone_name'] ?? '',
			'address'    => $address_data['address'] ?? '',
		] );

		return [
			'price'     => $quote['price_total'],
			'zone_name' => $address_data['zone_name'] ?? '',
			'cart_key'  => $added,
		];
	}

	/**
	 * Remove transport child item from a pack
	 */
	private static function remove_transport_item( int $group_id ) : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( self::is_transport_item( $item ) && self::get_item_group_id( $item ) === $group_id ) {
				$cart->remove_cart_item( $key );

				Logger::log( 'transport.cart.removed', [
					'group_id' => $group_id,
					'cart_key' => $key,
				] );
				break;
			}
		}
	}

	/**
	 * Clean up transport items when their parent pack is being removed
	 */
	public static function cleanup_transport_on_removal( string $cart_item_key, $cart ) : void {

		if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$cart_contents = $cart->get_cart();
		if ( ! isset( $cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$removed_item = $cart_contents[ $cart_item_key ];
		$group_id = self::get_item_group_id( $removed_item );

		if ( $group_id <= 0 ) {
			return;
		}

		// If a non-transport item is being removed, the Pack_Grouping atomic removal
		// will handle removing siblings (including transport). No extra action needed.
		// This hook is just for logging.
		if ( self::is_transport_item( $removed_item ) ) {
			Logger::log( 'transport.cart.cleanup', [
				'group_id'     => $group_id,
				'cart_item_key' => $cart_item_key,
			] );
		}
	}

	/* ================================================================
	 * Session address management (order-level)
	 * ================================================================ */

	/**
	 * Get the shared transport address from WC session
	 *
	 * @return array|null Address data or null
	 */
	public static function get_session_address() : ?array {

		if ( ! WC() || ! WC()->session ) {
			return null;
		}

		$data = WC()->session->get( self::SESSION_ADDRESS );

		if ( ! is_array( $data ) || empty( $data['address'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Set the shared transport address in WC session
	 */
	private static function set_session_address( array $address_data ) : void {

		if ( ! WC() || ! WC()->session ) {
			return;
		}

		WC()->session->set( self::SESSION_ADDRESS, $address_data );

		Logger::log( 'transport.session.address_set', [
			'address' => $address_data['address'] ?? '',
			'zone'    => $address_data['zone_name'] ?? '',
		] );
	}

	/**
	 * Clear transport address from session
	 */
	private static function clear_session_address() : void {

		if ( ! WC() || ! WC()->session ) {
			return;
		}

		WC()->session->set( self::SESSION_ADDRESS, null );

		Logger::log( 'transport.session.address_cleared' );
	}

	/**
	 * Clear transport session data when cart is emptied
	 */
	public static function clear_transport_session() : void {
		self::clear_session_address();
	}

	/* ================================================================
	 * Cart pricing
	 * ================================================================ */

	/**
	 * Set transport item prices from stored quote data
	 *
	 * Runs on woocommerce_before_calculate_totals to enforce transport prices.
	 */
	public static function set_transport_prices( $cart ) : void {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_transport_item( $item ) ) {
				continue;
			}

			$product = $item['data'] ?? null;
			if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'set_price' ) ) {
				continue;
			}

			// Use the stored transport price
			$price = 0.0;
			if ( isset( $item['_tcbf_transport_price'] ) ) {
				$price = (float) $item['_tcbf_transport_price'];
			} elseif ( isset( $item['booking'][ \TC_BF\Plugin::BK_CUSTOM_COST ] ) ) {
				$price = (float) $item['booking'][ \TC_BF\Plugin::BK_CUSTOM_COST ];
			}

			$product->set_price( $price );
		}
	}

	/**
	 * Recalculate prices for all transport items after address change
	 */
	private static function recalculate_all_transport_prices( array $address_data ) : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$quote = self::calculate_pack_transport_quote( $address_data );

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_transport_item( $item ) ) {
				continue;
			}

			// Update price and address data in cart contents
			$cart->cart_contents[ $key ]['_tcbf_transport_price']     = $quote['price_total'];
			$cart->cart_contents[ $key ]['_tcbf_transport_address']   = $address_data['address'] ?? '';
			$cart->cart_contents[ $key ]['_tcbf_transport_lat']       = $address_data['lat'] ?? 0;
			$cart->cart_contents[ $key ]['_tcbf_transport_lng']       = $address_data['lng'] ?? 0;
			$cart->cart_contents[ $key ]['_tcbf_transport_zone_id']   = $address_data['zone_id'] ?? '';
			$cart->cart_contents[ $key ]['_tcbf_transport_zone_name'] = $address_data['zone_name'] ?? '';

			if ( isset( $cart->cart_contents[ $key ]['booking'] ) ) {
				$cart->cart_contents[ $key ]['booking'][ \TC_BF\Plugin::BK_CUSTOM_COST ] = wc_format_decimal( $quote['price_total'], 2 );
			}
		}
	}

	/**
	 * Update address data on an existing transport item
	 */
	private static function update_transport_item_address( int $group_id, array $address_data ) : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$quote = self::calculate_pack_transport_quote( $address_data );

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( self::is_transport_item( $item ) && self::get_item_group_id( $item ) === $group_id ) {
				$cart->cart_contents[ $key ]['_tcbf_transport_price']     = $quote['price_total'];
				$cart->cart_contents[ $key ]['_tcbf_transport_address']   = $address_data['address'] ?? '';
				$cart->cart_contents[ $key ]['_tcbf_transport_lat']       = $address_data['lat'] ?? 0;
				$cart->cart_contents[ $key ]['_tcbf_transport_lng']       = $address_data['lng'] ?? 0;
				$cart->cart_contents[ $key ]['_tcbf_transport_zone_id']   = $address_data['zone_id'] ?? '';
				$cart->cart_contents[ $key ]['_tcbf_transport_zone_name'] = $address_data['zone_name'] ?? '';

				if ( isset( $cart->cart_contents[ $key ]['booking'] ) ) {
					$cart->cart_contents[ $key ]['booking'][ \TC_BF\Plugin::BK_CUSTOM_COST ] = wc_format_decimal( $quote['price_total'], 2 );
				}
				break;
			}
		}
	}

	/**
	 * Calculate transport quote for a single bike delivery
	 */
	private static function calculate_pack_transport_quote( array $address_data ) : array {

		return TransportPricing::calculate_quote( [
			'type'        => 'delivery',
			'dropoff_lat' => (float) ( $address_data['lat'] ?? 0 ),
			'dropoff_lng' => (float) ( $address_data['lng'] ?? 0 ),
		] );
	}

	/* ================================================================
	 * Cart display
	 * ================================================================ */

	/**
	 * Display transport info in cart item meta
	 */
	public static function display_transport_cart_item_data( array $item_data, array $cart_item ) : array {

		if ( ! self::is_transport_item( $cart_item ) ) {
			return $item_data;
		}

		// Filter out WooCommerce Bookings auto-fields (not relevant for transport)
		$item_data = [];

		// Show delivery address (truncated)
		$address = $cart_item['_tcbf_transport_address'] ?? '';
		if ( $address !== '' ) {
			$display_address = mb_strlen( $address ) > 50 ? mb_substr( $address, 0, 47 ) . '...' : $address;
			$item_data[] = [
				'name'  => Woo::translate( '[:en]Delivery to[:es]Entrega en[:]' ),
				'value' => $display_address,
			];
		}

		// Show zone
		$zone_name = $cart_item['_tcbf_transport_zone_name'] ?? '';
		if ( $zone_name !== '' ) {
			$item_data[] = [
				'name'  => Woo::translate( '[:en]Zone[:es]Zona[:]' ),
				'value' => $zone_name,
			];
		}

		return $item_data;
	}

	/**
	 * Render transport toggle switch after rental item names in cart
	 *
	 * Only shows on rental (child) items that belong to a pack.
	 */
	public static function render_transport_toggle( array $cart_item, string $cart_item_key ) : void {

		// DEBUG: comprehensive diagnostic (visible in View Source only) — remove after testing
		$debug_is_cart       = is_cart() ? 'yes' : 'no';
		$debug_scope         = Pack_Grouping::get_scope( $cart_item );
		$debug_group_id      = Pack_Grouping::get_group_id( $cart_item );
		$debug_product_id_t  = TransportPricing::get_transport_product_id();
		$debug_product_id    = $cart_item['product_id'] ?? 0;
		$debug_bk_scope      = $cart_item['booking'][\TC_BF\Plugin::BK_SCOPE] ?? '(unset)';
		$debug_bk_entry      = $cart_item['booking'][\TC_BF\Plugin::BK_ENTRY_ID] ?? '(unset)';
		$debug_tcbf_scope    = $cart_item['_tcbf_scope'] ?? '(unset)';
		$debug_pg_scope      = $cart_item[ Pack_Grouping::META_SCOPE ] ?? '(unset)';
		$debug_pg_role       = $cart_item[ Pack_Grouping::META_GROUP_ROLE ] ?? '(unset)';
		$debug_has_booking   = isset( $cart_item['booking'] ) ? 'yes' : 'no';
		$debug_booking_keys  = isset( $cart_item['booking'] ) && is_array( $cart_item['booking'] )
			? implode( ',', array_keys( $cart_item['booking'] ) ) : '(none)';
		printf(
			'<!-- TCBF-transport-debug: is_cart=%s scope=%s group_id=%d transport_product=%d key=%s ' .
			'product_id=%d bk_scope=%s bk_entry=%s tcbf_scope=%s pg_scope=%s pg_role=%s ' .
			'has_booking=%s booking_keys=[%s] -->',
			$debug_is_cart,
			$debug_scope ?: '(empty)',
			$debug_group_id,
			$debug_product_id_t,
			esc_attr( $cart_item_key ),
			$debug_product_id,
			$debug_bk_scope,
			$debug_bk_entry,
			$debug_tcbf_scope,
			$debug_pg_scope,
			$debug_pg_role,
			$debug_has_booking,
			$debug_booking_keys
		);

		// Only show on cart page (not checkout/mini-cart)
		if ( ! is_cart() ) {
			return;
		}

		// Only show on rental child items
		$scope = Pack_Grouping::get_scope( $cart_item );
		if ( $scope !== 'rental' ) {
			return;
		}

		$group_id = Pack_Grouping::get_group_id( $cart_item );
		if ( $group_id <= 0 ) {
			return;
		}

		// Check if transport product is configured
		$transport_product_id = TransportPricing::get_transport_product_id();
		if ( $transport_product_id <= 0 ) {
			return;
		}

		// Check if this pack already has transport
		$has_transport = self::pack_has_transport( $group_id );
		$checked = $has_transport ? 'checked' : '';

		// Get current address (for display if set)
		$address = self::get_session_address();
		$address_display = '';
		$price_display = '';

		if ( $has_transport && $address ) {
			$address_display = esc_html( $address['address'] ?? '' );

			// Find the transport item to get price
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::is_transport_item( $item ) && self::get_item_group_id( $item ) === $group_id ) {
					$price = (float) ( $item['_tcbf_transport_price'] ?? 0 );
					if ( $price > 0 ) {
						$price_display = wp_strip_all_tags( wc_price( $price ) );
					}
					break;
				}
			}
		}

		$label = Woo::translate( '[:en]Bike transport[:es]Transporte de bicicleta[:]' );

		printf(
			'<div class="tcbf-transport-toggle" data-group-id="%d">' .
			'<label class="tcbf-transport-toggle__label">' .
			'<input type="checkbox" class="tcbf-transport-toggle__input" data-group-id="%d" %s />' .
			'<span class="tcbf-transport-toggle__slider"></span>' .
			'<span class="tcbf-transport-toggle__text">%s</span>' .
			'</label>' .
			'<span class="tcbf-transport-toggle__price" %s>%s</span>' .
			'<span class="tcbf-transport-toggle__address" %s>%s</span>' .
			'</div>',
			$group_id,
			$group_id,
			$checked,
			esc_html( $label ),
			$price_display ? '' : 'style="display:none"',
			$price_display ? esc_html( $price_display ) : '',
			$address_display ? '' : 'style="display:none"',
			$address_display ? esc_html( $address_display ) : ''
		);
	}

	/* ================================================================
	 * Order persistence
	 * ================================================================ */

	/**
	 * Persist transport meta to order line items
	 */
	public static function persist_transport_order_meta( $item, string $cart_item_key, array $values, $order ) : void {

		if ( ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		if ( ! self::is_transport_item( $values ) ) {
			return;
		}

		$meta_keys = [
			'_tcbf_transport_address',
			'_tcbf_transport_lat',
			'_tcbf_transport_lng',
			'_tcbf_transport_zone_id',
			'_tcbf_transport_zone_name',
			'_tcbf_transport_price',
		];

		foreach ( $meta_keys as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$item->add_meta_data( $key, $values[ $key ], true );
			}
		}

		Logger::log( 'transport.order_meta.persisted', [
			'order_id'  => method_exists( $order, 'get_id' ) ? $order->get_id() : 0,
			'group_id'  => $values[ Pack_Grouping::META_GROUP_ID ] ?? 0,
			'address'   => $values['_tcbf_transport_address'] ?? '',
			'price'     => $values['_tcbf_transport_price'] ?? 0,
		] );
	}

	/**
	 * Persist shared transport address to order meta
	 */
	public static function persist_transport_order_address( $order, $data ) : void {

		if ( ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$address = self::get_session_address();
		if ( ! $address ) {
			return;
		}

		$order->update_meta_data( '_tcbf_transport_delivery_address', $address['address'] ?? '' );
		$order->update_meta_data( '_tcbf_transport_delivery_lat', $address['lat'] ?? 0 );
		$order->update_meta_data( '_tcbf_transport_delivery_lng', $address['lng'] ?? 0 );
		$order->update_meta_data( '_tcbf_transport_delivery_zone_id', $address['zone_id'] ?? '' );
		$order->update_meta_data( '_tcbf_transport_delivery_zone_name', $address['zone_name'] ?? '' );

		// Count how many bikes are being transported
		$transport_count = 0;
		if ( WC() && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::is_transport_item( $item ) ) {
					$transport_count++;
				}
			}
		}
		$order->update_meta_data( '_tcbf_transport_bike_count', $transport_count );

		Logger::log( 'transport.order.address_persisted', [
			'order_id' => $order->get_id(),
			'address'  => $address['address'] ?? '',
			'bikes'    => $transport_count,
		] );
	}

	/* ================================================================
	 * Frontend assets
	 * ================================================================ */

	/**
	 * Enqueue transport JS and CSS on cart/checkout pages
	 */
	public static function enqueue_assets() : void {

		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		// Check if transport product is configured
		$transport_product_id = TransportPricing::get_transport_product_id();
		if ( $transport_product_id <= 0 ) {
			return;
		}

		$google_maps_key = TransportPricing::get_google_maps_key();

		// Enqueue Google Maps Places API (if key configured)
		if ( $google_maps_key !== '' ) {
			wp_enqueue_script(
				'google-maps-places',
				'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $google_maps_key ) . '&libraries=places',
				[],
				null,
				true
			);
		}

		// Enqueue transport JS
		wp_enqueue_script(
			'tcbf-transport',
			TC_BF_URL . 'assets/js/tcbf-transport.js',
			[ 'jquery' ],
			TC_BF_VERSION,
			true
		);

		// Pass data to JS
		$address = self::get_session_address();

		wp_localize_script( 'tcbf-transport', 'tcbfTransport', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'tcbf_transport_nonce' ),
			'hasAddress' => ! empty( $address ),
			'address'    => $address ?: null,
			'hasMapsKey' => $google_maps_key !== '',
			'i18n'       => [
				'modalTitle'       => Woo::translate( '[:en]Delivery Address[:es]Dirección de entrega[:]' ),
				'addressLabel'     => Woo::translate( '[:en]Enter delivery address[:es]Ingrese la dirección de entrega[:]' ),
				'confirmBtn'       => Woo::translate( '[:en]Confirm[:es]Confirmar[:]' ),
				'cancelBtn'        => Woo::translate( '[:en]Cancel[:es]Cancelar[:]' ),
				'changeAddressBtn' => Woo::translate( '[:en]Change address[:es]Cambiar dirección[:]' ),
				'quoteLabel'       => Woo::translate( '[:en]Transport cost[:es]Coste de transporte[:]' ),
				'zoneLabel'        => Woo::translate( '[:en]Zone[:es]Zona[:]' ),
				'outsideZones'     => Woo::translate( '[:en]Outside service zones[:es]Fuera de zonas de servicio[:]' ),
				'loading'          => Woo::translate( '[:en]Calculating...[:es]Calculando...[:]' ),
				'errorGeneric'     => Woo::translate( '[:en]Something went wrong. Please try again.[:es]Algo salió mal. Inténtalo de nuevo.[:]' ),
			],
		] );

		// Enqueue transport CSS
		wp_enqueue_style(
			'tcbf-transport',
			TC_BF_URL . 'assets/css/tcbf-transport.css',
			[],
			TC_BF_VERSION
		);
	}

	/* ================================================================
	 * Helper methods
	 * ================================================================ */

	/**
	 * Check if a cart item is a transport item
	 */
	public static function is_transport_item( array $item ) : bool {

		if ( isset( $item[ Pack_Grouping::META_SCOPE ] ) && $item[ Pack_Grouping::META_SCOPE ] === self::SCOPE_TRANSPORT ) {
			return true;
		}

		if ( isset( $item['_tcbf_scope'] ) && $item['_tcbf_scope'] === self::SCOPE_TRANSPORT ) {
			return true;
		}

		if ( isset( $item['booking'][ \TC_BF\Plugin::BK_SCOPE ] ) && $item['booking'][ \TC_BF\Plugin::BK_SCOPE ] === self::SCOPE_TRANSPORT ) {
			return true;
		}

		return false;
	}

	/**
	 * Get group ID from a cart item
	 */
	private static function get_item_group_id( array $item ) : int {

		if ( isset( $item[ Pack_Grouping::META_GROUP_ID ] ) ) {
			return (int) $item[ Pack_Grouping::META_GROUP_ID ];
		}

		return 0;
	}

	/**
	 * Check if a pack already has a transport child item
	 */
	public static function pack_has_transport( int $group_id ) : bool {

		if ( $group_id <= 0 || ! WC() || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_transport_item( $item ) && self::get_item_group_id( $item ) === $group_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the cart has any transport items
	 */
	private static function cart_has_any_transport() : bool {

		if ( ! WC() || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_transport_item( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the rental cart item in a pack
	 */
	private static function find_pack_rental( int $group_id ) : ?array {

		if ( ! WC() || ! WC()->cart ) {
			return null;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::get_item_group_id( $item ) === $group_id ) {
				$scope = '';
				if ( isset( $item['booking'][ \TC_BF\Plugin::BK_SCOPE ] ) ) {
					$scope = $item['booking'][ \TC_BF\Plugin::BK_SCOPE ];
				}
				if ( $scope === 'rental' ) {
					return $item;
				}
			}
		}

		return null;
	}

	/**
	 * Get WC cart fragments for AJAX response (triggers cart refresh)
	 */
	private static function get_cart_fragments() : array {

		if ( ! WC() || ! WC()->cart ) {
			return [];
		}

		WC()->cart->calculate_totals();

		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();

		return [
			'div.widget_shopping_cart_content' => $mini_cart,
		];
	}
}
