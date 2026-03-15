<?php
/**
 * WooCommerce/GravityForms Notification Integration
 *
 * Handles custom GravityForms notification events triggered by WooCommerce order statuses.
 *
 * Notification Strategy:
 * - WC___paid: Fired when order enters a paid-equivalent status (processing, completed, invoiced)
 * - WC___settled: Reserved for future use when invoiced orders are later settled
 *
 * TCBF triggers events. GF conditional logic decides who receives emails.
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * WooCommerce Notifications Integration
 *
 * Manages GravityForms notification events for WooCommerce order payment and settlement.
 */
class Woo_Notifications {

	/**
	 * Order meta keys for notification audit trail.
	 */
	const META_PAID_NOTIFS_SENT       = '_tc_gf_paid_notifs_sent';
	const META_PAID_NOTIFS_SENT_AT    = '_tcbf_paid_notifs_sent_at';
	const META_PAID_NOTIFS_TRIGGER    = '_tcbf_paid_notifs_trigger_status';
	const META_SETTLED_NOTIFS_SENT    = '_tc_gf_settled_notifs_sent';
	const META_SETTLED_NOTIFS_SENT_AT = '_tcbf_settled_notifs_sent_at';

	/* =========================================================
	 * Bridge: write _gf_entry_id when GF WC Add-on creates entry
	 * ========================================================= */

	/**
	 * Bridge _gf_entry_id onto the order item when the GF WC Product Add-ons
	 * plugin creates the real GF entry at checkout time.
	 *
	 * Hook: woocommerce_gravityforms_entry_created (fired by Lucas Stark's plugin)
	 *
	 * @param int|mixed $entry_id   GF entry ID just created.
	 * @param int|mixed $order_id   WC order ID.
	 * @param mixed     $order_item WC_Order_Item_Product instance.
	 * @param mixed     $form_data  Form config array.
	 * @param mixed     $lead_data  Raw field values.
	 */
	public static function bridge_gf_entry_id_to_order_item( $entry_id, $order_id, $order_item, $form_data, $lead_data ) : void {
		$entry_id = (int) $entry_id;
		if ( $entry_id <= 0 ) return;

		if ( ! is_object( $order_item ) || ! method_exists( $order_item, 'update_meta_data' ) ) return;

		$order_item->update_meta_data( '_gf_entry_id', (string) $entry_id );
		$order_item->save();

		\TC_BF\Support\Logger::log( 'gf.notif.bridge_entry_id', [
			'entry_id' => $entry_id,
			'order_id' => (int) $order_id,
		]);
	}

	/* =========================================================
	 * GF notifications (parity with legacy snippets)
	 * ========================================================= */

	/**
	 * Register custom GF notification events.
	 *
	 * WC___paid: Triggered for all paid-equivalent statuses (processing, completed, invoiced)
	 * WC___settled: Reserved for future invoice settlement tracking
	 */
	public static function gf_register_notification_events( array $events ) : array {
		$events['WC___paid']    = __( 'WooCommerce order paid (includes invoiced)', TC_BF_TEXTDOMAIN );
		$events['WC___settled'] = __( 'Invoice settled (future use)', TC_BF_TEXTDOMAIN );
		return $events;
	}

	/**
	 * Collect GF entry IDs from order line items.
	 *
	 * Resolution order per item:
	 * 1. _gf_entry_id (written by TCBF or bridge_gf_entry_id_to_order_item)
	 * 2. _gravity_forms_history['_gravity_form_linked_entry_id'] (written by WC GF Product Add-ons)
	 *
	 * @param \WC_Order $order
	 * @return int[] Unique entry IDs.
	 */
	private static function collect_entry_ids( \WC_Order $order ) : array {
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) continue;

			// Primary: _gf_entry_id (TCBF event packs + bridge hook for booking products)
			$eid = (int) $item->get_meta( '_gf_entry_id', true );

			// Fallback: WC GF Product Add-ons stores the linked entry ID in _gravity_forms_history
			if ( $eid <= 0 ) {
				$history = $item->get_meta( '_gravity_forms_history', true );
				if ( is_array( $history ) && ! empty( $history['_gravity_form_linked_entry_id'] ) ) {
					$eid = (int) $history['_gravity_form_linked_entry_id'];
				}
			}

