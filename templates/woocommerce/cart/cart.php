<?php
/**
 * Cart Page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 7.9.0
 *
 * TCBF Customization v3:
 * - Groups items by tc_group_id (pack grouping: participation + rental)
 * - Transport items hidden from table (rendered inline under parent rental)
 * - Adds per-item / per-group EB summary rows
 * - Uses event images for tour items
 * - Consistent card layout across all screen sizes
 * - Maintains all WooCommerce hooks
 */

defined( 'ABSPATH' ) || exit;

// Flag to prevent hook from also rendering pack footers (template handles it)
global $tcbf_cart_template_loaded;
$tcbf_cart_template_loaded = true;

/**
 * Sort group items: participation first, then rentals.
 * Transport items are filtered out by woocommerce_cart_item_visible.
 */
if ( ! function_exists( 'tcbf_sort_group_items' ) ) :
function tcbf_sort_group_items( array $group_items ) : array {
	$participation = [];
	$rentals       = [];
	$other         = [];

	foreach ( $group_items as $cart_key => $item ) {
		$scope = $item['tcbf_scope']
			?? ( isset( $item['booking'] ) ? ( $item['booking'][ \TC_BF\Plugin::BK_SCOPE ] ?? '' ) : '' );

		if ( $scope === 'participation' ) {
			$participation[ $cart_key ] = $item;
		} elseif ( $scope === 'rental' || $scope === '' ) {
			$rentals[ $cart_key ] = $item;
		} else {
			$other[ $cart_key ] = $item;
		}
	}

	return $participation + $rentals + $other;
}
endif;

/**
 * Render an inline EB summary row for a single cart item.
 */
if ( ! function_exists( 'tcbf_render_inline_eb_row' ) ) :
function tcbf_render_inline_eb_row( array $cart_item, $group_id ) : void {
	$base      = (float) ( $cart_item['_tcbf_ledger_base'] ?? 0 );
	$eb_amount = (float) ( $cart_item['_tcbf_ledger_eb_amount'] ?? 0 );
	$total     = (float) ( $cart_item['_tcbf_ledger_total'] ?? 0 );

	if ( $eb_amount > 0 && $base > 0 ) {
		$base_label  = '[:en]Price before EB[:es]Precio antes de RA[:]';
		$eb_label    = '[:en]Early booking discount[:es]Descuento reserva anticipada[:]';
		$total_label = '[:en]Total[:es]Total[:]';
		if ( function_exists( 'tc_sc_event_tr' ) ) {
			$base_label  = tc_sc_event_tr( $base_label );
			$eb_label    = tc_sc_event_tr( $eb_label );
			$total_label = tc_sc_event_tr( $total_label );
		}
	} else {
		$item_eb_totals = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::calculate_cart_pack_totals( [ $cart_item ] );
		if ( ! $item_eb_totals['has_eb'] ) {
			return;
		}
		$base        = $item_eb_totals['base_price'];
		$eb_amount   = $item_eb_totals['eb_discount'];
		$total       = $item_eb_totals['pack_total'];
		$base_label  = $item_eb_totals['base_label'];
		$eb_label    = __( 'Early booking discount', 'tc-booking-flow-next' );
		$total_label = $item_eb_totals['total_label'];
	}
	?>
	<tr class="tcbf-pack-footer-row tcbf-pack-footer-row--inline" data-tcbf-group="<?php echo esc_attr( $group_id ); ?>">
		<td colspan="6" class="tcbf-pack-footer-cell">
			<div class="tcbf-pack-footer tcbf-pack-footer--cart tcbf-pack-footer--inline">
				<div class="tcbf-pack-footer-line tcbf-pack-footer-base">
					<span class="tcbf-pack-footer-label"><?php echo esc_html( $base_label ); ?></span>
					<span class="tcbf-pack-footer-value"><?php echo wp_kses_post( wc_price( $base ) ); ?></span>
				</div>
				<?php if ( $eb_amount > 0 ) : ?>
				<div class="tcbf-pack-footer-line tcbf-pack-footer-eb">
					<span class="tcbf-pack-footer-label"><?php echo esc_html( $eb_label ); ?></span>
					<span class="tcbf-pack-footer-value tcbf-pack-footer-discount">-<?php echo wp_kses_post( wc_price( $eb_amount ) ); ?></span>
				</div>
				<?php endif; ?>
				<div class="tcbf-pack-footer-line tcbf-pack-footer-total">
					<span class="tcbf-pack-footer-label"><?php echo esc_html( $total_label ); ?></span>
					<span class="tcbf-pack-footer-value"><?php echo wp_kses_post( wc_price( $total ) ); ?></span>
				</div>
			</div>
		</td>
	</tr>
	<?php
}
endif;

