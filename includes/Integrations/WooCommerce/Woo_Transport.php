<?php
namespace TC_BF\Integrations\WooCommerce;

use TC_BF\Domain\TransportZones;
use TC_BF\Domain\TransportPricing;
use TC_BF\Domain\TransportAvailability;
use TC_BF\Support\Logger;
use TC_BF\Support\Money;

if ( ! defined('ABSPATH') ) exit;

/**
 * Woo_Transport — Cart-level transport addon for bike rentals (v2: dual-direction)
 *
 * Architecture:
 * - Two independent toggles per rental: Delivery (bikes TO customer) and Return (bikes FROM customer)
 * - Each direction has its own address + time window stored in WC session
 * - Each toggled-on rental+direction gets a transport child item (scope = 'transport')
 * - transport_type meta: 'delivery' or 'pickup' (pickup = return direction)
 * - Capacity enforced per (date, window) across both directions
 * - Bulk pricing: total computed for all bikes in a direction, then split equally per child item
 *
 * Session keys (per-direction):
 * - tcbf_transport_delivery_address = {address, lat, lng, place_id}
 * - tcbf_transport_delivery_window  = morning|afternoon
 * - tcbf_transport_return_address   = {address, lat, lng, place_id}
 * - tcbf_transport_return_window    = morning|afternoon
 * - tcbf_transport_link_return      = 0|1
 */
final class Woo_Transport {

	const SESSION_DELIVERY_ADDRESS = 'tcbf_transport_delivery_address';
	const SESSION_DELIVERY_WINDOW  = 'tcbf_transport_delivery_window';
	const SESSION_RETURN_ADDRESS   = 'tcbf_transport_return_address';
	const SESSION_RETURN_WINDOW    = 'tcbf_transport_return_window';
	const SESSION_LINK_RETURN      = 'tcbf_transport_link_return';

	const SCOPE_TRANSPORT = 'transport';

	const DIR_DELIVERY = 'delivery';
	const DIR_PICKUP   = 'pickup';

