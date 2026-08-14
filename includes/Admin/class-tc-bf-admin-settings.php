<?php
namespace TC_BF\Admin;

use TC_BF\Integrations\GravityForms\GF_Notification_Templates;

if ( ! defined('ABSPATH') ) exit;

final class Settings {

	const OPT_FORM_ID = 'tc_bf_form_id';
	const OPT_DEBUG   = 'tc_bf_debug';
	const OPT_LOGS    = 'tc_bf_logs';

	// TCBF-11+: Global fallback participation product (bookable products only)
	const OPT_DEFAULT_PARTICIPATION_PRODUCT_ID = 'tcbf_default_participation_product_id';

	// TCBF-12: Partner program toggle default (enabled by default)
	const OPT_PARTNERS_ENABLED_DEFAULT = 'tcbf_partners_enabled_default';

	// TCBF-13: Booking product form ID (for GF Product Add-ons integration)
	const OPT_BOOKING_FORM_ID = 'tcbf_booking_form_id';

	// TCBF Participants List settings
	const OPT_PARTICIPANTS_PRIVACY_MODE   = 'tcbf_participants_privacy_mode';
	const OPT_PARTICIPANTS_EVENT_UID_FIELD = 'tcbf_participants_event_uid_field_id';

	// TCBF: Forbidden pickup dates for bike rentals (start date only; returns unaffected)
	const OPT_FORBIDDEN_PICKUP_DATES = 'tcbf_forbidden_pickup_dates';
	const OPT_RENTAL_CATEGORY_IDS    = 'tcbf_rental_category_ids';

	public static function init() : void {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		// AJAX logging endpoint for admin-only diagnostics.
		add_action('wp_ajax_tc_bf_log', [__CLASS__, 'ajax_log']);
	}

	/**
	 * Compatibility shim (legacy callers rely on this).
	 *
	 * IMPORTANT:
	 * Older code calls append_log() with arrays/objects as the first argument.
	 * So we MUST accept mixed input and normalize safely.
	 *
	 * @param mixed $message string|array|object|int|etc
	 * @param mixed $context optional
	 */
	public static function append_log( $message, $context = null ) : void {
		if ( ! self::is_debug() ) {
			return;
		}

		// If legacy code passes an array/object as "message" and no context,
		// treat it as context and use a generic label.
		$label = 'log';
		if ( (is_array($message) || is_object($message)) && $context === null ) {
			$context = $message;
			$message = $label;
		}

		// Normalize message to string
		if ( is_array($message) || is_object($message) ) {
			$msg = wp_json_encode($message);
		} elseif ( $message === null ) {
			$msg = '';
		} else {
			$msg = (string) $message;
		}

		$line = gmdate('c') . ' ' . trim($msg);

		// Normalize context
		if ( $context !== null ) {
			if ( is_array($context) || is_object($context) ) {
				$line .= ' ' . wp_json_encode($context);
			} else {
				$line .= ' ' . (string) $context;
			}
		}

		// Server-side log (safe)
		error_log('[TC_BF] ' . $line);

		// Optional rolling buffer stored in wp_options (kept small)
		$logs = get_option(self::OPT_LOGS, []);
		if ( ! is_array($logs) ) {
			$logs = [];
		}

		$logs[] = $line;

		// Keep last 200 lines max to avoid bloating wp_options
		$max = 200;
		$count = count($logs);
		if ( $count > $max ) {
			$logs = array_slice($logs, $count - $max);
		}

		// No autoload
		update_option(self::OPT_LOGS, $logs, false);
	}

	public static function ajax_log() : void {
		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer( 'tc_bf_log', 'nonce' );

		$msg = isset($_POST['message']) ? sanitize_text_field( wp_unslash($_POST['message']) ) : '';
		$ctx = isset($_POST['context']) ? wp_unslash($_POST['context']) : '';

		if ( is_string($ctx) ) {
			$decoded = json_decode($ctx, true);
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$ctx = $decoded;
			}
		}