/**
 * Render a combined EB summary row for an entire group (pack).
 */
if ( ! function_exists( 'tcbf_render_group_eb_row' ) ) :
function tcbf_render_group_eb_row( array $group_items, $group_id ) : void {
	$group_items_array = array_values( $group_items );

	$ledger_base = 0;
	$ledger_eb   = 0;
	$ledger_total = 0;
	$has_ledger  = false;

	foreach ( $group_items_array as $item ) {
		$item_eb = (float) ( $item['_tcbf_ledger_eb_amount'] ?? 0 );
		if ( $item_eb > 0 ) {
			$has_ledger   = true;
			$ledger_base  += (float) ( $item['_tcbf_ledger_base'] ?? 0 );
			$ledger_eb    += $item_eb;
			$ledger_total += (float) ( $item['_tcbf_ledger_total'] ?? 0 );
		}
	}

	if ( $has_ledger && $ledger_eb > 0 && $ledger_base > 0 ) {
		$base      = $ledger_base;
		$eb_amount = $ledger_eb;
		$total     = $ledger_total;
		$is_pack     = count( $group_items_array ) > 1;
		$base_label  = $is_pack
			? '[:en]Pack price before EB[:es]Precio del pack antes de RA[:]'
			: '[:en]Price before EB[:es]Precio antes de RA[:]';
		$eb_label    = '[:en]Early booking discount[:es]Descuento reserva anticipada[:]';
		$total_label = $is_pack
			? '[:en]Pack total[:es]Total del pack[:]'
			: '[:en]Total[:es]Total[:]';
		if ( function_exists( 'tc_sc_event_tr' ) ) {
			$base_label  = tc_sc_event_tr( $base_label );
			$eb_label    = tc_sc_event_tr( $eb_label );
			$total_label = tc_sc_event_tr( $total_label );
		}
	} else {
		$pack_totals = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::calculate_cart_pack_totals( $group_items_array );
		if ( ! $pack_totals['has_eb'] ) {
			return;
		}
		$base        = $pack_totals['base_price'];
		$eb_amount   = $pack_totals['eb_discount'];
		$total       = $pack_totals['pack_total'];
		$base_label  = $pack_totals['base_label'];
		$eb_label    = __( 'Early booking discount', 'tc-booking-flow-next' );
		$total_label = $pack_totals['total_label'];
	}
	?>
	<tr class="tcbf-pack-footer-row tcbf-pack-footer-row--inline" data-tcbf-group="<?php echo esc_attr( $group_id ); ?>">
		<td colspan="6" class="tcbf-pack-footer-cell">
			<div class="tcbf-pack-footer tcbf-pack-footer--cart tcbf-pack-footer--inline">
				<div class="tcbf-pack-footer-line tcbf-pack-footer-base">
					<span class="tcbf-pack-footer-label"><?php echo esc_html( $base_label ); ?></span>
					<span class="tcbf-pack-footer-value"><?php echo wp_kses_post( wc_price( $base ) ); ?></span>
				</div>
				<?php if ( $eb_amount > 0 ) : ?>
				<div class="tcbf-pack-footer-line tcbf-pack-footer-eb">
					<span class="tcbf-pack-footer-label"><?php echo esc_html( $eb_label ); ?></span>
					<span class="tcbf-pack-footer-value tcbf-pack-footer-discount">-<?php echo wp_kses_post( wc_price( $eb_amount ) ); ?></span>
				</div>
				<?php endif; ?>
				<div class="tcbf-pack-footer-line tcbf-pack-footer-total">
					<span class="tcbf-pack-footer-label"><?php echo esc_html( $total_label ); ?></span>
					<span class="tcbf-pack-footer-value"><?php echo wp_kses_post( wc_price( $total ) ); ?></span>
				</div>
			</div>
		</td>
	</tr>
	<?php
}
endif;

/**
 * Render a single cart item row.
 * Used for both grouped and ungrouped items.
 */