	public static function init() : void {

		// AJAX: toggle transport on/off for a rental + direction
		add_action( 'wp_ajax_tcbf_transport_toggle', [ __CLASS__, 'ajax_toggle_transport' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_toggle', [ __CLASS__, 'ajax_toggle_transport' ] );

		// AJAX: set/update transport address for a direction
		add_action( 'wp_ajax_tcbf_transport_set_address', [ __CLASS__, 'ajax_set_address' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_set_address', [ __CLASS__, 'ajax_set_address' ] );

		// AJAX: get transport price quote
		add_action( 'wp_ajax_tcbf_transport_quote', [ __CLASS__, 'ajax_get_quote' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_quote', [ __CLASS__, 'ajax_get_quote' ] );

		// AJAX: server-side geocoding for manual address input
		add_action( 'wp_ajax_tcbf_transport_geocode', [ __CLASS__, 'ajax_geocode' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_geocode', [ __CLASS__, 'ajax_geocode' ] );

		// Cart display
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_transport_cart_item_data' ], 25, 2 );

		// Cart pricing
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'set_transport_prices' ], 25, 1 );

		// Order persistence
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_transport_order_meta' ], 15, 4 );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'persist_transport_order_address' ], 20, 2 );

		// Checkout validation (race-condition guard for availability)
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_transport_availability' ], 15 );

		// Frontend assets
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );

		// Render transport toggles
		add_action( 'woocommerce_after_cart_item_name', [ __CLASS__, 'render_transport_toggle' ], 20, 2 );

		// Cleanup on parent removal
		add_action( 'woocommerce_remove_cart_item', [ __CLASS__, 'cleanup_transport_on_removal' ], 3, 2 );

		// Clear session on cart empty
		add_action( 'woocommerce_cart_emptied', [ __CLASS__, 'clear_transport_session' ], 5 );
	}

	/* ================================================================
	 * AJAX: Toggle transport on/off
	 * ================================================================ */

	public static function ajax_toggle_transport() : void {

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		$cart_key  = isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '';
		$direction = isset( $_POST['direction'] ) ? sanitize_text_field( wp_unslash( $_POST['direction'] ) ) : '';
		$enabled   = isset( $_POST['enabled'] ) ? (string) $_POST['enabled'] : '0';

		if ( $cart_key === '' || ! in_array( $direction, [ self::DIR_DELIVERY, self::DIR_PICKUP ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid parameters' ] );
		}

		if ( ! WC() || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'Cart not available' ] );
		}

		$rental_item = WC()->cart->get_cart_item( $cart_key );
		if ( ! $rental_item ) {
			wp_send_json_error( [ 'message' => 'Cart item not found' ] );
		}

		if ( $enabled === '1' ) {
			$address = self::get_direction_address( $direction );

			if ( empty( $address ) ) {
				wp_send_json_success( [
					'action'    => 'needs_address',
					'cart_key'  => $cart_key,
					'direction' => $direction,
				] );
			}

			$window = self::get_direction_window( $direction );
			$service_date = self::derive_service_date( $rental_item, $direction );

			// Check availability: can we add 1 more bike to this (date, window)?
			if ( $service_date && $window ) {
				if ( ! TransportAvailability::can_add( $service_date, $window, 1 ) ) {
					$remaining = TransportAvailability::remaining_capacity( $service_date, $window );
					$in_cart   = TransportAvailability::count_in_cart( $service_date, $window );
					wp_send_json_error( [
						'message'   => sprintf( 'No capacity available for %s %s. %d slots remaining (%d in your cart).', $service_date, $window, $remaining, $in_cart ),
						'remaining' => max( 0, $remaining - $in_cart ),
					] );
				}
			}

			$result = self::add_transport_item( $cart_key, $rental_item, $address, $direction, $window, $service_date );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			// Recalculate prices for this direction (bulk pricing)
			self::recalculate_direction_prices( $direction );

			wp_send_json_success( [
				'action'    => 'added',
				'cart_key'  => $cart_key,
				'direction' => $direction,
				'price'     => $result['price'],
				'zone'      => $result['zone_name'] ?? '',
				'fragments' => self::get_cart_fragments(),
			] );

		} else {
			self::remove_transport_item( $cart_key, $direction );

			// Recalculate remaining items in this direction
			self::recalculate_direction_prices( $direction );

			if ( ! self::cart_has_any_transport() ) {
				self::clear_all_direction_sessions();
			}

			wp_send_json_success( [
				'action'    => 'removed',
				'cart_key'  => $cart_key,
				'direction' => $direction,
				'fragments' => self::get_cart_fragments(),
			] );
		}
	}

	/* ================================================================
	 * AJAX: Set/update address for a direction
	 * ================================================================ */

	public static function ajax_set_address() : void {

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		$address_text = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$lat          = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : 0.0;
		$lng          = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : 0.0;
		$place_id     = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '';
		$direction    = isset( $_POST['direction'] ) ? sanitize_text_field( wp_unslash( $_POST['direction'] ) ) : 'delivery';
		$window       = isset( $_POST['window'] ) ? sanitize_text_field( wp_unslash( $_POST['window'] ) ) : 'morning';
		$cart_key     = isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '';
		$link_return  = isset( $_POST['link_return'] ) ? (int) $_POST['link_return'] : 0;

		if ( ! in_array( $direction, [ self::DIR_DELIVERY, self::DIR_PICKUP ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid direction' ] );
		}

		if ( ! in_array( $window, [ 'morning', 'afternoon' ], true ) ) {
			$window = 'morning';
		}

		if ( $address_text === '' || ( $lat == 0.0 && $lng == 0.0 ) ) {
			wp_send_json_error( [ 'message' => 'Invalid address' ] );
		}

		$zone = TransportZones::resolve_zone( $lat, $lng );

		$address_data = [
			'address'  => $address_text,
			'lat'      => $lat,
			'lng'      => $lng,
			'place_id' => $place_id,
			'zone_id'  => $zone ? ( $zone['id'] ?? '' ) : '',
			'zone_name'=> $zone ? ( $zone['name'] ?? '' ) : '',
		];

		// Store in session
		self::set_direction_address( $direction, $address_data );
		self::set_direction_window( $direction, $window );

		// Link return to delivery if requested
		if ( $direction === self::DIR_DELIVERY && $link_return ) {
			self::set_direction_address( self::DIR_PICKUP, $address_data );
			self::set_direction_window( self::DIR_PICKUP, $window );
			self::set_session( self::SESSION_LINK_RETURN, 1 );
		} elseif ( $direction === self::DIR_DELIVERY ) {
			// If previously linked, update return too
			$currently_linked = (int) self::get_session( self::SESSION_LINK_RETURN );
			if ( $currently_linked ) {
				self::set_direction_address( self::DIR_PICKUP, $address_data );
				self::set_direction_window( self::DIR_PICKUP, $window );
			}
		}

		// If a cart_key was provided, add transport item for that rental
		if ( $cart_key !== '' && WC() && WC()->cart ) {
			$rental_item = WC()->cart->get_cart_item( $cart_key );
			if ( $rental_item ) {
				$service_date = self::derive_service_date( $rental_item, $direction );
				$result = self::add_transport_item( $cart_key, $rental_item, $address_data, $direction, $window, $service_date );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( [ 'message' => $result->get_error_message() ] );
				}
			}
		}

		// Recalculate prices for this direction
		self::recalculate_direction_prices( $direction );

		// If linked, also recalculate pickup
		if ( $direction === self::DIR_DELIVERY && ( $link_return || (int) self::get_session( self::SESSION_LINK_RETURN ) ) ) {
			self::recalculate_direction_prices( self::DIR_PICKUP );
		}

		// Calculate quote for display
		$bike_qty = max( 1, self::count_direction_bikes( $direction ) );
		$quote = self::calculate_direction_quote( $address_data, $direction, $bike_qty );

		wp_send_json_success( [
			'action'    => 'address_set',
			'direction' => $direction,
			'address'   => $address_data,
			'window'    => $window,
			'quote'     => $quote,
			'cart_key'  => $cart_key,
			'fragments' => self::get_cart_fragments(),
		] );
	}

	/* ================================================================
	 * AJAX: Get quote preview
	 * ================================================================ */

	public static function ajax_get_quote() : void {

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		$lat       = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : 0.0;
		$lng       = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : 0.0;
		$direction = isset( $_POST['direction'] ) ? sanitize_text_field( wp_unslash( $_POST['direction'] ) ) : 'delivery';
		$window    = isset( $_POST['window'] ) ? sanitize_text_field( wp_unslash( $_POST['window'] ) ) : 'morning';

		if ( $lat == 0.0 && $lng == 0.0 ) {
			wp_send_json_error( [ 'message' => 'Invalid coordinates' ] );
		}

		$zone = TransportZones::resolve_zone( $lat, $lng );

		// Count how many bikes are toggled for this direction (include the one being added)
		$bike_qty = max( 1, self::count_direction_bikes( $direction ) );

		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';
		$quote = TransportPricing::calculate_quote( [
			'type'        => $type,
			'dropoff_lat' => ( $type === 'delivery' ) ? $lat : 0,
			'dropoff_lng' => ( $type === 'delivery' ) ? $lng : 0,
			'pickup_lat'  => ( $type === 'pickup' ) ? $lat : 0,
			'pickup_lng'  => ( $type === 'pickup' ) ? $lng : 0,
			'bike_qty'    => $bike_qty,
		] );

		// Availability info
		$service_date = '';
		$remaining = null;
		// Try to derive from first rental in cart
		if ( WC() && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::is_transport_eligible( $item ) ) {
					$service_date = self::derive_service_date( $item, $direction );
					break;
				}
			}
		}
		$is_available = null;
		if ( $service_date && $window ) {
			$remaining    = TransportAvailability::remaining_capacity( $service_date, $window );
			$in_cart      = TransportAvailability::count_in_cart( $service_date, $window );
			$remaining    = max( 0, $remaining - $in_cart );
			$is_available = TransportAvailability::can_add( $service_date, $window, 1 );
		}

		wp_send_json_success( [
			'quote'        => $quote,
			'zone'         => $zone,
			'zone_name'    => $zone ? ( $zone['name'] ?? '' ) : null,
			'remaining'    => $remaining,
			'in_cart'      => $in_cart ?? 0,
			'is_available' => $is_available,
		] );
	}

	/* ================================================================
	 * AJAX: Server-side geocoding
	 * ================================================================ */

	public static function ajax_geocode() : void {

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		if ( $address === '' ) {
			wp_send_json_error( [ 'message' => 'Empty address' ] );
		}

		$api_key = TransportPricing::get_google_maps_key();
		if ( $api_key === '' ) {
			wp_send_json_error( [ 'message' => 'Geocoding not available (no API key)' ] );
		}

		$url = add_query_arg( [
			'address' => $address,
			'key'     => $api_key,
		], 'https://maps.googleapis.com/maps/api/geocode/json' );

		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Geocoding request failed' ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ( $body['status'] ?? '' ) !== 'OK' || empty( $body['results'][0] ) ) {
			wp_send_json_error( [ 'message' => 'Address not found' ] );
		}

		$result = $body['results'][0];
		$location = $result['geometry']['location'] ?? [];

		wp_send_json_success( [
			'formatted_address' => $result['formatted_address'] ?? $address,
			'lat'               => (float) ( $location['lat'] ?? 0 ),
			'lng'               => (float) ( $location['lng'] ?? 0 ),
			'place_id'          => $result['place_id'] ?? '',
		] );
	}

	/* ================================================================
	 * Cart item management
	 * ================================================================ */

	private static function add_transport_item( string $rental_cart_key, array $rental_item, array $address_data, string $direction, string $window, string $service_date ) {

		if ( ! WC() || ! WC()->cart ) {
			return new \WP_Error( 'no_cart', 'Cart not available' );
		}

		$cart = WC()->cart;

		// Check if transport already exists for this rental+direction
		if ( self::rental_has_transport( $rental_cart_key, $direction ) ) {
			self::update_transport_item_meta( $rental_cart_key, $direction, $address_data, $window, $service_date );
			$quote = self::calculate_direction_quote( $address_data, $direction );
			return [
				'price'     => $quote['per_bike_price'],
				'zone_name' => $address_data['zone_name'] ?? '',
				'updated'   => true,
			];
		}

		$transport_product_id = TransportPricing::get_transport_product_id();
		if ( $transport_product_id <= 0 ) {
			return new \WP_Error( 'no_product', 'Transport product not configured' );
		}

		$transport_product = wc_get_product( $transport_product_id );
		if ( ! $transport_product ) {
			return new \WP_Error( 'invalid_product', 'Transport product not found' );
		}

		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';
		$quote = self::calculate_direction_quote( $address_data, $direction );

		$rental_booking = isset( $rental_item['booking'] ) ? (array) $rental_item['booking'] : [];

		$cart_item_meta = [
			'booking' => [
				\TC_BF\Plugin::BK_SCOPE       => self::SCOPE_TRANSPORT,
				\TC_BF\Plugin::BK_CUSTOM_COST => wc_format_decimal( $quote['per_bike_price'], 2 ),
			],
			Pack_Grouping::META_SCOPE          => self::SCOPE_TRANSPORT,
			'_tcbf_scope'                      => self::SCOPE_TRANSPORT,
			'_tcbf_transport_parent_key'       => $rental_cart_key,
			'_tcbf_transport_type'             => $type,
			'_tcbf_transport_address'          => $address_data['address'] ?? '',
			'_tcbf_transport_lat'              => $address_data['lat'] ?? 0,
			'_tcbf_transport_lng'              => $address_data['lng'] ?? 0,
			'_tcbf_transport_place_id'         => $address_data['place_id'] ?? '',
			'_tcbf_transport_zone_id'          => $address_data['zone_id'] ?? '',
			'_tcbf_transport_zone_name'        => $address_data['zone_name'] ?? '',
			'_tcbf_transport_price'            => $quote['per_bike_price'],
			'_tcbf_transport_service_date'     => $service_date,
			'_tcbf_transport_window'           => $window,
			'_tcbf_transport_quote_json'       => wp_json_encode( $quote ),
		];

		// Copy TCBF pack metadata from rental
		if ( isset( $rental_booking[ \TC_BF\Plugin::BK_EVENT_ID ] ) ) {
			$cart_item_meta['booking'][\TC_BF\Plugin::BK_EVENT_ID] = $rental_booking[\TC_BF\Plugin::BK_EVENT_ID];
			$cart_item_meta['_tcbf_event_id'] = $rental_booking[\TC_BF\Plugin::BK_EVENT_ID];
		}
		if ( isset( $rental_booking[ \TC_BF\Plugin::BK_EVENT_TITLE ] ) ) {
			$cart_item_meta['booking'][\TC_BF\Plugin::BK_EVENT_TITLE] = $rental_booking[\TC_BF\Plugin::BK_EVENT_TITLE];
		}
		if ( isset( $rental_booking[ \TC_BF\Plugin::BK_ENTRY_ID ] ) ) {
			$group_id = (int) $rental_booking[\TC_BF\Plugin::BK_ENTRY_ID];
			$cart_item_meta['booking'][\TC_BF\Plugin::BK_ENTRY_ID] = $group_id;
			$cart_item_meta[ Pack_Grouping::META_GROUP_ID ]   = $group_id;
			$cart_item_meta[ Pack_Grouping::META_GROUP_ROLE ] = Pack_Grouping::ROLE_CHILD;
			$cart_item_meta['_tcbf_gf_entry_id'] = $group_id;
		}

		if ( isset( $rental_item['_tcbf_participant_name'] ) ) {
			$cart_item_meta['_tcbf_participant_name'] = $rental_item['_tcbf_participant_name'];
		}

		$added = $cart->add_to_cart( $transport_product_id, 1, 0, [], $cart_item_meta );

		if ( ! $added ) {
			return new \WP_Error( 'add_failed', 'Failed to add transport to cart' );
		}

		Logger::log( 'transport.cart.added', [
			'rental_key' => $rental_cart_key,
			'cart_key'   => $added,
			'direction'  => $direction,
			'type'       => $type,
			'price'      => $quote['per_bike_price'],
			'zone'       => $address_data['zone_name'] ?? '',
		] );

		return [
			'price'     => $quote['per_bike_price'],
			'zone_name' => $address_data['zone_name'] ?? '',
			'cart_key'  => $added,
		];
	}

	private static function remove_transport_item( string $rental_cart_key, string $direction ) : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( self::is_transport_item( $item )
				&& self::is_transport_for_rental( $item, $rental_cart_key )
				&& ( $item['_tcbf_transport_type'] ?? '' ) === $type
			) {
				$cart->remove_cart_item( $key );
				Logger::log( 'transport.cart.removed', [
					'rental_key' => $rental_cart_key,
					'cart_key'   => $key,
					'direction'  => $direction,
				] );
				break;
			}
		}
	}

	public static function cleanup_transport_on_removal( string $cart_item_key, $cart ) : void {

		if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$cart_contents = $cart->get_cart();
		if ( ! isset( $cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$removed_item = $cart_contents[ $cart_item_key ];

		if ( self::is_transport_item( $removed_item ) ) {
			return;
		}

		// Rental removed — find and remove ALL transport children (both directions)
		foreach ( $cart_contents as $key => $item ) {
			if ( $key === $cart_item_key ) {
				continue;
			}
			if ( self::is_transport_item( $item ) && self::is_transport_for_rental( $item, $cart_item_key ) ) {
				$cart->remove_cart_item( $key );
				Logger::log( 'transport.cart.cleanup.child_removed', [
					'rental_key'    => $cart_item_key,
					'transport_key' => $key,
					'type'          => $item['_tcbf_transport_type'] ?? '',
				] );
			}
		}
	}

	/* ================================================================
	 * Session management (per-direction)
	 * ================================================================ */

	public static function get_direction_address( string $direction ) : ?array {
		$key = ( $direction === self::DIR_PICKUP ) ? self::SESSION_RETURN_ADDRESS : self::SESSION_DELIVERY_ADDRESS;
		$data = self::get_session( $key );
		if ( ! is_array( $data ) || empty( $data['address'] ) ) {
			return null;
		}
		return $data;
	}

	private static function set_direction_address( string $direction, array $data ) : void {
		$key = ( $direction === self::DIR_PICKUP ) ? self::SESSION_RETURN_ADDRESS : self::SESSION_DELIVERY_ADDRESS;
		self::set_session( $key, $data );
	}

	public static function get_direction_window( string $direction ) : string {
		$key = ( $direction === self::DIR_PICKUP ) ? self::SESSION_RETURN_WINDOW : self::SESSION_DELIVERY_WINDOW;
		return (string) ( self::get_session( $key ) ?? 'morning' );
	}

	private static function set_direction_window( string $direction, string $window ) : void {
		$key = ( $direction === self::DIR_PICKUP ) ? self::SESSION_RETURN_WINDOW : self::SESSION_DELIVERY_WINDOW;
		self::set_session( $key, $window );
	}

	private static function get_session( string $key ) {
		if ( ! WC() || ! WC()->session ) {
			return null;
		}
		return WC()->session->get( $key );
	}

	private static function set_session( string $key, $value ) : void {
		if ( ! WC() || ! WC()->session ) {
			return;
		}
		WC()->session->set( $key, $value );
	}

	private static function clear_all_direction_sessions() : void {
		self::set_session( self::SESSION_DELIVERY_ADDRESS, null );
		self::set_session( self::SESSION_DELIVERY_WINDOW, null );
		self::set_session( self::SESSION_RETURN_ADDRESS, null );
		self::set_session( self::SESSION_RETURN_WINDOW, null );
		self::set_session( self::SESSION_LINK_RETURN, null );
	}

	public static function clear_transport_session() : void {
		self::clear_all_direction_sessions();
	}

	/* ================================================================
	 * Cart pricing
	 * ================================================================ */

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
	 * Recalculate prices for all transport items of a specific direction
	 */
	private static function recalculate_direction_prices( string $direction ) : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';
		$address = self::get_direction_address( $direction );

		if ( ! $address ) {
			return;
		}

		$bike_qty = self::count_direction_bikes( $direction );
		if ( $bike_qty <= 0 ) {
			return;
		}

		$quote = self::calculate_direction_quote( $address, $direction, $bike_qty );
		$per_bike = $quote['per_bike_price'];

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_transport_item( $item ) ) {
				continue;
			}
			if ( ( $item['_tcbf_transport_type'] ?? '' ) !== $type ) {
				continue;
			}

			$cart->cart_contents[ $key ]['_tcbf_transport_price']      = $per_bike;
			$cart->cart_contents[ $key ]['_tcbf_transport_address']    = $address['address'] ?? '';
			$cart->cart_contents[ $key ]['_tcbf_transport_lat']        = $address['lat'] ?? 0;
			$cart->cart_contents[ $key ]['_tcbf_transport_lng']        = $address['lng'] ?? 0;
			$cart->cart_contents[ $key ]['_tcbf_transport_zone_id']    = $address['zone_id'] ?? '';
			$cart->cart_contents[ $key ]['_tcbf_transport_zone_name']  = $address['zone_name'] ?? '';
			$cart->cart_contents[ $key ]['_tcbf_transport_quote_json'] = wp_json_encode( $quote );

			if ( isset( $cart->cart_contents[ $key ]['booking'] ) ) {
				$cart->cart_contents[ $key ]['booking'][ \TC_BF\Plugin::BK_CUSTOM_COST ] = wc_format_decimal( $per_bike, 2 );
			}
		}
	}

