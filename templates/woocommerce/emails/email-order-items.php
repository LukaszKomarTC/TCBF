<?php
/**
 * Email Order Items (TCBF override)
 *
 * Based on WooCommerce emails/email-order-items.php.
 * Adds a hook point after each item row to inject full-width <tr> rows
 * (EB banners, pack summaries, etc.) via Woo_OrderMeta::get_email_item_extra_rows().
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package TC_Booking_Flow
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$margin_side = is_rtl() ? 'left' : 'right';

$email_improvements_enabled = class_exists( FeaturesUtil::class )
	&& FeaturesUtil::feature_is_enabled( 'email_improvements' );
$price_text_align           = $email_improvements_enabled ? 'right' : 'left';

// TCBF: Reorder items with full grouping awareness.
// 1. Tour pack groups (tc_group_id): participation → rental child → transport children
// 2. Standalone rentals followed by their transport children
// 3. Remaining items
// Also builds a map of which items are "children" for ↳ arrow display.
$tcbf_child_item_ids = []; // item_id => true for items that should show ↳ prefix

if ( class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' ) ) {
	$meta = '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta';

	// Categorize all items
	$grouped       = []; // group_id => [ item_id => [ 'item' => ..., 'scope' => ..., ... ] ]
	$standalone    = []; // item_id => [ 'item' => ..., 'scope' => ..., ... ]

	foreach ( $items as $item_id => $item ) {
		$scope = $meta::get_item_meta_ci( $item, 'tcbf_scope' );
		if ( $scope === '' ) {
			$scope = $meta::get_item_meta_ci( $item, '_tcbf_scope' );
		}
		if ( $scope === '' ) {
			$scope = $meta::get_item_meta_ci( $item, '_tc_scope' );
		}

		$group_id    = (int) $meta::get_item_meta_ci( $item, 'tc_group_id' );
		$group_role  = $meta::get_item_meta_ci( $item, 'tc_group_role' );
		$event_id    = (int) $meta::get_item_meta_ci( $item, '_event_id' );
		if ( $event_id <= 0 ) {
			$event_id = (int) $meta::get_item_meta_ci( $item, '_tcbf_event_id' );
		}
		$participant = $meta::get_item_meta_ci( $item, '_tcbf_participant_name' );
		if ( $participant === '' ) {
			$participant = $meta::get_item_meta_ci( $item, '_participant' );
		}
		// Fallback: check the 'participant' meta (set by woo_checkout_create_order_line_item)
		if ( $participant === '' ) {
			$participant = $meta::get_item_meta_ci( $item, 'participant' );
		}

		$is_transport = ( $scope === 'transport' || $meta::get_item_meta_ci( $item, '_tcbf_transport_type' ) !== '' );
		$transport_parent_product_id = (int) $meta::get_item_meta_ci( $item, '_tcbf_transport_parent_product_id' );

		// GF entry ID — primary key for matching items from the same form submission
		$entry_id = (int) $meta::get_item_meta_ci( $item, '_gf_entry_id' );
		if ( $entry_id <= 0 ) {
			$entry_id = (int) $meta::get_item_meta_ci( $item, '_tcbf_gf_entry_id' );
		}

		$product    = $item->get_product();
		$product_id = $product ? $product->get_id() : 0;

		$record = [
			'item'                        => $item,
			'scope'                       => $scope,
			'group_id'                    => $group_id,
			'group_role'                  => $group_role,
			'entry_id'                    => $entry_id,
			'event_id'                    => $event_id,
			'participant'                 => $participant,
			'is_transport'                => $is_transport,
			'transport_parent_product_id' => $transport_parent_product_id,
			'product_id'                  => $product_id,
		];

		if ( $group_id > 0 ) {
			$grouped[ $group_id ][ $item_id ] = $record;
		} else {
			$standalone[ $item_id ] = $record;
		}
	}

	// Process grouped items: sort within each group (participation → rental → transport)
	$reordered = [];

	foreach ( $grouped as $gid => $group_items ) {
		$parent_ids    = [];
		$child_ids     = [];
		$transport_ids = [];

		foreach ( $group_items as $gitem_id => $grecord ) {
			if ( $grecord['is_transport'] ) {
				$transport_ids[] = $gitem_id;
			} elseif ( $grecord['group_role'] === 'parent' || $grecord['scope'] === 'participation'
				|| ( $grecord['event_id'] > 0 && $grecord['scope'] !== 'rental' ) ) {
				$parent_ids[] = $gitem_id;
			} else {
				$child_ids[] = $gitem_id;
			}
		}

		// If no parent found, first non-transport item is parent
		if ( empty( $parent_ids ) ) {
			$all_non_transport = array_merge( $child_ids );
			if ( ! empty( $all_non_transport ) ) {
				$parent_ids[] = array_shift( $all_non_transport );
				$child_ids = $all_non_transport;
			}
		}

		// Add in order: parents, then rental children (↳), then transport children (↳)
		foreach ( $parent_ids as $pid ) {
			$reordered[ $pid ] = $group_items[ $pid ]['item'];
		}
		foreach ( $child_ids as $cid ) {
			$reordered[ $cid ] = $group_items[ $cid ]['item'];
			$tcbf_child_item_ids[ $cid ] = true;
		}
		foreach ( $transport_ids as $tid ) {
			$reordered[ $tid ] = $group_items[ $tid ]['item'];
			$tcbf_child_item_ids[ $tid ] = true;
		}
	}

	// Process standalone items: rentals with their transport children, then the rest
	$st_transport = [];
	$st_rental    = [];
	$st_other     = [];

	foreach ( $standalone as $sid => $srecord ) {
		if ( $srecord['is_transport'] ) {
			$st_transport[ $sid ] = $srecord;
		} elseif ( $srecord['scope'] === 'rental' || $srecord['scope'] === '' ) {
			$st_rental[ $sid ] = $srecord;
		} else {
			$st_other[ $sid ] = $srecord;
		}
	}

	// Match transports to rentals (same logic as order summary)
	$claimed = [];
	$rental_subgroups = []; // rental_item_id => [ transport_item_ids ]

	foreach ( $st_rental as $r_id => $r_data ) {
		$rental_subgroups[ $r_id ] = [];

		foreach ( $st_transport as $t_id => $t_data ) {
			if ( isset( $claimed[ $t_id ] ) ) {
				continue;
			}

			// Strategy 0: entry_id match (most reliable — same GF submission)
			// Strategy 1: parent_product_id match (reliable — set at cart time, no participant needed)
			// Strategy 2: event_id + participant (tour-linked rentals, partner/admin orders)
			// Strategy 3: participant only (legacy orders without parent_product_id)
			$match = false;
			if ( $r_data['entry_id'] > 0 && $t_data['entry_id'] === $r_data['entry_id'] ) {
				$match = true;
			} elseif ( $t_data['transport_parent_product_id'] > 0
				&& $t_data['transport_parent_product_id'] === $r_data['product_id'] ) {
				$match = true;
			} elseif ( $r_data['event_id'] > 0 && $t_data['event_id'] === $r_data['event_id']
				&& $t_data['participant'] !== '' && $t_data['participant'] === $r_data['participant'] ) {
				$match = true;
			} elseif ( $t_data['transport_parent_product_id'] <= 0
				&& $r_data['event_id'] <= 0
				&& $t_data['participant'] !== ''
				&& $t_data['participant'] === $r_data['participant'] ) {
				$match = true;
			}

			if ( $match ) {
				$rental_subgroups[ $r_id ][] = $t_id;
				$claimed[ $t_id ] = true;
			}
		}
	}

	// Add standalone rentals + their transport children
	foreach ( $st_rental as $r_id => $r_data ) {
		$reordered[ $r_id ] = $r_data['item'];

		foreach ( $rental_subgroups[ $r_id ] as $t_id ) {
			$reordered[ $t_id ] = $st_transport[ $t_id ]['item'];
			$tcbf_child_item_ids[ $t_id ] = true;
		}
	}

	// Unclaimed transports
	foreach ( $st_transport as $t_id => $t_data ) {
		if ( ! isset( $claimed[ $t_id ] ) ) {
			$reordered[ $t_id ] = $t_data['item'];
		}
	}

	// Other items (e.g. standalone participation without group, shop products, etc.)
	foreach ( $st_other as $o_id => $o_data ) {
		$reordered[ $o_id ] = $o_data['item'];
	}

	$items = $reordered;
}

foreach ( $items as $item_id => $item ) :
	$product       = $item->get_product();
	$sku           = '';
	$purchase_note = '';
	$image         = '';

	if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
		continue;
	}

	if ( is_object( $product ) ) {
		$sku           = $product->get_sku();
		$purchase_note = $product->get_purchase_note();
		$image         = $product->get_image( $image_size );
	}

	// Determine if this item is a child (should show ↳ prefix)
	$is_child_item = isset( $tcbf_child_item_ids[ $item_id ] );
	$child_arrow   = $is_child_item ? '<span style="color:#9ca3af; font-size:14px; margin-right:4px;">&#8627;</span>' : '';
	$child_padding = $is_child_item ? 'padding-left:18px;' : '';

	?>
	<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>">
		<td class="td font-family text-align-left" style="vertical-align: middle; word-wrap:break-word; <?php echo $child_padding; ?>">
			<?php if ( $email_improvements_enabled ) { ?>
				<table class="order-item-data" role="presentation">
					<tr>
						<?php
						// Show title/image etc.
						if ( $show_image ) {
							echo '<td>' . wp_kses_post( apply_filters( 'woocommerce_order_item_thumbnail', $image, $item ) ) . '</td>';
						}
						?>
						<td>
							<?php
							$order_item_name = apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false );
							echo wp_kses_post( "<h3 style='font-size: inherit;font-weight: inherit;'>{$child_arrow}{$order_item_name}</h3>" );

							// SKU.
							if ( $show_sku && $sku ) {
								echo wp_kses_post( ' (#' . $sku . ')' );
							}

							do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );

							$item_meta = wc_display_item_meta(
								$item,
								array(
									'before'       => '',
									'after'        => '',
									'separator'    => '<br>',
									'echo'         => false,
									'label_before' => '<span>',
									'label_after'  => ':</span> ',
								)
							);
							echo '<div class="email-order-item-meta">';
							echo wp_kses(
								$item_meta,
								array(
									'br'   => array(),
									'span' => array(),
									'a'    => array(
										'href'   => true,
										'target' => true,
										'rel'    => true,
										'title'  => true,
									),
								)
							);
							echo '</div>';

							do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );

							?>
						</td>
					</tr>
				</table>
				<?php
			} else {

				// Show title/image etc.
				if ( $show_image ) {
					echo wp_kses_post( apply_filters( 'woocommerce_order_item_thumbnail', $image, $item ) );
				}

				// Child arrow prefix + product name
				$order_item_name = apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false );
				echo wp_kses_post( $child_arrow . $order_item_name );

				// SKU.
				if ( $show_sku && $sku ) {
					echo wp_kses_post( ' (#' . $sku . ')' );
				}

				do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );

				wc_display_item_meta(
					$item,
					array(
						'label_before' => '<strong class="wc-item-meta-label" style="float: ' . ( is_rtl() ? 'right' : 'left' ) . '; margin-' . esc_attr( $margin_side ) . ': .25em; clear: both">',
					)
				);

				do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );
			}
			?>
		</td>
		<td class="td font-family text-align-<?php echo esc_attr( $price_text_align ); ?>" style="vertical-align:middle;">
			<?php
			echo $email_improvements_enabled ? '&times;' : '';
			$qty          = $item->get_quantity();
			$refunded_qty = $order->get_qty_refunded_for_item( $item_id );

			if ( $refunded_qty ) {
				$qty_display = '<del>' . esc_html( $qty ) . '</del> <ins>' . esc_html( $qty - ( $refunded_qty * -1 ) ) . '</ins>';
			} else {
				$qty_display = esc_html( $qty );
			}
			echo wp_kses_post( apply_filters( 'woocommerce_email_order_item_quantity', $qty_display, $item ) );
			?>
		</td>
		<?php
		$tcbf_item_has_eb = false;
		if ( class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' ) ) {
			$tcbf_item_has_eb = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_email_item_has_eb( $order, (int) $item_id, $item );
		}
		$tcbf_price_html = $order->get_formatted_line_subtotal( $item );
		?>
		<?php if ( $tcbf_item_has_eb ) : ?>
			<td class="td font-family" style="vertical-align:middle; text-align:right; padding:6px 10px;">
				<del style="color:#1a1a1a; font-size:12px;"><?php echo wp_kses_post( $tcbf_price_html ); ?></del>
			</td>
		<?php else : ?>
			<td class="td font-family" style="vertical-align:middle; text-align:right; padding:6px 10px; background:#f8f5ff;">
				<span style="font-size:14px; font-weight:800; color:#1a1a1a;"><?php echo wp_kses_post( $tcbf_price_html ); ?></span>
			</td>
		<?php endif; ?>
	</tr>
	<?php

	// === TCBF: inject full-width extra rows (EB banner, pack summary) ===
	if ( class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' ) ) {
		echo \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_email_item_extra_rows( $order, (int) $item_id, $item );
	}

	if ( $show_purchase_note && $purchase_note ) {
		?>
		<tr>
			<td colspan="3" class="font-family text-align-left" style="vertical-align:middle;">
				<?php
				echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) );
				?>
			</td>
		</tr>
		<?php
	}
	?>

<?php endforeach; ?>
