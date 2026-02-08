<?php
/**
 * Notification Language Resolver
 *
 * Determines the correct language for email notifications based on:
 * - Customer: Language used during form submission / checkout
 * - Partner: User preference (meta) or per-booking override
 * - Admin: User preference (meta)
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Notification Language Resolver
 */
class NotificationLanguage {

	/**
	 * User meta key for notification language preference
	 */
	const USER_META_KEY = 'tcbf_notification_language';

	/**
	 * Order meta key for customer language
	 */
	const ORDER_META_KEY = '_tcbf_customer_language';

	/**
	 * GF entry meta key for submission language
	 */
	const ENTRY_META_KEY = '_tcbf_language';

	/**
	 * GF entry meta key for language override (admin/partner use)
	 */
	const ENTRY_OVERRIDE_KEY = '_tcbf_notification_language_override';

	/**
	 * Get available languages from qTranslate XT
	 *
	 * @return array Associative array of language code => language name
	 */
	public static function get_available_languages() : array {
		$languages = [];

		// Try qTranslate XT
		if ( function_exists( 'qtranxf_getSortedLanguages' ) && function_exists( 'qtranxf_getLanguageName' ) ) {
			$enabled = qtranxf_getSortedLanguages();
			foreach ( $enabled as $lang_code ) {
				$languages[ $lang_code ] = qtranxf_getLanguageName( $lang_code );
			}
		}

		// Fallback if qTranslate not available
		if ( empty( $languages ) ) {
			$languages = [
				'en' => __( 'English', 'flavor' ),
				'es' => __( 'Spanish', 'flavor' ),
			];
		}

		return $languages;
	}

	/**
	 * Get current qTranslate language
	 *
	 * @return string Language code (e.g., 'es', 'en')
	 */
	public static function get_current_language() : string {
		// Try qTranslate XT
		if ( function_exists( 'qtranxf_getLanguage' ) ) {
			return qtranxf_getLanguage();
		}

		// Fallback to WordPress locale
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return substr( $locale, 0, 2 );
	}

	/**
	 * Get default site language
	 *
	 * @return string Language code
	 */
	public static function get_default_language() : string {
		// Try qTranslate XT default
		if ( function_exists( 'qtranxf_getLanguageDefault' ) ) {
			return qtranxf_getLanguageDefault();
		}

		// Fallback to WordPress locale
		$locale = get_locale();
		return substr( $locale, 0, 2 );
	}

	/**
	 * Convert language code to WordPress locale
	 *
	 * @param string $lang_code Short language code (e.g., 'es', 'en')
	 * @return string WordPress locale (e.g., 'es_ES', 'en_US')
	 */
	public static function lang_to_locale( string $lang_code ) : string {
		$map = [
			'es' => 'es_ES',
			'en' => 'en_US',
			'ca' => 'ca',
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'it' => 'it_IT',
			'pt' => 'pt_PT',
			'nl' => 'nl_NL',
		];

		return $map[ $lang_code ] ?? $lang_code;
	}

	/**
	 * Get language for customer notifications
	 *
	 * Resolution order:
	 * 1. Entry override (field 207) - admin/partner explicitly chose language
	 * 2. Order meta - customer language captured at checkout
	 * 3. Entry meta - submission language from field 206 or auto-detected
	 * 4. Fallback - site default language
	 *
	 * @param \WC_Order|int $order Order object or ID
	 * @return string Language code
	 */
	public static function for_customer( $order ) : string {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return self::get_default_language();
		}