if ( ! function_exists( 'tcbf_render_cart_item_row' ) ) :
function tcbf_render_cart_item_row( string $cart_item_key, array $cart_item, string $row_class = '', string $group_id = '' ) : bool {
	$_product     = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
	$product_id   = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
	$product_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

	if ( ! ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) ) {
		return false;
	}

	$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );

	// Event image
	$event_id = isset( $cart_item['_event_id'] ) ? (int) $cart_item['_event_id'] : 0;
	$event_url = $event_id > 0 ? get_permalink( $event_id ) : '';
	$custom_thumb_url = '';
	if ( $event_id > 0 ) {
		$custom_thumb_url = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_event_image_url( $event_id );
	}

	$tr_classes = 'woocommerce-cart-form__cart-item ' . esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) );
	if ( $row_class ) {
		$tr_classes .= ' ' . $row_class;
	}
	$group_attr = $group_id ? ' data-tcbf-group="' . esc_attr( $group_id ) . '"' : '';
	?>
	<tr class="<?php echo esc_attr( $tr_classes ); ?>"<?php echo $group_attr; ?> data-cart_item_key="<?php echo esc_attr( $cart_item_key ); ?>">

		<td class="product-remove">
			<?php
			echo apply_filters(
				'woocommerce_cart_item_remove_link',
				sprintf(
					'<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
					esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
					esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), wp_strip_all_tags( $product_name ) ) ),
					esc_attr( $product_id ),
					esc_attr( $_product->get_sku() )
				),
				$cart_item_key
			);
			?>
		</td>

		<td class="product-thumbnail">
			<?php
			if ( $custom_thumb_url ) {
				$thumbnail = '<img src="' . esc_url( $custom_thumb_url ) . '" class="tcbf-event-thumb" alt="' . esc_attr( $product_name ) . '" />';
			} else {
				$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image(), $cart_item, $cart_item_key );
			}

			if ( ! $product_permalink ) {
				echo $thumbnail;
			} else {
				$link_url = $event_url ?: $product_permalink;
				printf( '<a href="%s">%s</a>', esc_url( $link_url ), $thumbnail );
			}
			?>
		</td>

		<td class="product-name" data-title="<?php esc_attr_e( 'Product', 'woocommerce' ); ?>">
			<?php
			$title_url = $event_url ?: $product_permalink;
			if ( ! $title_url ) {
				echo wp_kses_post( $product_name );
			} else {
				echo wp_kses_post( sprintf( '<a href="%s" class="tcbf-product-link">%s</a>', esc_url( $title_url ), $product_name ) );
			}

			do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );

			// Meta data.
			echo wc_get_formatted_cart_item_data( $cart_item );

			// Backorder notification.
			if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
				echo wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'woocommerce' ) . '</p>', $product_id ) );
			}
			?>
		</td>

		<td class="product-price" data-title="<?php esc_attr_e( 'Price', 'woocommerce' ); ?>">
			<?php
			echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
			?>
		</td>

		<td class="product-quantity" data-title="<?php esc_attr_e( 'Quantity', 'woocommerce' ); ?>">
			<?php
			if ( $_product->is_sold_individually() ) {
				$min_quantity = 1;
				$max_quantity = 1;
			} else {
				$min_quantity = 0;
				$max_quantity = $_product->get_max_purchase_quantity();
			}

			$product_quantity = woocommerce_quantity_input(
				array(
					'input_name'   => "cart[{$cart_item_key}][qty]",
					'input_value'  => $cart_item['quantity'],
					'max_value'    => $max_quantity,
					'min_value'    => $min_quantity,
					'product_name' => $product_name,
				),
				$_product,
				false
			);

			echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item );
			?>
		</td>

		<td class="product-subtotal" data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>">
			<?php
			echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
			?>
		</td>
	</tr>
	<?php
	return true;
}
endif;

// Cart/checkout pack UI CSS is injected via Plugin.php (wp_head). Order pages use Woo_OrderMeta styles.

do_action( 'woocommerce_before_cart' ); ?>
<!-- TCBF cart template v3 active -->

