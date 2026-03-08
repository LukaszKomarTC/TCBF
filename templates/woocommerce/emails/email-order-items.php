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

// TCBF: Reorder items so transport items follow their parent rental.
// Uses (event_id, participant) matching since cart keys don't persist to orders.
if ( class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' ) ) {
	$reordered    = [];
	$transport_items = [];
	$rental_items = [];
	$other_items  = [];

	foreach ( $items as $item_id => $item ) {
		$scope = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, 'tcbf_scope' );
		if ( $scope === '' ) {
			$scope = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_scope' );
		}

		if ( $scope === 'transport' || \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_transport_type' ) !== '' ) {
			$event_id = (int) \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_event_id' );
			if ( $event_id <= 0 ) {
				$event_id = (int) \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_event_id' );
			}
			$participant = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_participant_name' );
			if ( $participant === '' ) {
				$participant = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_participant' );
			}
			$transport_items[ $item_id ] = [
				'item'        => $item,
				'event_id'    => $event_id,
				'participant' => $participant,
			];
		} elseif ( $scope === 'rental' || $scope === '' ) {
			$event_id = (int) \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_event_id' );
			$participant = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_tcbf_participant_name' );
			if ( $participant === '' ) {
				$participant = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_item_meta_ci( $item, '_participant' );
			}
			$rental_items[ $item_id ] = [
				'item'        => $item,
				'event_id'    => $event_id,
				'participant' => $participant,
			];
		} else {
			$other_items[ $item_id ] = $item;
		}
	}

	// Build reordered: each rental followed by its transport children
	$claimed = [];
	foreach ( $rental_items as $r_id => $r_data ) {
		$reordered[ $r_id ] = $r_data['item'];

		foreach ( $transport_items as $t_id => $t_data ) {
			if ( isset( $claimed[ $t_id ] ) ) {
				continue;
			}
			if ( $r_data['event_id'] > 0 && $t_data['event_id'] === $r_data['event_id']
				&& $t_data['participant'] === $r_data['participant'] ) {
				$reordered[ $t_id ] = $t_data['item'];
				$claimed[ $t_id ] = true;
			}
		}
	}

	// Unclaimed transports + other items at the end
	foreach ( $transport_items as $t_id => $t_data ) {
		if ( ! isset( $claimed[ $t_id ] ) ) {
			$reordered[ $t_id ] = $t_data['item'];
		}
	}
	foreach ( $other_items as $o_id => $o_item ) {
		$reordered[ $o_id ] = $o_item;
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

	?>
	<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>">
		<td class="td font-family text-align-left" style="vertical-align: middle; word-wrap:break-word;">
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
							echo wp_kses_post( "<h3 style='font-size: inherit;font-weight: inherit;'>{$order_item_name}</h3>" );

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

				echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );

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