		error_log('[TC_BF] ajax_log: ' . $msg . ' ' . ( is_array($ctx) ? wp_json_encode($ctx) : (string) $ctx ));
		wp_send_json_success(['ok' => true]);
	}

	public static function menu() : void {
		add_options_page(
			'TC Booking Flow',
			'TC Booking Flow',
			'manage_options',
			'tc-bf-settings',
			[__CLASS__, 'render']
		);
	}

	/**
	 * Get current admin tab
	 */
	public static function get_current_tab() : string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$valid = [ 'general', 'transport', 'pickup' ];
		return in_array( $tab, $valid, true ) ? $tab : 'general';
	}

	public static function render() : void {
		if ( ! current_user_can('manage_options') ) return;

		$current_tab = self::get_current_tab();
		$page_url    = admin_url( 'options-general.php?page=tc-bf-settings' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('TC Booking Flow', 'tc-booking-flow-next'); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $page_url ); ?>"
				   class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( 'General', 'tc-booking-flow-next' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'transport', $page_url ) ); ?>"
				   class="nav-tab <?php echo $current_tab === 'transport' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( 'Transport', 'tc-booking-flow-next' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'pickup', $page_url ) ); ?>"
				   class="nav-tab <?php echo $current_tab === 'pickup' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( 'Pickup Restrictions', 'tc-booking-flow-next' ); ?>
				</a>
			</nav>

			<?php
			if ( $current_tab === 'transport' ) {
				Settings_Transport::render_tab();
			} elseif ( $current_tab === 'pickup' ) {
				self::render_pickup_tab();
			} else {
				self::render_general_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the Pickup Restrictions settings tab
	 *
	 * Uses its OWN Settings API group (tc_bf_pickup_settings). This is load-
	 * bearing, not cosmetic: wp-admin/options.php iterates every option
	 * registered in the submitted group and calls update_option() with NULL
	 * for any option absent from $_POST. Sharing a group across tab forms
	 * therefore resets the other tab's options through their sanitizers on
	 * every save. Each tab form must post a group containing exactly the
	 * options it renders.
	 */
	private static function render_pickup_tab() : void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields('tc_bf_pickup_settings'); ?>

			<h2><?php echo esc_html__('Pickup Restrictions', 'tc-booking-flow-next'); ?></h2>
			<p><?php echo esc_html__('Block bike rentals from STARTING (pickup) on specific dates without changing availability: rentals that start earlier may continue through these dates and may end (return) on them.', 'tc-booking-flow-next'); ?></p>

			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_FORBIDDEN_PICKUP_DATES); ?>"><?php echo esc_html__('Forbidden pickup dates', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<textarea class="large-text code" rows="5" name="<?php echo esc_attr(self::OPT_FORBIDDEN_PICKUP_DATES); ?>" id="<?php echo esc_attr(self::OPT_FORBIDDEN_PICKUP_DATES); ?>"><?php echo esc_textarea( (string) get_option(self::OPT_FORBIDDEN_PICKUP_DATES, '') ); ?></textarea>
							<p class="description">
								<?php echo esc_html__('Strict format, one entry per line: a single date (2026-09-01) or a range (2026-08-17 - 2026-08-19; reversed ranges are auto-swapped). Lines starting with # are comments. A save containing any invalid line is rejected and the previous value kept.', 'tc-booking-flow-next'); ?>
							</p>
							<?php
							$fp_parsed = \TC_BF\Integrations\WooCommerce\Woo_ForbiddenPickup::parse_config( (string) get_option(self::OPT_FORBIDDEN_PICKUP_DATES, '') );
							if ( $fp_parsed['ranges'] ) {
								$fp_labels = array_map( function( $r ) {
									return $r['start'] === $r['end'] ? $r['start'] : $r['start'] . ' → ' . $r['end'];
								}, $fp_parsed['ranges'] );
								echo '<p class="description"><strong>' . esc_html__('Currently active:', 'tc-booking-flow-next') . '</strong> ' . esc_html( implode( ', ', $fp_labels ) ) . '</p>';
							}
							?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php echo esc_html__('Rental categories', 'tc-booking-flow-next'); ?>
						</th>
						<td>
							<?php
							$selected_cats = array_filter( array_map( 'absint', explode( ',', (string) get_option(self::OPT_RENTAL_CATEGORY_IDS, '207,208,209,219') ) ) );
							$all_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] );
							?>
							<input type="hidden" name="<?php echo esc_attr(self::OPT_RENTAL_CATEGORY_IDS); ?>" value="" />
							<?php if ( is_array( $all_cats ) && $all_cats ) : ?>
								<fieldset style="max-height: 220px; overflow-y: auto; border: 1px solid #c3c4c7; padding: 8px 12px; background: #fff;">
									<?php foreach ( $all_cats as $cat ) : ?>
										<label style="display: block; margin: 2px 0;">
											<input type="checkbox" name="<?php echo esc_attr(self::OPT_RENTAL_CATEGORY_IDS); ?>[]" value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php checked( in_array( (int) $cat->term_id, $selected_cats, true ) ); ?> />
											<?php echo esc_html( $cat->name . ' (' . $cat->term_id . ')' ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							<?php else : ?>
								<p><?php echo esc_html__('No product categories found.', 'tc-booking-flow-next'); ?></p>
							<?php endif; ?>
							<p class="description">
								<?php echo esc_html__('Product categories treated as bike rentals. Only products in the checked categories are subject to the pickup restriction. Unchecking all disables the restriction for every product.', 'tc-booking-flow-next'); ?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the General settings tab
	 */
	private static function render_general_tab() : void {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields('tc_bf_settings');
			do_settings_sections('tc_bf_settings');
			?>

			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_FORM_ID); ?>"><?php echo esc_html__('Event Form ID', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_FORM_ID); ?>" id="<?php echo esc_attr(self::OPT_FORM_ID); ?>" value="<?php echo esc_attr( (string) get_option(self::OPT_FORM_ID, 44) ); ?>" min="1" step="1" />
							<p class="description"><?php echo esc_html__('The Gravity Form used for event registrations (Form 44).', 'tc-booking-flow-next'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_BOOKING_FORM_ID); ?>"><?php echo esc_html__('Booking Product Form ID', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_BOOKING_FORM_ID); ?>" id="<?php echo esc_attr(self::OPT_BOOKING_FORM_ID); ?>" value="<?php echo esc_attr( (string) get_option(self::OPT_BOOKING_FORM_ID, 55) ); ?>" min="1" step="1" />
							<p class="description"><?php echo esc_html__('The Gravity Form used for booking products with GF Product Add-ons (rentals).', 'tc-booking-flow-next'); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID); ?>"><?php echo esc_html__('Fallback Participation Product', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<?php
							$val = (int) get_option(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID, 0);

							$products = get_posts([
								'post_type'      => 'product',
								'post_status'    => 'publish',
								'posts_per_page' => 500,
								'orderby'        => 'title',
								'order'          => 'ASC',
								'tax_query'      => [[
									'taxonomy' => 'product_type',
									'field'    => 'slug',
									'terms'    => ['booking'],
								]],
							]);
							?>
							<select name="<?php echo esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID); ?>" id="<?php echo esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID); ?>">
								<option value="0"><?php echo esc_html__('— None —', 'tc-booking-flow-next'); ?></option>
								<?php foreach ( $products as $p ) : ?>
									<option value="<?php echo esc_attr((string) $p->ID); ?>" <?php selected($val, (int)$p->ID); ?>>
										<?php echo esc_html($p->post_title . ' (#' . $p->ID . ')'); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php echo esc_html__('Used when an event does not have a custom participation product selected.', 'tc-booking-flow-next'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_PARTNERS_ENABLED_DEFAULT); ?>"><?php echo esc_html__('Partner program enabled by default', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<?php $enabled = (int) get_option(self::OPT_PARTNERS_ENABLED_DEFAULT, 1) === 1; ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPT_PARTNERS_ENABLED_DEFAULT); ?>" id="<?php echo esc_attr(self::OPT_PARTNERS_ENABLED_DEFAULT); ?>" value="1" <?php checked($enabled); ?> />
								<?php echo esc_html__('Enable partner program for all events by default (unless disabled at event level).', 'tc-booking-flow-next'); ?>
							</label>
							<p class="description">
								<?php echo esc_html__('When disabled for an event: partner coupons will not apply and no commission will be calculated. Direct booking remains unaffected.', 'tc-booking-flow-next'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_DEBUG); ?>"><?php echo esc_html__('Debug Mode', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<?php $debug = (int) get_option(self::OPT_DEBUG, 0) === 1; ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPT_DEBUG); ?>" id="<?php echo esc_attr(self::OPT_DEBUG); ?>" value="1" <?php checked($debug); ?> />
								<?php echo esc_html__('Enable debug logging (server logs only).', 'tc-booking-flow-next'); ?>
							</label>
						</td>
					</tr>

				</tbody>
			</table>

			<h2 style="margin-top: 2em;"><?php echo esc_html__('Participants List', 'tc-booking-flow-next'); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_PARTICIPANTS_PRIVACY_MODE); ?>"><?php echo esc_html__('Privacy Mode', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<?php $privacy_mode = get_option(self::OPT_PARTICIPANTS_PRIVACY_MODE, 'public_masked'); ?>
							<select name="<?php echo esc_attr(self::OPT_PARTICIPANTS_PRIVACY_MODE); ?>" id="<?php echo esc_attr(self::OPT_PARTICIPANTS_PRIVACY_MODE); ?>">
								<option value="public_masked" <?php selected($privacy_mode, 'public_masked'); ?>><?php echo esc_html__('Public (masked names/emails)', 'tc-booking-flow-next'); ?></option>
								<option value="admin_only" <?php selected($privacy_mode, 'admin_only'); ?>><?php echo esc_html__('Admin only (hidden from public)', 'tc-booking-flow-next'); ?></option>
								<option value="full" <?php selected($privacy_mode, 'full'); ?>><?php echo esc_html__('Full (show all data publicly)', 'tc-booking-flow-next'); ?></option>
							</select>
							<p class="description">
								<?php echo esc_html__('Controls visibility of participant data. Admins (manage_options or manage_woocommerce) always see full data.', 'tc-booking-flow-next'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr(self::OPT_PARTICIPANTS_EVENT_UID_FIELD); ?>"><?php echo esc_html__('Event UID Field ID', 'tc-booking-flow-next'); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_PARTICIPANTS_EVENT_UID_FIELD); ?>" id="<?php echo esc_attr(self::OPT_PARTICIPANTS_EVENT_UID_FIELD); ?>" value="<?php echo esc_attr( (string) get_option(self::OPT_PARTICIPANTS_EVENT_UID_FIELD, 145) ); ?>" min="1" step="1" />
							<p class="description">
								<?php echo esc_html__('Gravity Forms field ID that stores the event unique identifier (default: 145).', 'tc-booking-flow-next'); ?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>

		<?php
		$debug = self::is_debug();
		if ( ! $debug ) {
			?>
			<hr/>
			<h2><?php echo esc_html__('Form Health', 'tc-booking-flow-next'); ?></h2>
			<?php
			if ( class_exists( '\\TC_BF\\Integrations\\GravityForms\\GF_FormValidator' ) ) {
				\TC_BF\Integrations\GravityForms\GF_FormValidator::render_health_section();
			}
			?>

			<hr/>
			<h2><?php echo esc_html__('Tools', 'tc-booking-flow-next'); ?></h2>

			<?php self::render_notification_tools(); ?>

			<hr/>
			<h2><?php echo esc_html__('Diagnostics', 'tc-booking-flow-next'); ?></h2>

			<?php
			echo '<p><em>' . esc_html__('Debug mode is currently off. Enable it above to collect logs.', 'tc-booking-flow-next') . '</em></p>';
		} else {
			// Clear logs handler
			if ( isset($_POST['tc_bf_clear_logs']) && check_admin_referer('tc_bf_clear_logs') ) {
				self::clear_logs();
				echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared.', 'tc-booking-flow-next') . '</p></div>';
			}

			$logs = array_reverse( self::get_logs() );
			?>
			<form method="post" style="margin:0 0 12px 0;">
				<?php wp_nonce_field('tc_bf_clear_logs'); ?>
				<input type="hidden" name="tc_bf_clear_logs" value="1" />
				<?php submit_button( esc_html__('Clear logs', 'tc-booking-flow-next'), 'secondary', 'submit', false ); ?>
			</form>

			<?php
			// Process sync POST BEFORE rendering health check, so patched inputName is visible
			$sync_result = null;
			if ( isset( $_POST['tcbf_sync_notifications'] ) && check_admin_referer( 'tcbf_sync_notifications' ) ) {
				$dry_run = isset( $_POST['tcbf_dry_run'] );
				$sync_result = \TC_BF\Integrations\GravityForms\GF_Notification_Templates::sync_all( $dry_run );
			}

			if ( class_exists( '\\TC_BF\\Integrations\\GravityForms\\GF_FormValidator' ) ) {
				\TC_BF\Integrations\GravityForms\GF_FormValidator::render_health_section();
			}
			?>

			<hr/>
			<h2><?php echo esc_html__('Tools', 'tc-booking-flow-next'); ?></h2>

			<?php self::render_notification_tools( $sync_result ); ?>

			<hr/>
			<h2><?php echo esc_html__('Diagnostics', 'tc-booking-flow-next'); ?></h2>

			<?php
			echo '<table class="widefat striped" style="max-width: 1200px;">';
			echo '<thead><tr><th>' . esc_html__('Log Entry', 'tc-booking-flow-next') . '</th></tr></thead><tbody>';
			foreach ( $logs as $line ) {
				echo '<tr><td><pre style="white-space:pre-wrap; margin:0; font-family:monospace; font-size:12px;">' . esc_html($line) . '</pre></td></tr>';
			}
			echo '</tbody></table>';

			$bundle = [
				'site' => home_url(),
				'time' => gmdate('c'),
				'plugin' => 'tc-booking-flow-next',
				'version' => defined('TC_BF_VERSION') ? TC_BF_VERSION : '',
				'logs' => array_reverse($logs),
			];
			$json = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			echo '<h3 style="margin-top:16px;">' . esc_html__('Copy debug bundle', 'tc-booking-flow-next') . '</h3>';
			echo '<textarea readonly style="width:100%; max-width:1200px; height:240px; font-family:monospace; font-size:12px;">' . esc_textarea($json) . '</textarea>';
		}
		?>
		<?php
	}

	public static function register_settings() : void {
		register_setting('tc_bf_settings', self::OPT_FORM_ID, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return absint($v); },
			'default'           => 44,
		]);

		register_setting('tc_bf_settings', self::OPT_DEBUG, [
			'type' => 'boolean',
			'sanitize_callback' => function($v){ return (int)(!empty($v)); },
			'default' => 0,
		]);

		register_setting('tc_bf_settings', self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return absint($v); },
			'default'           => 0,
		]);

		register_setting('tc_bf_settings', self::OPT_PARTNERS_ENABLED_DEFAULT, [
			'type' => 'boolean',
			'sanitize_callback' => function($v){ return (int)(!empty($v)); },
			'default' => 1,
		]);

		// Participants List settings
		register_setting('tc_bf_settings', self::OPT_PARTICIPANTS_PRIVACY_MODE, [
			'type'              => 'string',
			'sanitize_callback' => function($v){
				$valid = ['public_masked', 'admin_only', 'full'];
				return in_array($v, $valid, true) ? $v : 'public_masked';
			},
			'default'           => 'public_masked',
		]);

		register_setting('tc_bf_settings', self::OPT_PARTICIPANTS_EVENT_UID_FIELD, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return max(1, absint($v)); },
			'default'           => 145,
		]);

		// TCBF-13: Booking product form ID
		register_setting('tc_bf_settings', self::OPT_BOOKING_FORM_ID, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return absint($v); },
			'default'           => 55,
		]);

		// TCBF Pickup Restrictions live in their OWN settings group: the tab
		// renders its own form, and options.php nulls out every registered
		// option of the posted group that is absent from $_POST. Keeping these
		// two options out of tc_bf_settings (and General options out of this
		// group) is what isolates the tabs' saves from each other.
		//
		// Forbidden pickup dates. Strict validation: a save containing any
		// malformed line is rejected whole (previous value retained) with a
		// settings error listing the bad lines, so a typo can never silently
		// become a different restriction.
		register_setting('tc_bf_pickup_settings', self::OPT_FORBIDDEN_PICKUP_DATES, [
			'type'              => 'string',
			'sanitize_callback' => function($v){
				$v      = sanitize_textarea_field( (string) $v );
				$parsed = \TC_BF\Integrations\WooCommerce\Woo_ForbiddenPickup::parse_config( $v );

				if ( $parsed['invalid'] ) {
					add_settings_error(
						self::OPT_FORBIDDEN_PICKUP_DATES,
						'tcbf_forbidden_pickup_invalid',
						sprintf(
							/* translators: %s: list of rejected config lines */
							__( 'Forbidden pickup dates were NOT saved. Each line must be a single date (2026-09-01) or a range (2026-08-17 - 2026-08-19). Invalid lines: %s', 'tc-booking-flow-next' ),
							'"' . implode( '", "', array_map( 'esc_html', $parsed['invalid'] ) ) . '"'
						),
						'error'
					);
					return (string) get_option( self::OPT_FORBIDDEN_PICKUP_DATES, '' );
				}

				if ( $parsed['ranges'] ) {
					$labels = array_map( function( $r ) {
						return $r['start'] === $r['end'] ? $r['start'] : $r['start'] . ' → ' . $r['end'];
					}, $parsed['ranges'] );
					add_settings_error(
						self::OPT_FORBIDDEN_PICKUP_DATES,
						'tcbf_forbidden_pickup_active',
						sprintf(
							/* translators: %s: list of active forbidden pickup ranges */
							__( 'Forbidden pickup dates saved. Active restrictions: %s', 'tc-booking-flow-next' ),
							implode( ', ', $labels )
						),
						'updated'
					);
				}

				return $v;
			},
			'default'           => '',
		]);

		// TCBF: Rental category IDs. Checkbox UI posts an array of term IDs;
		// stored as CSV. Unknown terms are dropped with a warning. An
		// explicitly empty selection is allowed and disables the restriction.
		register_setting('tc_bf_pickup_settings', self::OPT_RENTAL_CATEGORY_IDS, [
			'type'              => 'string',
			'sanitize_callback' => function($v){
				$raw = is_array( $v ) ? $v : explode( ',', (string) $v );
				$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );

				$valid = [];
				$unknown = [];
				foreach ( $ids as $id ) {
					$term = get_term( $id, 'product_cat' );
					if ( $term && ! is_wp_error( $term ) ) { $valid[] = $id; } else { $unknown[] = $id; }
				}
				if ( $unknown ) {
					add_settings_error(
						self::OPT_RENTAL_CATEGORY_IDS,
						'tcbf_rental_cats_unknown',
						sprintf(
							/* translators: %s: list of unknown product category IDs */
							__( 'Ignored unknown product category IDs: %s', 'tc-booking-flow-next' ),
							implode( ', ', $unknown )
						),
						'warning'
					);
				}
				if ( ! $valid ) {
					add_settings_error(
						self::OPT_RENTAL_CATEGORY_IDS,
						'tcbf_rental_cats_empty',
						__( 'No rental categories selected — the pickup restriction currently applies to no products.', 'tc-booking-flow-next' ),
						'warning'
					);
				}
				return implode( ',', $valid );
			},
			'default'           => '207,208,209,219',
		]);
	}

	public static function get_form_id() : int {
		$v = (int) get_option(self::OPT_FORM_ID, 44);
		return $v > 0 ? $v : 44;
	}

	/**
	 * Get the configured booking product form ID
	 *
	 * @return int Form ID (default 55)
	 */
	public static function get_booking_form_id() : int {
		$v = (int) get_option(self::OPT_BOOKING_FORM_ID, 55);
		return $v > 0 ? $v : 55;
	}

	/**
	 * Get the global fallback participation product ID.
	 *
	 * Used by Admin_Event_Meta (event edit screen) when displaying
	 * the participation product selector.
	 *
	 * @return int Product ID or 0 if none set.
	 */
	public static function get_default_participation_product_id() : int {
		return (int) get_option( self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID, 0 );
	}

	/**
	 * Return bookable (WooCommerce Bookings) products as [product_id => label].
	 *
	 * Label format: "Title (#ID)".
	 * Used by Admin_Event_Meta for the participation product dropdown.
	 *
	 * @return array<int, string> Array of product_id => label pairs.
	 */
	public static function get_bookable_products_for_select() : array {
		if ( ! function_exists( 'get_posts' ) ) {
			return [];
		}

		$products = get_posts([
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => [[
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => ['booking'],
			]],
		]);

		$out = [];
		foreach ( $products as $p ) {
			if ( empty( $p->ID ) ) {
				continue;
			}
			$out[(int) $p->ID] = $p->post_title . ' (#' . $p->ID . ')';
		}
		return $out;
	}

	public static function is_debug() : bool {
		return (int) get_option(self::OPT_DEBUG, 0) === 1;
	}

	public static function get_logs() : array {
		$logs = get_option(self::OPT_LOGS, []);
		return is_array($logs) ? $logs : [];
	}

	public static function clear_logs() : void {
		delete_option(self::OPT_LOGS);
	}

	/**
	 * Render notification sync tools section
	 */
	private static function render_notification_tools( ?array $result = null ): void {
		// Display sync result (POST was already processed before health check)
		if ( $result !== null ) {
			$dry_run = isset( $_POST['tcbf_dry_run'] );

			if ( $result['success'] ) {
				$msg = $dry_run
					? __( 'Dry run complete. No changes made.', 'tc-booking-flow-next' )
					: __( 'Notifications synced successfully.', 'tc-booking-flow-next' );
				echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Sync completed with errors:', 'tc-booking-flow-next' ) . '</p>';
				echo '<ul>';
				foreach ( $result['errors'] as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			}

			// Show details
			echo '<div class="tcbf-sync-details" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 15px 0; max-width: 800px;">';
			echo '<h4 style="margin-top: 0;">' . esc_html__( 'Sync Details', 'tc-booking-flow-next' ) . '</h4>';

			foreach ( $result['forms'] as $form_id => $form_result ) {
				echo '<p><strong>' . esc_html__( 'Form', 'tc-booking-flow-next' ) . ' ' . esc_html( (string) $form_id ) . ':</strong></p>';
				echo '<ul style="margin-left: 20px;">';

				if ( ! empty( $form_result['notifications'] ) ) {
					foreach ( $form_result['notifications'] as $id => $notif_result ) {
						$action = $notif_result['action'] ?? 'unknown';
						$icon = match( $action ) {
							'add'    => '+',
							'update' => '~',
							'skip'   => '-',
							'error'  => '!',
							default  => '?',
						};
						echo '<li><code>' . esc_html( $icon ) . '</code> ' . esc_html( $id ) . ': ' . esc_html( $notif_result['message'] ?? '' ) . '</li>';
					}
				}

				echo '</ul>';
			}

			echo '</div>';
		}

		// Get current status
		$status = GF_Notification_Templates::get_status();
		?>

		<h3><?php echo esc_html__( 'GF Notification Sync', 'tc-booking-flow-next' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'Sync TCBF notification templates to configured Gravity Forms. This will add or update notifications with tcbf_* IDs only.', 'tc-booking-flow-next' ); ?>
		</p>

		<?php if ( isset( $status['error'] ) ) : ?>
			<div class="notice notice-warning inline">
				<p><?php echo esc_html( $status['error'] ); ?></p>
			</div>
		<?php else : ?>

			<table class="widefat striped" style="max-width: 800px; margin: 15px 0;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Form', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'TCBF Notifications', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'tc-booking-flow-next' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status['forms'] as $form_id => $form_status ) : ?>
						<tr>
							<td>
								<strong>#<?php echo esc_html( (string) $form_id ); ?></strong>
								<?php if ( $form_status['exists'] ) : ?>
									<br><small><?php echo esc_html( $form_status['title'] ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $form_status['exists'] ) : ?>
									<em><?php echo esc_html__( 'Form not found', 'tc-booking-flow-next' ); ?></em>
								<?php elseif ( empty( $form_status['notifications'] ) ) : ?>
									<em><?php echo esc_html__( 'None installed', 'tc-booking-flow-next' ); ?></em>
								<?php else : ?>
									<ul style="margin: 0; padding-left: 15px;">
										<?php foreach ( $form_status['notifications'] as $id => $notif ) : ?>
											<li>
												<?php echo esc_html( $notif['name'] ); ?>
												<small style="color: <?php echo $notif['isActive'] ? 'green' : 'gray'; ?>;">
													(<?php echo $notif['isActive'] ? 'active' : 'inactive'; ?>)
												</small>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $form_status['exists'] ) : ?>
									<span style="color: red;">&#10007;</span>
								<?php elseif ( empty( $form_status['missing'] ) ) : ?>
									<span style="color: green;">&#10003; <?php echo esc_html__( 'Up to date', 'tc-booking-flow-next' ); ?></span>
								<?php else : ?>
									<span style="color: orange;">&#9888; <?php echo esc_html( count( $form_status['missing'] ) ); ?> <?php echo esc_html__( 'missing', 'tc-booking-flow-next' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" style="margin: 15px 0;">
				<?php wp_nonce_field( 'tcbf_sync_notifications' ); ?>
				<p>
					<label>
						<input type="checkbox" name="tcbf_dry_run" value="1" />
						<?php echo esc_html__( 'Dry run (validate only, no changes)', 'tc-booking-flow-next' ); ?>
					</label>
				</p>
				<?php submit_button( esc_html__( 'Sync Notifications', 'tc-booking-flow-next' ), 'secondary', 'tcbf_sync_notifications', false ); ?>
			</form>

		<?php endif;
	}
}