<form class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
	<?php do_action( 'woocommerce_before_cart_table' ); ?>

	<table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents" cellspacing="0">
		<thead>
			<tr>
				<th class="product-remove"><span class="screen-reader-text"><?php esc_html_e( 'Remove item', 'woocommerce' ); ?></span></th>
				<th class="product-thumbnail"><span class="screen-reader-text"><?php esc_html_e( 'Thumbnail image', 'woocommerce' ); ?></span></th>
				<th class="product-name"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
				<th class="product-price"><?php esc_html_e( 'Price', 'woocommerce' ); ?></th>
				<th class="product-quantity"><?php esc_html_e( 'Quantity', 'woocommerce' ); ?></th>
				<th class="product-subtotal"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php do_action( 'woocommerce_before_cart_contents' ); ?>

			<?php
			/**
			 * TCBF: Group cart items by pack for visual grouping.
			 * Transport items are hidden via woocommerce_cart_item_visible filter
			 * and their info is rendered inline under the parent rental.
			 */
			$tcbf_groups    = [];
			$tcbf_ungrouped = [];
			$tcbf_enabled   = class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' );

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				// Skip transport items entirely (they are hidden and rendered inline)
				$scope = $cart_item['tcbf_scope']
					?? ( isset( $cart_item['booking'] ) ? ( $cart_item['booking'][ \TC_BF\Plugin::BK_SCOPE ] ?? '' ) : '' );
				if ( $scope === 'transport' ) {
					continue;
				}

				$group_id = isset( $cart_item['tc_group_id'] ) ? (int) $cart_item['tc_group_id'] : 0;

				if ( $tcbf_enabled && $group_id > 0 ) {
					if ( ! isset( $tcbf_groups[ $group_id ] ) ) {
						$tcbf_groups[ $group_id ] = [];
					}
					$tcbf_groups[ $group_id ][ $cart_item_key ] = $cart_item;
				} else {
					$tcbf_ungrouped[ $cart_item_key ] = $cart_item;
				}
			}

			// Render grouped items (packs: participation + rental)
			$is_first_pack = true;
			foreach ( $tcbf_groups as $group_id => $group_items ) :
				$group_items = tcbf_sort_group_items( $group_items );

				if ( ! $is_first_pack ) : ?>
				<tr class="tcbf-pack-separator"><td colspan="6"></td></tr>
				<?php endif;
				$is_first_pack = false;

				foreach ( $group_items as $cart_item_key => $cart_item ) :
					$is_parent = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::is_cart_item_parent( $cart_item );
					$row_class = 'tcbf-pack-item ' . ( $is_parent ? 'tcbf-cart-row--parent' : 'tcbf-cart-row--child' );
					tcbf_render_cart_item_row( $cart_item_key, $cart_item, $row_class, (string) $group_id );
				endforeach;

				// Combined EB row for the group
				tcbf_render_group_eb_row( $group_items, $group_id );

			endforeach;

			// Render ungrouped items
			$is_first_ungrouped = empty( $tcbf_groups );
			foreach ( $tcbf_ungrouped as $cart_item_key => $cart_item ) :
				if ( ! $is_first_ungrouped ) : ?>
				<tr class="tcbf-pack-separator"><td colspan="6"></td></tr>
				<?php endif;
				$is_first_ungrouped = false;

				tcbf_render_cart_item_row( $cart_item_key, $cart_item );
				tcbf_render_inline_eb_row( $cart_item, 0 );
			endforeach;
			?>

			<?php do_action( 'woocommerce_cart_contents' ); ?>

			<tr>
				<td colspan="6" class="actions">

					<?php if ( wc_coupons_enabled() ) { ?>
						<div class="coupon">
							<label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Coupon:', 'woocommerce' ); ?></label>
							<input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e( 'Coupon code', 'woocommerce' ); ?>" />
							<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="apply_coupon" value="<?php esc_attr_e( 'Apply coupon', 'woocommerce' ); ?>"><?php esc_html_e( 'Apply coupon', 'woocommerce' ); ?></button>
							<?php do_action( 'woocommerce_cart_coupon' ); ?>
						</div>
					<?php } ?>

					<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="update_cart" value="<?php esc_attr_e( 'Update cart', 'woocommerce' ); ?>"><?php esc_html_e( 'Update cart', 'woocommerce' ); ?></button>

					<?php do_action( 'woocommerce_cart_actions' ); ?>

					<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
				</td>
			</tr>

			<?php do_action( 'woocommerce_after_cart_contents' ); ?>
		</tbody>
	</table>
	<?php do_action( 'woocommerce_after_cart_table' ); ?>
</form>

<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

<div class="cart-collaterals">
	<?php
		/**
		 * Cart collaterals hook.
		 *
		 * @hooked woocommerce_cross_sell_display
		 * @hooked woocommerce_cart_totals - 10
		 */
		do_action( 'woocommerce_cart_collaterals' );
	?>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>

<style>
/* TCBF Cart v3 — Template-specific styles */

/* ---- Pack separators ---- */
.tcbf-pack-separator td {
	padding: 9px 0 0 0 !important;
	border: none !important;
	background: transparent !important;
}

