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
		if ( get_post_meta( $order_id, self::META_PAID_NOTIFS_SENT, true ) ) return;

		if ( ! class_exists('GFAPI') ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

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
			// Mark as sent + audit metadata
			update_post_meta( $order_id, self::META_PAID_NOTIFS_SENT, '1' );
			update_post_meta( $order_id, self::META_PAID_NOTIFS_SENT_AT, current_time( 'mysql' ) );
			update_post_meta( $order_id, self::META_PAID_NOTIFS_TRIGGER, $trigger );

			\TC_BF\Support\Logger::log('gf.notif.wc_paid.sent', [
				'order_id'  => $order_id,
				'entry_ids' => $entry_ids,
				'trigger'   => $trigger,
			]);
		}
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

		// Dedupe: send once per order
		if ( get_post_meta( $order_id, self::META_SETTLED_NOTIFS_SENT, true ) ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

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
			// Mark as sent + audit metadata
			update_post_meta( $order_id, self::META_SETTLED_NOTIFS_SENT, '1' );
			update_post_meta( $order_id, self::META_SETTLED_NOTIFS_SENT_AT, current_time( 'mysql' ) );

			\TC_BF\Support\Logger::log('gf.notif.wc_settled.sent', [
				'order_id'  => $order_id,
				'entry_ids' => $entry_ids,
			]);
		}
	}

}
