<?php
namespace TC_BF\Frontend;

use TC_BF\Domain\TransportPricing;
use TC_BF\Domain\TransportZones;

if ( ! defined('ABSPATH') ) exit;

/**
 * Transport Homepage Teaser
 *
 * Renders bilingual (EN/ES) transport info content for use inside PopupMaker.
 * The shortcode outputs clean informational HTML — no teaser card, no popup
 * wrapper. PopupMaker handles the popup chrome (overlay, close button, etc.).
 *
 * Usage: [tcbf_transport_teaser]
 *
 * Content follows docs/transport-homepage-content.md specification.
 * Pricing values are pulled from the live TransportPricing configuration.
 *
 * @since 1.1.0
 */
final class Transport_Homepage_Teaser {

	/**
	 * Register shortcode and assets
	 */
	public static function register() : void {
		add_shortcode( 'tcbf_transport_teaser', [ __CLASS__, 'render_shortcode' ] );
	}

	/**
	 * Enqueue CSS/JS on pages that use the shortcode
	 *
	 * Called via wp_enqueue_scripts hook; only enqueues when shortcode is present.
	 */
	public static function enqueue_assets() : void {
		global $post;

		if ( ! $post || ! has_shortcode( $post->post_content, 'tcbf_transport_teaser' ) ) {
			return;
		}

		// Leaflet CSS + JS (free, no API key needed — used for the read-only zone map)
		wp_enqueue_style(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			[],
			'1.9.4'
		);
		wp_enqueue_script(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			[],
			'1.9.4',
			true
		);

		wp_enqueue_style(
			'tcbf-transport-teaser',
			TC_BF_URL . 'assets/css/tcbf-transport-teaser.css',
			[ 'leaflet' ],
			defined('TC_BF_VERSION') ? TC_BF_VERSION : null
		);

		wp_enqueue_script(
			'tcbf-transport-teaser',
			TC_BF_URL . 'assets/js/tcbf-transport-teaser.js',
			[ 'leaflet', 'jquery' ],
			defined('TC_BF_VERSION') ? TC_BF_VERSION : null,
			true
		);

		// Pass zone data to JS for the map
		self::localize_zone_data();
	}

	/**
	 * Ensure CSS/JS assets are enqueued (fallback for when has_shortcode detection fails)
	 */
	private static function ensure_assets() : void {
		if ( wp_script_is( 'tcbf-transport-teaser', 'enqueued' ) ) {
			return;
		}

		// Leaflet CSS + JS
		wp_enqueue_style(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			[],
			'1.9.4'
		);
		wp_enqueue_script(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			[],
			'1.9.4',
			true
		);

		wp_enqueue_style(
			'tcbf-transport-teaser',
			TC_BF_URL . 'assets/css/tcbf-transport-teaser.css',
			[ 'leaflet' ],
			defined('TC_BF_VERSION') ? TC_BF_VERSION : null
		);

		wp_enqueue_script(
			'tcbf-transport-teaser',
			TC_BF_URL . 'assets/js/tcbf-transport-teaser.js',
			[ 'leaflet', 'jquery' ],
			defined('TC_BF_VERSION') ? TC_BF_VERSION : null,
			true
		);

		self::localize_zone_data();
	}

	/**
	 * Pass zone data to JS for the map
	 *
	 * Uses wp_add_inline_script instead of wp_localize_script because
	 * wp_localize_script converts sequential PHP arrays into JS objects
	 * (e.g. {"0": {…}, "1": {…}}) rather than arrays, which breaks
	 * Array methods like .forEach and .length in the map init code.
	 */
	private static function localize_zone_data() : void {
		$zones = TransportZones::get_zones();
		$zone_data = [];
		foreach ( $zones as $z ) {
			$zone_data[] = [
				'id'        => $z['id'] ?? '',
				'name'      => $z['name'] ?? '',
				'lat'       => (float) ( $z['lat'] ?? 0 ),
				'lng'       => (float) ( $z['lng'] ?? 0 ),
				'radius_km' => (float) ( $z['radius_km'] ?? 0 ),
			];
		}
		wp_add_inline_script(
			'tcbf-transport-teaser',
			'var tcbfTeaserZones = ' . wp_json_encode( array_values( $zone_data ) ) . ';',
			'before'
		);
	}

