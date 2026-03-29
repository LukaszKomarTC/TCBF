<?php
/**
 * Risk Classifier.
 *
 * Determines the effective safety tier for an action based on its type,
 * scope, and target. May escalate an action's tier above what the AI proposed.
 *
 * @package Tossa\Agent\Policy
 */

declare(strict_types=1);

namespace Tossa\Agent\Policy;

final class RiskClassifier {

    /**
     * Tier A actions — safe, reversible field-level mutations.
     * Auto-proposable, one-click approve.
     */
    private const TIER_A_ACTIONS = [
        'set_meta_title',
        'set_meta_description',
        'set_canonical',
        'set_robots',
        'set_social_title',
        'set_social_description',
        'set_focus_keyword',
        'set_noindex',
        'set_nofollow',
        'fix_missing_meta',
    ];

    /**
     * Tier B actions — editorial, require diff review.
     */
    private const TIER_B_ACTIONS = [
        'rewrite_heading',
        'rewrite_intro',
        'rewrite_section',
        'insert_internal_link',
        'remove_internal_link',
        'add_faq_schema',
        'update_schema',
        'suggest_alt_text',
    ];

    /**
     * Tier C actions — strategic, human-only.
     * The AI can flag these but never execute them.
     */
    private const TIER_C_ACTIONS = [
        'bulk_rewrite',
        'page_consolidation',
        'redirect_strategy',
        'new_landing_page',
        'multilingual_restructure',
        'delete_page',
        'change_url_structure',
        'modify_business_claims',
    ];

    /**
     * Classify an action and return its effective safety tier.
     *
     * @param array $action Action array with 'action_type' and 'safety_tier'.
     * @return string 'A' | 'B' | 'C'
     */
    public function classify(array $action): string {
        $type          = $action['action_type'] ?? '';
        $proposed_tier = $action['safety_tier'] ?? 'C';

        // Determine tier from action type.
        if (in_array($type, self::TIER_A_ACTIONS, true)) {
            $type_tier = 'A';
        } elseif (in_array($type, self::TIER_B_ACTIONS, true)) {
            $type_tier = 'B';
        } elseif (in_array($type, self::TIER_C_ACTIONS, true)) {
            $type_tier = 'C';
        } else {
            // Unknown action type — default to most restrictive.
            $type_tier = 'C';
        }

        // Effective tier is the MORE restrictive of proposed vs classified.
        return $this->most_restrictive($proposed_tier, $type_tier);
    }

    /**
     * Check if an action type is recognized.
     */
    public function is_known_action(string $action_type): bool {
        return in_array($action_type, self::TIER_A_ACTIONS, true)
            || in_array($action_type, self::TIER_B_ACTIONS, true)
            || in_array($action_type, self::TIER_C_ACTIONS, true);
    }

    /**
     * Get all action types grouped by tier (for admin display).
     *
     * @return array<string, string[]>
     */
    public function get_all_tiers(): array {
        return [
            'A' => self::TIER_A_ACTIONS,
            'B' => self::TIER_B_ACTIONS,
            'C' => self::TIER_C_ACTIONS,
        ];
    }

    /**
     * Return the more restrictive of two tiers: C > B > A.
     */
    private function most_restrictive(string $a, string $b): string {
        $order = ['A' => 0, 'B' => 1, 'C' => 2];
        $val_a = $order[$a] ?? 2;
        $val_b = $order[$b] ?? 2;

        return $val_a >= $val_b ? $a : $b;
    }
}
