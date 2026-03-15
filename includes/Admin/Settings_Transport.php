<?php
namespace TC_BF\Admin;

use TC_BF\Domain\TransportPricing;
use TC_BF\Domain\TransportZones;

if ( ! defined('ABSPATH') ) exit;

/**
 * Settings_Transport — Transport tab on the TC Booking Flow settings page
 *
 * Manages:
 * - Google Maps API key
 * - Transport WC product selection
 * - Base pricing configuration
 * - Surcharges
 * - Zone definitions (hub + radius)
 */
final class Settings_Transport {

	public static function init() : void {
		add_action( 'admin_init', [ __CLASS__, 'handle_save' ] );
	}

	/**
	 * Handle transport settings form submission
	 */
	public static function handle_save() : void {

		if ( ! isset( $_POST['tcbf_transport_save'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'tcbf_transport_settings' );

		// Google Maps API key
		$maps_key = isset( $_POST['tcbf_google_maps_api_key'] )
			? sanitize_text_field( wp_unslash( $_POST['tcbf_google_maps_api_key'] ) )
			: '';
		update_option( TransportPricing::OPT_GOOGLE_MAPS_KEY, $maps_key, false );

		// Pricing config
		$config = TransportPricing::get_config();

		$config['transport_product_id'] = isset( $_POST['tcbf_transport_product_id'] )
			? absint( $_POST['tcbf_transport_product_id'] )
			: 0;

		$config['base_pickup']   = self::sanitize_price( $_POST['tcbf_base_pickup'] ?? '' );
		$config['base_delivery'] = self::sanitize_price( $_POST['tcbf_base_delivery'] ?? '' );
		$config['base_both']     = self::sanitize_price( $_POST['tcbf_base_both'] ?? '' );

		$config['per_km_rate']         = self::sanitize_price( $_POST['tcbf_per_km_rate'] ?? '' );
		$config['min_distance_charge'] = self::sanitize_price( $_POST['tcbf_min_distance_charge'] ?? '' );
		$config['max_distance_charge'] = self::sanitize_price( $_POST['tcbf_max_distance_charge'] ?? '' );

		$config['surcharge_night']    = self::sanitize_price( $_POST['tcbf_surcharge_night'] ?? '' );
		$config['surcharge_bike_box'] = self::sanitize_price( $_POST['tcbf_surcharge_bike_box'] ?? '' );
		$config['surcharge_remote']   = self::sanitize_price( $_POST['tcbf_surcharge_remote'] ?? '' );

		// Bundle discount (for "both" computed from delivery + pickup)
		$config['bundle_discount'] = self::sanitize_price( $_POST['tcbf_bundle_discount'] ?? '' );

		// Bulk pricing
		$config['price_additional_bike_multiplier'] = min( 1.0, max( 0.0, (float) ( $_POST['tcbf_additional_bike_multiplier'] ?? 0.7 ) ) );

		// Time windows
		$config['window_morning_start']   = self::sanitize_time( $_POST['tcbf_window_morning_start'] ?? '09:00' );
		$config['window_morning_end']     = self::sanitize_time( $_POST['tcbf_window_morning_end'] ?? '13:00' );
		$config['window_afternoon_start'] = self::sanitize_time( $_POST['tcbf_window_afternoon_start'] ?? '14:00' );
		$config['window_afternoon_end']   = self::sanitize_time( $_POST['tcbf_window_afternoon_end'] ?? '18:00' );

		// Validate: morning end <= afternoon start
		if ( $config['window_morning_end'] > $config['window_afternoon_start'] ) {
			$config['window_morning_end']     = $config['window_afternoon_start'];
		}

		// Capacity
		$config['capacity_morning_bikes']   = max( 1, absint( $_POST['tcbf_capacity_morning_bikes'] ?? 5 ) );
		$config['capacity_afternoon_bikes'] = max( 1, absint( $_POST['tcbf_capacity_afternoon_bikes'] ?? 5 ) );

		TransportPricing::save_config( $config );

		// Zones
		$zones = [];
		if ( isset( $_POST['tcbf_zones'] ) && is_array( $_POST['tcbf_zones'] ) ) {
			foreach ( $_POST['tcbf_zones'] as $zone_data ) {
				$id   = sanitize_key( $zone_data['id'] ?? '' );
				$name = sanitize_text_field( wp_unslash( $zone_data['name'] ?? '' ) );
				if ( $id === '' || $name === '' ) {
					continue;
				}
				// Zone prices: empty string = null (use global fallback)
				$delivery_price = trim( $zone_data['delivery_price'] ?? '' );
				$pickup_price   = trim( $zone_data['pickup_price'] ?? '' );
				$both_price     = trim( $zone_data['both_price'] ?? '' );

				$zones[] = [
					'id'                => $id,
					'name'              => $name,
					'lat'               => (float) ( $zone_data['lat'] ?? 0 ),
					'lng'               => (float) ( $zone_data['lng'] ?? 0 ),
					'radius_km'         => max( 0.1, (float) ( $zone_data['radius_km'] ?? 5 ) ),
					'remote'            => ! empty( $zone_data['remote'] ),
					'delivery_price'    => ( $delivery_price !== '' ) ? round( max( 0, (float) $delivery_price ), 2 ) : null,
					'pickup_price'      => ( $pickup_price !== '' )   ? round( max( 0, (float) $pickup_price ), 2 )   : null,
					'both_price'        => ( $both_price !== '' )     ? round( max( 0, (float) $both_price ), 2 )     : null,
					'difficulty_factor' => max( 0.1, (float) ( $zone_data['difficulty_factor'] ?? 1.0 ) ),
				];
			}
		}

		if ( ! empty( $zones ) ) {
			TransportZones::save_zones( $zones );
		}

		// Redirect back with success notice
		wp_safe_redirect( add_query_arg( [
			'page'    => 'tc-bf-settings',
			'tab'     => 'transport',
			'updated' => '1',
		], admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Render the Transport settings tab
	 */
	public static function render_tab() : void {

		if ( isset( $_GET['updated'] ) && $_GET['updated'] === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Transport settings saved.', 'tc-booking-flow-next' )
				. '</p></div>';
		}

		$maps_key = TransportPricing::get_google_maps_key();
		$config   = TransportPricing::get_config();
		$zones    = TransportZones::get_zones();
		?>

		<form method="post">
			<?php wp_nonce_field( 'tcbf_transport_settings' ); ?>
			<input type="hidden" name="tcbf_transport_save" value="1" />

			<!-- Google Maps -->
			<h2><?php echo esc_html__( 'Google Maps', 'tc-booking-flow-next' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tcbf_google_maps_api_key"><?php echo esc_html__( 'API Key', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" name="tcbf_google_maps_api_key" id="tcbf_google_maps_api_key"
								value="<?php echo esc_attr( $maps_key ); ?>" autocomplete="off" />
							<p class="description">
								<?php echo esc_html__( 'Google Maps API key with Places API and Maps JavaScript API enabled. Leave empty to use manual address input.', 'tc-booking-flow-next' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Transport Product -->
			<h2><?php echo esc_html__( 'Transport Product', 'tc-booking-flow-next' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tcbf_transport_product_id"><?php echo esc_html__( 'WooCommerce Product', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<?php
							$product_id = (int) ( $config['transport_product_id'] ?? 0 );
							$products   = self::get_virtual_products();
							?>
							<select name="tcbf_transport_product_id" id="tcbf_transport_product_id">
								<option value="0"><?php echo esc_html__( '— Select product —', 'tc-booking-flow-next' ); ?></option>
								<?php foreach ( $products as $p ) : ?>
									<option value="<?php echo esc_attr( (string) $p->get_id() ); ?>" <?php selected( $product_id, $p->get_id() ); ?>>
										<?php echo esc_html( $p->get_name() . ' (#' . $p->get_id() . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php echo esc_html__( 'Virtual WC product used as the transport line item in cart. Price is overridden dynamically.', 'tc-booking-flow-next' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Base Pricing -->
			<h2><?php echo esc_html__( 'Base Pricing (Global Fallback)', 'tc-booking-flow-next' ); ?></h2>
			<p class="description" style="margin-bottom: 12px;">
				<?php echo esc_html__( 'Default prices used when a zone does not have its own price set. Zone-specific prices (below) take precedence.', 'tc-booking-flow-next' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tcbf_base_pickup"><?php echo esc_html__( 'Pickup', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_base_pickup" id="tcbf_base_pickup"
								value="<?php echo esc_attr( (string) $config['base_pickup'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_base_delivery"><?php echo esc_html__( 'Delivery', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_base_delivery" id="tcbf_base_delivery"
								value="<?php echo esc_attr( (string) $config['base_delivery'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_base_both"><?php echo esc_html__( 'Pickup + Delivery', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_base_both" id="tcbf_base_both"
								value="<?php echo esc_attr( (string) $config['base_both'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_bundle_discount"><?php echo esc_html__( 'Bundle discount', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_bundle_discount" id="tcbf_bundle_discount"
								value="<?php echo esc_attr( (string) ( $config['bundle_discount'] ?? 15 ) ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
							<p class="description">
								<?php echo esc_html__( 'Discount applied when "both" (pickup + delivery) is computed from separate zone prices. Not used when a zone has an explicit "both" price.', 'tc-booking-flow-next' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Distance Fallback -->
			<h2><?php echo esc_html__( 'Distance Fallback', 'tc-booking-flow-next' ); ?></h2>
			<p class="description" style="margin-bottom: 12px;">
				<?php echo esc_html__( 'Applied when the address is outside all defined zones.', 'tc-booking-flow-next' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tcbf_per_km_rate"><?php echo esc_html__( 'Per-km rate', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_per_km_rate" id="tcbf_per_km_rate"
								value="<?php echo esc_attr( (string) $config['per_km_rate'] ); ?>" min="0" step="0.01" />
							<span>&euro;/km</span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_min_distance_charge"><?php echo esc_html__( 'Minimum charge', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_min_distance_charge" id="tcbf_min_distance_charge"
								value="<?php echo esc_attr( (string) $config['min_distance_charge'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_max_distance_charge"><?php echo esc_html__( 'Maximum charge', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_max_distance_charge" id="tcbf_max_distance_charge"
								value="<?php echo esc_attr( (string) $config['max_distance_charge'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Surcharges -->
			<h2><?php echo esc_html__( 'Surcharges', 'tc-booking-flow-next' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tcbf_surcharge_night"><?php echo esc_html__( 'Night hours', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_surcharge_night" id="tcbf_surcharge_night"
								value="<?php echo esc_attr( (string) $config['surcharge_night'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
							<p class="description">
								<?php echo esc_html( sprintf(
									__( 'Before %d:00 or after %d:00', 'tc-booking-flow-next' ),
									$config['night_end_hour'] ?? 7,
									$config['night_start_hour'] ?? 21
								) ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_surcharge_bike_box"><?php echo esc_html__( 'Bike box (per box)', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_surcharge_bike_box" id="tcbf_surcharge_bike_box"
								value="<?php echo esc_attr( (string) $config['surcharge_bike_box'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_surcharge_remote"><?php echo esc_html__( 'Remote area', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_surcharge_remote" id="tcbf_surcharge_remote"
								value="<?php echo esc_attr( (string) $config['surcharge_remote'] ); ?>" min="0" step="0.01" />
							<span>&euro;</span>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Bulk Pricing -->
			<h2><?php echo esc_html__( 'Bulk Bike Pricing', 'tc-booking-flow-next' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tcbf_additional_bike_multiplier"><?php echo esc_html__( 'Additional bike multiplier', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_additional_bike_multiplier" id="tcbf_additional_bike_multiplier"
								value="<?php echo esc_attr( (string) ( $config['price_additional_bike_multiplier'] ?? 0.7 ) ); ?>" min="0" max="1" step="0.01" />
							<p class="description">
								<?php echo esc_html__( 'Price multiplier for 2nd+ bike (e.g., 0.7 = 70% of base). First bike is always full price.', 'tc-booking-flow-next' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Time Windows -->
			<h2><?php echo esc_html__( 'Time Windows', 'tc-booking-flow-next' ); ?></h2>
			<p class="description" style="margin-bottom: 12px;">
				<?php echo esc_html__( 'Define the morning and afternoon delivery/pickup windows shown to customers.', 'tc-booking-flow-next' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Morning window', 'tc-booking-flow-next' ); ?>
						</th>
						<td>
							<input type="time" name="tcbf_window_morning_start" id="tcbf_window_morning_start"
								value="<?php echo esc_attr( $config['window_morning_start'] ?? '09:00' ); ?>" />
							<span>&ndash;</span>
							<input type="time" name="tcbf_window_morning_end" id="tcbf_window_morning_end"
								value="<?php echo esc_attr( $config['window_morning_end'] ?? '13:00' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Afternoon window', 'tc-booking-flow-next' ); ?>
						</th>
						<td>
							<input type="time" name="tcbf_window_afternoon_start" id="tcbf_window_afternoon_start"
								value="<?php echo esc_attr( $config['window_afternoon_start'] ?? '14:00' ); ?>" />
							<span>&ndash;</span>
							<input type="time" name="tcbf_window_afternoon_end" id="tcbf_window_afternoon_end"
								value="<?php echo esc_attr( $config['window_afternoon_end'] ?? '18:00' ); ?>" />
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Capacity -->
			<h2><?php echo esc_html__( 'Transport Capacity', 'tc-booking-flow-next' ); ?></h2>
			<p class="description" style="margin-bottom: 12px;">
				<?php echo esc_html__( 'Maximum number of bikes per time window (shared across all directions).', 'tc-booking-flow-next' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tcbf_capacity_morning_bikes"><?php echo esc_html__( 'Morning capacity', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_capacity_morning_bikes" id="tcbf_capacity_morning_bikes"
								value="<?php echo esc_attr( (string) ( $config['capacity_morning_bikes'] ?? 5 ) ); ?>" min="1" step="1" />
							<span><?php echo esc_html__( 'bikes', 'tc-booking-flow-next' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tcbf_capacity_afternoon_bikes"><?php echo esc_html__( 'Afternoon capacity', 'tc-booking-flow-next' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="tcbf_capacity_afternoon_bikes" id="tcbf_capacity_afternoon_bikes"
								value="<?php echo esc_attr( (string) ( $config['capacity_afternoon_bikes'] ?? 5 ) ); ?>" min="1" step="1" />
							<span><?php echo esc_html__( 'bikes', 'tc-booking-flow-next' ); ?></span>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Zones -->
			<h2><?php echo esc_html__( 'Zones', 'tc-booking-flow-next' ); ?></h2>
			<p class="description" style="margin-bottom: 12px;">
				<?php echo esc_html__( 'Hub-based zones. Each zone is a center point (lat/lng) with a radius in km. Leave price fields empty to use global fallback prices.', 'tc-booking-flow-next' ); ?>
			</p>

			<table class="widefat striped" id="tcbf-zones-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'ID', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Name', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Lat', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Lng', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Radius (km)', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Delivery &euro;', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Pickup &euro;', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Both &euro;', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Remote', 'tc-booking-flow-next' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="tcbf-zones-body">
					<?php foreach ( $zones as $i => $zone ) : ?>
						<?php self::render_zone_row( $i, $zone ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top: 8px;">
				<button type="button" class="button" id="tcbf-add-zone">
					+ <?php echo esc_html__( 'Add Zone', 'tc-booking-flow-next' ); ?>
				</button>
			</p>

			<?php submit_button( __( 'Save Transport Settings', 'tc-booking-flow-next' ) ); ?>
		</form>

		<script>
		(function() {
			var idx = <?php echo count( $zones ); ?>;
			document.getElementById('tcbf-add-zone').addEventListener('click', function() {
				var tbody = document.getElementById('tcbf-zones-body');
				var row = document.createElement('tr');
				row.innerHTML =
					'<td><input type="text" name="tcbf_zones[' + idx + '][id]" value="" style="width:100px" /></td>' +
					'<td><input type="text" name="tcbf_zones[' + idx + '][name]" value="" style="width:140px" /></td>' +
					'<td><input type="number" name="tcbf_zones[' + idx + '][lat]" value="" step="0.0001" style="width:90px" /></td>' +
					'<td><input type="number" name="tcbf_zones[' + idx + '][lng]" value="" step="0.0001" style="width:90px" /></td>' +
					'<td><input type="number" name="tcbf_zones[' + idx + '][radius_km]" value="5" step="0.1" min="0.1" style="width:70px" /></td>' +
					'<td><input type="number" name="tcbf_zones[' + idx + '][delivery_price]" value="" step="0.01" min="0" style="width:70px" placeholder="—" /></td>' +
					'<td><input type="number" name="tcbf_zones[' + idx + '][pickup_price]" value="" step="0.01" min="0" style="width:70px" placeholder="—" /></td>' +
					'<td><input type="number" name="tcbf_zones[' + idx + '][both_price]" value="" step="0.01" min="0" style="width:70px" placeholder="—" /></td>' +
					'<td><input type="checkbox" name="tcbf_zones[' + idx + '][remote]" value="1" /></td>' +
					'<input type="hidden" name="tcbf_zones[' + idx + '][difficulty_factor]" value="1.0" />' +
					'<td><button type="button" class="button-link tcbf-remove-zone" style="color:#b32d2e">&times;</button></td>';
				tbody.appendChild(row);
				idx++;
			});

			document.getElementById('tcbf-zones-table').addEventListener('click', function(e) {
				if (e.target.classList.contains('tcbf-remove-zone')) {
					e.target.closest('tr').remove();
				}
			});
		})();
		</script>

		<?php
	}

	/**
	 * Render a single zone table row
	 */
	private static function render_zone_row( int $i, array $zone ) : void {
		$prefix = 'tcbf_zones[' . $i . ']';
		$delivery_val = ( isset( $zone['delivery_price'] ) && $zone['delivery_price'] !== null ) ? (string) $zone['delivery_price'] : '';
		$pickup_val   = ( isset( $zone['pickup_price'] ) && $zone['pickup_price'] !== null )     ? (string) $zone['pickup_price']   : '';
		$both_val     = ( isset( $zone['both_price'] ) && $zone['both_price'] !== null )         ? (string) $zone['both_price']     : '';
		?>
		<tr>
			<td>
				<input type="text" name="<?php echo esc_attr( $prefix . '[id]' ); ?>"
					value="<?php echo esc_attr( $zone['id'] ?? '' ); ?>" style="width:100px" />
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $prefix . '[name]' ); ?>"
					value="<?php echo esc_attr( $zone['name'] ?? '' ); ?>" style="width:140px" />
			</td>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix . '[lat]' ); ?>"
					value="<?php echo esc_attr( (string) ( $zone['lat'] ?? '' ) ); ?>" step="0.0001" style="width:90px" />
			</td>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix . '[lng]' ); ?>"
					value="<?php echo esc_attr( (string) ( $zone['lng'] ?? '' ) ); ?>" step="0.0001" style="width:90px" />
			</td>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix . '[radius_km]' ); ?>"
					value="<?php echo esc_attr( (string) ( $zone['radius_km'] ?? 5 ) ); ?>" step="0.1" min="0.1" style="width:70px" />
			</td>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix . '[delivery_price]' ); ?>"
					value="<?php echo esc_attr( $delivery_val ); ?>" step="0.01" min="0" style="width:70px" placeholder="—" />
			</td>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix . '[pickup_price]' ); ?>"
					value="<?php echo esc_attr( $pickup_val ); ?>" step="0.01" min="0" style="width:70px" placeholder="—" />
			</td>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix . '[both_price]' ); ?>"
					value="<?php echo esc_attr( $both_val ); ?>" step="0.01" min="0" style="width:70px" placeholder="—" />
			</td>
			<td>
				<input type="checkbox" name="<?php echo esc_attr( $prefix . '[remote]' ); ?>"
					value="1" <?php checked( ! empty( $zone['remote'] ) ); ?> />
			</td>
			<?php // difficulty_factor: preserved as hidden for backward compat ?>
			<input type="hidden" name="<?php echo esc_attr( $prefix . '[difficulty_factor]' ); ?>"
				value="<?php echo esc_attr( (string) ( $zone['difficulty_factor'] ?? 1.0 ) ); ?>" />
			<td>
				<button type="button" class="button-link tcbf-remove-zone" style="color:#b32d2e">&times;</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Get all virtual/simple WC products suitable as transport product
	 */
	private static function get_virtual_products() : array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		return wc_get_products( [
			'status'  => 'publish',
			'limit'   => 200,
			'orderby' => 'name',
			'order'   => 'ASC',
			'virtual' => true,
		] );
	}

	/**
	 * Sanitize a price value
	 */
	private static function sanitize_price( $value ) : float {
		return round( max( 0, (float) $value ), 2 );
	}

	/**
	 * Sanitize a time value (HH:MM format)
	 */
	private static function sanitize_time( $value ) : string {
		$value = sanitize_text_field( wp_unslash( $value ) );
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return $value;
		}
		return '00:00';
	}
}
