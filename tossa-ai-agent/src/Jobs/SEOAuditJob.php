<?php
/**
 * SEO Audit Job.
 *
 * Handles scheduled and manual SEO audit triggers via Action Scheduler.
 *
 * @package Tossa\Agent\Jobs
 */

declare(strict_types=1);

namespace Tossa\Agent\Jobs;

use Tossa\Agent\Audit\AuditLogger;
use Tossa\Agent\Policy\PolicyEngine;
use Tossa\Agent\SEO\SEOController;

final class SEOAuditJob {

    /**
     * Handle the scheduled audit action.
     */
    public static function handle(): void {
        $policy = new PolicyEngine();
        $logger = new AuditLogger();

        if ($policy->is_killed()) {
            $logger->log('warning', 'seo_audit_job', 'skipped_kill_switch', [
                'message' => 'Scheduled audit skipped because kill switch is active.',
            ]);
            return;
        }

        // Idempotency: skip if an audit is already running.
        $running = get_transient('tossa_agent_audit_running');
        if ($running) {
            $logger->log('info', 'seo_audit_job', 'skipped_already_running', [
                'running_audit_id' => $running,
            ]);
            return;
        }

        $mode = $policy->get_execution_mode();
        if ('analysis-only' !== $mode && 'propose-and-approve' !== $mode && 'full' !== $mode) {
            return;
        }

        $seo    = new SEOController($policy, $logger);
        $result = $seo->start_audit('scheduled');

        $logger->log('info', 'seo_audit_job', 'scheduled_audit_triggered', [
            'audit_id'   => $result['audit_id'],
            'page_count' => $result['page_count'],
            'status'     => $result['status'],
        ]);
    }
}