	private static function update_transport_item_meta( string $rental_cart_key, string $direction, array $address_data, string $window, string $service_date ) : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( self::is_transport_item( $item )
				&& self::is_transport_for_rental( $item, $rental_cart_key )
				&& ( $item['_tcbf_transport_type'] ?? '' ) === $type
			) {
				$cart->cart_contents[ $key ]['_tcbf_transport_address']      = $address_data['address'] ?? '';
				$cart->cart_contents[ $key ]['_tcbf_transport_lat']          = $address_data['lat'] ?? 0;
				$cart->cart_contents[ $key ]['_tcbf_transport_lng']          = $address_data['lng'] ?? 0;
				$cart->cart_contents[ $key ]['_tcbf_transport_place_id']     = $address_data['place_id'] ?? '';
				$cart->cart_contents[ $key ]['_tcbf_transport_zone_id']      = $address_data['zone_id'] ?? '';
				$cart->cart_contents[ $key ]['_tcbf_transport_zone_name']    = $address_data['zone_name'] ?? '';
				$cart->cart_contents[ $key ]['_tcbf_transport_window']       = $window;
				$cart->cart_contents[ $key ]['_tcbf_transport_service_date'] = $service_date;
				break;
			}
		}
	}

	private static function calculate_direction_quote( array $address_data, string $direction, int $bike_qty = 0 ) : array {

		if ( $bike_qty <= 0 ) {
			$bike_qty = max( 1, self::count_direction_bikes( $direction ) );
		}

		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';

		return TransportPricing::calculate_quote( [
			'type'        => $type,
			'dropoff_lat' => ( $type === 'delivery' ) ? (float) ( $address_data['lat'] ?? 0 ) : 0,
			'dropoff_lng' => ( $type === 'delivery' ) ? (float) ( $address_data['lng'] ?? 0 ) : 0,
			'pickup_lat'  => ( $type === 'pickup' ) ? (float) ( $address_data['lat'] ?? 0 ) : 0,
			'pickup_lng'  => ( $type === 'pickup' ) ? (float) ( $address_data['lng'] ?? 0 ) : 0,
			'bike_qty'    => $bike_qty,
		] );
	}

	/* ================================================================
	 * Cart display
	 * ================================================================ */

	public static function display_transport_cart_item_data( array $item_data, array $cart_item ) : array {

		if ( ! self::is_transport_item( $cart_item ) ) {
			return $item_data;
		}

		$item_data = [];

		$transport_type = $cart_item['_tcbf_transport_type'] ?? 'delivery';
		$direction_label = ( $transport_type === 'pickup' )
			? Woo::translate( '[:en]Return pickup[:es]Recogida de devolución[:]' )
			: Woo::translate( '[:en]Delivery[:es]Entrega[:]' );

		$item_data[] = [
			'name'  => Woo::translate( '[:en]Service[:es]Servicio[:]' ),
			'value' => $direction_label,
		];

		$address = $cart_item['_tcbf_transport_address'] ?? '';
		if ( $address !== '' ) {
			$display_address = mb_strlen( $address ) > 50 ? mb_substr( $address, 0, 47 ) . '...' : $address;
			$item_data[] = [
				'name'  => Woo::translate( '[:en]Address[:es]Dirección[:]' ),
				'value' => $display_address,
			];
		}

		$zone_name = $cart_item['_tcbf_transport_zone_name'] ?? '';
		if ( $zone_name !== '' ) {
			$item_data[] = [
				'name'  => Woo::translate( '[:en]Zone[:es]Zona[:]' ),
				'value' => $zone_name,
			];
		}

		$window = $cart_item['_tcbf_transport_window'] ?? '';
		if ( $window !== '' ) {
			$window_label = ( $window === 'morning' )
				? Woo::translate( '[:en]Morning[:es]Mañana[:]' )
				: Woo::translate( '[:en]Afternoon[:es]Tarde[:]' );
			$item_data[] = [
				'name'  => Woo::translate( '[:en]Window[:es]Horario[:]' ),
				'value' => $window_label,
			];
		}

		$service_date = $cart_item['_tcbf_transport_service_date'] ?? '';
		if ( $service_date !== '' ) {
			$item_data[] = [
				'name'  => Woo::translate( '[:en]Date[:es]Fecha[:]' ),
				'value' => $service_date,
			];
		}

		return $item_data;
	}

	/**
	 * Render transport toggles after cart item names
	 */
	public static function render_transport_toggle( array $cart_item, string $cart_item_key ) : void {

		if ( ! is_cart() ) {
			return;
		}

		if ( ! self::is_transport_eligible( $cart_item ) ) {
			return;
		}

		$transport_product_id = TransportPricing::get_transport_product_id();
		if ( $transport_product_id <= 0 ) {
			return;
		}

		$delivery_address = self::get_direction_address( self::DIR_DELIVERY );
		$return_address   = self::get_direction_address( self::DIR_PICKUP );

		// Delivery toggle
		self::render_single_toggle( $cart_item_key, self::DIR_DELIVERY, $delivery_address );

		// Return toggle
		self::render_single_toggle( $cart_item_key, self::DIR_PICKUP, $return_address );
	}

	private static function render_single_toggle( string $cart_item_key, string $direction, ?array $address ) : void {

		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';
		$has_transport = self::rental_has_transport( $cart_item_key, $direction );
		$checked = $has_transport ? 'checked' : '';

		$label = ( $direction === self::DIR_DELIVERY )
			? Woo::translate( '[:en]Bike delivery[:es]Entrega de bicicleta[:]' )
			: Woo::translate( '[:en]Bike return[:es]Devolución de bicicleta[:]' );

		$address_display = '';
		$price_display = '';

		if ( $has_transport && $address ) {
			$address_display = esc_html( $address['address'] ?? '' );

			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::is_transport_item( $item )
					&& self::is_transport_for_rental( $item, $cart_item_key )
					&& ( $item['_tcbf_transport_type'] ?? '' ) === $type
				) {
					$price = (float) ( $item['_tcbf_transport_price'] ?? 0 );
					if ( $price > 0 ) {
						$price_display = wp_strip_all_tags( wc_price( $price ) );
					}
					break;
				}
			}
		}

		printf(
			'<div class="tcbf-transport-toggle" data-cart-key="%s" data-direction="%s">' .
			'<label class="tcbf-transport-toggle__label">' .
			'<input type="checkbox" class="tcbf-transport-toggle__input" data-cart-key="%s" data-direction="%s" %s />' .
			'<span class="tcbf-transport-toggle__slider"></span>' .
			'<span class="tcbf-transport-toggle__text">%s</span>' .
			'</label>' .
			'<span class="tcbf-transport-toggle__price" %s>%s</span>' .
			'<span class="tcbf-transport-toggle__address" %s>%s</span>' .
			'</div>',
			esc_attr( $cart_item_key ),
			esc_attr( $direction ),
			esc_attr( $cart_item_key ),
			esc_attr( $direction ),
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
			'_tcbf_transport_place_id',
			'_tcbf_transport_zone_id',
			'_tcbf_transport_zone_name',
			'_tcbf_transport_price',
			'_tcbf_transport_parent_key',
			'_tcbf_transport_type',
			'_tcbf_transport_service_date',
			'_tcbf_transport_window',
			'_tcbf_transport_quote_json',
		];

		foreach ( $meta_keys as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$item->add_meta_data( $key, $values[ $key ], true );
			}
		}
	}

	public static function persist_transport_order_address( $order, $data ) : void {

		if ( ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		// Delivery address
		$del_addr = self::get_direction_address( self::DIR_DELIVERY );
		if ( $del_addr ) {
			$order->update_meta_data( '_tcbf_transport_delivery_address', $del_addr['address'] ?? '' );
			$order->update_meta_data( '_tcbf_transport_delivery_lat', $del_addr['lat'] ?? 0 );
			$order->update_meta_data( '_tcbf_transport_delivery_lng', $del_addr['lng'] ?? 0 );
			$order->update_meta_data( '_tcbf_transport_delivery_zone_id', $del_addr['zone_id'] ?? '' );
			$order->update_meta_data( '_tcbf_transport_delivery_zone_name', $del_addr['zone_name'] ?? '' );
			$order->update_meta_data( '_tcbf_transport_delivery_window', self::get_direction_window( self::DIR_DELIVERY ) );
		}

		// Return address
		$ret_addr = self::get_direction_address( self::DIR_PICKUP );
		if ( $ret_addr ) {
			$order->update_meta_data( '_tcbf_transport_return_address', $ret_addr['address'] ?? '' );
			$order->update_meta_data( '_tcbf_transport_return_lat', $ret_addr['lat'] ?? 0 );
			$order->update_meta_data( '_tcbf_transport_return_lng', $ret_addr['lng'] ?? 0 );
			$order->update_meta_data( '_tcbf_transport_return_zone_id', $ret_addr['zone_id'] ?? '' );
			$order->update_meta_data( '_tcbf_transport_return_zone_name', $ret_addr['zone_name'] ?? '' );
			$order->update_meta_data( '_tcbf_transport_return_window', self::get_direction_window( self::DIR_PICKUP ) );
		}

		// Count bikes per direction
		$delivery_count = 0;
		$return_count = 0;
		if ( WC() && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::is_transport_item( $item ) ) {
					$t = $item['_tcbf_transport_type'] ?? '';
					if ( $t === 'delivery' ) $delivery_count++;
					if ( $t === 'pickup' ) $return_count++;
				}
			}
		}
		$order->update_meta_data( '_tcbf_transport_delivery_bike_count', $delivery_count );
		$order->update_meta_data( '_tcbf_transport_return_bike_count', $return_count );
		$order->update_meta_data( '_tcbf_has_transport', ( $delivery_count + $return_count > 0 ) ? '1' : '0' );
	}

	/* ================================================================
	 * Checkout availability validation
	 * ================================================================ */

	public static function validate_transport_availability() : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		// Group transport items by (service_date, window) and count
		$slots = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! self::is_transport_item( $item ) ) {
				continue;
			}
			$date   = $item['_tcbf_transport_service_date'] ?? '';
			$window = $item['_tcbf_transport_window'] ?? '';
			if ( $date === '' || $window === '' ) {
				continue;
			}
			$slot_key = $date . '|' . $window;
			if ( ! isset( $slots[ $slot_key ] ) ) {
				$slots[ $slot_key ] = [ 'date' => $date, 'window' => $window, 'count' => 0 ];
			}
			$slots[ $slot_key ]['count']++;
		}

		foreach ( $slots as $slot ) {
			if ( ! TransportAvailability::checkout_check( $slot['date'], $slot['window'], $slot['count'] ) ) {
				$remaining = TransportAvailability::remaining_capacity( $slot['date'], $slot['window'] );
				wc_add_notice(
					sprintf(
						'Transport capacity exceeded for %s %s. Only %d bike slots remaining (you have %d in cart).',
						$slot['date'],
						$slot['window'],
						$remaining,
						$slot['count']
					),
					'error'
				);
			}
		}
	}

	/* ================================================================
	 * Frontend assets
	 * ================================================================ */

	public static function enqueue_assets() : void {

		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		$transport_product_id = TransportPricing::get_transport_product_id();
		if ( $transport_product_id <= 0 ) {
			return;
		}

		$google_maps_key = TransportPricing::get_google_maps_key();

		if ( $google_maps_key !== '' ) {
			wp_enqueue_script(
				'google-maps-places',
				'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $google_maps_key ) . '&libraries=places',
				[],
				null,
				true
			);
		}

		$deps = [ 'jquery' ];
		if ( $google_maps_key !== '' ) {
			$deps[] = 'google-maps-places';
		}

		wp_enqueue_script(
			'tcbf-transport',
			TC_BF_URL . 'assets/js/tcbf-transport.js',
			$deps,
			TC_BF_VERSION,
			true
		);

		$delivery_address = self::get_direction_address( self::DIR_DELIVERY );
		$return_address   = self::get_direction_address( self::DIR_PICKUP );

		wp_localize_script( 'tcbf-transport', 'tcbfTransport', [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'tcbf_transport_nonce' ),
			'hasMapsKey'      => $google_maps_key !== '',
			'deliveryAddress' => $delivery_address ?: null,
			'deliveryWindow'  => self::get_direction_window( self::DIR_DELIVERY ),
			'returnAddress'   => $return_address ?: null,
			'returnWindow'    => self::get_direction_window( self::DIR_PICKUP ),
			'linkReturn'      => (int) ( self::get_session( self::SESSION_LINK_RETURN ) ?? 0 ),
			'i18n'            => [
				'deliveryTitle'    => Woo::translate( '[:en]Delivery Address[:es]Dirección de entrega[:]' ),
				'returnTitle'      => Woo::translate( '[:en]Return Pickup Address[:es]Dirección de recogida[:]' ),
				'addressLabel'     => Woo::translate( '[:en]Enter address[:es]Ingrese la dirección[:]' ),
				'windowLabel'      => Woo::translate( '[:en]Time window[:es]Horario[:]' ),
				'windowMorning'    => Woo::translate( '[:en]Morning[:es]Mañana[:]' ),
				'windowAfternoon'  => Woo::translate( '[:en]Afternoon[:es]Tarde[:]' ),
				'confirmBtn'       => Woo::translate( '[:en]Confirm[:es]Confirmar[:]' ),
				'cancelBtn'        => Woo::translate( '[:en]Cancel[:es]Cancelar[:]' ),
				'quoteLabel'       => Woo::translate( '[:en]Transport cost[:es]Coste de transporte[:]' ),
				'perBikeLabel'     => Woo::translate( '[:en]per bike[:es]por bicicleta[:]' ),
				'zoneLabel'        => Woo::translate( '[:en]Zone[:es]Zona[:]' ),
				'outsideZones'     => Woo::translate( '[:en]Outside service zones[:es]Fuera de zonas de servicio[:]' ),
				'loading'          => Woo::translate( '[:en]Calculating...[:es]Calculando...[:]' ),
				'errorGeneric'     => Woo::translate( '[:en]Something went wrong. Please try again.[:es]Algo salió mal. Inténtalo de nuevo.[:]' ),
				'linkReturnLabel'  => Woo::translate( '[:en]Use same address for return[:es]Usar misma dirección para devolución[:]' ),
				'availabilityLabel'=> Woo::translate( '[:en]Available slots[:es]Plazas disponibles[:]' ),
				'geocoding'        => Woo::translate( '[:en]Looking up address...[:es]Buscando dirección...[:]' ),
				'geocodeFailed'    => Woo::translate( '[:en]Could not find that address. Please try a different one.[:es]No se encontró esa dirección. Pruebe con otra.[:]' ),
			],
		] );

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

	public static function is_transport_eligible( array $cart_item ) : bool {

		if ( empty( $cart_item['booking'] ) || ! is_array( $cart_item['booking'] ) ) {
			return false;
		}

		$transport_pid = TransportPricing::get_transport_product_id();
		if ( $transport_pid > 0 && ( (int) ( $cart_item['product_id'] ?? 0 ) ) === $transport_pid ) {
			return false;
		}

		if ( self::is_transport_item( $cart_item ) ) {
			return false;
		}

		$scope = Pack_Grouping::get_scope( $cart_item );
		if ( $scope === 'participation' ) {
			return false;
		}

		return true;
	}

	private static function is_transport_for_rental( array $transport_item, string $rental_cart_key ) : bool {
		return isset( $transport_item['_tcbf_transport_parent_key'] )
			&& $transport_item['_tcbf_transport_parent_key'] === $rental_cart_key;
	}

	public static function rental_has_transport( string $rental_cart_key, string $direction = '' ) : bool {

		if ( $rental_cart_key === '' || ! WC() || ! WC()->cart ) {
			return false;
		}

		$type = '';
		if ( $direction !== '' ) {
			$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! self::is_transport_item( $item ) || ! self::is_transport_for_rental( $item, $rental_cart_key ) ) {
				continue;
			}
			if ( $type === '' ) {
				return true;
			}
			if ( ( $item['_tcbf_transport_type'] ?? '' ) === $type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count bikes with transport toggled on for a direction
	 */
	private static function count_direction_bikes( string $direction ) : int {

		if ( ! WC() || ! WC()->cart ) {
			return 0;
		}

		$type = ( $direction === self::DIR_PICKUP ) ? 'pickup' : 'delivery';
		$count = 0;

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_transport_item( $item ) && ( $item['_tcbf_transport_type'] ?? '' ) === $type ) {
				$count++;
			}
		}

		return $count;
	}

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
	 * Derive service date from rental item
	 *
	 * For delivery: service_date = rental start date
	 * For return/pickup: service_date = rental end date
	 *
	 * Falls back to booking start_date/end_date from WC Bookings,
	 * or event date from TCBF event meta.
	 */
	private static function derive_service_date( array $rental_item, string $direction ) : string {

		$booking = isset( $rental_item['booking'] ) ? (array) $rental_item['booking'] : [];

		// Try WC Bookings date fields
		$start_year  = $booking['wc_bookings_field_start_date_year'] ?? '';
		$start_month = $booking['wc_bookings_field_start_date_month'] ?? '';
		$start_day   = $booking['wc_bookings_field_start_date_day'] ?? '';

		if ( $start_year && $start_month && $start_day ) {
			$start_date = sprintf( '%04d-%02d-%02d', (int) $start_year, (int) $start_month, (int) $start_day );

			if ( $direction === self::DIR_DELIVERY ) {
				return $start_date;
			}

			// For return, try to find end date
			// WC Bookings typically uses duration from start
			// If no explicit end, use start + 1 day as fallback
			$end_year  = $booking['wc_bookings_field_end_date_year'] ?? '';
			$end_month = $booking['wc_bookings_field_end_date_month'] ?? '';
			$end_day   = $booking['wc_bookings_field_end_date_day'] ?? '';

			if ( $end_year && $end_month && $end_day ) {
				return sprintf( '%04d-%02d-%02d', (int) $end_year, (int) $end_month, (int) $end_day );
			}

			// Fallback: last day of rental = start + (duration - 1) days
			// Duration=1 means single-day rental → return on same day as start
			$duration = isset( $booking['wc_bookings_field_duration'] ) ? max( 1, (int) $booking['wc_bookings_field_duration'] ) : 1;
			$end_ts = strtotime( $start_date ) + ( ( $duration - 1 ) * DAY_IN_SECONDS );
			return date( 'Y-m-d', $end_ts );
		}

		// Try event start timestamp
		$event_ts = isset( $booking[\TC_BF\Plugin::BK_EB_EVENT_TS] ) ? (int) $booking[\TC_BF\Plugin::BK_EB_EVENT_TS] : 0;
		if ( $event_ts > 0 ) {
			if ( $direction === self::DIR_DELIVERY ) {
				return date( 'Y-m-d', $event_ts );
			}
			// Events are typically multi-day; use event_ts + 1 as fallback for return
			return date( 'Y-m-d', $event_ts + DAY_IN_SECONDS );
		}

		return '';
	}

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
