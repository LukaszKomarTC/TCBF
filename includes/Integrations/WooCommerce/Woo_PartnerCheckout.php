<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce Partner Checkout Simplification
 *
 * Simplifies checkout for hotel partners by:
 * - Hiding billing fields (partners manage billing in My Account)
 * - Displaying informational message about saved billing details
 * - Auto-populating order billing data from user profile
 *
 * This reduces checkout friction for partners who make frequent bookings.
 *
 * @since 0.8.0
 */
final class Woo_PartnerCheckout {

	/**
	 * Roles that get simplified checkout.
	 *
	 * Note: Administrator excluded so they can test full checkout flow.
	 */
	private const PARTNER_ROLES = [ 'hotel' ];

	/**
	 * Billing fields to copy from user meta to order.
	 */
	private const BILLING_FIELDS = [
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'email',
		'phone',
	];

	/**
	 * Initialize partner checkout customizations.
	 */
	public static function init(): void {
		// Only load on frontend
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Defer role check until user is available
		add_action( 'wp', [ __CLASS__, 'maybe_register_hooks' ] );

		// Order billing population runs regardless of page context
		// (needed for AJAX checkout processing)
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'populate_order_billing' ], 10, 2 );
	}

	/**
	 * Register checkout display hooks if user is a partner.
	 *
	 * Called on 'wp' action when user is available.
	 */
	public static function maybe_register_hooks(): void {
		if ( ! self::user_is_partner() ) {
			return;
		}

		// Display info message before billing form
		add_action( 'woocommerce_before_checkout_billing_form', [ __CLASS__, 'render_billing_message' ] );

		// Remove billing fields from checkout form
		add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'remove_billing_fields' ], 20 );
	}

	/**
	 * Check if current user is a partner.
	 *
	 * @return bool True if user has partner role.
	 */
	private static function user_is_partner(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) ) {
			return false;
		}

		$roles = (array) $user->roles;
		return (bool) array_intersect( self::PARTNER_ROLES, $roles );
	}

	/**
	 * Render informational message about saved billing details.
	 */
	public static function render_billing_message(): void {
		$my_account_url = wc_get_page_permalink( 'myaccount' );
		if ( ! $my_account_url ) {
			$my_account_url = home_url( '/mi-cuenta/' );
		}

		$message = Woo::translate( sprintf(
			'[:en]Your billing details are saved and editable in your <a href="%1$s" target="_blank" rel="noopener"><span style="white-space: nowrap;"><i class="spk-icon spk-icon-user-account"></i> My Account page.</span></a>[:es]Tus datos de facturación están guardados y se pueden editar en la página <a href="%1$s" target="_blank" rel="noopener"><span style="white-space: nowrap;"><i class="spk-icon spk-icon-user-account"></i> Mi Cuenta</span></a>.[:]',
			esc_url( $my_account_url )
		) );

		printf(
			'<hr /><p class="tcbf-partner-billing-notice">%s</p><hr />',
			wp_kses_post( $message )
		);
	}

	/**
	 * Remove billing fields from checkout for partners.
	 *
	 * @param array $fields Checkout fields.
	 * @return array Modified fields.
	 */
	public static function remove_billing_fields( array $fields ): array {
		if ( ! is_user_logged_in() ) {
			return $fields;
		}

		// Double-check role (filter may be called in various contexts)
		if ( ! self::user_is_partner() ) {
			return $fields;
		}

		$fields['billing'] = [];

		return $fields;
	}

	/**
	 * Populate order billing data from user profile.
	 *
	 * Called during order creation to ensure billing data is saved
	 * even when billing fields are hidden from checkout.
	 *
	 * @param \WC_Order $order Order being created.
	 * @param array     $data  Checkout posted data.
	 */
	public static function populate_order_billing( $order, $data ): void {
		// Only for partners
		if ( ! self::user_is_partner() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Only populate if billing data is missing (fields were hidden)
		// Check first_name as indicator - if already set, checkout provided data
		if ( $order->get_billing_first_name() ) {
			return;
		}

		foreach ( self::BILLING_FIELDS as $field ) {
			$meta_key = 'billing_' . $field;
			$value = get_user_meta( $user_id, $meta_key, true );

			if ( $value !== '' && $value !== false ) {
				$setter = 'set_billing_' . $field;
				if ( method_exists( $order, $setter ) ) {
					$order->$setter( $value );
				}
			}
		}

		// Fallback: ensure email is set from user account if not in billing meta
		if ( ! $order->get_billing_email() ) {
			$user = get_userdata( $user_id );
			if ( $user && $user->user_email ) {
				$order->set_billing_email( $user->user_email );
			}
		}

		// Fallback: ensure name is set from user display name if not in billing meta
		if ( ! $order->get_billing_first_name() ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$order->set_billing_first_name( $user->first_name ?: $user->display_name );
				$order->set_billing_last_name( $user->last_name ?: '' );
			}
		}
	}
}
