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
 */
final class TransportAvailability {

	/**
	 * Count booked bikes for a given (date, window) from existing orders
	 *
	 * Looks at WC orders with transport line items matching the date+window.
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @return int Number of bikes already booked
	 */
	public static function count_booked_bikes( string $service_date, string $window ) : int {

		if ( $service_date === '' || $window === '' ) {
			return 0;
		}

		$count = 0;

		// Count from WC orders (processing, completed, on-hold)
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( [
				'status'     => [ 'processing', 'completed', 'on-hold' ],
				'limit'      => -1,
				'meta_query' => [
					'relation' => 'OR',
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
		}

		// Also count transport items in current cart (other users' carts are invisible,
		// but at least count our own cart for immediate feedback)
		if ( WC() && WC()->cart ) {
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
		}

		return $count;
	}

	/**
	 * Get remaining capacity for a (date, window)
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @return int Remaining bike slots (0 = full)
	 */
	public static function remaining_capacity( string $service_date, string $window ) : int {

		$capacity = self::get_capacity_for_window( $window );
		$booked   = self::count_booked_bikes( $service_date, $window );

		return max( 0, $capacity - $booked );
	}

	/**
	 * Check if a given number of bikes can be booked for (date, window)
	 *
	 * @param string $service_date YYYY-MM-DD
	 * @param string $window       morning|afternoon
	 * @param int    $bike_qty     Number of bikes to add
	 * @return bool True if capacity is available
	 */
	public static function is_available( string $service_date, string $window, int $bike_qty = 1 ) : bool {

		return self::remaining_capacity( $service_date, $window ) >= $bike_qty;
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
