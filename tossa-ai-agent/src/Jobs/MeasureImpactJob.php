<?php
/**
 * Measure Impact Job.
 *
 * Scheduled to run after an action is executed to capture post-execution
 * metrics and store them in the measurements table.
 *
 * @package Tossa\Agent\Jobs
 */

declare(strict_types=1);

namespace Tossa\Agent\Jobs;

use Tossa\Agent\Audit\AuditLogger;
use Tossa\Agent\SEO\RenderVerifier;
use Tossa\Agent\SEO\SEOPressAdapter;

final class MeasureImpactJob {

    /**
     * Handle impact measurement for an executed action.
     *
     * @param string $action_id The action to measure.
     */
    public static function handle(string $action_id): void {
        global $wpdb;

        $logger = new AuditLogger();

        $action = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE action_id = %s AND status = 'executed'",
                $action_id
            ),
            ARRAY_A
        );

        if (! $action) {
            return;
        }

        // Idempotency: skip if already verified.
        if (! empty($action['verified_at'])) {
            return;
        }

        $entity_id = (int) $action['entity_id'];

        if (! $entity_id) {
            return;
        }

        // Render verification.
        $verifier = new RenderVerifier();
        $seopress = new SEOPressAdapter();
        $current  = $seopress->get_meta($entity_id);

        $expected = [];
        $after    = json_decode($action['after_json'] ?? '', true) ?: [];
        if (! empty($after['title'])) {
            $expected['title'] = $after['title'];
        }
        if (! empty($after['description'])) {
            $expected['description'] = $after['description'];
        }
        if (! empty($after['canonical'])) {
            $expected['canonical'] = $after['canonical'];
        }

        $verification = $verifier->verify($entity_id, $expected);

        // Update action verification status.
        $wpdb->update(
            $wpdb->prefix . 'tossa_seo_actions',
            [
                'verified_at'    => current_time('mysql'),
                'verification_ok' => $verification['verified'] ? 1 : 0,
            ],
            ['action_id' => $action_id]
        );

        if (! $verification['verified']) {
            $logger->log('warning', 'measure_impact_job', 'render_verification_failed', [
                'action_id' => $action_id,
                'entity_id' => $entity_id,
                'issues'    => $verification['issues'],
            ]);
        }

        // Store render verification as a measurement.
        $wpdb->insert(
            $wpdb->prefix . 'tossa_seo_measurements',
            [
                'action_id'                 => $action_id,
                'measurement_window_start'  => $action['executed_at'],
                'measurement_window_end'    => current_time('mysql'),
                'metric_name'               => 'render_verification',
                'before_value'              => null,
                'after_value'               => $verification['verified'] ? 'pass' : 'fail',
                'confidence'                => 1.00,
                'evidence_source'           => 'render_verifier',
                'notes'                     => ! empty($verification['issues']) ? wp_json_encode($verification['issues']) : null,
                'created_at'                => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s']
        );

        $logger->log('info', 'measure_impact_job', 'measurement_recorded', [
            'action_id'   => $action_id,
            'entity_id'   => $entity_id,
            'verified'    => $verification['verified'],
        ]);
    }
}
