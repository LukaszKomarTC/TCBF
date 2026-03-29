<?php
/**
 * Content Inventory.
 *
 * Collects a full snapshot of the site's published content and SEO metadata,
 * structured for sending to the VPS agent for Claude analysis.
 *
 * @package Tossa\Agent\SEO
 */

declare(strict_types=1);

namespace Tossa\Agent\SEO;

final class ContentInventory {

    private SEOPressAdapter $seopress;

    public function __construct(SEOPressAdapter $seopress) {
        $this->seopress = $seopress;
    }

    /**
     * Build a complete site inventory for an SEO audit.
     *
     * @return array{
     *     site_info: array,
     *     pages: array<int, array>,
     *     terms: array<int, array>,
     *     stats: array,
     *     collected_at: string,
     * }
     */
    public function collect(): array {
        $pages = $this->collect_pages();
        $terms = $this->collect_terms();
        $stats = $this->compute_stats($pages, $terms);

        return [
            'site_info'    => $this->get_site_info(),
            'pages'        => $pages,
            'terms'        => $terms,
            'stats'        => $stats,
            'collected_at' => current_time('mysql'),
        ];
    }

    /**
     * Get basic site information.
     */
    private function get_site_info(): array {
        return [
            'name'         => get_bloginfo('name'),
            'url'          => home_url(),
            'language'     => get_bloginfo('language'),
            'wp_version'   => get_bloginfo('version'),
            'seopress_ver' => $this->seopress->get_version(),
            'post_types'   => $this->get_public_post_types(),
            'taxonomies'   => $this->get_public_taxonomies(),
        ];
    }

    /**
     * Collect all published pages/posts/products with SEO metadata.
     *
     * @return array<int, array>
     */
    private function collect_pages(): array {
        $post_types = $this->get_public_post_types();
        $seo_data   = $this->seopress->get_all_content_meta(array_keys($post_types));
        $pages      = [];

        foreach ($seo_data as $post_id => $meta) {
            $post = get_post($post_id);
            if (! $post) {
                continue;
            }

            $pages[$post_id] = array_merge($meta, [
                'word_count'     => str_word_count(wp_strip_all_tags($post->post_content)),
                'has_content'    => ! empty(trim($post->post_content)),
                'last_modified'  => $post->post_modified,
                'author'         => get_the_author_meta('display_name', $post->post_author),
                'categories'     => $this->get_post_terms($post_id, 'category'),
                'tags'           => $this->get_post_terms($post_id, 'post_tag'),
                'product_cat'    => $this->get_post_terms($post_id, 'product_cat'),
                'internal_links' => $this->count_internal_links($post->post_content),
                'images'         => $this->count_images($post->post_content),
                'headings'       => $this->extract_headings($post->post_content),
            ]);
        }

        return $pages;
    }

    /**
     * Collect taxonomy terms with SEO metadata.
     *
     * @return array<int, array>
     */
    private function collect_terms(): array {
        $terms_data = [];
        $taxonomies = array_keys($this->get_public_taxonomies());

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ]);

            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $terms_data[$term->term_id] = array_merge(
                    $this->seopress->get_term_meta($term->term_id),
                    [
                        'post_count' => $term->count,
                        'parent'     => $term->parent,
                    ]
                );
            }
        }

        return $terms_data;
    }

    /**
     * Compute summary statistics about the inventory.
     */
    private function compute_stats(array $pages, array $terms): array {
        $missing_title = 0;
        $missing_desc  = 0;
        $thin_content  = 0;
        $no_focus_kw   = 0;
        $orphan_pages  = 0;

        foreach ($pages as $page) {
            if (empty($page['title'])) {
                $missing_title++;
            }
            if (empty($page['description'])) {
                $missing_desc++;
            }
            if (($page['word_count'] ?? 0) < 300) {
                $thin_content++;
            }
            if (empty($page['focus_keyword'])) {
                $no_focus_kw++;
            }
            if (($page['internal_links'] ?? 0) === 0) {
                $orphan_pages++;
            }
        }

        return [
            'total_pages'       => count($pages),
            'total_terms'       => count($terms),
            'missing_title'     => $missing_title,
            'missing_desc'      => $missing_desc,
            'thin_content'      => $thin_content,
            'no_focus_keyword'  => $no_focus_kw,
            'orphan_pages'      => $orphan_pages,
        ];
    }

    /**
     * Get public post types as name => label pairs.
     *
     * @return array<string, string>
     */
    private function get_public_post_types(): array {
        $types  = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($types as $type) {
            if (in_array($type->name, ['attachment', 'revision', 'nav_menu_item'], true)) {
                continue;
            }
            $result[$type->name] = $type->label;
        }

        return $result;
    }

    /**
     * Get public taxonomies as name => label pairs.
     *
     * @return array<string, string>
     */
    private function get_public_taxonomies(): array {
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $result     = [];

        foreach ($taxonomies as $tax) {
            if (in_array($tax->name, ['post_format', 'nav_menu'], true)) {
                continue;
            }
            $result[$tax->name] = $tax->label;
        }

        return $result;
    }

    /**
     * Get terms for a post in a given taxonomy.
     *
     * @return string[]
     */
    private function get_post_terms(int $post_id, string $taxonomy): array {
        $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
        return is_array($terms) ? $terms : [];
    }

    /**
     * Count internal links in post content.
     */
    private function count_internal_links(string $content): int {
        $home = home_url();
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);

        $count = 0;
        foreach ($matches[1] ?? [] as $url) {
            if (str_starts_with($url, $home) || str_starts_with($url, '/')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count images in post content.
     */
    private function count_images(string $content): int {
        return preg_match_all('/<img\s/i', $content);
    }

    /**
     * Extract heading structure from content.
     *
     * @return array<array{level: int, text: string}>
     */
    private function extract_headings(string $content): array {
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/si', $content, $matches, PREG_SET_ORDER);

        $headings = [];
        foreach ($matches as $match) {
            $headings[] = [
                'level' => (int) $match[1],
                'text'  => wp_strip_all_tags($match[2]),
            ];
        }

        return $headings;
    }
}
