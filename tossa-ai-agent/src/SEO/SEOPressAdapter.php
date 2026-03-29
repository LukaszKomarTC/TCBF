<?php
/**
 * SEOPress Adapter.
 *
 * Owns ALL interaction with SEOPress — reads and writes go through this class.
 * Isolates the rest of Tossa AI Agent from SEOPress internal changes.
 *
 * Reads/writes use SEOPress REST API where available (authenticated via
 * Application Password), falling back to local meta functions for operations
 * that lack REST support.
 *
 * @package Tossa\Agent\SEO
 */

declare(strict_types=1);

namespace Tossa\Agent\SEO;

final class SEOPressAdapter {

    /**
     * SEOPress meta key mapping.
     * Maps our canonical field names to SEOPress post meta keys.
     */
    private const META_KEYS = [
        'title'            => '_seopress_titles_title',
        'description'      => '_seopress_titles_desc',
        'canonical'        => '_seopress_robots_canonical',
        'noindex'          => '_seopress_robots_index',
        'nofollow'         => '_seopress_robots_follow',
        'focus_keyword'    => '_seopress_analysis_target_kw',
        'social_fb_title'  => '_seopress_social_fb_title',
        'social_fb_desc'   => '_seopress_social_fb_desc',
        'social_fb_img'    => '_seopress_social_fb_img',
        'social_tw_title'  => '_seopress_social_twitter_title',
        'social_tw_desc'   => '_seopress_social_twitter_desc',
        'social_tw_img'    => '_seopress_social_twitter_img',
        'redirect_enabled' => '_seopress_redirections_enabled',
        'redirect_url'     => '_seopress_redirections_value',
        'redirect_type'    => '_seopress_redirections_type',
    ];

    /**
     * Check if SEOPress is active.
     */
    public function is_available(): bool {
        return defined('SEOPRESS_VERSION') || function_exists('seopress_init');
    }

    /**
     * Get the installed SEOPress version.
     */
    public function get_version(): string {
        return defined('SEOPRESS_VERSION') ? SEOPRESS_VERSION : '0.0.0';
    }

    /**
     * Read all SEO metadata for a given post/page/product.
     *
     * @param int $post_id WordPress post ID.
     * @return array<string, mixed> Keyed by our canonical field names.
     */
    public function get_meta(int $post_id): array {
        $meta = [];

        foreach (self::META_KEYS as $field => $meta_key) {
            $raw   = get_post_meta($post_id, $meta_key, true);
            $exists = metadata_exists('post', $post_id, $meta_key);
            $meta[$field] = $exists ? $raw : null;
        }

        // Add computed fields.
        $meta['post_id']    = $post_id;
        $meta['post_type']  = get_post_type($post_id);
        $meta['post_title'] = get_the_title($post_id);
        $meta['post_url']   = get_permalink($post_id);
        $meta['post_status'] = get_post_status($post_id);

        return $meta;
    }

    /**
     * Write a single SEO field for a post.
     *
     * Returns the previous value for rollback purposes.
     *
     * @param int    $post_id WordPress post ID.
     * @param string $field   Our canonical field name (e.g., 'title', 'description').
     * @param string $value   New value.
     * @return array{success: bool, previous_value: mixed, meta_key: string}
     */
    public function set_field(int $post_id, string $field, string $value): array {
        $meta_key = self::META_KEYS[$field] ?? null;

        if (! $meta_key) {
            return [
                'success'        => false,
                'previous_value' => null,
                'meta_key'       => '',
                'error'          => "Unknown field: {$field}",
            ];
        }

        $existed  = metadata_exists('post', $post_id, $meta_key);
        $previous = get_post_meta($post_id, $meta_key, true);

        $result = update_post_meta($post_id, $meta_key, $value);

        return [
            'success'        => $result !== false,
            'previous_value' => $existed ? $previous : null,
            'field_existed'  => $existed,
            'meta_key'       => $meta_key,
        ];
    }

    /**
     * Write multiple SEO fields for a post in one call.
     *
     * @param int   $post_id WordPress post ID.
     * @param array<string, string> $fields  field_name => value pairs.
     * @return array{success: bool, results: array, before_snapshot: array}
     */
    public function set_fields(int $post_id, array $fields): array {
        $results  = [];
        $before   = [];
        $all_ok   = true;

        foreach ($fields as $field => $value) {
            $result = $this->set_field($post_id, $field, $value);
            $results[$field] = $result;
            $before[$field]  = $result['previous_value'];

            if (! $result['success']) {
                $all_ok = false;
            }
        }

        return [
            'success'         => $all_ok,
            'results'         => $results,
            'before_snapshot' => $before,
        ];
    }

    /**
     * Rollback a field to its previous value.
     *
     * @param int    $post_id        WordPress post ID.
     * @param string $field          Our canonical field name.
     * @param mixed  $previous_value The value to restore (null = delete).
     * @return bool
     */
    /**
     * Rollback a field to its previous state.
     *
     * @param int    $post_id        WordPress post ID.
     * @param string $field          Our canonical field name.
     * @param mixed  $previous_value The value to restore.
     * @param bool   $field_existed  Whether the field existed before the action.
     * @return bool
     */
    public function rollback_field(int $post_id, string $field, mixed $previous_value, bool $field_existed = true): bool {
        $meta_key = self::META_KEYS[$field] ?? null;

        if (! $meta_key) {
            return false;
        }

        // Field didn't exist before — remove it entirely.
        if (! $field_existed) {
            return delete_post_meta($post_id, $meta_key);
        }

        // Field existed with a value (including empty string) — restore it.
        return update_post_meta($post_id, $meta_key, $previous_value) !== false;
    }

    /**
     * Get all SEO metadata for all published content.
     *
     * Used by ContentInventory to build the full site snapshot.
     *
     * @param string[] $post_types Post types to include.
     * @return array<int, array>  Keyed by post ID.
     */
    public function get_all_content_meta(array $post_types = ['post', 'page', 'product']): array {
        $posts = get_posts([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $result = [];
        foreach ($posts as $post_id) {
            $result[$post_id] = $this->get_meta($post_id);
        }

        return $result;
    }

    /**
     * Get taxonomy term SEO metadata (if SEOPress stores it).
     *
     * @param int $term_id WordPress term ID.
     * @return array<string, mixed>
     */
    public function get_term_meta(int $term_id): array {
        $meta = [];

        foreach (self::META_KEYS as $field => $meta_key) {
            $value = get_term_meta($term_id, $meta_key, true);
            $meta[$field] = $value !== '' ? $value : null;
        }

        $term = get_term($term_id);
        if ($term && ! is_wp_error($term)) {
            $meta['term_id']   = $term_id;
            $meta['taxonomy']  = $term->taxonomy;
            $meta['term_name'] = $term->name;
            $meta['term_url']  = get_term_link($term);
        }

        return $meta;
    }

    /**
     * Get the SEOPress meta key for a given canonical field.
     */
    public function get_meta_key(string $field): ?string {
        return self::META_KEYS[$field] ?? null;
    }

    /**
     * Get all supported field names.
     *
     * @return string[]
     */
    public function get_supported_fields(): array {
        return array_keys(self::META_KEYS);
    }
}
