<?php
namespace TC_BF\Domain;

use TC_BF\Support\Money;
use TC_BF\Support\Logger;

if ( ! defined('ABSPATH') ) exit;

/**
 * Transport Pricing Engine
 *
 * Computes transport quotes based on:
 * - Zone-based pricing (hub pairs)
 * - Surcharges (night hours, bike boxes, remote area)
 * - Distance fallback (when points are outside known zones)
 *
 * All pricing rules live in TCBF (not in any external geo plugin).
 * Server-side recalculation is the single source of truth.
 */
final class TransportPricing {

	/** Option keys for pricing configuration */
	const OPT_PRICING_CONFIG  = 'tcbf_transport_pricing';
	const OPT_GOOGLE_MAPS_KEY = 'tcbf_google_maps_api_key';

	/**
	 * Default pricing configuration
	 */
	private static $DEFAULT_CONFIG = [
		// Base prices per transport type (EUR)
		'base_pickup'           => 45.00,
		'base_delivery'         => 45.00,
		'base_both'             => 75.00,

		// Per-km rate for distance fallback (outside zones)
		'per_km_rate'           => 1.20,
		'min_distance_charge'   => 25.00,
		'max_distance_charge'   => 200.00,

		// Surcharges
		'surcharge_night'       => 15.00,   // Night hours (before 07:00 or after 21:00)
		'surcharge_bike_box'    => 10.00,   // Per bike box
		'surcharge_remote'      => 20.00,   // Remote area flag on zone

		// Night hour boundaries (24h format)
		'night_start_hour'      => 21,
		'night_end_hour'        => 7,

		// Zone-pair overrides: "zone_from|zone_to" => price
		// Empty = use base price + surcharges
		'zone_pair_prices'      => [],

		// Bulk bike pricing: additional bikes are cheaper
		'price_additional_bike_multiplier' => 0.7,  // 70% of base for 2nd+ bike

		// Capacity per window (bikes per slot)
		'capacity_morning_bikes'   => 5,
		'capacity_afternoon_bikes' => 5,

		// Transport product ID (virtual WC product for cart line item)
		'transport_product_id'  => 0,
	];

	/**
	 * Get pricing configuration
	 *
	 * @return array Pricing config
	 */
	public static function get_config() : array {

		$stored = get_option( self::OPT_PRICING_CONFIG, null );

		if ( is_string( $stored ) ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				return array_merge( self::$DEFAULT_CONFIG, $decoded );
			}
		}

		if ( is_array( $stored ) ) {
			return array_merge( self::$DEFAULT_CONFIG, $stored );
		}

