<?php
namespace TC_BF\Integrations\SugarCalendar;

if ( ! defined('ABSPATH') ) exit;

/**
 * SC_Compat — compatibility shims + site customizations for Sugar Calendar 3.12+
 *
 * Replaces the legacy "sc popover" code snippet in full. Mapping:
 *
 * - Popover / list-view image size 'large'
 *     Ported here via the supported size filters. Pre-3.12 the popover snippet
 *     had to duplicate the whole AJAX handler to control the image; 3.12+
 *     returns a rendered responsive <img>, so the duplicated handler answered
 *     with a bare URL that the new JS printed as literal text in the popup.
 *
 * - Popover description from the event's manual excerpt
 *     Native in Sugar Calendar since 3.11 (Helpers::get_event_excerpt() prefers
 *     post_excerpt, generated excerpt as fallback). What remains site-specific
 *     is ported to the 'sugar_calendar_helpers_get_event_excerpt' filter:
 *     qTranslate-XT marker translation (markers must never leak), tag/whitespace
 *     cleanup, and a word-safe character cap applied only to the popover
 *     (default 200, tunable via the pre-existing 'tc_sc_popover_desc_max_chars'
 *     filter). The filter also feeds the week-view cells and the list view, so
 *     translation/cleanup apply there too; the length cap does not.
 *
 * - add_post_type_support('sc_event','excerpt')
 *     Not ported: sc_event declares 'excerpt' in its own supports array now.
 *
 * - "[vc_*] stripping" block
 *     Not ported: it was attached to a mistyped hook name ('the_content_') and
 *     has never run; generated excerpts already go through strip_shortcodes().
 *
 * Cache-busting for block assets:
 *     Sugar Calendar enqueues its Calendar/Event List block CSS+JS with the
 *     version from each block's block.json ("1.0.1"), unchanged across plugin
 *     releases even though the files themselves change (3.12 moved block
 *     images from CSS backgrounds to responsive <img> tags and restyled them).
 *     Returning visitors therefore keep a heuristically-cached old stylesheet
 *     against new markup: list images render unclipped at natural aspect ratio
 *     and overflow the 190px container. Appending the real plugin version to
 *     those asset URLs makes every Sugar Calendar update bust browser caches
 *     like the rest of its assets already do.
 */
final class SC_Compat {

	public static function init() : void {

		// Sugar Calendar not active — nothing to shim.
		if ( ! defined('SC_PLUGIN_VERSION') ) return;

		// Append the Sugar Calendar plugin version to its block build assets
		// (src/Block/*/build/*.css|js), whose own ?ver= never changes.
		add_filter( 'style_loader_src',  [ __CLASS__, 'bust_block_asset_version' ], 20 );
		add_filter( 'script_loader_src', [ __CLASS__, 'bust_block_asset_version' ], 20 );

		// Event images: serve the 'large' size in the calendar popover and the
		// event list view (SC defaults to 'medium' for both).
		add_filter( 'sugar_calendar_block_calendar_loader_popover_image_size', [ __CLASS__, 'image_size_large' ] );
		add_filter( 'sugar_calendar_block_list_listview_image_size',           [ __CLASS__, 'image_size_large' ] );

		// Event descriptions (popover, week-view cells, list view): translate
		// qTranslate markers, clean up, and cap the popover length.
		add_filter( 'sugar_calendar_helpers_get_event_excerpt', [ __CLASS__, 'filter_event_excerpt' ], 10, 2 );
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

	public static function image_size_large( $size ) : string {
		return 'large';
	}

	/**
	 * Filter Sugar Calendar's event excerpt (popover, week view, list view).
	 *
	 * @param string $excerpt         Excerpt (manual post_excerpt when set).
	 * @param int    $event_object_id Event post ID.
	 */
	public static function filter_event_excerpt( $excerpt, $event_object_id ) : string {

		$excerpt = trim( (string) $excerpt );
		if ( $excerpt === '' ) return $excerpt;

		// qTranslate XT: resolve [:es]...[:en]...[:] to the current language;
		// strip the markers if qTranslate is unavailable so they never leak.
		if ( function_exists( 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage' ) ) {
			$excerpt = (string) qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $excerpt );
		} else {
			$excerpt = preg_replace( '/\[:[a-zA-Z_-]+\]/', '', $excerpt );
			$excerpt = str_replace( '[:]', '', $excerpt );
		}

		$excerpt = wp_strip_all_tags( $excerpt, true );
		$excerpt = trim( preg_replace( '/\s+/', ' ', $excerpt ) );

		// Word-safe character cap, popover only. The manual excerpt bypasses
		// SC's word-count trim, so an uncapped one would overflow the popup.
		if ( self::is_popover_request() ) {
			/**
			 * Filters the popover description length cap (characters).
			 *
			 * @param int $max             Maximum characters (word-safe cut).
			 * @param int $event_object_id Event post ID.
			 */
			$max     = (int) apply_filters( 'tc_sc_popover_desc_max_chars', 200, $event_object_id );
			$excerpt = self::trim_chars_word_safe( $excerpt, $max );
		}

		return $excerpt;
	}

	private static function is_popover_request() : bool {
		return wp_doing_ajax()
			&& isset( $_POST['action'] )
			&& $_POST['action'] === 'sugar_calendar_event_popover';
	}

	/**
	 * Cut to at most $max characters at a word boundary, appending an ellipsis.
	 * Falls back to the start of the string when the last space sits too early
	 * (< 30 chars) to avoid absurdly short output on long first words.
	 */
	private static function trim_chars_word_safe( string $text, int $max ) : string {

		if ( $max <= 0 ) return $text;

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text ) <= $max ) return $text;
			$cut   = mb_substr( $text, 0, $max );
			$space = mb_strrpos( $cut, ' ' );
			if ( $space !== false && $space > 30 ) {
				$cut = mb_substr( $cut, 0, $space );
			}
		} else {
			if ( strlen( $text ) <= $max ) return $text;
			$cut   = substr( $text, 0, $max );
			$space = strrpos( $cut, ' ' );
			if ( $space !== false && $space > 30 ) {
				$cut = substr( $cut, 0, $space );
			}
		}

		return rtrim( $cut ) . '…';
	}
}
