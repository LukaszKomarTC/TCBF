<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce My Account Customizations
 *
 * Enhances the My Account > Orders table for hotel partners and administrators.
 * Adds columns showing participants and services/products for quick booking overview.
 *
 * Features:
 * - Participants column: Shows participant names from each order line item
 * - Services/Products column: Shows products with event title and booking date
 *
 * Only visible to users with 'hotel' or 'administrator' role.
 *
 * @since 0.7.0
 */
final class Woo_MyAccount {

	/**
	 * Roles that can see the enhanced orders columns.
	 */
	private const ALLOWED_ROLES = [ 'hotel', 'administrator' ];

	/**
	 * Initialize My Account customizations.
	 *
	 * Only registers hooks if current user has an allowed role.
	 */
	public static function init(): void {
		// Only load on frontend
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Defer role check until user is available
		add_action( 'wp', [ __CLASS__, 'maybe_register_hooks' ] );
	}

	/**
	 * Register hooks if user has allowed role.
	 *
	 * Called on 'wp' action when user is available.
	 */
	public static function maybe_register_hooks(): void {
		if ( ! self::user_has_allowed_role() ) {
			return;
		}

		// Add custom columns to orders table
		add_filter( 'woocommerce_account_orders_columns', [ __CLASS__, 'add_orders_columns' ], 10, 1 );

		// Populate the custom columns
		add_action( 'woocommerce_my_account_my_orders_column_participant-column', [ __CLASS__, 'render_participant_column' ] );
		add_action( 'woocommerce_my_account_my_orders_column_order-content', [ __CLASS__, 'render_content_column' ] );
	}

	/**
	 * Check if current user has an allowed role.
	 *
	 * @return bool True if user can see enhanced columns.
	 */
	private static function user_has_allowed_role(): bool {
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) ) {
			return false;
		}

		$roles = (array) $user->roles;
		return (bool) array_intersect( self::ALLOWED_ROLES, $roles );
	}

	/**
	 * Add custom columns to the orders table.
	 *
	 * Inserts Participants and Services/Products columns after the Order Date column.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_orders_columns( array $columns ): array {
		$new_columns = [];

		foreach ( $columns as $key => $name ) {
			$new_columns[ $key ] = $name;

			// Insert our columns after order-date
			if ( 'order-date' === $key ) {
				$new_columns['participant-column'] = Woo::translate(
					'[:en]Clients/Participants[:es]Clientes/Participantes[:]'
				);
				$new_columns['order-content'] = Woo::translate(
					'[:en]Services/Products[:es]Servicios/Productos[:]'
				);
			}
		}

		return $new_columns;
	}

	/**
	 * Render the Participants column content.
	 *
	 * Shows participant names from each line item in the order.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public static function render_participant_column( $order ): void {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$participants = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			// Get participant name from item meta (check multiple keys with fallbacks)
			$participant = self::get_participant_from_item( $item );
			if ( $participant !== '' ) {
				$participants[] = esc_html( $participant );
			}
		}

		if ( $participants ) {
			echo implode( '<br>', $participants );
		}
	}

	/**
	 * Get participant name from order item, checking multiple meta keys.
	 *
	 * Fallback order (matches Woo_OrderMeta pattern):
	 * 1. _participant (TCBF primary)
	 * 2. participant (legacy/display key)
	 * 3. _tcbf_participant_name (standalone WC Bookings)
	 *
	 * @param \WC_Order_Item_Product $item Order item.
	 * @return string Participant name or empty string.
	 */
	private static function get_participant_from_item( $item ): string {
		// Primary: _participant
		$participant = (string) $item->get_meta( '_participant', true );
		if ( $participant !== '' ) {
			return $participant;
		}

		// Fallback 1: participant (display key)
		$participant = (string) $item->get_meta( 'participant', true );
		if ( $participant !== '' ) {
			return $participant;
		}

		// Fallback 2: _tcbf_participant_name (standalone WC Bookings)
		$participant = (string) $item->get_meta( '_tcbf_participant_name', true );
		if ( $participant !== '' ) {
			return $participant;
		}

		return '';
	}

	/**
	 * Render the Services/Products column content.
	 *
	 * Shows product names with event title and booking start date.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public static function render_content_column( $order ): void {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product = $item->get_product();
			$product_name = $item->get_name();

			// Determine the link URL
			$product_permalink = '';

			// Priority 1: Event page (if _event_id exists)
			$event_id = (int) $item->get_meta( '_event_id', true );
			if ( $event_id > 0 ) {
				$product_permalink = get_permalink( $event_id );
			}
			// Priority 2: Product permalink
			elseif ( $product && $product->is_visible() ) {
				$product_permalink = $product->get_permalink( $item );
			}

			// Get event title
			$event_title = $item->get_meta( 'event', true );

			// Get booking start date (if WC Bookings is active)
			$booking_start = self::get_booking_start_date( $item_id );

			// Build the display string
			$display_parts = [ $product_name ];

			if ( $event_title !== '' ) {
				$display_parts[] = $event_title;
			}

			if ( $booking_start !== '' ) {
				$display_parts[] = $booking_start;
			}

			$display_text = implode( ' | ', $display_parts );

			// Output with styling
			echo '<div style="background-color:#e5e5e5; padding:3px; margin:3px 0">';

			if ( $product_permalink ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( $product_permalink ),
					esc_html( $display_text )
				);
			} else {
				echo esc_html( $display_text );
			}

			echo '</div>';
		}
	}

	/**
	 * Get booking start date for an order item.
	 *
	 * @param int $item_id Order item ID.
	 * @return string Formatted booking start date, or empty string.
	 */
	private static function get_booking_start_date( int $item_id ): string {
		// Check if WC Bookings is available
		if ( ! class_exists( 'WC_Booking_Data_Store' ) || ! class_exists( 'WC_Booking' ) ) {
			return '';
		}

		try {
			$booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );

			if ( empty( $booking_ids ) ) {
				return '';
			}

			// Get the first booking's start date
			$booking_id = reset( $booking_ids );
			$booking = new \WC_Booking( $booking_id );

			$start_timestamp = $booking->get_start();
			if ( $start_timestamp ) {
				return esc_html( date_i18n( wc_date_format(), $start_timestamp ) );
			}
		} catch ( \Exception $e ) {
			// Silently fail if booking cannot be loaded
		}

		return '';
	}
}
