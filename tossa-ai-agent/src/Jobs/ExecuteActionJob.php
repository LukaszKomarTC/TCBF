<?php
/**
 * Execute Action Job.
 *
 * Executes a single approved SEO action via Action Scheduler.
 * Used when batch-executing approved actions asynchronously.
 *
 * @package Tossa\Agent\Jobs
 */

declare(strict_types=1);

namespace Tossa\Agent\Jobs;

use Tossa\Agent\Audit\AuditLogger;
use Tossa\Agent\Policy\PolicyEngine;
use Tossa\Agent\SEO\SEOController;

final class ExecuteActionJob {

    /**
     * Handle the action execution.
     *
     * @param string $action_id The action to execute.
     */
    public static function handle(string $action_id): void {
        $policy = new PolicyEngine();
        $logger = new AuditLogger();

        if ($policy->is_killed()) {
            $logger->log('warning', 'execute_action_job', 'skipped_kill_switch', [
                'action_id' => $action_id,
            ]);
            return;
        }

        $seo    = new SEOController($policy, $logger);
        $result = $seo->execute_action($action_id);

        $logger->log(
            $result['success'] ? 'info' : 'error',
            'execute_action_job',
            $result['success'] ? 'action_executed' : 'action_failed',
            [
                'action_id' => $action_id,
                'message'   => $result['message'],
            ]
        );
    }
}
