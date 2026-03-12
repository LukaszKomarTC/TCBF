<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Order View Enhancements
 *
 * Replaces the raw meta dump on admin order edit screens with:
 * - A structured "Booking Summary" meta box (event, participants, transport, pricing)
 * - Compact inline badges on each order item (scope, participant, event)
 * - Hidden TCBF custom fields from the Custom Fields metabox
 */
class Woo_AdminOrder {

	public static function init() : void {
		if ( ! is_admin() ) {
			return;
		}

		// Meta box on order edit screen
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ], 30 );

		// Inline badges after each order item in admin
		add_action( 'woocommerce_before_order_itemmeta', [ __CLASS__, 'render_item_badges' ], 10, 3 );

		// Hide TCBF keys from Custom Fields metabox
		add_filter( 'is_protected_meta', [ __CLASS__, 'protect_tcbf_meta' ], 10, 2 );

		// Admin CSS
		add_action( 'admin_head', [ __CLASS__, 'admin_css' ] );
	}

	/* =================================================================
	 * Meta Box Registration
	 * ================================================================= */

	public static function register_meta_boxes() : void {
		$screen = self::get_order_screen();
		if ( ! $screen ) {
			return;
		}

		// Only show on booking orders
		$order = self::get_current_order();
		if ( ! $order || ! Woo_OrderMeta::is_booking_order( $order ) ) {
			return;
		}

		add_meta_box(
			'tcbf-booking-summary',
			'Booking Summary',
			[ __CLASS__, 'render_meta_box' ],
			$screen,
			'normal',
			'high'
		);
	}

	/* =================================================================
	 * Meta Box Render
	 * ================================================================= */

	public static function render_meta_box( $post_or_order ) : void {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order ) {
			echo '<p>Order not found.</p>';
			return;
		}

		$currency = $order->get_currency();

		// Build item records
		$records      = [];
		$participants = [];
		$transport    = [];
		$event_title  = '';
		$event_date   = '';
		$event_id     = 0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$rec = Woo_OrderMeta::build_item_record( $order, $item_id, $item );
			$records[] = $rec;

			// Collect event info (first one found)
			if ( $event_title === '' && $rec['event_title'] !== '' ) {
				$event_title = $rec['event_title'];
				$event_id    = $rec['event_id'];
			}
			if ( $event_date === '' && ! empty( $rec['booking_date'] ) ) {
				$event_date = $rec['booking_date'];
			}

			// Collect participants (skip transport items)
			if ( ! $rec['is_transport'] && $rec['participant'] !== '' ) {
				$key = strtolower( trim( $rec['participant'] ) );
				if ( ! isset( $participants[ $key ] ) ) {
					$participants[ $key ] = [
						'name'    => $rec['participant'],
						'email'   => Woo_OrderMeta::get_item_meta_ci( $item, 'email' ),
						'bicycle' => $rec['bicycle'],
						'size'    => $rec['size'],
						'scope'   => $rec['scope'],
						'pedals'  => $rec['pedals'],
						'helmet'  => $rec['helmet'],
					];
				} else {
					// Merge: add rental scope info
					if ( $rec['scope'] === 'rental' ) {
						$participants[ $key ]['bicycle'] = $rec['bicycle'] ?: $participants[ $key ]['bicycle'];
						$participants[ $key ]['size']    = $rec['size'] ?: $participants[ $key ]['size'];
					}
				}
			}

			// Collect transport items
			if ( $rec['is_transport'] ) {
				$transport[] = $rec;
			}
		}

		// === Event Section ===
		echo '<div class="tcbf-admin-summary">';

		if ( $event_title !== '' ) {
			echo '<div class="tcbf-admin-section">';
			echo '<h4>Event</h4>';
			echo '<table class="tcbf-admin-table">';
			echo '<tr><td class="tcbf-label">Tour</td><td>';
			if ( $event_id > 0 ) {
				echo '<a href="' . esc_url( get_edit_post_link( $event_id ) ) . '">' . esc_html( $event_title ) . '</a>';
			} else {
				echo esc_html( $event_title );
			}
			echo '</td></tr>';
			if ( $event_date !== '' ) {
				echo '<tr><td class="tcbf-label">Date</td><td>' . esc_html( $event_date ) . '</td></tr>';
			}
			echo '<tr><td class="tcbf-label">Participants</td><td>' . count( $participants ) . '</td></tr>';
			echo '</table>';
			echo '</div>';
		}

		// === Participants Section ===
		if ( ! empty( $participants ) ) {
			echo '<div class="tcbf-admin-section">';
			echo '<h4>Participants</h4>';
			echo '<table class="tcbf-admin-table tcbf-admin-table-full">';
			echo '<thead><tr><th>Name</th><th>Email</th><th>Scope</th><th>Bicycle</th><th>Size</th><th>Pedals</th><th>Helmet</th></tr></thead>';
			echo '<tbody>';
			foreach ( $participants as $p ) {
				$scope_label = ucfirst( $p['scope'] ?: '—' );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $p['name'] ) . '</strong></td>';
				echo '<td>' . esc_html( $p['email'] ?: '—' ) . '</td>';
				echo '<td><span class="tcbf-scope-badge tcbf-scope-' . esc_attr( $p['scope'] ) . '">' . esc_html( $scope_label ) . '</span></td>';
				echo '<td>' . esc_html( $p['bicycle'] ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $p['size'] ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $p['pedals'] ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $p['helmet'] ?: '—' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}

		// === Transport Section ===
		if ( ! empty( $transport ) ) {
			echo '<div class="tcbf-admin-section">';
			echo '<h4>Transport</h4>';
			echo '<table class="tcbf-admin-table tcbf-admin-table-full">';
			echo '<thead><tr><th>Type</th><th>Date</th><th>Window</th><th>Zone</th><th>Address</th><th>Price</th></tr></thead>';
			echo '<tbody>';
			foreach ( $transport as $t ) {
				$type_label = $t['transport_type'] === 'delivery' ? 'Delivery' : 'Return pickup';
				$window_label = ucfirst( $t['transport_window'] ?: '—' );
				$price = Woo_OrderMeta::get_item_meta_ci( $t['item'], '_tcbf_transport_price' );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $type_label ) . '</strong></td>';
				echo '<td>' . esc_html( $t['transport_date'] ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $window_label ) . '</td>';
				echo '<td>' . esc_html( $t['transport_zone'] ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $t['transport_address'] ?: '—' ) . '</td>';
				echo '<td>' . ( $price !== '' ? wc_price( (float) $price, [ 'currency' => $currency ] ) : '—' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}

		// === Partner & Pricing Section ===
		$partner_id   = (int) $order->get_meta( 'partner_id', true );
		$partner_code = $order->get_meta( 'partner_code', true );

		if ( $partner_id > 0 || $partner_code !== '' ) {
			$commission      = (float) $order->get_meta( 'partner_commission', true );
			$commission_rate = (float) $order->get_meta( 'partner_commission_rate', true );
			$discount_pct    = (float) $order->get_meta( 'partner_discount_pct', true );
			$base_total      = (float) $order->get_meta( 'partner_base_total', true );
			$client_total    = (float) $order->get_meta( 'client_total', true );
			$client_discount = (float) $order->get_meta( 'client_discount', true );

			echo '<div class="tcbf-admin-section">';
			echo '<h4>Partner & Pricing</h4>';
			echo '<table class="tcbf-admin-table">';
			if ( $partner_code !== '' ) {
				echo '<tr><td class="tcbf-label">Partner code</td><td><code>' . esc_html( $partner_code ) . '</code></td></tr>';
			}
			if ( $partner_id > 0 ) {
				$partner_user = get_user_by( 'ID', $partner_id );
				$partner_name = $partner_user ? $partner_user->display_name : '#' . $partner_id;
				echo '<tr><td class="tcbf-label">Partner</td><td>' . esc_html( $partner_name ) . '</td></tr>';
			}
			if ( $discount_pct > 0 ) {
				echo '<tr><td class="tcbf-label">Client discount</td><td>' . esc_html( $discount_pct ) . '%';
				if ( $client_discount > 0 ) {
					echo ' (' . wc_price( $client_discount, [ 'currency' => $currency ] ) . ')';
				}
				echo '</td></tr>';
			}
			if ( $base_total > 0 ) {
				echo '<tr><td class="tcbf-label">Base total</td><td>' . wc_price( $base_total, [ 'currency' => $currency ] ) . '</td></tr>';
			}
			if ( $client_total > 0 ) {
				echo '<tr><td class="tcbf-label">Client total</td><td>' . wc_price( $client_total, [ 'currency' => $currency ] ) . '</td></tr>';
			}
			if ( $commission_rate > 0 ) {
				echo '<tr><td class="tcbf-label">Commission rate</td><td>' . esc_html( $commission_rate ) . '%</td></tr>';
			}
			if ( $commission > 0 ) {
				echo '<tr><td class="tcbf-label">Commission</td><td><strong>' . wc_price( $commission, [ 'currency' => $currency ] ) . '</strong></td></tr>';
			}
			echo '</table>';
			echo '</div>';
		}

		// === Early Booking Section ===
		$eb_amount = (float) $order->get_meta( 'early_booking_discount_amount', true );
		$eb_pct    = (float) $order->get_meta( 'early_booking_discount_pct', true );
		$eb_days   = $order->get_meta( 'eb_days_before', true );

		if ( $eb_amount > 0 ) {
			echo '<div class="tcbf-admin-section">';
			echo '<h4>Early Booking Discount</h4>';
			echo '<table class="tcbf-admin-table">';
			if ( $eb_pct > 0 ) {
				echo '<tr><td class="tcbf-label">Discount</td><td>' . esc_html( $eb_pct ) . '% (' . wc_price( $eb_amount, [ 'currency' => $currency ] ) . ')</td></tr>';
			} else {
				echo '<tr><td class="tcbf-label">Discount</td><td>' . wc_price( $eb_amount, [ 'currency' => $currency ] ) . '</td></tr>';
			}
			if ( $eb_days !== '' ) {
				echo '<tr><td class="tcbf-label">Days before event</td><td>' . esc_html( $eb_days ) . '</td></tr>';
			}
			echo '</table>';
			echo '</div>';
		}

		// === Settlement Section ===
		$settlement_channel = $order->get_meta( '_tcbf_settlement_channel', true );
		if ( $settlement_channel !== '' ) {
			$settlement_ts      = $order->get_meta( '_tcbf_settlement_timestamp', true );
			$settlement_user_id = (int) $order->get_meta( '_tcbf_settlement_user_id', true );

			echo '<div class="tcbf-admin-section">';
			echo '<h4>Settlement</h4>';
			echo '<table class="tcbf-admin-table">';
			echo '<tr><td class="tcbf-label">Channel</td><td>' . esc_html( $settlement_channel ) . '</td></tr>';
			if ( $settlement_user_id > 0 ) {
				$user = get_user_by( 'ID', $settlement_user_id );
				echo '<tr><td class="tcbf-label">Settled by</td><td>' . esc_html( $user ? $user->display_name : '#' . $settlement_user_id ) . '</td></tr>';
			}
			if ( $settlement_ts !== '' ) {
				echo '<tr><td class="tcbf-label">Settled at</td><td>' . esc_html( date_i18n( 'Y-m-d H:i', (int) $settlement_ts ) ) . '</td></tr>';
			}
			echo '</table>';
			echo '</div>';
		}

		echo '</div>'; // .tcbf-admin-summary
	}

	/* =================================================================
	 * Phase 4: Inline Item Badges
	 * ================================================================= */

	/**
	 * Render compact badges on each order item row in admin.
	 *
	 * @param int $item_id Order item ID
	 * @param \WC_Order_Item $item The order item
	 * @param \WC_Product|false $product The product
	 */
	public static function render_item_badges( $item_id, $item, $product ) : void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$order = $item->get_order();
		if ( ! $order ) {
			return;
		}

		// Only for booking orders
		if ( ! Woo_OrderMeta::is_booking_order( $order ) ) {
			return;
		}

		$scope       = Woo_OrderMeta::get_item_meta_ci( $item, '_tc_scope' );
		if ( $scope === '' ) {
			$scope = Woo_OrderMeta::get_item_meta_ci( $item, 'tcbf_scope' );
		}

		$participant = Woo_OrderMeta::get_item_meta_ci( $item, '_participant' );
		if ( $participant === '' ) {
			$participant = Woo_OrderMeta::get_item_meta_ci( $item, 'participant' );
		}
		if ( $participant === '' ) {
			$participant = Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_participant_name' );
		}

		$event_title = Woo_OrderMeta::get_item_meta_ci( $item, '_event_title' );
		if ( $event_title === '' ) {
			$eid = (int) Woo_OrderMeta::get_item_meta_ci( $item, '_event_id' );
			if ( $eid > 0 ) {
				$event_title = get_the_title( $eid );
			}
		}

		// Transport-specific info
		$transport_type = Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_transport_type' );
		$transport_zone = Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_transport_zone_name' );
		$transport_window = Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_transport_window' );

		$badges = [];

		// Scope badge
		if ( $transport_type !== '' ) {
			$label = $transport_type === 'delivery' ? 'Delivery' : 'Return';
			$badges[] = '<span class="tcbf-item-badge tcbf-scope-transport">' . esc_html( $label ) . '</span>';
		} elseif ( $scope !== '' ) {
			$badges[] = '<span class="tcbf-item-badge tcbf-scope-' . esc_attr( $scope ) . '">' . esc_html( ucfirst( $scope ) ) . '</span>';
		}

		// Participant
		if ( $participant !== '' ) {
			$badges[] = '<span class="tcbf-item-badge tcbf-badge-participant">' . esc_html( $participant ) . '</span>';
		}

		// Event (shortened)
		if ( $event_title !== '' && $transport_type === '' ) {
			$short = mb_strlen( $event_title ) > 40 ? mb_substr( $event_title, 0, 37 ) . '...' : $event_title;
			$badges[] = '<span class="tcbf-item-badge tcbf-badge-event">' . esc_html( $short ) . '</span>';
		}

		// Transport zone + window
		if ( $transport_zone !== '' ) {
			$zone_text = $transport_zone;
			if ( $transport_window !== '' ) {
				$zone_text .= ' · ' . ucfirst( $transport_window );
			}
			$badges[] = '<span class="tcbf-item-badge tcbf-badge-transport-detail">' . esc_html( $zone_text ) . '</span>';
		}

		if ( ! empty( $badges ) ) {
			echo '<div class="tcbf-item-badges">' . implode( ' ', $badges ) . '</div>';
		}
	}

	/* =================================================================
	 * Phase 3: Protect TCBF Meta from Custom Fields
	 * ================================================================= */

	/**
	 * Mark TCBF meta keys as protected so they don't appear in Custom Fields.
	 */
	public static function protect_tcbf_meta( $protected, $meta_key ) : bool {
		if ( $protected ) {
			return true;
		}

		// Already protected (underscore prefix)
		// But some TCBF keys don't have underscore prefix — protect those too
		$key_lower = strtolower( $meta_key );

		// Prefix-based protection
		$prefixes = [ 'partner_', 'client_', 'subtotal_', 'early_booking_', 'eb_', 'tc_' ];
		foreach ( $prefixes as $prefix ) {
			if ( strpos( $key_lower, $prefix ) === 0 ) {
				return true;
			}
		}

		// Exact key matches (non-underscore-prefixed TCBF meta stored on orders)
		static $protected_keys = null;
		if ( $protected_keys === null ) {
			$protected_keys = array_flip( array_map( 'strtolower', [
				'partner_id', 'partner_code', 'partner_coupon_type',
				'partner_discount_pct', 'partner_commission_rate', 'partner_base_total',
				'subtotal_original', 'client_total', 'client_discount', 'partner_commission',
				'early_booking_discount_pct', 'early_booking_discount_amount',
				'eb_event_id', 'eb_event_start_ts', 'eb_days_before',
				'tc_ledger_version',
			] ) );
		}

		return isset( $protected_keys[ $key_lower ] );
	}

	/* =================================================================
	 * Admin CSS
	 * ================================================================= */

	public static function admin_css() : void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only on order edit screens
		$order_screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		?>
		<style>
			/* Booking Summary meta box */
			#tcbf-booking-summary .tcbf-admin-summary { padding: 0; }
			#tcbf-booking-summary .tcbf-admin-section { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #f0f0f0; }
			#tcbf-booking-summary .tcbf-admin-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
			#tcbf-booking-summary h4 { margin: 0 0 8px; font-size: 13px; font-weight: 600; color: #1d2327; text-transform: uppercase; letter-spacing: 0.5px; }

			.tcbf-admin-table { border-collapse: collapse; width: auto; }
			.tcbf-admin-table td, .tcbf-admin-table th { padding: 4px 12px 4px 0; font-size: 13px; vertical-align: top; }
			.tcbf-admin-table .tcbf-label { font-weight: 600; color: #50575e; white-space: nowrap; min-width: 120px; }
			.tcbf-admin-table-full { width: 100%; }
			.tcbf-admin-table-full th { text-align: left; font-weight: 600; color: #50575e; border-bottom: 1px solid #e0e0e0; padding-bottom: 6px; }
			.tcbf-admin-table-full td { padding: 5px 8px 5px 0; border-bottom: 1px solid #f6f6f6; }

			/* Scope badges */
			.tcbf-scope-badge, .tcbf-item-badge {
				display: inline-block; padding: 2px 8px; border-radius: 3px;
				font-size: 11px; font-weight: 600; line-height: 1.4;
			}
			.tcbf-scope-participation { background: #e7f5e7; color: #1a6a1a; }
			.tcbf-scope-rental { background: #e8f0fe; color: #174ea6; }
			.tcbf-scope-transport { background: #fef3e0; color: #995c00; }

			/* Item badges in order items table */
			.tcbf-item-badges { margin: 4px 0 2px; display: flex; flex-wrap: wrap; gap: 4px; }
			.tcbf-badge-participant { background: #f0f0f0; color: #1d2327; font-weight: 600; }
			.tcbf-badge-event { background: #f5f0ff; color: #5b21b6; }
			.tcbf-badge-transport-detail { background: #fff8e1; color: #7a5900; }
		</style>
		<?php
	}

	/* =================================================================
	 * Helpers
	 * ================================================================= */

	/**
	 * Get the order edit screen ID (supports HPOS and legacy).
	 */
	private static function get_order_screen() : ?string {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return null;
		}

		if ( $screen->id === 'shop_order' || $screen->id === 'woocommerce_page_wc-orders' ) {
			return $screen->id;
		}

		return null;
	}

	/**
	 * Get the current order being edited.
	 */
	private static function get_current_order() : ?\WC_Order {
		global $post, $theorder;

		// HPOS: $theorder is set by WooCommerce
		if ( $theorder instanceof \WC_Order ) {
			return $theorder;
		}

		// Legacy: get from post
		if ( $post && $post->post_type === 'shop_order' ) {
			return wc_get_order( $post->ID );
		}

		// HPOS fallback: check GET param
		$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Resolve order from meta box callback argument (WP_Post or WC_Order).
	 */
	private static function resolve_order( $post_or_order ) : ?\WC_Order {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof \WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof \WC_Order ? $order : null;
		}
		return null;
	}
}
