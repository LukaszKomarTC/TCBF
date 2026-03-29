<?php
/**
 * Measurement Repository.
 *
 * Query interface for the seo_measurements table.
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

namespace Tossa\Agent\Database;

final class MeasurementRepository {

    /**
     * Get all measurements for an action.
     *
     * @return array[]
     */
    public function by_action(string $action_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_measurements WHERE action_id = %s ORDER BY created_at DESC",
                $action_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Insert a measurement.
     */
    public function insert(array $data): bool {
        global $wpdb;

        return (bool) $wpdb->insert(
            $wpdb->prefix . 'tossa_seo_measurements',
            [
                'action_id'                 => $data['action_id'],
                'measurement_window_start'  => $data['window_start'],
                'measurement_window_end'    => $data['window_end'],
                'metric_name'               => $data['metric_name'],
                'before_value'              => $data['before_value'] ?? null,
                'after_value'               => $data['after_value'] ?? null,
                'delta'                     => $data['delta'] ?? null,
                'confidence'                => $data['confidence'] ?? null,
                'evidence_source'           => $data['evidence_source'] ?? null,
                'notes'                     => $data['notes'] ?? null,
                'created_at'                => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s']
        );
    }

    /**
     * Get measurement summary for an audit's actions.
     *
     * @return array<string, array{improved: int, unchanged: int, regressed: int}>
     */
    public function audit_impact_summary(string $audit_id): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.metric_name, m.delta
                 FROM {$wpdb->prefix}tossa_seo_measurements m
                 INNER JOIN {$wpdb->prefix}tossa_seo_actions a ON m.action_id = a.action_id
                 WHERE a.audit_id = %s AND m.delta IS NOT NULL",
                $audit_id
            ),
            ARRAY_A
        );

        $summary = [];
        foreach ($rows ?: [] as $row) {
            $metric = $row['metric_name'];
            if (! isset($summary[$metric])) {
                $summary[$metric] = ['improved' => 0, 'unchanged' => 0, 'regressed' => 0];
            }

            $delta = (float) $row['delta'];
            if ($delta > 0) {
                $summary[$metric]['improved']++;
            } elseif ($delta < 0) {
                $summary[$metric]['regressed']++;
            } else {
                $summary[$metric]['unchanged']++;
            }
        }

        return $summary;
    }
}