		return self::$DEFAULT_CONFIG;
	}

	/**
	 * Save pricing configuration
	 *
	 * @param array $config Pricing config
	 */
	public static function save_config( array $config ) : void {
		update_option( self::OPT_PRICING_CONFIG, wp_json_encode( $config ), false );
	}

	/**
	 * Get Google Maps API key
	 *
	 * @return string API key or empty string
	 */
	public static function get_google_maps_key() : string {
		return (string) get_option( self::OPT_GOOGLE_MAPS_KEY, '' );
	}

	/**
	 * Get transport product ID (virtual WC product)
	 *
	 * @return int Product ID or 0
	 */
	public static function get_transport_product_id() : int {
		$config = self::get_config();
		return (int) ( $config['transport_product_id'] ?? 0 );
	}

	/**
	 * Calculate transport quote
	 *
	 * @param array $params Quote parameters:
	 *   - type: 'pickup' | 'delivery' | 'both'
	 *   - pickup_lat, pickup_lng: Pickup coordinates
	 *   - dropoff_lat, dropoff_lng: Delivery coordinates
	 *   - pickup_time_window: Time window string (for night surcharge)
	 *   - dropoff_time_window: Time window string
	 *   - bike_boxes: Number of bike boxes (int)
	 *   - bike_qty: Number of bikes for this direction (int, default 1)
	 *   - rental_start_ts: Rental start timestamp
	 *   - rental_end_ts: Rental end timestamp
	 * @return array Quote result with price_total, per_bike_price, breakdown, zone info
	 */
	public static function calculate_quote( array $params ) : array {

		$config = self::get_config();

		$type          = sanitize_text_field( $params['type'] ?? 'pickup' );
		$pickup_lat    = (float) ( $params['pickup_lat'] ?? 0 );
		$pickup_lng    = (float) ( $params['pickup_lng'] ?? 0 );
		$dropoff_lat   = (float) ( $params['dropoff_lat'] ?? 0 );
		$dropoff_lng   = (float) ( $params['dropoff_lng'] ?? 0 );
		$pickup_time   = sanitize_text_field( $params['pickup_time_window'] ?? '' );
		$dropoff_time  = sanitize_text_field( $params['dropoff_time_window'] ?? '' );
		$bike_boxes    = max( 0, (int) ( $params['bike_boxes'] ?? 0 ) );
		$bike_qty      = max( 1, (int) ( $params['bike_qty'] ?? 1 ) );

		// Validate type
		if ( ! in_array( $type, [ 'pickup', 'delivery', 'both' ], true ) ) {
			$type = 'pickup';
		}

		$breakdown = [];
		$zone_pickup  = null;
		$zone_dropoff = null;

		// Resolve zones
		if ( in_array( $type, [ 'pickup', 'both' ], true ) && $pickup_lat != 0.0 ) {
			$zone_pickup = TransportZones::resolve_zone( $pickup_lat, $pickup_lng );
		}

		if ( in_array( $type, [ 'delivery', 'both' ], true ) && $dropoff_lat != 0.0 ) {
			$zone_dropoff = TransportZones::resolve_zone( $dropoff_lat, $dropoff_lng );
		}

		// --- Base price ---
		$base_price = self::resolve_base_price( $type, $zone_pickup, $zone_dropoff, $config );
		$breakdown[] = [
			'label' => self::base_price_label( $type ),
			'amount' => $base_price,
		];

		// --- Distance fallback (outside known zones) ---
		$distance_charge = 0.0;
		$distance_km     = 0.0;

		if ( $type === 'pickup' && $zone_pickup === null && $pickup_lat != 0.0 ) {
			$distance_result = self::calculate_distance_surcharge( $pickup_lat, $pickup_lng, $config );
			$distance_charge += $distance_result['charge'];
			$distance_km      = $distance_result['distance_km'];
		}

		if ( $type === 'delivery' && $zone_dropoff === null && $dropoff_lat != 0.0 ) {
			$distance_result = self::calculate_distance_surcharge( $dropoff_lat, $dropoff_lng, $config );
			$distance_charge += $distance_result['charge'];
			$distance_km      = $distance_result['distance_km'];
		}

		if ( $type === 'both' ) {
			if ( $zone_pickup === null && $pickup_lat != 0.0 ) {
				$result = self::calculate_distance_surcharge( $pickup_lat, $pickup_lng, $config );
				$distance_charge += $result['charge'];
				$distance_km     += $result['distance_km'];
			}
			if ( $zone_dropoff === null && $dropoff_lat != 0.0 ) {
				$result = self::calculate_distance_surcharge( $dropoff_lat, $dropoff_lng, $config );
				$distance_charge += $result['charge'];
				$distance_km     += $result['distance_km'];
			}
		}

		if ( $distance_charge > 0 ) {
			$breakdown[] = [
				'label'  => sprintf( 'Distance surcharge (%.1f km)', $distance_km ),
				'amount' => Money::money_round( $distance_charge ),
			];
		}

		// --- Night surcharge ---
		$night_charge = 0.0;

		if ( in_array( $type, [ 'pickup', 'both' ], true ) && self::is_night_window( $pickup_time, $config ) ) {
			$night_charge += (float) $config['surcharge_night'];
			$breakdown[]   = [
				'label'  => 'Night pickup surcharge',
				'amount' => (float) $config['surcharge_night'],
			];
		}

		if ( in_array( $type, [ 'delivery', 'both' ], true ) && self::is_night_window( $dropoff_time, $config ) ) {
			$night_charge += (float) $config['surcharge_night'];
			$breakdown[]   = [
				'label'  => 'Night delivery surcharge',
				'amount' => (float) $config['surcharge_night'],
			];
		}

		// --- Bike box surcharge ---
		$bike_box_charge = 0.0;
		if ( $bike_boxes > 0 ) {
			$bike_box_charge = Money::money_round( $bike_boxes * (float) $config['surcharge_bike_box'] );
			$breakdown[] = [
				'label'  => sprintf( 'Bike boxes × %d', $bike_boxes ),
				'amount' => $bike_box_charge,
			];
		}

		// --- Remote area surcharge ---
		$remote_charge = 0.0;
		if ( $zone_pickup !== null && ! empty( $zone_pickup['remote'] ) ) {
			$remote_charge += (float) $config['surcharge_remote'];
			$breakdown[]    = [
				'label'  => 'Remote area (pickup)',
				'amount' => (float) $config['surcharge_remote'],
			];
		}
		if ( $zone_dropoff !== null && ! empty( $zone_dropoff['remote'] ) ) {
			$remote_charge += (float) $config['surcharge_remote'];
			$breakdown[]    = [
				'label'  => 'Remote area (delivery)',
				'amount' => (float) $config['surcharge_remote'],
			];
		}

		// --- Difficulty factor (zone-level multiplier) ---
		$difficulty_factor = 1.0;
		$active_zone = $zone_dropoff ?? $zone_pickup;
		if ( $active_zone !== null && isset( $active_zone['difficulty_factor'] ) ) {
			$difficulty_factor = (float) $active_zone['difficulty_factor'];
			if ( $difficulty_factor <= 0 ) {
				$difficulty_factor = 1.0;
			}
		}

		// Core subtotal = base + distance (before surcharges), apply difficulty
		$core_subtotal = $base_price + $distance_charge;
		if ( $difficulty_factor !== 1.0 ) {
			$adjusted = Money::money_round( $core_subtotal * $difficulty_factor );
			$diff = $adjusted - $core_subtotal;
			if ( abs( $diff ) > 0.005 ) {
				$breakdown[] = [
					'label'  => sprintf( 'Difficulty factor (×%.2f)', $difficulty_factor ),
					'amount' => $diff,
				];
			}
			$core_subtotal = $adjusted;
		}

		// --- Per-bike total (single bike) ---
		$single_bike_total = Money::money_round(
			$core_subtotal + $night_charge + $bike_box_charge + $remote_charge
		);

		// --- Bulk pricing for multiple bikes ---
		$additional_multiplier = (float) ( $config['price_additional_bike_multiplier'] ?? 0.7 );
		if ( $additional_multiplier <= 0 || $additional_multiplier > 1.0 ) {
			$additional_multiplier = 1.0;
		}

		if ( $bike_qty > 1 ) {
			// First bike at full price, additional bikes at multiplier
			$total_for_qty = $single_bike_total + Money::money_round( $single_bike_total * $additional_multiplier * ( $bike_qty - 1 ) );
			$per_bike_price = Money::money_round( $total_for_qty / $bike_qty );
			$price_total = Money::money_round( $total_for_qty );

			// Show the multi-bike discount as a negative delta
			$full_price_all = Money::money_round( $single_bike_total * $bike_qty );
			$discount = Money::money_round( $full_price_all - $price_total );
			if ( $discount > 0 ) {
				$breakdown[] = [
					'label'  => sprintf( 'Multi-bike discount (%d bikes, %d×%.0f%%)', $bike_qty, $bike_qty - 1, $additional_multiplier * 100 ),
					'amount' => -$discount,
				];
			}
		} else {
			$price_total = $single_bike_total;
			$per_bike_price = $single_bike_total;
		}

		$result = [
			'price_total'    => $price_total,
			'per_bike_price' => $per_bike_price,
			'bike_qty'       => $bike_qty,
			'breakdown'      => $breakdown,
			'zone_pickup'    => $zone_pickup ? ( $zone_pickup['id'] ?? $zone_pickup['name'] ?? '' ) : null,
			'zone_dropoff'   => $zone_dropoff ? ( $zone_dropoff['id'] ?? $zone_dropoff['name'] ?? '' ) : null,
			'distance_km'    => round( $distance_km, 1 ),
			'type'           => $type,
			'bike_boxes'     => $bike_boxes,
		];

		Logger::log( 'transport.quote.calculated', [
			'type'        => $type,
			'price_total' => $price_total,
			'zone_pickup' => $result['zone_pickup'],
			'zone_dropoff'=> $result['zone_dropoff'],
			'distance_km' => $result['distance_km'],
		] );

		return $result;
	}

	/**
	 * Resolve base price for a transport type and zone pair
	 */
	private static function resolve_base_price( string $type, ?array $zone_pickup, ?array $zone_dropoff, array $config ) : float {

		// Check zone-pair override
		$zone_pair_prices = $config['zone_pair_prices'] ?? [];
		if ( ! empty( $zone_pair_prices ) && is_array( $zone_pair_prices ) ) {
			$pickup_id  = $zone_pickup  ? ( $zone_pickup['id'] ?? '' )  : '_outside';
			$dropoff_id = $zone_dropoff ? ( $zone_dropoff['id'] ?? '' ) : '_outside';

			$pair_key = $pickup_id . '|' . $dropoff_id;
			if ( isset( $zone_pair_prices[ $pair_key ] ) ) {
				return (float) $zone_pair_prices[ $pair_key ];
			}

			// Try reverse direction
			$pair_key_rev = $dropoff_id . '|' . $pickup_id;
			if ( isset( $zone_pair_prices[ $pair_key_rev ] ) ) {
				return (float) $zone_pair_prices[ $pair_key_rev ];
			}
		}

		// Default base prices by type
		switch ( $type ) {
			case 'pickup':   return (float) $config['base_pickup'];
			case 'delivery': return (float) $config['base_delivery'];
			case 'both':     return (float) $config['base_both'];
			default:         return (float) $config['base_pickup'];
		}
	}

	/**
	 * Calculate distance surcharge for a point outside known zones
	 *
	 * Uses straight-line distance to nearest zone hub as proxy.
	 */
	private static function calculate_distance_surcharge( float $lat, float $lng, array $config ) : array {

		$zones = TransportZones::get_zones();

		if ( empty( $zones ) ) {
			return [ 'charge' => 0.0, 'distance_km' => 0.0 ];
		}

		// Find distance to nearest zone hub
		$min_distance = PHP_FLOAT_MAX;
		foreach ( $zones as $zone ) {
			$d = TransportZones::haversine_km( $lat, $lng, (float) $zone['lat'], (float) $zone['lng'] );
			$zone_radius = (float) ( $zone['radius_km'] ?? 0 );
			// Distance beyond the zone boundary
			$beyond = max( 0, $d - $zone_radius );
			if ( $beyond < $min_distance ) {
				$min_distance = $beyond;
			}
		}

		if ( $min_distance <= 0 ) {
			return [ 'charge' => 0.0, 'distance_km' => 0.0 ];
		}

		$per_km   = (float) $config['per_km_rate'];
		$min_charge = (float) $config['min_distance_charge'];
		$max_charge = (float) $config['max_distance_charge'];

		$charge = Money::money_round( $min_distance * $per_km );
		$charge = max( $min_charge, min( $max_charge, $charge ) );

		return [
			'charge'      => $charge,
			'distance_km' => round( $min_distance, 1 ),
		];
	}

	/**
	 * Check if a time window falls in night hours
	 *
	 * Supports formats: "06:00-08:00", "Early morning (before 07:00)", etc.
	 */
	private static function is_night_window( string $time_window, array $config ) : bool {

		if ( $time_window === '' ) {
			return false;
		}

		$night_start = (int) ( $config['night_start_hour'] ?? 21 );
		$night_end   = (int) ( $config['night_end_hour'] ?? 7 );

		// Try to extract hour from time window string
		if ( preg_match( '/(\d{1,2}):(\d{2})/', $time_window, $m ) ) {
			$hour = (int) $m[1];
			return ( $hour >= $night_start || $hour < $night_end );
		}

		// Keyword matching fallback
		$lower = strtolower( $time_window );
		if ( strpos( $lower, 'night' ) !== false || strpos( $lower, 'early morning' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Get human-readable label for base price
	 */
	private static function base_price_label( string $type ) : string {
		switch ( $type ) {
			case 'pickup':   return 'Pickup transport';
			case 'delivery': return 'Delivery transport';
			case 'both':     return 'Pickup + Delivery transport';
			default:         return 'Transport';
		}
	}

	/**
	 * Get available time windows for transport scheduling
	 *
	 * @return array Array of time window options
	 */
	public static function get_time_windows() : array {
		return [
			'06:00-08:00' => 'Early morning (06:00 – 08:00)',
			'08:00-10:00' => 'Morning (08:00 – 10:00)',
			'10:00-12:00' => 'Late morning (10:00 – 12:00)',
			'12:00-14:00' => 'Midday (12:00 – 14:00)',
			'14:00-16:00' => 'Afternoon (14:00 – 16:00)',
			'16:00-18:00' => 'Late afternoon (16:00 – 18:00)',
			'18:00-20:00' => 'Evening (18:00 – 20:00)',
			'20:00-22:00' => 'Night (20:00 – 22:00)',
		];
	}
}
