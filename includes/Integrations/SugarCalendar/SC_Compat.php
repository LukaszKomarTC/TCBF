<?php
namespace TC_BF\Integrations\SugarCalendar;

if ( ! defined('ABSPATH') ) exit;

/**
 * SC_Compat — compatibility shims for Sugar Calendar 3.12+ frontend blocks
 *
 * 1) Cache-busting for block assets.
 *    Sugar Calendar enqueues its Calendar/Event List block CSS+JS with the
 *    version from each block's block.json ("1.0.1"), and that value has not
 *    changed across plugin releases even though the files themselves have
 *    (e.g. 3.11 styled the list image as a CSS background; 3.12+ renders a
 *    responsive <img> and needs new rules for it). Because the URL is
 *    byte-identical before and after a plugin update, returning visitors keep
 *    using their heuristically-cached old stylesheet against the new markup:
 *    list images render unclipped at natural aspect ratio and overflow the
 *    190px container. Appending the real plugin version to those asset URLs
 *    makes every Sugar Calendar update bust browser caches like the rest of
 *    its assets already do.
 *
 * 2) Popover image size.
 *    The calendar-view popover image was historically enlarged on this site
 *    by a code snippet that duplicated the whole AJAX handler (pre-3.12 the
 *    handler returned a bare URL for a CSS background, so that was the only
 *    way). Sugar Calendar 3.12+ returns a rendered <img> and the snippet's
 *    URL-shaped response now prints as literal text in the popup, so the
 *    snippet must be removed — this filter is the supported replacement and
 *    keeps the popover serving the 'large' size once the native handler runs.
 */
final class SC_Compat {

	public static function init() : void {

		// Sugar Calendar not active — nothing to shim.
		if ( ! defined('SC_PLUGIN_VERSION') ) return;

		// 1) Append the Sugar Calendar plugin version to its block build assets
		//    (src/Block/*/build/*.css|js), whose own ?ver= never changes.
		add_filter( 'style_loader_src',  [ __CLASS__, 'bust_block_asset_version' ], 20 );
		add_filter( 'script_loader_src', [ __CLASS__, 'bust_block_asset_version' ], 20 );

		// 2) Calendar-view popover image: keep the 'large' size this site has
		//    always used (replaces the legacy full-handler snippet).
		add_filter( 'sugar_calendar_block_calendar_loader_popover_image_size', [ __CLASS__, 'popover_image_size' ] );
	}

	/**
	 * Append Sugar Calendar's plugin version to its block build asset URLs.
	 *
	 * Matches only URLs under the Sugar Calendar plugin's src/Block/ tree, so
	 * its properly-versioned assets (assets/js/*.js?ver=SC_PLUGIN_VERSION) and
	 * every other plugin's files pass through untouched.
	 */
	public static function bust_block_asset_version( $src ) {
		if ( ! is_string($src) || $src === '' ) return $src;
		if ( strpos( $src, 'sugar-calendar/src/Block/' ) === false ) return $src;
		return add_query_arg( 'tcbf_scv', rawurlencode( (string) SC_PLUGIN_VERSION ), $src );
	}

	public static function popover_image_size( $size ) : string {
		return 'large';
	}
}