			if ( $eid > 0 ) $entry_ids[] = $eid;
		}
		return array_values( array_unique( $entry_ids ) );
	}

	/**
	 * Fire GF notifications when order enters a paid-equivalent status.
	 *
	 * Paid-equivalent statuses: processing, completed, invoiced
	 * (Defined in Woo_StatusPolicy::get_paid_equivalent_statuses())
	 *
	 * Hooks:
	 * - woocommerce_payment_complete (order id)
	 * - woocommerce_order_status_processing (order id, order)
	 * - woocommerce_order_status_completed (order id, order)
	 * - woocommerce_order_status_invoiced (order id, order)
	 *
	 * @param int|mixed      $order_id    Order ID.
	 * @param \WC_Order|null $maybe_order Order object (if available from hook).
	 * @param string         $trigger     Optional trigger status for audit trail.
	 */
	public static function woo_fire_gf_paid_notifications( $order_id, $maybe_order = null, string $trigger = '' ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		// Dedupe: avoid duplicate sends (truthy check for robustness)
		// Use wc_get_order() early so we can check meta via HPOS-compatible API
		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		if ( $order->get_meta( self::META_PAID_NOTIFS_SENT, true ) ) return;

		if ( ! class_exists('GFAPI') ) return;

		// Determine trigger status for audit trail
		if ( $trigger === '' ) {
			$trigger = $order->get_status();
		}

		$entry_ids = self::collect_entry_ids( $order );

		// No GF entries found - skip silently (may be non-pack order)
		if ( ! $entry_ids ) return;

		// Get customer language for notifications
		$customer_lang = \TC_BF\Domain\NotificationLanguage::for_customer( $order );

		$did_any = false;
		foreach ( $entry_ids as $entry_id ) {
			try {
				$entry = \GFAPI::get_entry( (int) $entry_id );
				if ( is_wp_error($entry) || ! is_array($entry) ) continue;

				$form_id = (int) rgar( $entry, 'form_id' );
				if ( $form_id <= 0 ) {
					$form_id = (int) \TC_BF\Admin\Settings::get_form_id();
				}
				if ( $form_id <= 0 ) continue;

				$form = \GFAPI::get_form( $form_id );
				if ( ! is_array($form) || empty($form['id']) ) continue;

				// Fire WC___paid event with locale switching for customer language
				// GF conditional logic handles recipient decisions
				\TC_BF\Domain\NotificationLanguage::with_locale( $customer_lang, function() use ( $form, $entry ) {
					\GFAPI::send_notifications( $form, $entry, 'WC___paid' );
				} );
				$did_any = true;
			} catch ( \Throwable $e ) {
				\TC_BF\Support\Logger::log('gf.notif.wc_paid.exception', [
					'order_id' => $order_id,
					'entry_id' => (int) $entry_id,
					'err'      => $e->getMessage(),
				], 'error');
			}
		}

		if ( $did_any ) {
			// Mark as sent + audit metadata (HPOS-compatible)
			$order->update_meta_data( self::META_PAID_NOTIFS_SENT, '1' );
			$order->update_meta_data( self::META_PAID_NOTIFS_SENT_AT, current_time( 'mysql' ) );
			$order->update_meta_data( self::META_PAID_NOTIFS_TRIGGER, $trigger );
			$order->save();

			\TC_BF\Support\Logger::log('gf.notif.wc_paid.sent', [
				'order_id'  => $order_id,
				'entry_ids' => $entry_ids,
				'trigger'   => $trigger,
			]);
		}
	}

	/* =========================================================
	 * Order Flow: Skip Processing for Booking Products
	 *
	 * WC Bookings products return true for needs_processing(),
	 * forcing orders through "processing" status even though
	 * all items are virtual/services. This makes payment_complete()
	 * transition directly to "completed", sending the Customer
	 * Completed Order email instead of the Processing one.
	 * ========================================================= */

	/**
	 * Tell WC that booking and TCBF transport line items don't need processing.
	 *
	 * When all items return false, payment_complete() sets the order
	 * to "completed" directly, skipping "processing" entirely.
	 *
	 * Covers:
	 * - WC Bookings products (participation + rental)
	 * - TCBF transport addon products (bike delivery/pickup)
	 *
	 * Without this, orders with transport addons would go to "processing"
	 * (transport is a simple product) while the processing email is
	 * suppressed, leaving the customer with no order confirmation.
	 *
	 * Hook: woocommerce_order_item_needs_processing
	 *
	 * @param bool        $needs     Whether the item needs processing.
	 * @param \WC_Product $product   Product object.
	 * @param int         $order_id  Order ID.
	 * @return bool False for booking/transport products, original value otherwise.
	 */
	public static function booking_item_skip_processing( $needs, $product, $order_id ) : bool {
		if ( ! $product ) {
			return (bool) $needs;
		}

		// WC Bookings products (participation + rental items)
		if ( is_callable( [ $product, 'is_type' ] ) && $product->is_type( 'booking' ) ) {
			return false;
		}

		// TCBF transport addon product (bike delivery/pickup)
		$transport_pid = \TC_BF\Domain\TransportPricing::get_transport_product_id();
		if ( $transport_pid > 0 && (int) $product->get_id() === $transport_pid ) {
			return false;
		}

		return (bool) $needs;
	}

	/* =========================================================
	 * Suppress WC Processing Email for Booking Orders (safety net)
	 *
	 * Backup filter in case the order still reaches "processing"
	 * status through a non-standard path (manual status change,
	 * other plugins, etc.).
	 * ========================================================= */

	/**
	 * Disable WC "Customer Processing Order" email for orders containing bookable products.
	 *
	 * Hook: woocommerce_email_enabled_customer_processing_order
	 *
	 * @param bool      $enabled Whether the email is enabled.
	 * @param \WC_Order $order   Order object (may be null during admin settings).
	 * @return bool False if order contains bookings, original value otherwise.
	 */
	public static function suppress_processing_email_for_bookings( $enabled, $order ) : bool {
		if ( ! $enabled || ! $order instanceof \WC_Order ) {
			return (bool) $enabled;
		}

		// Check if any line item is a bookable product
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && is_callable( [ $product, 'is_type' ] ) && $product->is_type( 'booking' ) ) {
				\TC_BF\Support\Logger::log( 'email.processing_suppressed', [
					'order_id' => $order->get_id(),
					'reason'   => 'order_has_bookings',
				] );
				return false;
			}
		}

		return $enabled;
	}

	/* =========================================================
	 * WooCommerce Email Subject Localization
	 * ========================================================= */

	/**
	 * Filter customer completed order email subject with multilingual support.
	 *
	 * Hook: woocommerce_email_subject_customer_completed_order
	 *
	 * @param string    $subject Original subject.
	 * @param \WC_Order $order   Order object.
	 * @return string Localized subject.
	 */
	public static function filter_completed_order_subject( $subject, $order ) : string {
		$order_id = $order->get_id();
		$text = sprintf(
			'[:es]Tu pedido [ #%d ] ha sido completado[:en]Your order [ #%d ] has been completed[:]',
			$order_id,
			$order_id
		);
		return Woo::translate( $text );
	}

	/**
	 * Filter new order (admin) email subject with multilingual support.
	 *
	 * Hook: woocommerce_email_subject_new_order
	 *
	 * @param string    $subject Original subject.
	 * @param \WC_Order $order   Order object.
	 * @return string Localized subject.
	 */
	public static function filter_new_order_subject( $subject, $order ) : string {
		$order_id = $order->get_id();
		$text = sprintf(
			'[:es]Nuevo pedido [ #%d ][:en]New order [ #%d ][:]',
			$order_id,
			$order_id
		);
		return Woo::translate( $text );
	}

	/**
	 * Filter booking reminder email subject with multilingual support.
	 *
	 * Hook: woocommerce_email_subject_booking_reminder
	 *
	 * @param string $subject Original subject.
	 * @param mixed  $booking Booking object.
	 * @return string Localized subject.
	 */
	public static function filter_booking_reminder_subject( $subject, $booking ) : string {
		$booking_id = is_object( $booking ) && method_exists( $booking, 'get_id' )
			? $booking->get_id()
			: 0;
		$text = sprintf(
			'[:es]Reserva #%d: ¡Recordatorio![:en]Booking #%d: Reminder![:]',
			$booking_id,
			$booking_id
		);
		return Woo::translate( $text );
	}

	/* =========================================================
	 * GF Invoice Settlement Notifications
	 * ========================================================= */

	/**
	 * Fire GF notifications when an invoice is settled.
	 *
	 * Reserved for future use when invoiced orders are later marked as settled.
	 * Hook: woocommerce_order_status_settled (order id, order)
	 *
	 * @param int|mixed      $order_id    Order ID.
	 * @param \WC_Order|null $maybe_order Order object (if available from hook).
	 */
	public static function woo_fire_gf_settled_notifications( $order_id, $maybe_order = null ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		// Dedupe: send once per order (HPOS-compatible)
		if ( $order->get_meta( self::META_SETTLED_NOTIFS_SENT, true ) ) return;

		$entry_ids = self::collect_entry_ids( $order );
		if ( empty( $entry_ids ) ) return;

		if ( ! class_exists('GFAPI') ) return;

		$did_any = false;
		foreach ( $entry_ids as $eid ) {
			try {
				$entry = \GFAPI::get_entry( $eid );
				if ( is_wp_error($entry) || empty($entry) ) continue;

				$form = \GFAPI::get_form( (int)$entry['form_id'] );
				if ( empty($form) ) continue;

				\GFAPI::send_notifications( $form, $entry, 'WC___settled' );
				$did_any = true;
			} catch ( \Throwable $e ) {
				\TC_BF\Support\Logger::log('gf.notif.wc_settled.exception', [
					'order_id' => $order_id,
					'entry_id' => (int) $eid,
					'err'      => $e->getMessage(),
				], 'error');
			}
		}

		if ( $did_any ) {
			// Mark as sent + audit metadata (HPOS-compatible)
			$order->update_meta_data( self::META_SETTLED_NOTIFS_SENT, '1' );
			$order->update_meta_data( self::META_SETTLED_NOTIFS_SENT_AT, current_time( 'mysql' ) );
			$order->save();

			\TC_BF\Support\Logger::log('gf.notif.wc_settled.sent', [
				'order_id'  => $order_id,
				'entry_ids' => $entry_ids,
			]);
		}
	}

	/* =========================================================
	 * WooCommerce Email Locale Switching
	 *
	 * Ensures WC emails render in the correct language based on recipient:
	 * - Customer emails: Use customer's captured language
	 * - Admin emails: Use admin's preferred language
	 * ========================================================= */

	/**
	 * Previous qTranslate language (for restoration)
	 *
	 * @var string|null
	 */
	private static $prev_qtx_lang = null;

	/**
	 * Whether WP locale was switched
	 *
	 * @var bool
	 */
	private static $wp_locale_switched = false;

	/**
	 * Setup locale before WC email rendering.
	 *
	 * Hook: woocommerce_email_setup_locale
	 *
	 * @param \WC_Email $email Email object
	 */
	public static function setup_email_locale( $email ) : void {
		if ( ! $email || ! is_object( $email ) ) {
			return;
		}

		// Determine target language based on email type
		$target_lang = self::get_email_target_language( $email );
		if ( ! $target_lang ) {
			return;
		}

		// Switch qTranslate language
		if ( function_exists( 'qtranxf_getLanguage' ) ) {
			self::$prev_qtx_lang = qtranxf_getLanguage();
			if ( self::$prev_qtx_lang !== $target_lang ) {
				global $q_config;
				if ( isset( $q_config ) ) {
					$q_config['language'] = $target_lang;
				}
			}
		}

		// Switch WordPress locale
		$target_locale = \TC_BF\Domain\NotificationLanguage::lang_to_locale( $target_lang );
		if ( function_exists( 'switch_to_locale' ) && $target_locale !== get_locale() ) {
			switch_to_locale( $target_locale );
			self::$wp_locale_switched = true;
		}
	}

	/**
	 * Restore locale after WC email rendering.
	 *
	 * Hook: woocommerce_email_restore_locale
	 *
	 * @param \WC_Email $email Email object
	 */
	public static function restore_email_locale( $email ) : void {
		// Restore qTranslate language
		if ( self::$prev_qtx_lang !== null && function_exists( 'qtranxf_getLanguage' ) ) {
			global $q_config;
			if ( isset( $q_config ) ) {
				$q_config['language'] = self::$prev_qtx_lang;
			}
			self::$prev_qtx_lang = null;
		}

		// Restore WordPress locale
		if ( self::$wp_locale_switched && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
			self::$wp_locale_switched = false;
		}
	}

	/**
	 * Determine target language for an email.
	 *
	 * @param \WC_Email $email Email object
	 * @return string|null Language code or null if cannot determine
	 */
	private static function get_email_target_language( $email ) : ?string {
		// Get email ID
		$email_id = '';
		if ( property_exists( $email, 'id' ) ) {
			$email_id = (string) $email->id;
		}

		// Admin emails: use admin's preferred language
		$admin_email_ids = [
			'new_order',
			'cancelled_order',
			'failed_order',
			'new_booking',
		];
		if ( strpos( $email_id, 'admin_' ) === 0 || in_array( $email_id, $admin_email_ids, true ) ) {
			return \TC_BF\Domain\NotificationLanguage::for_admin();
		}

		// Customer emails: try to get order and customer language
		$order = null;
		if ( property_exists( $email, 'object' ) && $email->object instanceof \WC_Order ) {
			$order = $email->object;
		}

		if ( $order ) {
			return \TC_BF\Domain\NotificationLanguage::for_customer( $order );
		}

		// Booking-related emails: try to get order from booking
		if ( property_exists( $email, 'object' ) && class_exists( 'WC_Booking' ) && $email->object instanceof \WC_Booking ) {
			$booking = $email->object;
			$order_id = $booking->get_order_id();
			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					return \TC_BF\Domain\NotificationLanguage::for_customer( $order );
				}
			}
		}

		// Fallback to site default
		return \TC_BF\Domain\NotificationLanguage::get_default_language();
	}

}
