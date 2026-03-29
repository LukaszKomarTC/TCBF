<?php
/**
 * SEO Action Repository.
 *
 * Query interface for the seo_actions table.
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

namespace Tossa\Agent\Database;

final class SEOActionRepository {

    /**
     * Get an action by its ID.
     */
    public function find(string $action_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE action_id = %s",
                $action_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get actions by audit with optional status filter.
     *
     * @return array[]
     */
    public function by_audit(string $audit_id, ?string $status = null): array {
        global $wpdb;

        $sql = "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE audit_id = %s";
        $args = [$audit_id];

        if ($status) {
            $sql .= ' AND status = %s';
            $args[] = $status;
        }

        $sql .= ' ORDER BY safety_tier ASC, confidence DESC';

        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
    }

    /**
     * Get actions by entity (for page-focus view).
     *
     * @return array[]
     */
    public function by_entity(string $entity_type, int $entity_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions
                 WHERE entity_type = %s AND entity_id = %d
                 ORDER BY created_at DESC",
                $entity_type,
                $entity_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count actions by status.
     *
     * @return array<string, int>
     */
    public function count_by_status(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}tossa_seo_actions GROUP BY status",
            ARRAY_A
        );

        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Count actions by safety tier (for proposed actions only).
     *
     * @return array<string, int>
     */
    public function count_by_tier(?string $status = 'proposed'): array {
        global $wpdb;

        $sql = "SELECT safety_tier, COUNT(*) as count FROM {$wpdb->prefix}tossa_seo_actions";
        $args = [];

        if ($status) {
            $sql .= ' WHERE status = %s';
            $args[] = $status;
        }

        $sql .= ' GROUP BY safety_tier';

        $rows = empty($args)
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[$row['safety_tier']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get actions pending render verification.
     *
     * @return array[]
     */
    public function pending_verification(int $limit = 20): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions
                 WHERE status = 'executed' AND verified_at IS NULL
                 ORDER BY executed_at ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }
}