/* ---- Pack row hierarchy (participation = parent, rental = child) ---- */
.tcbf-cart-row--parent {
	border-left: 3px solid var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00))) !important;
}
.tcbf-cart-row--child {
	border-left: 3px solid color-mix(in srgb, var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00))) 50%, transparent) !important;
}

/* ---- Product link color ---- */
.tcbf-product-link {
	color: var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00)));
}

/* ---- Event thumbnail ---- */
.tcbf-event-thumb {
	width: 80px;
	height: auto;
	border-radius: 4px;
}

/* ---- Inline EB summary rows ---- */
.tcbf-pack-footer-row--inline .tcbf-pack-footer--inline {
	border-left: 3px solid color-mix(in srgb, var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00))) 30%, transparent);
	padding-left: 12px;
	font-size: 0.9em;
}

/* ---- Inline transport summary (under parent rental name) ---- */
.tcbf-transport-inline {
	margin-top: 8px;
	padding: 6px 10px;
	background: #f0fdf4;
	border: 1px solid #bbf7d0;
	border-radius: 6px;
	font-size: 12px;
}
.tcbf-transport-inline__line {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 2px 0;
	flex-wrap: wrap;
}
.tcbf-transport-inline__icon {
	flex-shrink: 0;
	font-size: 14px;
	line-height: 1;
}
.tcbf-transport-inline__label {
	font-weight: 600;
	color: #166534;
	white-space: nowrap;
}
.tcbf-transport-inline__detail {
	color: #555;
	min-width: 0;
}
.tcbf-transport-inline__price {
	margin-left: auto;
	font-weight: 600;
	color: #166534;
	white-space: nowrap;
}

/* ---- Responsive: consistent card layout ---- */
@media (max-width: 1024px) {
	/* Each cart item is a card */
	.woocommerce-cart .shop_table_responsive tr.cart_item {
		display: block;
		padding: 12px 0;
	}

	.woocommerce-cart .shop_table_responsive tr.cart_item td {
		display: block;
		width: 100%;
		box-sizing: border-box;
	}

	/* Remove cell: compact */
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-remove {
		width: auto;
	}

	/* Metrics bar: Price | Qty | Subtotal in consistent 3-column row */
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-price,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-quantity,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-subtotal {
		display: inline-block;
		width: 33.333%;
		vertical-align: top;
		padding: 10px 6px;
		text-align: center !important;
		box-sizing: border-box;
	}

	/* Metric labels from data-title */
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-price::before,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-quantity::before,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-subtotal::before {
		display: block;
		content: attr(data-title);
		font-size: 11px;
		font-weight: 600;
		opacity: 0.7;
		margin-bottom: 4px;
		text-align: center;
	}

	/* Suppress ::before on cells where it's not needed */
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-thumbnail::before,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-remove::before,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-name::before {
		display: none !important;
		content: none !important;
	}

	/* Special rows: suppress all responsive pseudo-elements */
	.woocommerce-cart .shop_table_responsive tr.tcbf-pack-separator td::before,
	.woocommerce-cart .shop_table_responsive tr.tcbf-pack-footer-row td::before {
		display: none !important;
		content: none !important;
	}

	/* Pack footer row: full width block */
	.woocommerce-cart .shop_table_responsive tr.tcbf-pack-footer-row {
		display: block;
		width: 100%;
	}
	.woocommerce-cart .shop_table_responsive tr.tcbf-pack-footer-row > td {
		display: block;
		width: 100%;
	}

	/* Pack separator: full width */
	.woocommerce-cart .shop_table_responsive tr.tcbf-pack-separator {
		display: block;
		width: 100%;
	}
	.woocommerce-cart .shop_table_responsive tr.tcbf-pack-separator > td {
		display: block;
		width: 100%;
	}

	/* Parent row: pad from border */
	tr.tcbf-cart-row--parent > td:not(.product-remove) {
		padding-left: 12px !important;
	}

	/* Child row (rental in a pack): slight indent */
	tr.tcbf-cart-row--child > td:not(.product-remove) {
		padding-left: 20px !important;
	}

	/* Neat values */
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-price .amount,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-subtotal .amount {
		display: inline-block;
		font-size: 14px;
	}
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-quantity .quantity,
	.woocommerce-cart .shop_table_responsive tr.cart_item td.product-quantity input.qty {
		display: inline-block;
		font-size: 14px;
	}
}
</style>
