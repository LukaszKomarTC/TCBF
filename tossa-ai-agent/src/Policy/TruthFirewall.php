<?php
/**
 * Truth Firewall.
 *
 * Prevents the AI agent from making claims that are factually dangerous,
 * legally risky, or require local expert verification for Tossa Cycling.
 *
 * @package Tossa\Agent\Policy
 */

declare(strict_types=1);

namespace Tossa\Agent\Policy;

final class TruthFirewall {

    /**
     * Claims that must NEVER be generated without explicit human approval.
     * Matched case-insensitively against proposed content.
     */
    private const FORBIDDEN_PHRASES = [
        'perfect for all fitness levels',
        'guaranteed availability',
        'guaranteed delivery',
        'completely safe',
        '100% safe',
        'no traffic',
        'traffic-free',          // Must be verified per route segment
        'risk-free',
        'cheapest in',
        'best price guaranteed',
    ];

    /**
     * Claims that auto-escalate to Tier C (human review required).
     * These are not forbidden but need local knowledge verification.
     */
    private const REQUIRES_LOCAL_REVIEW = [
        'suitable for children',
        'family friendly',
        'family-friendly',
        'beginner friendly',
        'beginner-friendly',
        'best route',
        'recommended route',
        'easy route',
        'gravel suitable',
        'gravel-suitable',
        'safe for',
        'wheelchair accessible',
        'suitable for e-bikes',
        'pet friendly',
        'pet-friendly',
    ];

    /**
     * Tone constraints — phrases that indicate generic AI copy.
     */
    private const TONE_VIOLATIONS = [
        'nestled in',
        'hidden gem',
        'a paradise for',
        'unforgettable experience',
        'breathtaking views',
        'world-class',
        'second to none',
        'like no other',
        'discover the magic',
    ];

    /**
     * Check a proposed action against truth rules.
     *
     * @param array $action The action array with 'payload' containing proposed content.
     *
     * @return array{blocked: bool, reason: string, warnings: string[]}
     */
    public function check(array $action): array {
        $warnings = [];
        $content  = $this->extract_content($action);

        if (empty($content)) {
            return ['blocked' => false, 'reason' => '', 'warnings' => []];
        }

        $content_lower = mb_strtolower($content);

        // Check forbidden phrases — these block the action entirely.
        foreach (self::FORBIDDEN_PHRASES as $phrase) {
            if (str_contains($content_lower, mb_strtolower($phrase))) {
                return [
                    'blocked'  => true,
                    'reason'   => sprintf(
                        'Forbidden claim detected: "%s". This claim requires explicit human verification and cannot be auto-generated.',
                        $phrase
                    ),
                    'warnings' => [],
                ];
            }
        }

        // Check local-review phrases — these add warnings and escalate tier.
        foreach (self::REQUIRES_LOCAL_REVIEW as $phrase) {
            if (str_contains($content_lower, mb_strtolower($phrase))) {
                $warnings[] = sprintf(
                    'Local knowledge required: "%s" — escalated to human review.',
                    $phrase
                );
            }
        }

        // Check tone violations — these add warnings but don't block.
        foreach (self::TONE_VIOLATIONS as $phrase) {
            if (str_contains($content_lower, mb_strtolower($phrase))) {
                $warnings[] = sprintf(
                    'Generic AI tone detected: "%s" — consider rewriting for authenticity.',
                    $phrase
                );
            }
        }

        // Multilingual consistency check.
        $ml_warnings = $this->check_multilingual($action);
        $warnings    = array_merge($warnings, $ml_warnings);

        return [
            'blocked'  => false,
            'reason'   => '',
            'warnings' => $warnings,
        ];
    }

    /**
     * Get all configured forbidden phrases (for admin display).
     *
     * @return string[]
     */
    public function get_forbidden_phrases(): array {
        $custom = get_option('tossa_agent_forbidden_claims', []);
        return array_merge(self::FORBIDDEN_PHRASES, is_array($custom) ? $custom : []);
    }

    /**
     * Get all local-review phrases (for admin display).
     *
     * @return string[]
     */
    public function get_local_review_phrases(): array {
        $custom = get_option('tossa_agent_local_review_claims', []);
        return array_merge(self::REQUIRES_LOCAL_REVIEW, is_array($custom) ? $custom : []);
    }

    /**
     * Extract text content from an action's payload for scanning.
     */
    private function extract_content(array $action): string {
        $payload = $action['payload'] ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        $fields = ['title', 'description', 'content', 'heading', 'text', 'meta_title', 'meta_description'];
        $parts  = [];

        foreach ($fields as $field) {
            if (! empty($payload[$field])) {
                $parts[] = $payload[$field];
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Check for multilingual issues.
     *
     * @return string[]
     */
    private function check_multilingual(array $action): array {
        $warnings = [];

        // Only check if multilingual enforcement is enabled.
        if (! get_option('tossa_agent_multilingual_check', true)) {
            return $warnings;
        }

        $payload = $action['payload'] ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        $content = $this->extract_content($action);
        if (empty($content)) {
            return $warnings;
        }

        // Detect if the target language is specified and warn if not.
        if (empty($payload['language']) && ! empty($action['entity_id'])) {
            $warnings[] = 'No target language specified for this content change. Verify multilingual consistency.';
        }

        return $warnings;
    }

    /**
     * Check if an action is an auto-translation that should be blocked.
     *
     * @return array{blocked: bool, reason: string}
     */
    public function check_auto_translate(array $action): array {
        if (! get_option('tossa_agent_block_auto_translate', true)) {
            return ['blocked' => false, 'reason' => ''];
        }

        $action_type = $action['action_type'] ?? '';
        $payload     = $action['payload'] ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        // Block if the action is flagged as auto-translation.
        $is_translation = ($payload['is_translation'] ?? false)
            || str_contains($action_type, 'translate')
            || str_contains($action_type, 'translation');

        if ($is_translation) {
            return [
                'blocked' => true,
                'reason'  => 'Auto-translation of SEO metadata is blocked. Translations must be human-reviewed.',
            ];
        }

        return ['blocked' => false, 'reason' => ''];
    }
}
