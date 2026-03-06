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

		// AJAX: bulk configure transport for selected bikes
		add_action( 'wp_ajax_tcbf_transport_bulk_configure', [ __CLASS__, 'ajax_bulk_configure' ] );
		add_action( 'wp_ajax_nopriv_tcbf_transport_bulk_configure', [ __CLASS__, 'ajax_bulk_configure' ] );

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

		// Cart-level service card (after cart table)
		add_action( 'woocommerce_after_cart_table', [ __CLASS__, 'render_transport_service_card' ], 10 );

		// Per-bike compact status indicators
		add_action( 'woocommerce_after_cart_item_name', [ __CLASS__, 'render_transport_indicator' ], 20, 2 );

		// Cleanup on parent removal
		add_action( 'woocommerce_remove_cart_item', [ __CLASS__, 'cleanup_transport_on_removal' ], 3, 2 );

		// Clear session on cart empty
		add_action( 'woocommerce_cart_emptied', [ __CLASS__, 'clear_transport_session' ], 5 );
	}

	/* ================================================================
	 * AJAX: Bulk configure transport for selected bikes
	 *
	 * Single endpoint that handles add/update/remove of transport items
	 * for any combination of delivery + pickup across selected bikes.
	 * ================================================================ */

	public static function ajax_bulk_configure() : void {

		// Clean any prior output (PHP notices/warnings) to ensure valid JSON response
		if ( ob_get_level() ) {
			ob_clean();
		}

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		if ( ! WC() || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'Cart not available' ] );
		}

		$cart = WC()->cart;

		// Date uniformity gate
		$dates = self::get_rental_dates_uniformity();
		$enable_delivery = ! empty( $_POST['enable_delivery'] );
		$enable_pickup   = ! empty( $_POST['enable_pickup'] );
		if ( ( $enable_delivery || $enable_pickup ) && ! $dates['is_uniform'] ) {
			wp_send_json_error( [
				'message' => Woo::translate( '[:en]Transport is only available when all bikes in the cart have the same rental dates. Please place separate orders for bikes with different dates.[:es]El transporte solo está disponible cuando todas las bicicletas del carrito tienen las mismas fechas de alquiler. Haz pedidos separados para bicicletas con fechas distintas.[:]' ),
				'code'    => 'mixed_dates',
			] );
		}

		// Parse input
		$enable_delivery = ! empty( $_POST['enable_delivery'] );
		$enable_pickup   = ! empty( $_POST['enable_pickup'] );

		$delivery_address_text = isset( $_POST['delivery_address'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_address'] ) ) : '';
		$delivery_lat          = isset( $_POST['delivery_lat'] ) ? (float) $_POST['delivery_lat'] : 0.0;
		$delivery_lng          = isset( $_POST['delivery_lng'] ) ? (float) $_POST['delivery_lng'] : 0.0;
		$delivery_place_id     = isset( $_POST['delivery_place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_place_id'] ) ) : '';
		$delivery_window       = isset( $_POST['delivery_window'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_window'] ) ) : 'morning';

		$same_address          = ! empty( $_POST['same_address'] );

		$pickup_address_text   = isset( $_POST['pickup_address'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_address'] ) ) : '';
		$pickup_lat            = isset( $_POST['pickup_lat'] ) ? (float) $_POST['pickup_lat'] : 0.0;
		$pickup_lng            = isset( $_POST['pickup_lng'] ) ? (float) $_POST['pickup_lng'] : 0.0;
		$pickup_place_id       = isset( $_POST['pickup_place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_place_id'] ) ) : '';
		$pickup_window         = isset( $_POST['pickup_window'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_window'] ) ) : 'morning';

		// Selected bike cart keys (JSON array)
		$bike_keys_raw = isset( $_POST['bike_keys'] ) ? wp_unslash( $_POST['bike_keys'] ) : '[]';
		$bike_keys     = json_decode( $bike_keys_raw, true );
		if ( ! is_array( $bike_keys ) ) {
			$bike_keys = [];
		}

		// Validate windows
		if ( ! in_array( $delivery_window, [ 'morning', 'afternoon' ], true ) ) {
			$delivery_window = 'morning';
		}
		if ( ! in_array( $pickup_window, [ 'morning', 'afternoon' ], true ) ) {
			$pickup_window = 'morning';
		}

		// Build address data for delivery
		$delivery_addr = null;
		if ( $enable_delivery ) {
			if ( $delivery_address_text === '' || ( $delivery_lat == 0.0 && $delivery_lng == 0.0 ) ) {
				wp_send_json_error( [ 'message' => 'Delivery address is required' ] );
			}
			$zone = TransportZones::resolve_zone( $delivery_lat, $delivery_lng );
			$delivery_addr = [
				'address'   => $delivery_address_text,
				'lat'       => $delivery_lat,
				'lng'       => $delivery_lng,
				'place_id'  => $delivery_place_id,
				'zone_id'   => $zone ? ( $zone['id'] ?? '' ) : '',
				'zone_name' => $zone ? ( $zone['name'] ?? '' ) : '',
			];
		}

		// Build address data for pickup
		$pickup_addr = null;
		if ( $enable_pickup ) {
			if ( $same_address && $delivery_addr ) {
				$pickup_addr = $delivery_addr;
			} else {
				if ( $pickup_address_text === '' || ( $pickup_lat == 0.0 && $pickup_lng == 0.0 ) ) {
					wp_send_json_error( [ 'message' => 'Pickup address is required' ] );
				}
				$zone = TransportZones::resolve_zone( $pickup_lat, $pickup_lng );
				$pickup_addr = [
					'address'   => $pickup_address_text,
					'lat'       => $pickup_lat,
					'lng'       => $pickup_lng,
					'place_id'  => $pickup_place_id,
					'zone_id'   => $zone ? ( $zone['id'] ?? '' ) : '',
					'zone_name' => $zone ? ( $zone['name'] ?? '' ) : '',
				];
			}
		}

		// Determine which bikes to operate on
		// If no bike_keys provided, default to all eligible bikes
		$eligible_keys = self::get_eligible_bike_keys();
		if ( empty( $bike_keys ) ) {
			$bike_keys = $eligible_keys;
		} else {
			$bike_keys = array_intersect( $bike_keys, $eligible_keys );
		}

		if ( empty( $bike_keys ) ) {
			wp_send_json_error( [ 'message' => 'No eligible bikes in cart' ] );
		}

		// Step 1: Remove all existing transport items for selected bikes
		self::remove_transport_for_bikes( $bike_keys );

		// Step 2: Store session data
		if ( $delivery_addr ) {
			self::set_direction_address( self::DIR_DELIVERY, $delivery_addr );
			self::set_direction_window( self::DIR_DELIVERY, $delivery_window );
		}
		if ( $pickup_addr ) {
			self::set_direction_address( self::DIR_PICKUP, $pickup_addr );
			self::set_direction_window( self::DIR_PICKUP, $pickup_window );
		}
		self::set_session( self::SESSION_LINK_RETURN, $same_address ? 1 : 0 );

		// Step 3: Add transport items for each selected bike + enabled direction
		$errors = [];

		if ( $enable_delivery && $delivery_addr ) {
			// Check capacity for delivery
			$sample_item = $cart->get_cart_item( $bike_keys[0] );
			$service_date = $sample_item ? self::derive_service_date( $sample_item, self::DIR_DELIVERY ) : '';

			if ( $service_date && $delivery_window ) {
				$capacity    = TransportAvailability::get_capacity_for_window( $delivery_window );
				$in_orders   = TransportAvailability::count_booked_in_orders( $service_date, $delivery_window );
				$available   = max( 0, $capacity - $in_orders );
				if ( count( $bike_keys ) > $available ) {
					$errors[] = sprintf( 'Delivery: only %d slots available for %s %s (requested %d).', $available, $service_date, $delivery_window, count( $bike_keys ) );
				}
			}

			if ( empty( $errors ) ) {
				foreach ( $bike_keys as $key ) {
					$rental = $cart->get_cart_item( $key );
					if ( ! $rental ) continue;
					$sdate = self::derive_service_date( $rental, self::DIR_DELIVERY );
					$result = self::add_transport_item( $key, $rental, $delivery_addr, self::DIR_DELIVERY, $delivery_window, $sdate );
					if ( is_wp_error( $result ) ) {
						$errors[] = $result->get_error_message();
						break;
					}
				}
			}
		}

		if ( $enable_pickup && $pickup_addr && empty( $errors ) ) {
			$sample_item = $cart->get_cart_item( $bike_keys[0] );
			$service_date = $sample_item ? self::derive_service_date( $sample_item, self::DIR_PICKUP ) : '';

			if ( $service_date && $pickup_window ) {
				$capacity    = TransportAvailability::get_capacity_for_window( $pickup_window );
				$in_orders   = TransportAvailability::count_booked_in_orders( $service_date, $pickup_window );
				$available   = max( 0, $capacity - $in_orders );
				if ( count( $bike_keys ) > $available ) {
					$errors[] = sprintf( 'Pickup: only %d slots available for %s %s (requested %d).', $available, $service_date, $pickup_window, count( $bike_keys ) );
				}
			}

			if ( empty( $errors ) ) {
				foreach ( $bike_keys as $key ) {
					$rental = $cart->get_cart_item( $key );
					if ( ! $rental ) continue;
					$sdate = self::derive_service_date( $rental, self::DIR_PICKUP );
					$result = self::add_transport_item( $key, $rental, $pickup_addr, self::DIR_PICKUP, $pickup_window, $sdate );
					if ( is_wp_error( $result ) ) {
						$errors[] = $result->get_error_message();
						break;
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			// Rollback: remove any transport items we just added
			self::remove_transport_for_bikes( $bike_keys );
			wp_send_json_error( [ 'message' => implode( ' ', $errors ) ] );
		}

		// Step 4: Recalculate prices
		if ( $enable_delivery ) {
			self::recalculate_direction_prices( self::DIR_DELIVERY );
		}
		if ( $enable_pickup ) {
			self::recalculate_direction_prices( self::DIR_PICKUP );
		}

		if ( ! $enable_delivery && ! $enable_pickup ) {
			self::clear_all_direction_sessions();
		}

		// Build summary for response
		$summary = self::get_transport_service_summary();

		wp_send_json_success( [
			'action'    => 'configured',
			'summary'   => $summary,
			'fragments' => self::get_cart_fragments(),
		] );
	}

	/* ================================================================
	 * AJAX: Get quote preview
	 * ================================================================ */

	public static function ajax_get_quote() : void {

		if ( ob_get_level() ) {
			ob_clean();
		}

		check_ajax_referer( 'tcbf_transport_nonce', 'nonce' );

		// Date uniformity gate
		$dates = self::get_rental_dates_uniformity();
		if ( ! $dates['is_uniform'] ) {
			wp_send_json_error( [ 'message' => 'Transport unavailable for mixed-date carts', 'code' => 'mixed_dates' ] );
		}

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

		if ( ob_get_level() ) {
			ob_clean();
		}

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

	/* ================================================================
	 * Cart-level service card
	 * ================================================================ */

	public static function render_transport_service_card() : void {

		if ( ! is_cart() ) {
			return;
		}

		$transport_product_id = TransportPricing::get_transport_product_id();
		if ( $transport_product_id <= 0 ) {
			return;
		}

		$eligible_count = self::count_eligible_bikes();
		if ( $eligible_count <= 0 ) {
			return;
		}

		$state   = self::get_transport_service_state();
		$summary = self::get_transport_service_summary();

		$state_class  = 'tcbf-service-card--' . $state;

		echo '<div class="tcbf-service-card ' . esc_attr( $state_class ) . '" id="tcbf-service-card">';

		echo '<div class="tcbf-service-card__header">';
		echo '<div class="tcbf-service-card__icon">';
		echo ( $state === 'configured' ) ? '&#10003;' : '&#128690;';
		echo '</div>';
		echo '<div class="tcbf-service-card__title-wrap">';
		echo '<h3 class="tcbf-service-card__title">';
		echo esc_html( Woo::translate( '[:en]Bike Transport[:es]Transporte de bicicletas[:]' ) );
		echo '</h3>';
		echo '<span class="tcbf-service-card__subtitle">';

		switch ( $state ) {
			case 'not_configured':
				echo esc_html( Woo::translate( '[:en]Have your bikes delivered to your accommodation[:es]Recibe tus bicicletas en tu alojamiento[:]' ) );
				break;
			case 'partial':
				$configured = (int) ( $summary['delivery_count'] ?? 0 );
				$pickup_count = (int) ( $summary['pickup_count'] ?? 0 );
				$total = max( $configured, $pickup_count );
				echo esc_html( sprintf(
					Woo::translate( '[:en]%d of %d bikes configured[:es]%d de %d bicicletas configuradas[:]' ),
					$total,
					$eligible_count
				) );
				break;
			case 'configured':
				echo esc_html( self::format_summary_line( $summary ) );
				break;
			case 'mismatch':
				echo esc_html( Woo::translate( '[:en]Configuration needs updating[:es]La configuración necesita actualización[:]' ) );
				break;
			case 'mixed_dates':
				echo esc_html( Woo::translate( '[:en]Transport is only available when all bikes in the cart have the same rental dates.[:es]El transporte solo está disponible cuando todas las bicicletas del carrito tienen las mismas fechas de alquiler.[:]' ) );
				break;
			case 'invalid_dates':
				echo esc_html( Woo::translate( '[:en]The current transport configuration is no longer valid because the cart contains bikes with different rental dates.[:es]La configuración de transporte ya no es válida porque el carrito contiene bicicletas con fechas distintas.[:]' ) );
				break;
		}

		echo '</span>';
		echo '</div>'; // title-wrap

		// Price on the right if configured
		if ( $state === 'configured' || $state === 'partial' ) {
			$total_price = self::get_total_transport_price();
			if ( $total_price > 0 ) {
				echo '<span class="tcbf-service-card__price">' . wp_kses_post( wc_price( $total_price ) ) . '</span>';
			}
		}

		echo '</div>'; // header

		// Help text for date-related states
		if ( $state === 'mixed_dates' || $state === 'invalid_dates' ) {
			echo '<p class="tcbf-service-card__help">';
			echo esc_html( Woo::translate( '[:en]If you want to add delivery or pickup for bikes with different dates, please place separate orders.[:es]Si quieres añadir entrega o recogida para bicicletas con fechas distintas, haz pedidos separados.[:]' ) );
			echo '</p>';
		}

		// Action buttons
		echo '<div class="tcbf-service-card__actions">';
		if ( $state === 'mixed_dates' ) {
			// No action buttons — just informational
		} elseif ( $state === 'invalid_dates' ) {
			// Only allow removing transport
			echo '<button type="button" class="tcbf-service-card__btn tcbf-service-card__btn--remove" id="tcbf-remove-transport">';
			echo esc_html( Woo::translate( '[:en]Remove transport[:es]Eliminar transporte[:]' ) );
			echo '</button>';
		} elseif ( $state === 'not_configured' ) {
			echo '<button type="button" class="tcbf-service-card__btn tcbf-service-card__btn--primary" id="tcbf-configure-transport">';
			echo esc_html( Woo::translate( '[:en]Add transport[:es]Añadir transporte[:]' ) );
			echo '</button>';
		} else {
			echo '<button type="button" class="tcbf-service-card__btn tcbf-service-card__btn--secondary" id="tcbf-configure-transport">';
			echo esc_html( Woo::translate( '[:en]Edit transport[:es]Editar transporte[:]' ) );
			echo '</button>';
			echo '<button type="button" class="tcbf-service-card__btn tcbf-service-card__btn--remove" id="tcbf-remove-transport">';
			echo esc_html( Woo::translate( '[:en]Remove[:es]Eliminar[:]' ) );
			echo '</button>';
		}
		echo '</div>'; // actions

		echo '</div>'; // service-card
	}

	/**
	 * Compact per-bike status indicator.
	 *
	 * Shows ✓ for active directions and ✕ exclusion badges when the order has
	 * transport configured but this bike is excluded.
	 */
	public static function render_transport_indicator( array $cart_item, string $cart_item_key ) : void {

		if ( ! is_cart() ) {
			return;
		}

		if ( ! self::is_transport_eligible( $cart_item ) ) {
			return;
		}

		$has_delivery = self::rental_has_transport( $cart_item_key, self::DIR_DELIVERY );
		$has_pickup   = self::rental_has_transport( $cart_item_key, self::DIR_PICKUP );

		// Determine if the order has any transport at all (for exclusion badges)
		$order_delivery_count = self::count_direction_bikes( self::DIR_DELIVERY );
		$order_pickup_count   = self::count_direction_bikes( self::DIR_PICKUP );
		$order_has_transport  = ( $order_delivery_count + $order_pickup_count ) > 0;

		if ( ! $has_delivery && ! $has_pickup && ! $order_has_transport ) {
			return;
		}

		$parts = [];
		$excluded = false;

		// Delivery status
		if ( $has_delivery ) {
			$parts[] = Woo::translate( '[:en]Delivery[:es]Entrega[:]' ) . ' &#10003;';
		} elseif ( $order_delivery_count > 0 ) {
			$parts[] = '<span class="tcbf-transport-indicator__excluded">' . Woo::translate( '[:en]Delivery[:es]Entrega[:]' ) . ' &#10007;</span>';
			$excluded = true;
		}

		// Pickup status
		if ( $has_pickup ) {
			$parts[] = Woo::translate( '[:en]Pickup[:es]Recogida[:]' ) . ' &#10003;';
		} elseif ( $order_pickup_count > 0 ) {
			$parts[] = '<span class="tcbf-transport-indicator__excluded">' . Woo::translate( '[:en]Pickup[:es]Recogida[:]' ) . ' &#10007;</span>';
			$excluded = true;
		}

		if ( empty( $parts ) ) {
			return;
		}

		$css_class = 'tcbf-transport-indicator';
		if ( $excluded && ! $has_delivery && ! $has_pickup ) {
			$css_class .= ' tcbf-transport-indicator--excluded';
		}

		printf(
			'<div class="%s" data-cart-key="%s">%s</div>',
			esc_attr( $css_class ),
			esc_attr( $cart_item_key ),
			wp_kses_post( implode( ' <span class="tcbf-transport-indicator__sep">|</span> ', $parts ) )
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

		// Block checkout if transport items exist but dates are mixed
		if ( self::cart_has_any_transport() ) {
			$dates = self::get_rental_dates_uniformity();
			if ( ! $dates['is_uniform'] ) {
				wc_add_notice(
					Woo::translate( '[:en]The current transport configuration is no longer valid because the cart contains bikes with different rental dates. Please remove transport or adjust your cart.[:es]La configuración de transporte ya no es válida porque el carrito contiene bicicletas con fechas de alquiler distintas. Elimina el transporte o ajusta tu carrito.[:]' ),
					'error'
				);
				return;
			}
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

		$summary       = self::get_transport_service_summary();
		$dates_info    = self::get_rental_dates_uniformity();

		// Build bike list for JS checklist with enriched labels
		$bike_list = [];
		if ( WC() && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				if ( self::is_transport_eligible( $item ) ) {
					$label       = self::format_transport_bike_label( $item );
					$has_delivery = self::rental_has_transport( $key, self::DIR_DELIVERY );
					$has_pickup   = self::rental_has_transport( $key, self::DIR_PICKUP );
					$bike_list[] = [
						'key'          => $key,
						'model'        => $label['model'],
						'size'         => $label['size'],
						'start_date'   => $label['start_date'],
						'end_date'     => $label['end_date'],
						'rider'        => $label['rider'],
						'has_delivery' => $has_delivery,
						'has_pickup'   => $has_pickup,
					];
				}
			}
		}

		wp_localize_script( 'tcbf-transport', 'tcbfTransport', [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'tcbf_transport_nonce' ),
			'hasMapsKey'      => $google_maps_key !== '',
			'summary'         => $summary,
			'bikes'           => $bike_list,
			'datesUniform'    => $dates_info['is_uniform'],
			'i18n'            => [
				'modalTitle'       => Woo::translate( '[:en]Configure Bike Transport[:es]Configurar transporte de bicicletas[:]' ),
				'modeLabel'        => Woo::translate( '[:en]Transport service needed[:es]Servicio de transporte[:]' ),
				'modeDeliveryOnly' => Woo::translate( '[:en]Delivery only[:es]Solo entrega[:]' ),
				'modePickupOnly'   => Woo::translate( '[:en]Pickup only[:es]Solo recogida[:]' ),
				'modeBoth'         => Woo::translate( '[:en]Delivery + pickup[:es]Entrega + recogida[:]' ),
				'deliverySection'  => Woo::translate( '[:en]Delivery[:es]Entrega[:]' ),
				'deliverySectionFull' => Woo::translate( '[:en]Bike Delivery[:es]Entrega de bicicletas[:]' ),
				'pickupSection'    => Woo::translate( '[:en]Return Pickup[:es]Recogida de devolución[:]' ),
				'pickupSectionFull'=> Woo::translate( '[:en]Bike Pickup[:es]Recogida de bicicletas[:]' ),
				'differentPickupSeparator' => Woo::translate( '[:en]Pickup at a different address[:es]Recogida en una dirección diferente[:]' ),
				'bikesIncludedLabel' => Woo::translate( '[:en]Included bikes[:es]Bicicletas incluidas[:]' ),
				'sizeLabel'        => Woo::translate( '[:en]size[:es]talla[:]' ),
				'addressLabel'     => Woo::translate( '[:en]Delivery address (hotel, accommodation, or any location)[:es]Dirección de entrega (hotel, alojamiento o cualquier ubicación)[:]' ),
				'pickupAddressLabel' => Woo::translate( '[:en]Pickup address[:es]Dirección de recogida[:]' ),
				'sameAddressLabel' => Woo::translate( '[:en]Same address for pickup[:es]Misma dirección para recogida[:]' ),
				'differentAddress' => Woo::translate( '[:en]Use a different pickup address[:es]Usar una dirección de recogida diferente[:]' ),
				'windowLabel'      => Woo::translate( '[:en]Time window[:es]Horario[:]' ),
				'windowMorning'    => Woo::translate( '[:en]Morning (9:00–13:00)[:es]Mañana (9:00–13:00)[:]' ),
				'windowAfternoon'  => Woo::translate( '[:en]Afternoon (14:00–18:00)[:es]Tarde (14:00–18:00)[:]' ),
				'bikesLabel'       => Woo::translate( '[:en]Bikes to transport[:es]Bicicletas a transportar[:]' ),
				'selectAll'        => Woo::translate( '[:en]Select all[:es]Seleccionar todas[:]' ),
				'confirmBtn'       => Woo::translate( '[:en]Confirm transport[:es]Confirmar transporte[:]' ),
				'cancelBtn'        => Woo::translate( '[:en]Cancel[:es]Cancelar[:]' ),
				'quoteLabel'       => Woo::translate( '[:en]Estimated cost[:es]Coste estimado[:]' ),
				'perBikeLabel'     => Woo::translate( '[:en]per bike[:es]por bicicleta[:]' ),
				'zoneLabel'        => Woo::translate( '[:en]Zone[:es]Zona[:]' ),
				'outsideZones'     => Woo::translate( '[:en]Outside service area — please contact us[:es]Fuera del área de servicio — contáctenos[:]' ),
				'loading'          => Woo::translate( '[:en]Calculating...[:es]Calculando...[:]' ),
				'saving'           => Woo::translate( '[:en]Saving...[:es]Guardando...[:]' ),
				'errorGeneric'     => Woo::translate( '[:en]Something went wrong. Please try again.[:es]Algo salió mal. Inténtalo de nuevo.[:]' ),
				'availabilityLabel'=> Woo::translate( '[:en]Available slots[:es]Plazas disponibles[:]' ),
				'geocoding'        => Woo::translate( '[:en]Looking up address...[:es]Buscando dirección...[:]' ),
				'geocodeFailed'    => Woo::translate( '[:en]Could not find that address. Please try a different one.[:es]No se encontró esa dirección. Pruebe con otra.[:]' ),
				'removeConfirm'    => Woo::translate( '[:en]Remove transport for all bikes?[:es]¿Eliminar transporte para todas las bicicletas?[:]' ),
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
	 * State detection & summary helpers
	 * ================================================================ */

	public static function get_transport_service_state() : string {

		$eligible = self::count_eligible_bikes();
		if ( $eligible <= 0 ) {
			return 'not_configured';
		}

		// Check date uniformity first
		$dates = self::get_rental_dates_uniformity();
		$has_transport = self::cart_has_any_transport();

		if ( ! $dates['is_uniform'] ) {
			return $has_transport ? 'invalid_dates' : 'mixed_dates';
		}

		$delivery_count = self::count_direction_bikes( self::DIR_DELIVERY );
		$pickup_count   = self::count_direction_bikes( self::DIR_PICKUP );
		$total_transport = $delivery_count + $pickup_count;

		if ( $total_transport === 0 ) {
			return 'not_configured';
		}

		// Check for mismatch: transport items referencing non-existent rental keys
		if ( self::has_orphan_transport_items() ) {
			return 'mismatch';
		}

		// Partial: some bikes have transport but not all
		$has_delivery = $delivery_count > 0;
		$has_pickup   = $pickup_count > 0;

		if ( $has_delivery && $delivery_count < $eligible ) {
			return 'partial';
		}
		if ( $has_pickup && $pickup_count < $eligible ) {
			return 'partial';
		}

		return 'configured';
	}

	/**
	 * Check if all eligible rental bikes share the same start+end dates.
	 */
	public static function get_rental_dates_uniformity() : array {

		$result = [
			'is_uniform' => true,
			'start_date' => null,
			'end_date'   => null,
			'count'      => 0,
		];

		if ( ! WC() || ! WC()->cart ) {
			return $result;
		}

		$first_start = null;
		$first_end   = null;

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! self::is_transport_eligible( $item ) ) {
				continue;
			}

			$dates = self::extract_rental_dates( $item );
			$result['count']++;

			if ( ! $dates['start'] ) {
				// Can't determine dates → treat as non-uniform
				$result['is_uniform'] = false;
				continue;
			}

			if ( $first_start === null ) {
				$first_start = $dates['start'];
				$first_end   = $dates['end'];
				$result['start_date'] = $first_start;
				$result['end_date']   = $first_end;
			} else {
				if ( $dates['start'] !== $first_start || $dates['end'] !== $first_end ) {
					$result['is_uniform'] = false;
				}
			}
		}

		// If only 0 or 1 bike, trivially uniform
		if ( $result['count'] <= 1 ) {
			$result['is_uniform'] = true;
		}

		return $result;
	}

	/**
	 * Extract start and end rental dates from a cart item.
	 *
	 * Canonical date extractor — used by date uniformity checks, service date
	 * derivation, bike label display. Always returns normalised Y-m-d strings.
	 */
	public static function extract_rental_dates( array $cart_item ) : array {

		$booking = isset( $cart_item['booking'] ) ? (array) $cart_item['booking'] : [];
		$start_date = null;
		$end_date   = null;

		// --- Strategy 1: year/month/day form fields (our programmatic sim_post) ---
		$start_year  = $booking['wc_bookings_field_start_date_year'] ?? '';
		$start_month = $booking['wc_bookings_field_start_date_month'] ?? '';
		$start_day   = $booking['wc_bookings_field_start_date_day'] ?? '';

		if ( $start_year !== '' && $start_month !== '' && $start_day !== '' ) {
			$start_date = sprintf( '%04d-%02d-%02d', (int) $start_year, (int) $start_month, (int) $start_day );

			$end_year  = $booking['wc_bookings_field_end_date_year'] ?? '';
			$end_month = $booking['wc_bookings_field_end_date_month'] ?? '';
			$end_day   = $booking['wc_bookings_field_end_date_day'] ?? '';

			if ( $end_year !== '' && $end_month !== '' && $end_day !== '' ) {
				$end_date = sprintf( '%04d-%02d-%02d', (int) $end_year, (int) $end_month, (int) $end_day );
			} else {
				$duration  = isset( $booking['wc_bookings_field_duration'] ) ? max( 1, (int) $booking['wc_bookings_field_duration'] ) : 1;
				$end_ts    = strtotime( $start_date . ' 00:00:00' ) + ( ( $duration - 1 ) * DAY_IN_SECONDS );
				$end_date  = gmdate( 'Y-m-d', $end_ts );
			}
		}

		// --- Strategy 2: WC Bookings internal timestamps ---
		if ( ! $start_date ) {
			$bk_start = $booking['_start_date'] ?? 0;
			$bk_end   = $booking['_end_date'] ?? 0;
			if ( $bk_start ) {
				$start_date = is_numeric( $bk_start )
					? gmdate( 'Y-m-d', (int) $bk_start )
					: gmdate( 'Y-m-d', strtotime( $bk_start ) );
			}
			if ( $bk_end ) {
				$end_date = is_numeric( $bk_end )
					? gmdate( 'Y-m-d', (int) $bk_end )
					: gmdate( 'Y-m-d', strtotime( $bk_end ) );
			}
		}

		// --- Strategy 3: event timestamp fallback ---
		if ( ! $start_date ) {
			$event_ts = isset( $booking[\TC_BF\Plugin::BK_EB_EVENT_TS] ) ? (int) $booking[\TC_BF\Plugin::BK_EB_EVENT_TS] : 0;
			if ( $event_ts > 0 ) {
				$start_date = gmdate( 'Y-m-d', $event_ts );
				$duration   = isset( $booking['wc_bookings_field_duration'] ) ? max( 1, (int) $booking['wc_bookings_field_duration'] ) : 1;
				$end_date   = gmdate( 'Y-m-d', $event_ts + ( ( $duration - 1 ) * DAY_IN_SECONDS ) );
			}
		}

		// If we got start but not end, default end = start
		if ( $start_date && ! $end_date ) {
			$end_date = $start_date;
		}

		// Final normalisation: ensure Y-m-d format
		if ( $start_date ) {
			$start_date = gmdate( 'Y-m-d', strtotime( $start_date . ' 00:00:00' ) );
		}
		if ( $end_date ) {
			$end_date = gmdate( 'Y-m-d', strtotime( $end_date . ' 00:00:00' ) );
		}

		return [ 'start' => $start_date ?: null, 'end' => $end_date ?: null ];
	}

	/**
	 * Build structured bike label for transport checklist display.
	 */
	public static function format_transport_bike_label( array $cart_item ) : array {

		$product = $cart_item['data'] ?? null;
		$model   = $product ? $product->get_name() : Woo::translate( '[:en]Bike[:es]Bicicleta[:]' );

		// Size from WC Bookings resource
		$size = '';
		$booking = isset( $cart_item['booking'] ) ? (array) $cart_item['booking'] : [];

		// Try multiple keys for resource ID (WC Bookings stores it inconsistently)
		$resource_id = 0;
		foreach ( [ 'wc_bookings_field_resource', 'resource_id', '_resource_id' ] as $key ) {
			if ( ! empty( $booking[ $key ] ) ) {
				$resource_id = (int) $booking[ $key ];
				break;
			}
		}

		if ( $resource_id > 0 ) {
			$resource = get_post( $resource_id );
			if ( $resource && ! empty( $resource->post_title ) ) {
				$title = trim( $resource->post_title );
				// Try standard letter sizes first
				if ( preg_match( '/\b(XXS|XXL|XS|XL|[SMLX])\b/i', $title, $matches ) ) {
					$size = strtoupper( $matches[1] );
				// Then try numeric sizes (e.g. "54", "54cm", "Size 54")
				} elseif ( preg_match( '/(\d{2,3})\s*(?:cm)?\b/i', $title, $matches ) ) {
					$size = $matches[1];
				} else {
					// Use the full resource title as size label
					$size = $title;
				}
			}
		}

		// Fallback: try to get size from product attributes (variation)
		if ( $size === '' && $product && method_exists( $product, 'get_attribute' ) ) {
			$attr_size = $product->get_attribute( 'pa_size' );
			if ( ! $attr_size ) {
				$attr_size = $product->get_attribute( 'pa_talla' );
			}
			if ( $attr_size ) {
				$size = $attr_size;
			}
		}

		// Dates
		$dates = self::extract_rental_dates( $cart_item );

		// Rider
		$rider = $cart_item['_tcbf_participant_name'] ?? '';

		return [
			'model'      => $model,
			'size'       => $size,
			'start_date' => $dates['start'] ?? '',
			'end_date'   => $dates['end'] ?? '',
			'rider'      => $rider,
		];
	}

	public static function get_transport_service_summary() : array {

		$delivery_addr  = self::get_direction_address( self::DIR_DELIVERY );
		$pickup_addr    = self::get_direction_address( self::DIR_PICKUP );
		$delivery_count = self::count_direction_bikes( self::DIR_DELIVERY );
		$pickup_count   = self::count_direction_bikes( self::DIR_PICKUP );
		$link_return    = (int) ( self::get_session( self::SESSION_LINK_RETURN ) ?? 0 );

		$delivery_quote = null;
		$pickup_quote   = null;

		if ( $delivery_count > 0 && $delivery_addr ) {
			$delivery_quote = self::calculate_direction_quote( $delivery_addr, self::DIR_DELIVERY, $delivery_count );
		}
		if ( $pickup_count > 0 && $pickup_addr ) {
			$pickup_quote = self::calculate_direction_quote( $pickup_addr, self::DIR_PICKUP, $pickup_count );
		}

		return [
			'delivery_count'   => $delivery_count,
			'pickup_count'     => $pickup_count,
			'delivery_address' => $delivery_addr,
			'pickup_address'   => $pickup_addr,
			'delivery_window'  => self::get_direction_window( self::DIR_DELIVERY ),
			'pickup_window'    => self::get_direction_window( self::DIR_PICKUP ),
			'delivery_quote'   => $delivery_quote,
			'pickup_quote'     => $pickup_quote,
			'link_return'      => $link_return,
			'eligible_count'   => self::count_eligible_bikes(),
			'state'            => self::get_transport_service_state(),
		];
	}

	public static function count_eligible_bikes() : int {

		if ( ! WC() || ! WC()->cart ) {
			return 0;
		}

		$count = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_transport_eligible( $item ) ) {
				$count++;
			}
		}
		return $count;
	}

	public static function get_eligible_bike_keys() : array {

		if ( ! WC() || ! WC()->cart ) {
			return [];
		}

		$keys = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( self::is_transport_eligible( $item ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	private static function has_orphan_transport_items() : bool {

		if ( ! WC() || ! WC()->cart ) {
			return false;
		}

		$cart_contents = WC()->cart->get_cart();
		foreach ( $cart_contents as $item ) {
			if ( ! self::is_transport_item( $item ) ) {
				continue;
			}
			$parent_key = $item['_tcbf_transport_parent_key'] ?? '';
			if ( $parent_key !== '' && ! isset( $cart_contents[ $parent_key ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function get_total_transport_price() : float {

		if ( ! WC() || ! WC()->cart ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_transport_item( $item ) ) {
				$total += (float) ( $item['_tcbf_transport_price'] ?? 0 );
			}
		}
		return $total;
	}

	private static function format_summary_line( array $summary ) : string {

		$parts = [];
		if ( (int) $summary['delivery_count'] > 0 ) {
			$parts[] = sprintf(
				Woo::translate( '[:en]Delivery × %d[:es]Entrega × %d[:]' ),
				$summary['delivery_count']
			);
		}
		if ( (int) $summary['pickup_count'] > 0 ) {
			$parts[] = sprintf(
				Woo::translate( '[:en]Pickup × %d[:es]Recogida × %d[:]' ),
				$summary['pickup_count']
			);
		}
		return implode( ' + ', $parts );
	}

	private static function remove_transport_for_bikes( array $bike_keys ) : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$to_remove = [];

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_transport_item( $item ) ) {
				continue;
			}
			$parent_key = $item['_tcbf_transport_parent_key'] ?? '';
			if ( in_array( $parent_key, $bike_keys, true ) ) {
				$to_remove[] = $key;
			}
		}

		foreach ( $to_remove as $key ) {
			$cart->remove_cart_item( $key );
		}
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

		$dates = self::extract_rental_dates( $rental_item );

		if ( $direction === self::DIR_DELIVERY ) {
			return $dates['start'] ?? '';
		}

		return $dates['end'] ?? '';
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
