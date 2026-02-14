<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Woo_ProductBrief — Inject a "booking brief" block into the product short description.
 *
 * Features:
 * - Product-level toggle (checkbox in WC General tab)
 * - Dynamic price table built from WC Bookings pricing rules
 * - Translatable badges and note via qTranslate
 * - CSS enqueued only on product pages that have the brief enabled
 *
 * @since TCBF-15
 */
final class Woo_ProductBrief {

	/** @var string Post meta key for the toggle */
	const META_ENABLED = '_tcbf_booking_brief_enabled';

	/** @var string Post meta key for custom note override */
	const META_NOTE = '_tcbf_booking_brief_note';

	/**
	 * Initialize hooks.
	 */
	public static function init() : void {
		// Admin: product edit fields
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_fields' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_fields' ] );

		// Frontend: prepend brief to short description
		add_filter( 'woocommerce_short_description', [ __CLASS__, 'prepend_brief' ], 5 );

		// Frontend: enqueue CSS on product pages
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_css' ] );
	}

	/* =========================================================
	 * Admin — Product edit screen
	 * ========================================================= */

	/**
	 * Render fields in WooCommerce General product data tab.
	 */
	public static function render_fields() : void {
		echo '<div class="options_group">';

		woocommerce_wp_checkbox( [
			'id'          => self::META_ENABLED,
			'label'       => 'Booking brief',
			'description' => 'Show booking rules & price table above the short description',
		] );

		woocommerce_wp_textarea_input( [
			'id'          => self::META_NOTE,
			'label'       => 'Brief note (optional)',
			'desc_tip'    => true,
			'description' => 'Override the default rental note. Supports qTranslate [:en]..[:es].. tags. Leave empty for default.',
			'rows'        => 3,
		] );

		echo '</div>';
	}

	/**
	 * Save fields on product save.
	 *
	 * @param int $post_id Product post ID.
	 */
	public static function save_fields( int $post_id ) : void {
		// Toggle
		$enabled = isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_ENABLED, $enabled );

		// Note override
		$note = isset( $_POST[ self::META_NOTE ] )
			? wp_kses_post( wp_unslash( $_POST[ self::META_NOTE ] ) )
			: '';
		if ( $note === '' ) {
			delete_post_meta( $post_id, self::META_NOTE );
		} else {
			update_post_meta( $post_id, self::META_NOTE, $note );
		}
	}

	/* =========================================================
	 * Frontend — Short description injection
	 * ========================================================= */

	/**
	 * Prepend booking brief to product short description.
	 *
	 * @param string $description Original short description.
	 * @return string Modified description.
	 */
	public static function prepend_brief( string $description ) : string {
		// Only on single product pages
		if ( ! is_product() ) {
			return $description;
		}

		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $description;
		}

		$enabled = get_post_meta( $product->get_id(), self::META_ENABLED, true );
		if ( $enabled !== 'yes' ) {
			return $description;
		}

		$brief = self::build_brief_html( $product );
		return $brief . $description;
	}

	/**
	 * Build the complete brief HTML block.
	 *
	 * @param \WC_Product $product The product.
	 * @return string HTML.
	 */
	private static function build_brief_html( \WC_Product $product ) : string {
		$html = '<div class="tc-brief">';

		// Heading
		$html .= '<h4>' . esc_html( Woo::translate(
			'[:en]Book your bike in advance[:es]Reserva tu bicicleta con antelación[:]'
		) ) . '</h4>';

		// Intro text
		$html .= esc_html( Woo::translate(
			'[:en]Choose your frame size, check availability, and add your reservation to the cart.[:es]Elige tu talla de cuadro, comprueba disponibilidad y añade la reserva al carrito.[:]'
		) );

		// Badges
		$html .= '<div class="tc-badges">';
		$html .= '<div class="tc-badge">' . esc_html( Woo::translate(
			'[:en]Select first & last day to book several days[:es]Selecciona primer y último día para varios días[:]'
		) ) . '</div>';
		$html .= '<div class="tc-badge">' . esc_html( Woo::translate(
			'[:en]Add multiple bikes for group booking[:es]Añade varias bicis para reserva de grupo[:]'
		) ) . '</div>';
		$html .= '</div>';

		// Dynamic price table
		$price_html = self::build_price_table( $product );
		if ( $price_html ) {
			$html .= $price_html;
		}

		// Note
		$html .= self::build_note( $product );

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build the price table from WC Bookings pricing rules.
	 *
	 * Reads _wc_booking_pricing (array of duration-based rules) and
	 * the base block cost. Renders a clean table with tiers.
	 *
	 * @param \WC_Product $product The product.
	 * @return string HTML table or empty string.
	 */
	private static function build_price_table( \WC_Product $product ) : string {
		$product_id = $product->get_id();

		// Base cost per block (1 day/unit)
		$block_cost = (float) get_post_meta( $product_id, '_wc_booking_block_cost', true );
		$base_cost  = (float) get_post_meta( $product_id, '_wc_booking_base_cost', true );

		// WC Bookings stores per-block cost and a one-time base cost.
		// For daily rentals: block_cost = daily rate, base_cost = one-time fee (often 0).
		$single_day_price = $block_cost + $base_cost;

		if ( $single_day_price <= 0 ) {
			// Fallback: try product price
			$single_day_price = (float) $product->get_price();
		}

		if ( $single_day_price <= 0 ) {
			return '';
		}

		// Duration unit label
		$duration_unit = get_post_meta( $product_id, '_wc_booking_duration_unit', true );
		$unit_label    = self::get_unit_label( $duration_unit ?: 'day' );

		// Pricing rules (tiered/volume discounts)
		$pricing_rules = get_post_meta( $product_id, '_wc_booking_pricing', true );
		$tiers = self::parse_pricing_tiers( $pricing_rules, $single_day_price, $base_cost, $unit_label, $product_id );

		// Build table
		$header = Woo::translate( '[:en]Prices (VAT included)[:es]Precios (IVA incluido)[:]' );
		$html  = '<table class="price-table">';
		$html .= '<thead><tr><th colspan="2">' . esc_html( $header ) . '</th></tr></thead>';
		$html .= '<tbody>';

		foreach ( $tiers as $tier ) {
			$html .= '<tr>';
			$html .= '<td>' . esc_html( $tier['label'] ) . '</td>';
			$html .= '<td>' . esc_html( $tier['price'] ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Parse WC Bookings pricing rules into display tiers.
	 *
	 * @param mixed  $rules           Raw _wc_booking_pricing meta value.
	 * @param float  $single_price    Price for 1 block (base + block cost).
	 * @param float  $base_cost       One-time base cost.
	 * @param string $unit_label      Translated unit label ("day"/"día").
	 * @param int    $product_id      Product ID (to read max duration).
	 * @return array Array of ['label' => '...', 'price' => '...'].
	 */
	private static function parse_pricing_tiers( $rules, float $single_price, float $base_cost, string $unit_label, int $product_id = 0 ) : array {
		$tiers = [];
		$unit_singular = Woo::translate( '[:en]1 day[:es]1 día[:]' );
		$per_unit      = Woo::translate( '[:en]/ day[:es]/ día[:]' );

		// Max bookable duration — if the last tier's "to" meets or exceeds this, show "N+" instead
		$max_duration = $product_id ? (int) get_post_meta( $product_id, '_wc_booking_max_duration', true ) : 0;

		// First tier: single unit at base price
		$tiers[] = [
			'label' => $unit_singular,
			'price' => wp_strip_all_tags( wc_price( $single_price ) ),
		];

		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return $tiers;
		}

		// Sort rules by "from" value
		usort( $rules, function( $a, $b ) {
			return (int) ( $a['from'] ?? 0 ) - (int) ( $b['from'] ?? 0 );
		} );

		foreach ( $rules as $rule ) {
			$type = $rule['type'] ?? '';

			// We care about block/duration-based rules
			if ( ! in_array( $type, [ 'blocks', 'custom', 'block_count' ], true ) ) {
				continue;
			}

			$from = (int) ( $rule['from'] ?? 0 );
			$to   = (int) ( $rule['to'] ?? 0 );

			// Skip single-unit rule — already covered by the "1 day" base tier
			if ( $from <= 1 && $to <= 1 ) {
				continue;
			}

			// Calculate the effective per-block price from the rule.
			// WC Bookings pricing rules can use modifiers (multiply, equals, etc.)
			$modifier   = $rule['base_modifier'] ?? '';
			$rule_cost  = (float) ( $rule['base_cost'] ?? 0 );
			$block_mod  = $rule['modifier'] ?? '';
			$block_cost = (float) ( $rule['cost'] ?? 0 );

			// Determine final per-block price.
			// Common patterns: modifier="" and cost=X means "set block cost to X"
			$per_block = self::calculate_rule_price( $single_price, $base_cost, $rule );

			if ( $per_block <= 0 ) {
				continue;
			}

			// Build label — collapse last tier to "N+" when "to" meets/exceeds max bookable duration
			$is_open_ended = ( $to <= 0 || $to >= 9999 )
				|| ( $max_duration > 0 && $to >= $max_duration );

			if ( ! $is_open_ended ) {
				$label = $from . '–' . $to . ' ' . $unit_label;
			} else {
				$label = $from . '+ ' . $unit_label;
			}

			$price_str = wp_strip_all_tags( wc_price( $per_block ) ) . ' ' . $per_unit;

			$tiers[] = [
				'label' => $label,
				'price' => $price_str,
			];
		}

		return $tiers;
	}

	/**
	 * Calculate the effective per-block price from a WC Bookings pricing rule.
	 *
	 * WC Bookings rules can modify base_cost and block_cost with modifiers:
	 * - "" (empty) or "equals" → set to the value
	 * - "minus" → subtract from original
	 * - "times" → multiply original
	 * - "plus" → add to original
	 *
	 * @param float $original_price Original single-block price (base + block).
	 * @param float $original_base  Original base cost.
	 * @param array $rule           The pricing rule.
	 * @return float Effective per-block price.
	 */
	private static function calculate_rule_price( float $original_price, float $original_base, array $rule ) : float {
		$original_block = $original_price - $original_base;

		// Apply base_cost modifier (one-time cost)
		$base_mod  = $rule['base_modifier'] ?? '';
		$base_val  = (float) ( $rule['base_cost'] ?? 0 );
		$new_base  = self::apply_modifier( $original_base, $base_mod, $base_val );

		// Apply block cost modifier (per-block cost)
		$block_mod = $rule['modifier'] ?? '';
		$block_val = (float) ( $rule['cost'] ?? 0 );
		$new_block = self::apply_modifier( $original_block, $block_mod, $block_val );

		return $new_base + $new_block;
	}

	/**
	 * Apply a WC Bookings cost modifier.
	 *
	 * @param float  $original Original value.
	 * @param string $modifier Modifier type.
	 * @param float  $value    Modifier value.
	 * @return float Modified value.
	 */
	private static function apply_modifier( float $original, string $modifier, float $value ) : float {
		switch ( $modifier ) {
			case 'minus':
				return $original - $value;
			case 'plus':
				return $original + $value;
			case 'times':
				return $original * $value;
			case 'divide':
				return $value > 0 ? $original / $value : $original;
			case 'equals':
			case '':
			default:
				return $value;
		}
	}

	/**
	 * Get translated unit label (plural).
	 *
	 * @param string $unit WC Bookings unit (day, hour, month, etc.).
	 * @return string Translated plural label.
	 */
	private static function get_unit_label( string $unit ) : string {
		switch ( $unit ) {
			case 'day':
				return Woo::translate( '[:en]days[:es]días[:]' );
			case 'hour':
				return Woo::translate( '[:en]hours[:es]horas[:]' );
			case 'month':
				return Woo::translate( '[:en]months[:es]meses[:]' );
			default:
				return $unit;
		}
	}

	/**
	 * Build the note section.
	 *
	 * Uses per-product override if set, otherwise default rental note.
	 *
	 * @param \WC_Product $product The product.
	 * @return string HTML.
	 */
	private static function build_note( \WC_Product $product ) : string {
		$custom_note = get_post_meta( $product->get_id(), self::META_NOTE, true );

		if ( ! empty( $custom_note ) ) {
			$note_text = Woo::translate( $custom_note );
		} else {
			$note_text = Woo::translate(
				'[:en]<strong>Daily Rental</strong> — Pick-up from <strong>10:00</strong> and return before <strong>20:00</strong> the same day. '
				. 'With prior notice, you can <strong>collect the bike at 20:00 the day before</strong> to start early the next morning — '
				. '<strong>subject to confirmation and availability</strong>.'
				. '[:es]<strong>Alquiler diario</strong> — Recogida desde las <strong>10:00</strong> y devolución antes de las <strong>20:00</strong> del mismo día. '
				. 'Con aviso previo, puedes <strong>recoger la bicicleta a las 20:00 del día anterior</strong> para comenzar temprano al día siguiente — '
				. '<strong>sujeto a confirmación y disponibilidad</strong>.[:]'
			);
		}

		return '<div class="note">' . wp_kses_post( $note_text ) . '</div>';
	}

	/* =========================================================
	 * Frontend — CSS
	 * ========================================================= */

	/**
	 * Enqueue brief CSS on product pages where the brief is enabled.
	 */
	public static function maybe_enqueue_css() : void {
		if ( ! is_product() ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		$enabled = get_post_meta( $post->ID, self::META_ENABLED, true );
		if ( $enabled !== 'yes' ) {
			return;
		}

		wp_register_style( 'tcbf-product-brief', false );
		wp_enqueue_style( 'tcbf-product-brief' );
		wp_add_inline_style( 'tcbf-product-brief', self::get_css() );
	}

	/**
	 * Get the brief CSS.
	 *
	 * @return string CSS rules.
	 */
	private static function get_css() : string {
		return <<<'CSS'
/* TCBF Booking Brief */
.tc-brief { font-size: 14px; line-height: 1.5; color: #222; margin: 0 0 16px; }
.tc-brief h4 { font-size: 16px; margin: 0 0 8px; font-weight: 700; }
.tc-brief p { margin: 0 0 8px; }
.tc-brief ul { margin: 6px 0 10px 18px; }
.tc-brief li { margin: 2px 0; }

.tc-brief .price-table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
.tc-brief .price-table th,
.tc-brief .price-table td { border-top: 1px solid #eee; padding: 6px 8px; font-size: 13px; }
.tc-brief .price-table th { text-transform: uppercase; font-weight: 600; font-size: 11px; background: #f7f7f7; color: #444; }
.tc-brief .price-table td:last-child { text-align: right; font-weight: 600; }
.tc-brief .price-table tr:last-child td { border-bottom: 1px solid #eee; }

.tc-brief .note {
  background: #faf6f0; border-left: 3px solid #c8a974;
  padding: 10px 12px; font-size: 13px; color: #333; margin: 10px 0 0;
}

body.single-product .tc-badges {
  display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px; margin: 10px 0 16px;
}
body.single-product .tc-badge {
  border: 1px solid #000000;
  padding: 8px 10px; font-size: 13px; font-weight: 500; color: #333;
  text-align: center; line-height: 1.35; box-shadow: 0 1px 2px rgba(0,0,0,.05);
}
@media (max-width: 991.98px) {
  body.single-product .tc-badges { grid-template-columns: 1fr; }
}
CSS;
	}
}
