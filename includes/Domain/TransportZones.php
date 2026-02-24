<?php
namespace TC_BF\Domain;

use TC_BF\Support\Money;

if ( ! defined('ABSPATH') ) exit;

/**
 * Transport Zones — Hub-based zone definition and point-in-zone resolution
 *
 * MVP uses hub + radius model (center lat/lng + radius in km).
 * Each zone has a name, hub coordinates, radius, and pricing attributes.
 *
 * Zones are stored in wp_options as JSON (admin-configurable).
 */
final class TransportZones {

	const OPT_ZONES = 'tcbf_transport_zones';

	/**
	 * Default zones (Barcelona cycling context)
	 */
	private static $DEFAULT_ZONES = [
		[
			'id'           => 'bcn_airport',
			'name'         => 'BCN Airport (El Prat)',
			'lat'          => 41.2974,
			'lng'          => 2.0833,
			'radius_km'    => 5,
			'remote'       => false,
		],
		[
			'id'           => 'girona_airport',
			'name'         => 'Girona Airport',
			'lat'          => 41.9009,
			'lng'          => 2.7605,
			'radius_km'    => 5,
			'remote'       => false,
		],
		[
			'id'           => 'girona_city',
			'name'         => 'Girona City',
			'lat'          => 41.9794,
			'lng'          => 2.8214,
			'radius_km'    => 8,
			'remote'       => false,
		],
		[
			'id'           => 'bcn_city',
			'name'         => 'Barcelona City',
			'lat'          => 41.3874,
			'lng'          => 2.1686,
			'radius_km'    => 12,
			'remote'       => false,
		],
	];

	/**
	 * Get all configured zones
	 *
	 * @return array Array of zone definitions
	 */
	public static function get_zones() : array {

		$stored = get_option( self::OPT_ZONES, null );

		if ( $stored !== null && is_string( $stored ) ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return $decoded;
			}
		}

		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return $stored;
		}

		return self::$DEFAULT_ZONES;
	}

	/**
	 * Save zones configuration
	 *
	 * @param array $zones Array of zone definitions
	 */
	public static function save_zones( array $zones ) : void {
		update_option( self::OPT_ZONES, wp_json_encode( $zones ), false );
	}

	/**
	 * Resolve which zone a coordinate falls into
	 *
	 * Returns the first matching zone (by smallest radius first for precision).
	 * Returns null if the point is outside all zones.
	 *
	 * @param float $lat Latitude
	 * @param float $lng Longitude
	 * @return array|null Zone definition or null
	 */
	public static function resolve_zone( float $lat, float $lng ) : ?array {

		if ( $lat == 0.0 && $lng == 0.0 ) {
			return null;
		}

		$zones = self::get_zones();

		// Sort by radius ascending (prefer more specific zone)
		usort( $zones, function( $a, $b ) {
			return ( $a['radius_km'] ?? 999 ) <=> ( $b['radius_km'] ?? 999 );
		});

		foreach ( $zones as $zone ) {
			$zone_lat = (float) ( $zone['lat'] ?? 0 );
			$zone_lng = (float) ( $zone['lng'] ?? 0 );
			$radius   = (float) ( $zone['radius_km'] ?? 0 );

			if ( $zone_lat == 0.0 && $zone_lng == 0.0 ) {
				continue;
			}

			$distance = self::haversine_km( $lat, $lng, $zone_lat, $zone_lng );

			if ( $distance <= $radius ) {
				$zone['_distance_km'] = round( $distance, 2 );
				return $zone;
			}
		}

		return null;
	}

	/**
	 * Get a zone by its ID
	 *
	 * @param string $zone_id Zone ID
	 * @return array|null Zone definition or null
	 */
	public static function get_zone_by_id( string $zone_id ) : ?array {

		foreach ( self::get_zones() as $zone ) {
			if ( ( $zone['id'] ?? '' ) === $zone_id ) {
				return $zone;
			}
		}
		return null;
	}

	/**
	 * Calculate haversine distance between two coordinates in km
	 *
	 * @param float $lat1 Point 1 latitude
	 * @param float $lng1 Point 1 longitude
	 * @param float $lat2 Point 2 latitude
	 * @param float $lng2 Point 2 longitude
	 * @return float Distance in km
	 */
	public static function haversine_km( float $lat1, float $lng1, float $lat2, float $lng2 ) : float {

		$earth_radius = 6371; // km

		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) * sin( $d_lat / 2 )
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
			* sin( $d_lng / 2 ) * sin( $d_lng / 2 );

		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth_radius * $c;
	}

	/**
	 * Straight-line distance between two points in km
	 *
	 * @param float $lat1 Point 1 latitude
	 * @param float $lng1 Point 1 longitude
	 * @param float $lat2 Point 2 latitude
	 * @param float $lng2 Point 2 longitude
	 * @return float Distance in km
	 */
	public static function distance_between( float $lat1, float $lng1, float $lat2, float $lng2 ) : float {
		return self::haversine_km( $lat1, $lng1, $lat2, $lng2 );
	}
}