		// Collect entry IDs from order items for override/language lookup
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			$entry_id = $item->get_meta( '_gf_entry_id', true );
			if ( ! $entry_id ) {
				// Try WC GF Product Add-ons location
				$history = $item->get_meta( '_gravity_forms_history', true );
				if ( is_array( $history ) && ! empty( $history['_gravity_form_linked_entry_id'] ) ) {
					$entry_id = $history['_gravity_form_linked_entry_id'];
				}
			}
			if ( $entry_id ) {
				$entry_ids[] = (int) $entry_id;
			}
		}

		// Priority 1: Check for admin/partner override in linked entries
		if ( ! empty( $entry_ids ) && class_exists( 'GFAPI' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				$override = \gform_get_meta( $entry_id, self::ENTRY_OVERRIDE_KEY );
				if ( ! empty( $override ) ) {
					return $override;
				}
			}
		}

		// Priority 2: Try order meta (customer language captured at checkout)
		$lang = $order->get_meta( self::ORDER_META_KEY, true );
		if ( ! empty( $lang ) ) {
			return $lang;
		}

		// Priority 3: Try entry meta (submission language from field 206 or auto-detected)
		if ( ! empty( $entry_ids ) && class_exists( 'GFAPI' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				$entry_lang = \gform_get_meta( $entry_id, self::ENTRY_META_KEY );
				if ( ! empty( $entry_lang ) ) {
					return $entry_lang;
				}
			}
		}

		// Priority 4: Fallback to default
		return self::get_default_language();
	}

	/**
	 * Get language for partner notifications
	 *
	 * @param int   $user_id Partner user ID
	 * @param array $entry   Optional GF entry (for per-booking override)
	 * @return string Language code
	 */
	public static function for_partner( int $user_id, array $entry = [] ) : string {
		// Check for per-booking override in entry
		if ( ! empty( $entry['id'] ) && class_exists( 'GFAPI' ) ) {
			$override = \gform_get_meta( $entry['id'], self::ENTRY_OVERRIDE_KEY );
			if ( ! empty( $override ) ) {
				return $override;
			}
		}

		// Check user preference
		if ( $user_id > 0 ) {
			$pref = get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( ! empty( $pref ) ) {
				return $pref;
			}
		}

		// Fallback to default
		return self::get_default_language();
	}

	/**
	 * Get language for admin notifications
	 *
	 * @param int $user_id Admin user ID (0 = site admin email recipient)
	 * @return string Language code
	 */
	public static function for_admin( int $user_id = 0 ) : string {
		// Check user preference
		if ( $user_id > 0 ) {
			$pref = get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( ! empty( $pref ) ) {
				return $pref;
			}
		}

		// Fallback to default (admin emails typically in site default)
		return self::get_default_language();
	}

	/**
	 * Get user's notification language preference
	 *
	 * @param int $user_id User ID
	 * @return string Language code or empty string if not set
	 */
	public static function get_user_preference( int $user_id ) : string {
		return (string) get_user_meta( $user_id, self::USER_META_KEY, true );
	}

	/**
	 * Set user's notification language preference
	 *
	 * @param int    $user_id User ID
	 * @param string $lang    Language code
	 * @return bool Success
	 */
	public static function set_user_preference( int $user_id, string $lang ) : bool {
		$available = self::get_available_languages();

		// Validate language code
		if ( ! empty( $lang ) && ! isset( $available[ $lang ] ) ) {
			return false;
		}

		if ( empty( $lang ) ) {
			delete_user_meta( $user_id, self::USER_META_KEY );
		} else {
			update_user_meta( $user_id, self::USER_META_KEY, $lang );
		}

		return true;
	}

	/**
	 * Store customer language on order
	 *
	 * @param \WC_Order $order Order object
	 * @param string    $lang  Language code (empty = detect current)
	 */
	public static function store_on_order( \WC_Order $order, string $lang = '' ) : void {
		if ( empty( $lang ) ) {
			$lang = self::get_current_language();
		}

		$order->update_meta_data( self::ORDER_META_KEY, $lang );
	}

	/**
	 * Store submission language on GF entry
	 *
	 * @param int    $entry_id Entry ID
	 * @param string $lang     Language code (empty = detect current)
	 */
	public static function store_on_entry( int $entry_id, string $lang = '' ) : void {
		if ( empty( $lang ) ) {
			$lang = self::get_current_language();
		}

		if ( function_exists( 'gform_update_meta' ) ) {
			\gform_update_meta( $entry_id, self::ENTRY_META_KEY, $lang );
		}
	}

	/**
	 * Execute callback with switched locale
	 *
	 * @param string   $lang     Target language code
	 * @param callable $callback Callback to execute
	 * @return mixed Callback return value
	 */
	public static function with_locale( string $lang, callable $callback ) {
		$locale = self::lang_to_locale( $lang );
		$switched = false;

		// Switch WordPress locale
		if ( function_exists( 'switch_to_locale' ) && $locale !== get_locale() ) {
			switch_to_locale( $locale );
			$switched = true;
		}

		// Switch qTranslate language
		$qtx_switched = false;
		$prev_qtx_lang = null;
		if ( function_exists( 'qtranxf_getLanguage' ) && function_exists( 'qtranxf_setLanguage' ) ) {
			$prev_qtx_lang = qtranxf_getLanguage();
			if ( $prev_qtx_lang !== $lang ) {
				// Use global to switch qTranslate language
				global $q_config;
				if ( isset( $q_config ) ) {
					$q_config['language'] = $lang;
					$qtx_switched = true;
				}
			}
		}

		try {
			$result = $callback();
		} finally {
			// Restore qTranslate language
			if ( $qtx_switched && $prev_qtx_lang !== null ) {
				global $q_config;
				if ( isset( $q_config ) ) {
					$q_config['language'] = $prev_qtx_lang;
				}
			}

			// Restore WordPress locale
			if ( $switched && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		}

		return $result;
	}

	/* =========================================================================
	 * ADMIN UI - User Profile Fields
	 * ========================================================================= */

	/**
	 * Render notification language field on user profile page
	 *
	 * Hook: show_user_profile, edit_user_profile
	 *
	 * @param \WP_User $user User object
	 */
	public static function render_user_profile_field( \WP_User $user ) : void {
		// Only show for users who might receive notifications (admins, partners)
		if ( ! user_can( $user, 'edit_posts' ) && ! self::user_is_partner( $user->ID ) ) {
			return;
		}

		$current = self::get_user_preference( $user->ID );
		$languages = self::get_available_languages();
		$default_lang = self::get_default_language();
		?>
		<h3><?php esc_html_e( 'Notification Preferences', 'flavor' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th>
					<label for="tcbf_notification_language">
						<?php esc_html_e( 'Notification Language', 'flavor' ); ?>
					</label>
				</th>
				<td>
					<select name="tcbf_notification_language" id="tcbf_notification_language">
						<option value="">
							<?php
							printf(
								/* translators: %s: default language name */
								esc_html__( 'Site default (%s)', 'flavor' ),
								esc_html( $languages[ $default_lang ] ?? $default_lang )
							);
							?>
						</option>
						<?php foreach ( $languages as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>>
								<?php echo esc_html( $name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Choose the language for email notifications you receive (booking confirmations, admin alerts, etc.)', 'flavor' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save notification language field from user profile
	 *
	 * Hook: personal_options_update, edit_user_profile_update
	 *
	 * @param int $user_id User ID being updated
	 */
	public static function save_user_profile_field( int $user_id ) : void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$lang = isset( $_POST['tcbf_notification_language'] ) ? sanitize_text_field( $_POST['tcbf_notification_language'] ) : '';
		self::set_user_preference( $user_id, $lang );
	}

	/**
	 * Check if user is a partner
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	private static function user_is_partner( int $user_id ) : bool {
		// Check for partner role or partner code meta
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		// Check roles
		$partner_roles = [ 'partner', 'tc_partner', 'hotel_partner' ];
		if ( array_intersect( $partner_roles, $user->roles ) ) {
			return true;
		}

		// Check partner code meta
		$partner_code = get_user_meta( $user_id, 'partner_code', true );
		if ( ! empty( $partner_code ) ) {
			return true;
		}

		return false;
	}

	/* =========================================================================
	 * MY ACCOUNT UI - Frontend for Partners
	 * ========================================================================= */

	/**
	 * Render notification language field on WooCommerce My Account page
	 *
	 * Hook: woocommerce_edit_account_form
	 */
	public static function render_my_account_field() : void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Only show for partners or users with edit_posts capability
		if ( ! current_user_can( 'edit_posts' ) && ! self::user_is_partner( $user_id ) ) {
			return;
		}

		$current = self::get_user_preference( $user_id );
		$languages = self::get_available_languages();
		$default_lang = self::get_default_language();
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Notification Preferences', 'flavor' ); ?></legend>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="tcbf_notification_language">
					<?php esc_html_e( 'Notification Language', 'flavor' ); ?>
				</label>
				<select name="tcbf_notification_language" id="tcbf_notification_language" class="woocommerce-Input woocommerce-Input--select input-select">
					<option value="">
						<?php
						printf(
							/* translators: %s: default language name */
							esc_html__( 'Site default (%s)', 'flavor' ),
							esc_html( $languages[ $default_lang ] ?? $default_lang )
						);
						?>
					</option>
					<?php foreach ( $languages as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>>
							<?php echo esc_html( $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description">
					<?php esc_html_e( 'Choose the language for email notifications you receive.', 'flavor' ); ?>
				</span>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Save notification language field from My Account form
	 *
	 * Hook: woocommerce_save_account_details
	 *
	 * @param int $user_id User ID
	 */
	public static function save_my_account_field( int $user_id ) : void {
		$lang = isset( $_POST['tcbf_notification_language'] ) ? sanitize_text_field( $_POST['tcbf_notification_language'] ) : '';
		self::set_user_preference( $user_id, $lang );
	}

	/* =========================================================================
	 * HOOK HANDLERS - Called from Plugin.php
	 * ========================================================================= */

	/**
	 * Capture language on GF form submission
	 *
	 * Hook: gform_after_submission (priority 5, before other handlers)
	 *
	 * Reads:
	 * - Field 206 (participant_language): Auto-populated hidden field with qTranslate language
	 * - Field 207 (participant_notification_language_override): Admin/partner override select
	 *
	 * @param array $entry GF entry
	 * @param array $form  GF form
	 */
	public static function on_gf_submission( array $entry, array $form ) : void {
		if ( empty( $entry['id'] ) ) {
			return;
		}

		$entry_id = (int) $entry['id'];

		// Check for admin/partner override (field 207)
		// Use semantic field resolution if available, fallback to direct field ID
		$override = '';
		if ( class_exists( '\\TC_BF\\Integrations\\GravityForms\\GF_SemanticFields' ) ) {
			$override = \TC_BF\Integrations\GravityForms\GF_SemanticFields::entry_value(
				$entry,
				(int) ( $form['id'] ?? 0 ),
				'participant_notification_language_override'
			);
		}
		// Fallback: try field 207 directly
		if ( empty( $override ) && ! empty( $entry['207'] ) ) {
			$override = $entry['207'];
		}

		// Store override if set (validate against available languages)
		if ( ! empty( $override ) ) {
			$available = self::get_available_languages();
			if ( isset( $available[ $override ] ) ) {
				\gform_update_meta( $entry_id, self::ENTRY_OVERRIDE_KEY, $override );
			}
		}

		// Get language from field 206, or detect current language
		$lang = '';
		if ( class_exists( '\\TC_BF\\Integrations\\GravityForms\\GF_SemanticFields' ) ) {
			$lang = \TC_BF\Integrations\GravityForms\GF_SemanticFields::entry_value(
				$entry,
				(int) ( $form['id'] ?? 0 ),
				'participant_language'
			);
		}
		// Fallback: try field 206 directly
		if ( empty( $lang ) && ! empty( $entry['206'] ) ) {
			$lang = $entry['206'];
		}
		// Final fallback: detect current language
		if ( empty( $lang ) ) {
			$lang = self::get_current_language();
		}

		self::store_on_entry( $entry_id, $lang );
	}

	/**
	 * Capture language on WC order creation
	 *
	 * Hook: woocommerce_checkout_order_processed (priority 5, early)
	 *
	 * @param int       $order_id    Order ID
	 * @param array     $posted_data Posted checkout data
	 * @param \WC_Order $order       Order object
	 */
	public static function on_checkout_order_processed( int $order_id, array $posted_data, \WC_Order $order ) : void {
		self::store_on_order( $order );
		$order->save();
	}

	/**
	 * Copy language from GF entry to order item when entry is linked
	 *
	 * Hook: woocommerce_gravityforms_entry_created (priority 15)
	 *
	 * @param int|mixed $entry_id   GF entry ID just created.
	 * @param int|mixed $order_id   WC order ID.
	 * @param mixed     $order_item WC_Order_Item_Product instance.
	 * @param mixed     $form_data  Form config array.
	 * @param mixed     $lead_data  Raw field values.
	 */
	public static function on_gf_entry_linked_to_order( $entry_id, $order_id, $order_item, $form_data, $lead_data ) : void {
		$entry_id = (int) $entry_id;
		$order_id = (int) $order_id;
		// Get entry language
		$entry_lang = '';
		if ( function_exists( 'gform_get_meta' ) ) {
			$entry_lang = \gform_get_meta( $entry_id, self::ENTRY_META_KEY );
		}

		if ( empty( $entry_lang ) ) {
			return;
		}

		// Update order meta if not already set
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$existing = $order->get_meta( self::ORDER_META_KEY, true );
			if ( empty( $existing ) ) {
				$order->update_meta_data( self::ORDER_META_KEY, $entry_lang );
				$order->save();
			}
		}
	}
}

