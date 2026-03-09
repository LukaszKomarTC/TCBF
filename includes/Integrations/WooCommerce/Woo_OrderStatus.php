<?php
/**
 * WooCommerce Custom Order Statuses
 *
 * Registers custom order statuses used by TC Booking Flow:
 * - wc-invoiced: Partner/admin offline orders (paid-equivalent)
 * - wc-settled: Future use for invoice settlement tracking
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * WooCommerce Order Status Registration
 */
class Woo_OrderStatus {

	/**
	 * Initialize order status registration.
	 */
	public static function init() : void {
		// Register custom post statuses
		add_action( 'init', [ __CLASS__, 'register_order_statuses' ], 5 );

		// Add to WooCommerce order status list
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_order_statuses' ], 10, 1 );

		// Register invoiced transitions as email-triggering actions
		add_filter( 'woocommerce_email_actions', [ __CLASS__, 'register_email_actions' ], 10, 1 );

		// Attach WC email classes to invoiced notification hooks
		add_action( 'woocommerce_email', [ __CLASS__, 'hook_email_triggers' ], 10, 1 );

		// Let WooCommerce treat invoiced as a paid status
		add_filter( 'woocommerce_order_is_paid_statuses', [ __CLASS__, 'add_paid_statuses' ], 10, 1 );

		// Add bulk action for marking orders as invoiced
		add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'register_bulk_actions' ], 20, 1 );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'bulk_action_admin_notice' ] );

		// HPOS compatibility: bulk actions for woocommerce_page_wc-orders screen
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'register_bulk_actions' ], 20, 1 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_actions' ], 10, 3 );
	}

	/**
	 * Register custom order statuses as WordPress post statuses.
	 */
	public static function register_order_statuses() : void {
		// Invoiced status (paid-equivalent for partners/admins)
		register_post_status( 'wc-invoiced', [
			'label'                     => _x( 'Invoiced', 'Order status', TC_BF_TEXTDOMAIN ),
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Invoiced <span class="count">(%s)</span>',
				'Invoiced <span class="count">(%s)</span>',
				TC_BF_TEXTDOMAIN
			),
		] );

		// Settled status (stub for future invoice settlement tracking)
		register_post_status( 'wc-settled', [
			'label'                     => _x( 'Settled', 'Order status', TC_BF_TEXTDOMAIN ),
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Settled <span class="count">(%s)</span>',
				'Settled <span class="count">(%s)</span>',
				TC_BF_TEXTDOMAIN
			),
		] );
	}

	/**
	 * Add custom statuses to WooCommerce order status dropdown.
	 *
	 * @param array $statuses Existing order statuses.
	 * @return array Modified order statuses.
	 */
	public static function add_order_statuses( array $statuses ) : array {
		// Insert after 'wc-on-hold' for logical ordering
		$new_statuses = [];
		foreach ( $statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( $key === 'wc-on-hold' ) {
				$new_statuses['wc-invoiced'] = _x( 'Invoiced', 'Order status', TC_BF_TEXTDOMAIN );
				$new_statuses['wc-settled']  = _x( 'Settled', 'Order status', TC_BF_TEXTDOMAIN );
			}
		}

		// Fallback if wc-on-hold wasn't found
		if ( ! isset( $new_statuses['wc-invoiced'] ) ) {
			$new_statuses['wc-invoiced'] = _x( 'Invoiced', 'Order status', TC_BF_TEXTDOMAIN );
			$new_statuses['wc-settled']  = _x( 'Settled', 'Order status', TC_BF_TEXTDOMAIN );
		}

		return $new_statuses;
	}

	/**
	 * Register invoiced status transitions as WooCommerce email-triggering actions.
	 *
	 * WC_Emails only relays actions listed in woocommerce_email_actions.
	 * For each registered action it fires a *_notification suffix action that
	 * individual email classes listen to.
	 *
	 * @param array $actions Existing email actions.
	 * @return array Modified email actions.
	 */
	public static function register_email_actions( array $actions ) : array {
		$actions[] = 'woocommerce_order_status_pending_to_invoiced';
		$actions[] = 'woocommerce_order_status_on-hold_to_invoiced';
		$actions[] = 'woocommerce_order_status_failed_to_invoiced';
		$actions[] = 'woocommerce_order_status_cancelled_to_invoiced';
		return $actions;
	}

	/**
	 * Attach WooCommerce email classes to invoiced notification hooks.
	 *
	 * Called on the 'woocommerce_email' action, which fires after all
	 * email classes are instantiated. We use a wrapper callback that
	 * checks needs_processing() at trigger time to dispatch the correct
	 * customer email (Processing vs Completed), mirroring the normal
	 * payment_complete() behavior.
	 *
	 * @param \WC_Emails $emails WC_Emails instance.
	 */
	public static function hook_email_triggers( $emails ) : void {
		// Transitions that should trigger emails
		$invoiced_hooks = [
			'woocommerce_order_status_pending_to_invoiced_notification',
			'woocommerce_order_status_on-hold_to_invoiced_notification',
			'woocommerce_order_status_failed_to_invoiced_notification',
			'woocommerce_order_status_cancelled_to_invoiced_notification',
		];

		foreach ( $invoiced_hooks as $hook ) {
			// Admin email: New Order (always fires)
			add_action( $hook, [ __CLASS__, 'trigger_admin_new_order_email' ], 10, 2 );
			// Customer email: Processing or Completed based on needs_processing()
			add_action( $hook, [ __CLASS__, 'trigger_customer_order_email' ], 10, 2 );
		}
	}

	/**
	 * Trigger the admin New Order email for invoiced orders.
	 *
	 * Uses the admin's preferred notification language for locale switching.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order object.
	 */
	public static function trigger_admin_new_order_email( int $order_id, $order = null ) : void {
		$admin_lang = \TC_BF\Domain\NotificationLanguage::for_admin();

		\TC_BF\Domain\NotificationLanguage::with_locale( $admin_lang, function() use ( $order_id, $order ) {
			$emails = \WC()->mailer()->get_emails();
			if ( isset( $emails['WC_Email_New_Order'] ) ) {
				$emails['WC_Email_New_Order']->trigger( $order_id, $order );
			}
		} );
	}

	/**
	 * Trigger the appropriate customer email based on order contents.
	 *
	 * Mirrors WooCommerce payment_complete() behavior:
	 * - Virtual products (bookings) → Customer Completed Order email
	 * - Physical products → Customer Processing Order email
	 *
	 * Uses the customer's captured language for locale switching.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order object.
	 */
	public static function trigger_customer_order_email( int $order_id, $order = null ) : void {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Get the customer's language captured at checkout/form submission
		$customer_lang = \TC_BF\Domain\NotificationLanguage::for_customer( $order );

		\TC_BF\Domain\NotificationLanguage::with_locale( $customer_lang, function() use ( $order_id, $order ) {
			$emails = \WC()->mailer()->get_emails();

			// Check if order needs processing (has physical items)
			// Virtual-only orders (like bookings) return false → completed email
			if ( $order->needs_processing() ) {
				// Physical items present → Processing email
				if ( isset( $emails['WC_Email_Customer_Processing_Order'] ) ) {
					$emails['WC_Email_Customer_Processing_Order']->trigger( $order_id, $order );
				}
			} else {
				// All virtual items → Completed email
				if ( isset( $emails['WC_Email_Customer_Completed_Order'] ) ) {
					$emails['WC_Email_Customer_Completed_Order']->trigger( $order_id, $order );
				}
			}
		} );
	}

	/**
	 * Add invoiced to WooCommerce's paid statuses.
	 *
	 * Makes $order->is_paid() return true for invoiced orders,
	 * consistent with TCBF's Woo_StatusPolicy paid-equivalent list.
	 *
	 * @param array $statuses Existing paid statuses.
	 * @return array Modified paid statuses.
	 */
	public static function add_paid_statuses( array $statuses ) : array {
		if ( ! in_array( 'invoiced', $statuses, true ) ) {
			$statuses[] = 'invoiced';
		}
		return $statuses;
	}

	/**
	 * Register bulk actions for order status changes.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public static function register_bulk_actions( array $actions ) : array {
		$actions['mark_invoiced'] = __( 'Change status to invoiced', TC_BF_TEXTDOMAIN );
		$actions['mark_settled']  = __( 'Change status to settled', TC_BF_TEXTDOMAIN );
		return $actions;
	}

	/**
	 * Handle bulk action for marking orders with custom statuses.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action being performed.
	 * @param array  $post_ids    Order IDs being processed.
	 * @return string Modified redirect URL.
	 */
	public static function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ) : string {
		$status_map = [
			'mark_invoiced' => 'wc-invoiced',
			'mark_settled'  => 'wc-settled',
		];

		if ( ! isset( $status_map[ $action ] ) ) {
			return $redirect_to;
		}

		$new_status = $status_map[ $action ];
		$changed    = 0;

		foreach ( $post_ids as $post_id ) {
			$order = wc_get_order( $post_id );
			if ( ! $order ) {
				continue;
			}

			$order->update_status( $new_status, __( 'Order status changed via bulk action.', TC_BF_TEXTDOMAIN ), true );
			$changed++;
		}

		return add_query_arg( [
			'tcbf_bulk_status_changed' => $changed,
			'tcbf_bulk_status_to'      => $new_status,
		], $redirect_to );
	}

	/**
	 * Display admin notice after bulk status change.
	 */
	public static function bulk_action_admin_notice() : void {
		if ( empty( $_REQUEST['tcbf_bulk_status_changed'] ) ) {
			return;
		}

		$changed = (int) $_REQUEST['tcbf_bulk_status_changed'];
		$status  = isset( $_REQUEST['tcbf_bulk_status_to'] ) ? sanitize_text_field( $_REQUEST['tcbf_bulk_status_to'] ) : '';

		$status_labels = [
			'wc-invoiced' => __( 'Invoiced', TC_BF_TEXTDOMAIN ),
			'wc-settled'  => __( 'Settled', TC_BF_TEXTDOMAIN ),
		];

		$label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: 1: number of orders, 2: status label */
			sprintf(
				_n(
					'%1$d order status changed to %2$s.',
					'%1$d order statuses changed to %2$s.',
					$changed,
					TC_BF_TEXTDOMAIN
				),
				$changed,
				'<strong>' . esc_html( $label ) . '</strong>'
			)
		);
	}
}
