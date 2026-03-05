<?php
namespace TC_BF\Domain;

use TC_BF\Support\Logger;

if ( ! defined('ABSPATH') ) exit;

/**
 * Transport Availability — Capacity enforcement per (date, window)
 *
 * Capacity is keyed by (service_date, window) regardless of direction.
 * A morning slot can be consumed by deliveries (rental start) or pickups (rental end).
 * Each transport child item = 1 bike.
 *
 * Availability model:
 *   booked_in_orders(date, window) + requested_in_cart(date, window) <= capacity(window)
 */
final class TransportAvailability {

	/**
	 * Count booked bikes from completed/processing WC orders ONLY (not cart)
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @return int Number of bikes booked in orders
	 */
	public static function count_booked_in_orders( string $service_date, string $window ) : int {

		if ( $service_date === '' || $window === '' ) {
			return 0;
		}

		$count = 0;

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders( [
			'status'     => [ 'processing', 'completed', 'on-hold' ],
			'limit'      => -1,
			'meta_query' => [
				[
					'key'   => '_tcbf_has_transport',
					'value' => '1',
				],
			],
		] );

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$item_scope = $item->get_meta( 'tcbf_scope' );
				if ( $item_scope !== 'transport' ) {
					continue;
				}

				$item_date   = $item->get_meta( '_tcbf_transport_service_date' );
				$item_window = $item->get_meta( '_tcbf_transport_window' );

				if ( $item_date === $service_date && $item_window === $window ) {
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * Count transport bikes in the current WC cart for a (date, window)
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @return int Number of bikes in cart for this slot
	 */
	public static function count_in_cart( string $service_date, string $window ) : int {

		if ( $service_date === '' || $window === '' ) {
			return 0;
		}

		if ( ! WC() || ! WC()->cart ) {
			return 0;
		}

		$count = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! isset( $item['_tcbf_scope'] ) || $item['_tcbf_scope'] !== 'transport' ) {
				continue;
			}

			$item_date   = $item['_tcbf_transport_service_date'] ?? '';
			$item_window = $item['_tcbf_transport_window'] ?? '';

			if ( $item_date === $service_date && $item_window === $window ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Get remaining capacity for a (date, window) considering orders only
	 *
	 * This returns how many MORE bikes could potentially be added.
	 * Cart items are NOT subtracted here — callers add cart counts separately.
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @return int Remaining capacity (orders already subtracted)
	 */
	public static function remaining_capacity( string $service_date, string $window ) : int {

		$capacity = self::get_capacity_for_window( $window );
		$booked   = self::count_booked_in_orders( $service_date, $window );

		return max( 0, $capacity - $booked );
	}

	/**
	 * Check if adding bike_qty MORE bikes is possible for (date, window)
	 *
	 * Formula: orders_booked + cart_current + bike_qty <= capacity
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @param int    $additional   Number of additional bikes to add (not total)
	 * @return bool True if capacity allows
	 */
	public static function can_add( string $service_date, string $window, int $additional = 1 ) : bool {

		$capacity    = self::get_capacity_for_window( $window );
		$in_orders   = self::count_booked_in_orders( $service_date, $window );
		$in_cart     = self::count_in_cart( $service_date, $window );

		return ( $in_orders + $in_cart + $additional ) <= $capacity;
	}

	/**
	 * Check if the current cart's transport items fit within capacity
	 *
	 * Used at checkout: orders_booked + cart_count <= capacity
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @param int    $cart_count   Number of transport items in cart for this slot
	 * @return bool True if within capacity
	 */
	public static function checkout_check( string $service_date, string $window, int $cart_count ) : bool {

		$capacity  = self::get_capacity_for_window( $window );
		$in_orders = self::count_booked_in_orders( $service_date, $window );

		return ( $in_orders + $cart_count ) <= $capacity;
	}

	/**
	 * Get configured capacity for a window
	 *
	 * @param string $window morning|afternoon
	 * @return int Capacity in bikes
	 */
	public static function get_capacity_for_window( string $window ) : int {

		$config = TransportPricing::get_config();

		if ( $window === 'morning' ) {
			return (int) ( $config['capacity_morning_bikes'] ?? 5 );
		}

		if ( $window === 'afternoon' ) {
			return (int) ( $config['capacity_afternoon_bikes'] ?? 5 );
		}

		return 0;
	}
}
