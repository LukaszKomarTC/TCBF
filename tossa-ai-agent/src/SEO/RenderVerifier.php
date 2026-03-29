<?php
/**
 * Render Verifier.
 *
 * After writing SEO metadata, verifies that the actual rendered HTML output
 * matches what was stored. Catches issues caused by caching, CDN, theme
 * overrides, or conflicting plugin output.
 *
 * @package Tossa\Agent\SEO
 */

declare(strict_types=1);

namespace Tossa\Agent\SEO;

final class RenderVerifier {

    /**
     * Verify that a post's rendered <head> contains the expected SEO metadata.
     *
     * @param int   $post_id  The post to verify.
     * @param array $expected Keyed by field name: ['title' => '...', 'description' => '...'].
     *
     * @return array{
     *     verified: bool,
     *     issues: string[],
     *     rendered: array,
     * }
     */
    public function verify(int $post_id, array $expected): array {
        $url = get_permalink($post_id);

        if (! $url) {
            return [
                'verified' => false,
                'issues'   => ['Could not determine URL for post ' . $post_id],
                'rendered' => [],
            ];
        }

        // Fetch the page. Use a non-blocking internal request.
        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'user-agent' => 'TossaAIAgent/RenderVerifier',
            'sslverify'  => false, // Internal request, staging may lack valid cert.
        ]);

        if (is_wp_error($response)) {
            return [
                'verified' => false,
                'issues'   => ['Fetch failed: ' . $response->get_error_message()],
                'rendered' => [],
            ];
        }

        $html = wp_remote_retrieve_body($response);

        if (empty($html)) {
            return [
                'verified' => false,
                'issues'   => ['Empty response body.'],
                'rendered' => [],
            ];
        }

        // Parse the <head> section.
        $rendered = $this->parse_head($html);
        $issues   = [];

        // Compare each expected field.
        if (! empty($expected['title'])) {
            if (empty($rendered['title'])) {
                $issues[] = 'Title tag not found in rendered output.';
            } elseif (trim($rendered['title']) !== trim($expected['title'])) {
                $issues[] = sprintf(
                    'Title mismatch. Expected: "%s" — Got: "%s"',
                    $expected['title'],
                    $rendered['title']
                );
            }
        }

        if (! empty($expected['description'])) {
            if (empty($rendered['description'])) {
                $issues[] = 'Meta description not found in rendered output.';
            } elseif (trim($rendered['description']) !== trim($expected['description'])) {
                $issues[] = sprintf(
                    'Description mismatch. Expected: "%s" — Got: "%s"',
                    $expected['description'],
                    $rendered['description']
                );
            }
        }

        if (! empty($expected['canonical'])) {
            if (empty($rendered['canonical'])) {
                $issues[] = 'Canonical link not found in rendered output.';
            } elseif (trim($rendered['canonical']) !== trim($expected['canonical'])) {
                $issues[] = sprintf(
                    'Canonical mismatch. Expected: "%s" — Got: "%s"',
                    $expected['canonical'],
                    $rendered['canonical']
                );
            }
        }

        // Check for duplicate meta tags (common issue after plugin migration).
        if (($rendered['title_count'] ?? 0) > 1) {
            $issues[] = 'Multiple <title> tags found — possible plugin conflict.';
        }
        if (($rendered['description_count'] ?? 0) > 1) {
            $issues[] = 'Multiple meta descriptions found — possible plugin conflict.';
        }
        if (($rendered['canonical_count'] ?? 0) > 1) {
            $issues[] = 'Multiple canonical links found — possible plugin conflict.';
        }

        return [
            'verified' => empty($issues),
            'issues'   => $issues,
            'rendered' => $rendered,
        ];
    }

    /**
     * Parse SEO-relevant tags from the <head> section of an HTML page.
     *
     * @return array{
     *     title: string|null,
     *     description: string|null,
     *     canonical: string|null,
     *     robots: string|null,
     *     og_title: string|null,
     *     og_description: string|null,
     *     title_count: int,
     *     description_count: int,
     *     canonical_count: int,
     * }
     */
    public function parse_head(string $html): array {
        $result = [
            'title'             => null,
            'description'       => null,
            'canonical'         => null,
            'robots'            => null,
            'og_title'          => null,
            'og_description'    => null,
            'title_count'       => 0,
            'description_count' => 0,
            'canonical_count'   => 0,
        ];

        // Extract <head> section.
        if (preg_match('/<head[^>]*>(.*?)<\/head>/si', $html, $head_match)) {
            $head = $head_match[1];
        } else {
            return $result;
        }

        // Title.
        $result['title_count'] = preg_match_all('/<title[^>]*>(.*?)<\/title>/si', $head, $title_matches);
        if (! empty($title_matches[1][0])) {
            $result['title'] = html_entity_decode(trim($title_matches[1][0]), ENT_QUOTES, 'UTF-8');
        }

        // Meta description.
        $result['description_count'] = preg_match_all(
            '/<meta\s[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*\/?>/si',
            $head,
            $desc_matches
        );
        if (empty($desc_matches[1][0])) {
            // Try reversed attribute order.
            preg_match_all(
                '/<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*\/?>/si',
                $head,
                $desc_matches_alt
            );
            if (! empty($desc_matches_alt[1][0])) {
                $result['description'] = html_entity_decode(trim($desc_matches_alt[1][0]), ENT_QUOTES, 'UTF-8');
                $result['description_count'] = count($desc_matches_alt[1]);
            }
        } else {
            $result['description'] = html_entity_decode(trim($desc_matches[1][0]), ENT_QUOTES, 'UTF-8');
        }

        // Canonical.
        $result['canonical_count'] = preg_match_all(
            '/<link\s[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']*)["\'][^>]*\/?>/si',
            $head,
            $canonical_matches
        );
        if (! empty($canonical_matches[1][0])) {
            $result['canonical'] = trim($canonical_matches[1][0]);
        }

        // Robots.
        if (preg_match('/<meta\s[^>]*name=["\']robots["\'][^>]*content=["\']([^"\']*)["\'][^>]*\/?>/si', $head, $robots_match)) {
            $result['robots'] = trim($robots_match[1]);
        }

        // OG title.
        if (preg_match('/<meta\s[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']*)["\'][^>]*\/?>/si', $head, $og_title)) {
            $result['og_title'] = html_entity_decode(trim($og_title[1]), ENT_QUOTES, 'UTF-8');
        }

        // OG description.
        if (preg_match('/<meta\s[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']*)["\'][^>]*\/?>/si', $head, $og_desc)) {
            $result['og_description'] = html_entity_decode(trim($og_desc[1]), ENT_QUOTES, 'UTF-8');
        }

        return $result;
    }
}
