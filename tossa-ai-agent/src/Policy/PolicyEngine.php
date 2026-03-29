<?php
/**
 * Policy Engine.
 *
 * Central authority for deciding whether an action is allowed, what safety
 * tier it belongs to, and whether rate limits have been exceeded.
 *
 * @package Tossa\Agent\Policy
 */

declare(strict_types=1);

namespace Tossa\Agent\Policy;

final class PolicyEngine {

    private TruthFirewall $truth;
    private RiskClassifier $risk;

    public function __construct() {
        $this->truth = new TruthFirewall();
        $this->risk  = new RiskClassifier();
    }

    /**
     * Evaluate a proposed action and return a policy verdict.
     *
     * @param array{
     *     action_type: string,
     *     entity_type: string,
     *     entity_id: int|null,
     *     safety_tier: string,
     *     payload: array,
     *     rationale: string,
     * } $action
     *
     * @return array{
     *     allowed: bool,
     *     reason: string,
     *     requires_approval: bool,
     *     warnings: string[],
     *     effective_tier: string,
     * }
     */
    public function evaluate(array $action): array {
        $warnings = [];

        // 1. Truth firewall check.
        $truth_result = $this->truth->check($action);
        if ($truth_result['blocked']) {
            return [
                'allowed'           => false,
                'reason'            => $truth_result['reason'],
                'requires_approval' => false,
                'warnings'          => $truth_result['warnings'],
                'effective_tier'    => 'C',
                'rejection_immutable' => true, // Cannot be overridden by manual approval.
            ];
        }
        $warnings = array_merge($warnings, $truth_result['warnings']);

        // 1b. Auto-translation block check.
        $translate_result = $this->truth->check_auto_translate($action);
        if ($translate_result['blocked']) {
            return [
                'allowed'           => false,
                'reason'            => $translate_result['reason'],
                'requires_approval' => false,
                'warnings'          => $warnings,
                'effective_tier'    => 'C',
                'rejection_immutable' => true,
            ];
        }

        // 2. Risk classification may escalate the tier.
        $effective_tier = $this->risk->classify($action);

        // 3. Rate limit check.
        if ($this->is_rate_limited()) {
            return [
                'allowed'           => false,
                'reason'            => 'Rate limit exceeded. Max actions per hour reached.',
                'requires_approval' => false,
                'warnings'          => $warnings,
                'effective_tier'    => $effective_tier,
            ];
        }

        // 4. Kill switch check.
        if ($this->is_killed()) {
            return [
                'allowed'           => false,
                'reason'            => 'Emergency kill switch is active. All agent actions are paused.',
                'requires_approval' => false,
                'warnings'          => $warnings,
                'effective_tier'    => $effective_tier,
            ];
        }

        // 5. Execution mode check.
        $mode = $this->get_execution_mode();
        if ('analysis-only' === $mode) {
            return [
                'allowed'           => false,
                'reason'            => 'Execution mode is analysis-only. Actions are logged but not executed.',
                'requires_approval' => false,
                'warnings'          => $warnings,
                'effective_tier'    => $effective_tier,
            ];
        }

        return [
            'allowed'           => true,
            'reason'            => 'Policy check passed.',
            'requires_approval' => in_array($effective_tier, ['B', 'C'], true),
            'warnings'          => $warnings,
            'effective_tier'    => $effective_tier,
        ];
    }

    /**
     * Check if the hourly rate limit has been exceeded.
     */
    public function is_rate_limited(): bool {
        $max_per_hour = (int) get_option('tossa_agent_max_actions_per_hour', 20);
        $count        = (int) get_transient('tossa_agent_actions_this_hour');

        return $count >= $max_per_hour;
    }

    /**
     * Increment the hourly action counter.
     */
    public function record_action(): void {
        $key   = 'tossa_agent_actions_this_hour';
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
    }

    /**
     * Check if the emergency kill switch is active.
     */
    public function is_killed(): bool {
        return (bool) get_option('tossa_agent_kill_switch', false);
    }

    /**
     * Get the current execution mode.
     *
     * @return string 'analysis-only' | 'propose-and-approve' | 'full'
     */
    public function get_execution_mode(): string {
        return get_option('tossa_agent_execution_mode', 'propose-and-approve');
    }

    public function truth(): TruthFirewall {
        return $this->truth;
    }

    public function risk(): RiskClassifier {
        return $this->risk;
    }
}