	/**
	 * Translate string using qTranslate XT if available
	 *
	 * @param string $text Multilingual string [:en]..[:es]..[:]
	 * @return string
	 */
	private static function tr( string $text ) : string {
		if ( function_exists( 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage' ) ) {
			return (string) qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $text );
		}
		return $text;
	}

	/**
	 * Render the shortcode
	 *
	 * Outputs clean informational HTML for PopupMaker (no teaser card, no popup wrapper).
	 *
	 * @param array|string $atts Shortcode attributes (unused, reserved for future)
	 * @return string HTML
	 */
	public static function render_shortcode( $atts = [] ) : string {

		// Ensure assets are enqueued even if has_shortcode() detection failed
		self::ensure_assets();

		// Pull live pricing from the system SSOT
		$cfg = class_exists( '\\TC_BF\\Domain\\TransportPricing' )
			? TransportPricing::get_config()
			: [];

		$base_delivery = isset( $cfg['base_delivery'] )   ? $cfg['base_delivery']   : 45.0;
		$base_both     = isset( $cfg['base_both'] )        ? $cfg['base_both']        : 75.0;
		$surcharge_night    = isset( $cfg['surcharge_night'] )    ? $cfg['surcharge_night']    : 15.0;
		$surcharge_box      = isset( $cfg['surcharge_bike_box'] ) ? $cfg['surcharge_bike_box'] : 10.0;
		$surcharge_remote   = isset( $cfg['surcharge_remote'] )   ? $cfg['surcharge_remote']   : 20.0;

		// Time windows from settings
		$window_morning_start   = isset( $cfg['window_morning_start'] )   ? $cfg['window_morning_start']   : '09:00';
		$window_morning_end     = isset( $cfg['window_morning_end'] )     ? $cfg['window_morning_end']     : '13:00';
		$window_afternoon_start = isset( $cfg['window_afternoon_start'] ) ? $cfg['window_afternoon_start'] : '14:00';
		$window_afternoon_end   = isset( $cfg['window_afternoon_end'] )   ? $cfg['window_afternoon_end']   : '18:00';

		// Capacity from settings
		$cap_morning   = isset( $cfg['capacity_morning_bikes'] )   ? (int) $cfg['capacity_morning_bikes']   : 5;
		$cap_afternoon = isset( $cfg['capacity_afternoon_bikes'] ) ? (int) $cfg['capacity_afternoon_bikes'] : 5;

		// Format prices for display
		$fmt = function( $v ) {
			return self::format_price( $v );
		};

		// Pull zone list from configuration
		$zone_cfg = class_exists( '\\TC_BF\\Domain\\TransportZones' )
			? TransportZones::get_zones()
			: [];

		// Build surcharges array — only include non-zero values
		$surcharges = [];
		if ( $surcharge_night > 0 ) {
			$surcharges[] = [
				'name_en'  => 'Night hours',
				'name_es'  => 'Horario nocturno',
				'amount'   => '+' . $fmt( $surcharge_night ) . ' ' . self::tr( '[:en]per direction[:es]por trayecto[:]' ),
				'when_en'  => 'Time window before 07:00 or after 21:00',
				'when_es'  => 'Franja horaria antes de las 07:00 o después de las 21:00',
			];
		}
		if ( $surcharge_box > 0 ) {
			$surcharges[] = [
				'name_en'  => 'Bike box',
				'name_es'  => 'Caja de bici',
				'amount'   => '+' . $fmt( $surcharge_box ) . ' ' . self::tr( '[:en]per box[:es]por caja[:]' ),
				'when_en'  => 'If you need a bike transport box',
				'when_es'  => 'Si necesitas una caja de transporte para la bici',
			];
		}
		if ( $surcharge_remote > 0 ) {
			$surcharges[] = [
				'name_en'  => 'Remote area',
				'name_es'  => 'Zona de difícil acceso',
				'amount'   => '+' . $fmt( $surcharge_remote ) . ' ' . self::tr( '[:en]per direction[:es]por trayecto[:]' ),
				'when_en'  => 'Locations flagged as difficult access',
				'when_es'  => 'Ubicaciones marcadas como acceso complicado',
			];
		}

		ob_start();
		?>
		<div class="tcbf-transport-info">

			<?php /* -- Title -- */ ?>
			<h2 class="tcbf-transport-info__title">
				<?php echo esc_html( self::tr(
					'[:en]Bike Transport Service — How it works'
					. '[:es]Servicio de Transporte de Bicis — Cómo funciona[:]'
				) ); ?>
			</h2>

			<p class="tcbf-transport-info__intro">
				<?php echo esc_html( self::tr(
					'[:en]We take care of getting your rental bike to you — and bringing it back. No need to visit the shop: we deliver directly to your accommodation, hotel, airport meeting point, or any accessible address within our coverage area.'
					. '[:es]Nos encargamos de llevar tu bici de alquiler hasta ti — y de recogerla cuando termines. No necesitas venir a la tienda: entregamos directamente en tu alojamiento, hotel, punto de encuentro en el aeropuerto o cualquier dirección accesible dentro de nuestra zona de cobertura.[:]'
				) ); ?>
			</p>

			<?php /* ------- HOW TO BOOK ------- */ ?>
			<h3><?php echo esc_html( self::tr( '[:en]How to book transport[:es]Cómo reservar el transporte[:]' ) ); ?></h3>
			<ol class="tcbf-transport-info__steps">
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Add your rental bikes to the cart</strong> on our website as usual.'
					. '[:es]<strong>Añade tus bicis de alquiler al carrito</strong> en nuestra web como de costumbre.[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>A transport card will appear</strong> below your cart — click "Configure".'
					. '[:es]<strong>Aparecerá una tarjeta de transporte</strong> debajo del carrito — haz clic en "Configurar".[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Choose your service:</strong> Delivery only, Pickup only, or Both (best value).'
					. '[:es]<strong>Elige tu servicio:</strong> Solo entrega, Solo recogida, o Ambos (mejor precio).[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Enter your address</strong> — our map will help you pinpoint the exact location.'
					. '[:es]<strong>Introduce tu dirección</strong> — el mapa te ayudará a señalar la ubicación exacta.[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Select a time window</strong> for each direction.'
					. '[:es]<strong>Selecciona una franja horaria</strong> para cada trayecto.[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Review the quote</strong> — you\'ll see the full price breakdown before confirming.'
					. '[:es]<strong>Revisa el presupuesto</strong> — verás el desglose completo del precio antes de confirmar.[:]'
				) ); ?></li>
			</ol>
			<p><?php echo esc_html( self::tr(
				'[:en]That\'s it. The transport is added to your cart and included in your order.'
				. '[:es]Eso es todo. El transporte se añade a tu carrito y se incluye en tu pedido.[:]'
			) ); ?></p>

			<?php /* ------- COVERAGE ZONES ------- */ ?>
			<h3><?php echo esc_html( self::tr( '[:en]Coverage zones[:es]Zonas de cobertura[:]' ) ); ?></h3>
			<p><?php echo esc_html( self::tr(
				'[:en]We operate in the following areas. If your address falls within a zone, the standard base price applies. If you\'re outside these zones, a distance-based surcharge is calculated automatically.'
				. '[:es]Operamos en las siguientes áreas. Si tu dirección está dentro de una zona, se aplica el precio base estándar. Si estás fuera de estas zonas, se calcula automáticamente un suplemento por distancia.[:]'
			) ); ?></p>

			<?php /* ---- Zone map ---- */ ?>
			<div id="tcbf-transport-zone-map" class="tcbf-transport-info__map"></div>

			<div class="tcbf-transport-info__table-wrap">
			<table class="tcbf-transport-info__table">
				<thead>
					<tr>
						<th><?php echo esc_html( self::tr( '[:en]Zone[:es]Zona[:]' ) ); ?></th>
						<th><?php echo esc_html( self::tr( '[:en]Area covered[:es]Área cubierta[:]' ) ); ?></th>
						<th><?php echo esc_html( self::tr( '[:en]1-way[:es]1 trayecto[:]' ) ); ?></th>
						<th><?php echo esc_html( self::tr( '[:en]Both ways[:es]Ida y vuelta[:]' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $zone_cfg as $z ) :
						$z_name   = esc_html( $z['name'] ?? '' );
						$z_radius = (int) ( $z['radius_km'] ?? 0 );
						$z_desc_en = sprintf( '~%d km radius', $z_radius );
						$z_desc_es = sprintf( '~%d km de radio', $z_radius );

						// Zone-specific price or system default
						$z_one_way = isset( $z['delivery_price'] ) && $z['delivery_price'] !== null && $z['delivery_price'] !== ''
							? (float) $z['delivery_price']
							: $base_delivery;
						$z_both = isset( $z['both_price'] ) && $z['both_price'] !== null && $z['both_price'] !== ''
							? (float) $z['both_price']
							: $base_both;
					?>
					<tr>
						<td><strong><?php echo $z_name; ?></strong></td>
						<td><?php echo esc_html( self::tr(
							'[:en]' . $z_desc_en . '[:es]' . $z_desc_es . '[:]'
						) ); ?></td>
						<td><?php echo esc_html( $fmt( $z_one_way ) ); ?></td>
						<td><?php echo esc_html( $fmt( $z_both ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p><?php echo esc_html( self::tr(
				'[:en]Custom locations outside these zones? No problem — we can still reach you. The system will calculate a per-km surcharge based on your distance from the nearest zone.'
				. '[:es]¿Tu ubicación está fuera de estas zonas? Sin problema — podemos llegar igualmente. El sistema calculará un suplemento por km basado en tu distancia a la zona más cercana.[:]'
			) ); ?></p>

			<?php /* ------- SURCHARGES (settings-driven, non-zero only) ------- */ ?>
			<?php if ( ! empty( $surcharges ) ) : ?>
			<h3><?php echo esc_html( self::tr( '[:en]Surcharges[:es]Suplementos[:]' ) ); ?></h3>
			<div class="tcbf-transport-info__table-wrap">
			<table class="tcbf-transport-info__table">
				<thead>
					<tr>
						<th><?php echo esc_html( self::tr( '[:en]Surcharge[:es]Suplemento[:]' ) ); ?></th>
						<th><?php echo esc_html( self::tr( '[:en]Amount[:es]Importe[:]' ) ); ?></th>
						<th><?php echo esc_html( self::tr( '[:en]When it applies[:es]Cuándo se aplica[:]' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $surcharges as $s ) : ?>
					<tr>
						<td><?php echo esc_html( self::tr( '[:en]' . $s['name_en'] . '[:es]' . $s['name_es'] . '[:]' ) ); ?></td>
						<td><?php echo esc_html( $s['amount'] ); ?></td>
						<td><?php echo esc_html( self::tr( '[:en]' . $s['when_en'] . '[:es]' . $s['when_es'] . '[:]' ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>

			<?php /* ------- TIME WINDOWS (from settings) ------- */ ?>
			<h3><?php echo esc_html( self::tr( '[:en]Available time windows[:es]Franjas horarias disponibles[:]' ) ); ?></h3>
			<p><?php echo esc_html( self::tr(
				'[:en]When configuring transport, you choose a time window for each direction:'
				. '[:es]Al configurar el transporte, eliges una franja horaria para cada trayecto:[:]'
			) ); ?></p>
			<div class="tcbf-transport-info__table-wrap">
			<table class="tcbf-transport-info__table tcbf-transport-info__table--compact">
				<thead>
					<tr>
						<th><?php echo esc_html( self::tr( '[:en]Window[:es]Franja[:]' ) ); ?></th>
						<th><?php echo esc_html( self::tr( '[:en]Hours[:es]Horario[:]' ) ); ?></th>
						<th><?php echo esc_html( self::tr( '[:en]Capacity[:es]Capacidad[:]' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php echo esc_html( self::tr( '[:en]Morning[:es]Mañana[:]' ) ); ?></td>
						<td><?php echo esc_html( $window_morning_start . ' – ' . $window_morning_end ); ?></td>
						<td><?php echo esc_html( self::tr(
							'[:en]up to ' . $cap_morning . ' bikes'
							. '[:es]hasta ' . $cap_morning . ' bicis[:]'
						) ); ?></td>
					</tr>
					<tr>
						<td><?php echo esc_html( self::tr( '[:en]Afternoon[:es]Tarde[:]' ) ); ?></td>
						<td><?php echo esc_html( $window_afternoon_start . ' – ' . $window_afternoon_end ); ?></td>
						<td><?php echo esc_html( self::tr(
							'[:en]up to ' . $cap_afternoon . ' bikes'
							. '[:es]hasta ' . $cap_afternoon . ' bicis[:]'
						) ); ?></td>
					</tr>
				</tbody>
			</table>
			</div>
			<p><strong><?php echo esc_html( self::tr(
				'[:en]Capacity is limited[:es]La capacidad es limitada[:]'
			) ); ?></strong> — <?php echo esc_html( self::tr(
				'[:en]each time window has a maximum number of bikes we can transport. Book early to secure your preferred slot.'
				. '[:es]cada franja horaria tiene un número máximo de bicis que podemos transportar. Reserva con antelación para asegurar tu horario preferido.[:]'
			) ); ?></p>
			<?php if ( $surcharge_night > 0 ) : ?>
			<p class="tcbf-transport-info__note"><?php echo wp_kses_post( self::tr(
				'[:en]<strong>Note:</strong> Windows before 07:00 or after 21:00 carry a night surcharge of ' . $fmt( $surcharge_night ) . '.'
				. '[:es]<strong>Nota:</strong> Las franjas antes de las 07:00 o después de las 21:00 tienen un suplemento nocturno de ' . $fmt( $surcharge_night ) . '.[:]'
			) ); ?></p>
			<?php endif; ?>

			<?php /* ------- IMPORTANT NOTES ------- */ ?>
			<h3><?php echo esc_html( self::tr( '[:en]Important things to know[:es]Cosas importantes que debes saber[:]' ) ); ?></h3>
			<ul class="tcbf-transport-info__notes">
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Same address option:</strong> When booking both directions, you can use the same address for delivery and pickup, or set different addresses for each.'
					. '[:es]<strong>Misma dirección:</strong> Al reservar ambos trayectos, puedes usar la misma dirección para entrega y recogida, o elegir direcciones diferentes para cada uno.[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>All bikes travel together:</strong> All your rental bikes must share the same transport dates and time windows.'
					. '[:es]<strong>Todas las bicis viajan juntas:</strong> Todas tus bicis de alquiler deben compartir las mismas fechas y franjas horarias de transporte.[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Price shown before you confirm:</strong> The full breakdown (base price, discounts, surcharges) is shown in the cart before checkout. No surprises.'
					. '[:es]<strong>Precio visible antes de confirmar:</strong> El desglose completo (precio base, descuentos, suplementos) se muestra en el carrito antes del pago. Sin sorpresas.[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Address precision matters:</strong> Use the map to place your pin accurately. This ensures we find you without delays and that zone pricing is calculated correctly.'
					. '[:es]<strong>La precisión de la dirección importa:</strong> Usa el mapa para colocar el pin con exactitud. Esto asegura que te encontremos sin retrasos y que el precio por zona se calcule correctamente.[:]'
				) ); ?></li>
				<li><?php echo wp_kses_post( self::tr(
					'[:en]<strong>Cancellation:</strong> Transport follows the same cancellation policy as your bike rental.'
					. '[:es]<strong>Cancelación:</strong> El transporte sigue la misma política de cancelación que tu alquiler de bici.[:]'
				) ); ?></li>
			</ul>

			<?php /* ------- QUICK SUMMARY ------- */ ?>
			<div class="tcbf-transport-info__summary">
				<p><?php echo esc_html( self::tr(
					'[:en]Add bicycle(s) to the cart, add transport (cart level), choose delivery/pickup/both, enter your address, pick your time slot — done!'
					. '[:es]Añade bicicleta(s) al carrito, añade transporte (a nivel de carrito), elige entrega/recogida/ambos, introduce tu dirección, escoge tu franja horaria — ¡listo![:]'
				) ); ?></p>
			</div>

		</div><!-- .tcbf-transport-info -->

		<?php
		return ob_get_clean();
	}

	/**
	 * Format a price value for display
	 *
	 * Uses € symbol. Respects decimal comma locale for Spanish.
	 *
	 * @param float $value
	 * @return string e.g. "€45" or "45 €"
	 */
	private static function format_price( float $value ) : string {
		$lang = 'en';
		if ( function_exists( 'qtranxf_getLanguage' ) ) {
			$lang = qtranxf_getLanguage();
		}

		if ( $lang === 'es' ) {
			$formatted = ( floor( $value ) == $value )
				? number_format( $value, 0, ',', '.' )
				: number_format( $value, 2, ',', '.' );
			return $formatted . ' €';
		}

		$formatted = ( floor( $value ) == $value )
			? number_format( $value, 0, '.', ',' )
			: number_format( $value, 2, '.', ',' );
		return '€' . $formatted;
	}
}
