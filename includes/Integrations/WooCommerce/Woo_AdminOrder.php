<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Order View Enhancements
 *
 * Replaces the raw meta dump on admin order edit screens with:
 * - A structured "Booking Summary" meta box (event, participants, transport, pricing)
 * - Compact inline badges on each order item (scope, participant, event)
 * - Visual grouping of related items (tour pack, rental + transport)
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

		// Admin CSS + JS (grouping logic)
		add_action( 'admin_head', [ __CLASS__, 'admin_css' ] );
		add_action( 'admin_footer', [ __CLASS__, 'admin_grouping_js' ] );

		// Suppress WC Bookings' default booking display in admin order items.
		// TCBF's Booking Summary meta box + inline badges already show all booking info.
		// The default WC Bookings display can cause critical errors when rendering
		// booking meta that contains raw multilingual strings.
		add_action( 'admin_init', [ __CLASS__, 'suppress_wc_bookings_admin_display' ], 20 );
	}

	/**
	 * Remove WC Bookings' woocommerce_after_order_itemmeta callback.
	 *
	 * WC Bookings registers a callback on woocommerce_after_order_itemmeta to
	 * render booking details (status, dates, meta) under each order line item.
	 * Since TCBF provides its own Booking Summary meta box and inline badges,
	 * this display is redundant and can cause PHP errors with multilingual meta.
	 */
	public static function suppress_wc_bookings_admin_display() : void {
		global $wp_filter;

		if ( ! isset( $wp_filter['woocommerce_after_order_itemmeta'] ) ) {
			return;
		}

		$hook = $wp_filter['woocommerce_after_order_itemmeta'];

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				$func = $callback['function'] ?? null;
				if ( ! $func ) {
					continue;
				}

				// Match WC Bookings callbacks by class name
				$class_name = '';
				if ( is_array( $func ) && is_object( $func[0] ) ) {
					$class_name = get_class( $func[0] );
				} elseif ( is_array( $func ) && is_string( $func[0] ) ) {
					$class_name = $func[0];
				}

				if ( $class_name !== '' && strpos( $class_name, 'WC_Booking' ) !== false ) {
					$hook->remove_filter( 'woocommerce_after_order_itemmeta', $func, $priority );
				}
			}
		}
	}

	/* =================================================================
	 * Meta Box Registration
	 * ================================================================= */

	public static function register_meta_boxes() : void {
		$screen = self::get_order_screen();
		if ( ! $screen ) {
			return;
		}

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

		// Build item records and categorize them
		$records           = [];
		$pack_groups       = []; // tc_group_id => [records]
		$standalone_rental = []; // standalone WC Booking rentals (no tour pack)
		$transport_records = [];

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$rec = Woo_OrderMeta::build_item_record( $order, $item_id, $item );
			$records[] = $rec;

			if ( $rec['is_transport'] ) {
				$transport_records[] = $rec;
			} elseif ( $rec['group_id'] > 0 ) {
				$pack_groups[ $rec['group_id'] ][] = $rec;
			} else {
				// Standalone item (no pack group) — could be a standalone rental or other
				$standalone_rental[] = $rec;
			}
		}

		// Match transports to standalone rentals (same logic as grouping map)
		$rental_transport_map = []; // rental item_id => [transport records]
		$claimed = [];
		foreach ( $standalone_rental as $rental ) {
			foreach ( $transport_records as $ti => $transport ) {
				if ( isset( $claimed[ $ti ] ) ) continue;
				$match = false;
				if ( $rental['entry_id'] > 0 && $transport['entry_id'] === $rental['entry_id'] ) {
					$match = true;
				} elseif ( $transport['transport_parent_product_id'] > 0
					&& $transport['transport_parent_product_id'] === $rental['product_id'] ) {
					$match = true;
				} elseif ( $rental['event_id'] > 0 && $transport['event_id'] === $rental['event_id']
					&& $transport['participant'] !== '' && $transport['participant'] === $rental['participant'] ) {
					$match = true;
				} elseif ( $transport['transport_parent_product_id'] <= 0
					&& $rental['event_id'] <= 0
					&& $transport['participant'] !== ''
					&& $transport['participant'] === $rental['participant'] ) {
					$match = true;
				}
				if ( $match ) {
					$rental_transport_map[ $rental['item_id'] ][] = $transport;
					$claimed[ $ti ] = true;
				}
			}
		}

		echo '<div class="tcbf-admin-summary">';

		// =============================================================
		// SECTION: Tour Packs (participation + rental grouped by event)
		// =============================================================
		if ( ! empty( $pack_groups ) ) {
			foreach ( $pack_groups as $gid => $pack_items ) {
				// Find parent (participation) and children (rental)
				$parent = null;
				$children = [];
				foreach ( $pack_items as $rec ) {
					if ( $rec['role'] === 'parent' ) {
						$parent = $rec;
					} else {
						$children[] = $rec;
					}
				}

				// Use parent for event info, fall back to first item
				$ref = $parent ?: $pack_items[0];
				$event_title = $ref['event_title'];
				$event_id    = $ref['event_id'];
				$event_date  = $ref['booking_date'];

				echo '<div class="tcbf-admin-section tcbf-section-tour">';
				echo '<h4>Tour</h4>';
				echo '<table class="tcbf-admin-table">';
				if ( $event_title !== '' ) {
					echo '<tr><td class="tcbf-label">Event</td><td>';
					if ( $event_id > 0 ) {
						echo '<a href="' . esc_url( get_edit_post_link( $event_id ) ) . '">' . esc_html( $event_title ) . '</a>';
					} else {
						echo esc_html( $event_title );
					}
					echo '</td></tr>';
				}
				if ( $event_date !== '' ) {
					echo '<tr><td class="tcbf-label">Date</td><td>' . esc_html( $event_date ) . '</td></tr>';
				}

				// Participant from parent
				if ( $ref['participant'] !== '' ) {
					echo '<tr><td class="tcbf-label">Participant</td><td><strong>' . esc_html( $ref['participant'] ) . '</strong></td></tr>';
				}

				// Rental child details
				foreach ( $children as $child ) {
					if ( $child['scope'] === 'rental' ) {
						echo '<tr><td class="tcbf-label">Rental bike</td><td>' . esc_html( $child['product_name'] );
						if ( $child['size'] !== '' ) {
							echo ' — Size ' . esc_html( $child['size'] );
						}
						echo '</td></tr>';
						if ( $child['pedals'] !== '' ) {
							echo '<tr><td class="tcbf-label">Pedals</td><td>' . esc_html( $child['pedals'] ) . '</td></tr>';
						}
						if ( $child['helmet'] !== '' ) {
							echo '<tr><td class="tcbf-label">Helmet</td><td>' . esc_html( $child['helmet'] ) . '</td></tr>';
						}
					}
				}
				echo '</table>';
				echo '</div>';
			}
		}

		// =============================================================
		// SECTION: Standalone Rentals (WC Bookings, not part of a tour)
		// =============================================================
		if ( ! empty( $standalone_rental ) ) {
			foreach ( $standalone_rental as $rental ) {
				echo '<div class="tcbf-admin-section tcbf-section-rental">';
				echo '<h4>Standalone Rental</h4>';
				echo '<table class="tcbf-admin-table">';
				echo '<tr><td class="tcbf-label">Product</td><td><strong>' . esc_html( $rental['product_name'] ) . '</strong></td></tr>';
				if ( $rental['participant'] !== '' ) {
					echo '<tr><td class="tcbf-label">Customer</td><td>' . esc_html( $rental['participant'] ) . '</td></tr>';
				}
				if ( $rental['booking_date'] !== '' ) {
					$date_text = $rental['booking_date'];
					if ( ! empty( $rental['end_date'] ) ) {
						$date_text .= ' — ' . $rental['end_date'];
					}
					if ( ! empty( $rental['duration'] ) ) {
						$date_text .= ' (' . $rental['duration'] . ')';
					}
					echo '<tr><td class="tcbf-label">Dates</td><td>' . esc_html( $date_text ) . '</td></tr>';
				}
				if ( $rental['size'] !== '' ) {
					echo '<tr><td class="tcbf-label">Size</td><td>' . esc_html( $rental['size'] ) . '</td></tr>';
				}
				if ( $rental['pedals'] !== '' ) {
					echo '<tr><td class="tcbf-label">Pedals</td><td>' . esc_html( $rental['pedals'] ) . '</td></tr>';
				}
				if ( $rental['helmet'] !== '' ) {
					echo '<tr><td class="tcbf-label">Helmet</td><td>' . esc_html( $rental['helmet'] ) . '</td></tr>';
				}

				// Transport linked to this rental
				$transports = $rental_transport_map[ $rental['item_id'] ] ?? [];
				if ( ! empty( $transports ) ) {
					echo '<tr><td class="tcbf-label" style="padding-top:8px">Transport</td><td style="padding-top:8px">';
					foreach ( $transports as $t ) {
						$type_label = $t['transport_type'] === 'delivery' ? 'Delivery' : 'Return';
						$window_label = ucfirst( $t['transport_window'] ?: '' );
						$price = Woo_OrderMeta::get_item_meta_ci( $t['item'], '_tcbf_transport_price' );
						echo '<div style="margin-bottom:4px">';
						echo '<strong>' . esc_html( $type_label ) . '</strong>';
						if ( $t['transport_date'] !== '' ) echo ' · ' . esc_html( $t['transport_date'] );
						if ( $window_label !== '' ) echo ' · ' . esc_html( $window_label );
						if ( $t['transport_zone'] !== '' ) echo ' · ' . esc_html( $t['transport_zone'] );
						if ( $t['transport_address'] !== '' ) {
							$short_addr = mb_strlen( $t['transport_address'] ) > 50
								? mb_substr( $t['transport_address'], 0, 47 ) . '...'
								: $t['transport_address'];
							echo '<br><span style="color:#666; font-size:12px">' . esc_html( $short_addr ) . '</span>';
						}
						if ( $price !== '' ) {
							echo ' — ' . wc_price( (float) $price, [ 'currency' => $currency ] );
						}
						echo '</div>';
					}
					echo '</td></tr>';
				}

				echo '</table>';
				echo '</div>';
			}
		}

		// =============================================================
		// SECTION: Unmatched Transport (if any transport not linked above)
		// =============================================================
		$unmatched_transport = [];
		foreach ( $transport_records as $ti => $t ) {
			if ( ! isset( $claimed[ $ti ] ) ) {
				$unmatched_transport[] = $t;
			}
		}
		if ( ! empty( $unmatched_transport ) ) {
			echo '<div class="tcbf-admin-section">';
			echo '<h4>Transport</h4>';
			echo '<table class="tcbf-admin-table tcbf-admin-table-full">';
			echo '<thead><tr><th>Type</th><th>Date</th><th>Window</th><th>Zone</th><th>Address</th><th>Price</th></tr></thead>';
			echo '<tbody>';
			foreach ( $unmatched_transport as $t ) {
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
	 * Inline Item Badges
	 * ================================================================= */

	public static function render_item_badges( $item_id, $item, $product ) : void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$order = $item->get_order();
		if ( ! $order ) {
			return;
		}

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
		$transport_type   = Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_transport_type' );
		$transport_zone   = Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_transport_zone_name' );
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

		// Event (shortened) — only for participation items
		if ( $event_title !== '' && $scope === 'participation' ) {
			$short = mb_strlen( $event_title ) > 40 ? mb_substr( $event_title, 0, 37 ) . '...' : $event_title;
			$badges[] = '<span class="tcbf-item-badge tcbf-badge-event">' . esc_html( $short ) . '</span>';
		}

		// Transport zone + window
		if ( $transport_zone !== '' && $transport_window !== '' ) {
			$detail_text = $transport_zone . ' · ' . Woo_Transport::get_window_label( $transport_window );
			$badges[] = '<span class="tcbf-item-badge tcbf-badge-transport-detail">' . esc_html( $detail_text ) . '</span>';
		} elseif ( $transport_zone !== '' ) {
			$badges[] = '<span class="tcbf-item-badge tcbf-badge-transport-detail">' . esc_html( $transport_zone ) . '</span>';
		} elseif ( $transport_window !== '' ) {
			$badges[] = '<span class="tcbf-item-badge tcbf-badge-transport-detail">' . esc_html( Woo_Transport::get_window_label( $transport_window ) ) . '</span>';
		}

		if ( ! empty( $badges ) ) {
			echo '<div class="tcbf-item-badges">' . implode( ' ', $badges ) . '</div>';
		}
	}

	/* =================================================================
	 * Visual Grouping: Server-side data + Client-side DOM manipulation
	 * ================================================================= */

	/**
	 * Build the grouping map for the current order.
	 *
	 * Returns an ordered array of groups. Each group has:
	 * - 'items': ordered array of item_ids (parent first, then children)
	 * - 'color': CSS color for left border
	 * - 'roles': map of item_id => role ('parent'|'child')
	 *
	 * Reuses the same grouping logic as render_grouped_order_items_table().
	 */
	private static function build_grouping_map( \WC_Order $order ) : array {
		$records = [];
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$records[] = Woo_OrderMeta::build_item_record( $order, $item_id, $item );
		}

		if ( empty( $records ) ) {
			return [];
		}

		// Separate into tc_group_id groups, standalone rentals, transports, other
		$pack_groups       = []; // tc_group_id => [records]
		$standalone        = [];

		foreach ( $records as $rec ) {
			if ( $rec['group_id'] > 0 ) {
				$pack_groups[ $rec['group_id'] ][] = $rec;
			} else {
				$standalone[] = $rec;
			}
		}

		// Sub-group standalone: rentals + their transport children
		$rental_records    = [];
		$transport_records = [];
		$other_records     = [];

		foreach ( $standalone as $rec ) {
			if ( $rec['is_transport'] ) {
				$transport_records[] = $rec;
			} elseif ( $rec['scope'] === 'rental' || $rec['scope'] === '' ) {
				$rental_records[] = $rec;
			} else {
				$other_records[] = $rec;
			}
		}

		// Match transports to rentals (same logic as Woo_OrderMeta::render_grouped_order_items_table)
		$rental_subgroups      = [];
		$claimed_transport_ids = [];

		foreach ( $rental_records as $rental ) {
			$subgroup = [ 'rental' => $rental, 'transports' => [] ];

			foreach ( $transport_records as $ti => $transport ) {
				if ( isset( $claimed_transport_ids[ $ti ] ) ) {
					continue;
				}

				$match = false;
				if ( $rental['entry_id'] > 0 && $transport['entry_id'] === $rental['entry_id'] ) {
					$match = true;
				} elseif ( $transport['transport_parent_product_id'] > 0
					&& $transport['transport_parent_product_id'] === $rental['product_id'] ) {
					$match = true;
				} elseif ( $rental['event_id'] > 0 && $transport['event_id'] === $rental['event_id']
					&& $transport['participant'] !== '' && $transport['participant'] === $rental['participant'] ) {
					$match = true;
				} elseif ( $transport['transport_parent_product_id'] <= 0
					&& $rental['event_id'] <= 0
					&& $transport['participant'] !== ''
					&& $transport['participant'] === $rental['participant'] ) {
					$match = true;
				}

				if ( $match ) {
					$subgroup['transports'][] = $transport;
					$claimed_transport_ids[ $ti ] = true;
				}
			}

			$rental_subgroups[] = $subgroup;
		}

		// Unclaimed transports go to other
		foreach ( $transport_records as $ti => $transport ) {
			if ( ! isset( $claimed_transport_ids[ $ti ] ) ) {
				$other_records[] = $transport;
			}
		}

		// Build the final grouping map
		$groups = [];
		$colors = [ '#2271b1', '#d63638', '#00a32a', '#dba617', '#8c5e58', '#3858e9', '#b32d2e' ];
		$ci     = 0;

		// 1. Tour pack groups (participation parent + rental child)
		foreach ( $pack_groups as $gid => $pack_items ) {
			$group = [ 'items' => [], 'color' => $colors[ $ci % count( $colors ) ], 'roles' => [] ];

			// Sort: parent first, then children
			usort( $pack_items, function( $a, $b ) {
				if ( $a['role'] === 'parent' && $b['role'] !== 'parent' ) return -1;
				if ( $a['role'] !== 'parent' && $b['role'] === 'parent' ) return 1;
				return 0;
			} );

			foreach ( $pack_items as $rec ) {
				$group['items'][] = $rec['item_id'];
				$group['roles'][ $rec['item_id'] ] = $rec['role'] === 'parent' ? 'parent' : 'child';
			}

			// Check if any transport matches items in this pack
			foreach ( $transport_records as $ti => $transport ) {
				if ( isset( $claimed_transport_ids[ $ti ] ) ) {
					continue;
				}
				// Match transport to pack by entry_id or event_id+participant
				foreach ( $pack_items as $pack_rec ) {
					if ( $pack_rec['scope'] !== 'rental' ) continue;
					$match = false;
					if ( $pack_rec['entry_id'] > 0 && $transport['entry_id'] === $pack_rec['entry_id'] ) {
						$match = true;
					} elseif ( $pack_rec['event_id'] > 0 && $transport['event_id'] === $pack_rec['event_id']
						&& $transport['participant'] !== '' && $transport['participant'] === $pack_rec['participant'] ) {
						$match = true;
					}
					if ( $match ) {
						$group['items'][] = $transport['item_id'];
						$group['roles'][ $transport['item_id'] ] = 'child';
						$claimed_transport_ids[ $ti ] = true;
						break;
					}
				}
			}

			if ( count( $group['items'] ) > 1 ) {
				$groups[] = $group;
				$ci++;
			}
		}

		// 2. Standalone rental + transport sub-groups
		foreach ( $rental_subgroups as $subgroup ) {
			if ( empty( $subgroup['transports'] ) ) {
				continue; // Single rental with no transport — no grouping needed
			}

			$group = [ 'items' => [], 'color' => $colors[ $ci % count( $colors ) ], 'roles' => [] ];
			$group['items'][] = $subgroup['rental']['item_id'];
			$group['roles'][ $subgroup['rental']['item_id'] ] = 'parent';

			foreach ( $subgroup['transports'] as $t ) {
				$group['items'][] = $t['item_id'];
				$group['roles'][ $t['item_id'] ] = 'child';
			}

			$groups[] = $group;
			$ci++;
		}

		return $groups;
	}

	/**
	 * Output grouping JS in admin footer.
	 */
	public static function admin_grouping_js() : void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$order_screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		$order = self::get_current_order();
		if ( ! $order || ! Woo_OrderMeta::is_booking_order( $order ) ) {
			return;
		}

		$groups = self::build_grouping_map( $order );
		if ( empty( $groups ) ) {
			return;
		}

		// Prepare JSON-safe structure (convert item_id keys in roles to strings)
		$groups_json = [];
		foreach ( $groups as $g ) {
			$roles = [];
			foreach ( $g['roles'] as $id => $role ) {
				$roles[ (string) $id ] = $role;
			}
			$groups_json[] = [
				'items' => array_map( 'intval', $g['items'] ),
				'color' => $g['color'],
				'roles' => $roles,
			];
		}

		?>
		<script>
		(function() {
			var groups = <?php echo wp_json_encode( $groups_json ); ?>;
			if ( ! groups || ! groups.length ) return;

			function applyGrouping() {
				var tbody = document.getElementById( 'order_line_items' );
				if ( ! tbody ) return;

				groups.forEach( function( group ) {
					var parentRow = null;
					var rows = [];

					group.items.forEach( function( itemId ) {
						var row = tbody.querySelector( 'tr.item[data-order_item_id="' + itemId + '"]' );
						if ( ! row ) return;
						rows.push( row );
						if ( group.roles[ String( itemId ) ] === 'parent' && ! parentRow ) {
							parentRow = row;
						}
					} );

					if ( rows.length < 2 ) return;

					// Ensure rows are adjacent: move children right after parent
					if ( parentRow ) {
						var insertAfter = parentRow;
						rows.forEach( function( row ) {
							if ( row === parentRow ) return;
							insertAfter.after( row );
							insertAfter = row;
						} );
					}

					// Apply visual grouping
					rows.forEach( function( row, idx ) {
						var itemId = parseInt( row.getAttribute( 'data-order_item_id' ), 10 );
						var role = group.roles[ String( itemId ) ] || 'child';

						row.style.borderLeft = '3px solid ' + group.color;

						if ( role === 'child' ) {
							// Indent the name cell
							var nameCell = row.querySelector( 'td.name' );
							if ( nameCell ) {
								nameCell.style.paddingLeft = '28px';
							}
						}

						// Remove bottom border for all but last in group
						if ( idx < rows.length - 1 ) {
							row.classList.add( 'tcbf-group-item' );
						}
						// Last item gets bottom border rounded visual
						if ( idx === rows.length - 1 ) {
							row.classList.add( 'tcbf-group-last' );
						}
						// First item
						if ( idx === 0 ) {
							row.classList.add( 'tcbf-group-first' );
						}
					} );
				} );
			}

			// Run on DOM ready and also after WC AJAX reloads the items
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', applyGrouping );
			} else {
				applyGrouping();
			}

			// Re-apply after WC reloads items via AJAX
			jQuery( document.body ).on( 'wc_backbone_modal_loaded wc-enhanced-select-init', applyGrouping );
			jQuery( document ).on( 'items_saved order-item-added', applyGrouping );
		})();
		</script>
		<?php
	}

	/* =================================================================
	 * Protect TCBF Meta from Custom Fields
	 * ================================================================= */

	public static function protect_tcbf_meta( $protected, $meta_key ) : bool {
		if ( $protected ) {
			return true;
		}

		$key_lower = strtolower( $meta_key );

		$prefixes = [ 'partner_', 'client_', 'subtotal_', 'early_booking_', 'eb_', 'tc_' ];
		foreach ( $prefixes as $prefix ) {
			if ( strpos( $key_lower, $prefix ) === 0 ) {
				return true;
			}
		}

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

		$order_screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		?>
		<style>
			/* Booking Summary meta box */
			#tcbf-booking-summary .tcbf-admin-summary { padding: 0; }
			#tcbf-booking-summary .tcbf-admin-section { margin-bottom: 16px; padding: 12px; border-radius: 4px; border: 1px solid #e0e0e0; }
			#tcbf-booking-summary .tcbf-admin-section:last-child { margin-bottom: 0; }
			#tcbf-booking-summary h4 { margin: 0 0 8px; font-size: 13px; font-weight: 600; color: #1d2327; text-transform: uppercase; letter-spacing: 0.5px; }

			/* Section type colors */
			#tcbf-booking-summary .tcbf-section-tour { border-left: 4px solid #00a32a; background: #f8fff8; }
			#tcbf-booking-summary .tcbf-section-tour h4 { color: #1a6a1a; }
			#tcbf-booking-summary .tcbf-section-rental { border-left: 4px solid #2271b1; background: #f6f9fd; }
			#tcbf-booking-summary .tcbf-section-rental h4 { color: #174ea6; }

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

			/* Grouping: connected items */
			#order_line_items tr.tcbf-group-item > td { border-bottom: 1px dashed #e0e0e0 !important; }
			#order_line_items tr.tcbf-group-first > td:first-child { border-top-left-radius: 3px; }
			#order_line_items tr.tcbf-group-last > td:first-child { border-bottom-left-radius: 3px; }
		</style>
		<?php
	}

	/* =================================================================
	 * Helpers
	 * ================================================================= */

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

	private static function get_current_order() : ?\WC_Order {
		global $post, $theorder;

		if ( $theorder instanceof \WC_Order ) {
			return $theorder;
		}

		if ( $post && $post->post_type === 'shop_order' ) {
			return wc_get_order( $post->ID );
		}

		$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}

		return null;
	}

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
