<?php
/**
 * Change Repository.
 *
 * Query interface for SEO actions and audits. Used by admin pages and
 * dashboard components.
 *
 * @package Tossa\Agent\Audit
 */

declare(strict_types=1);

namespace Tossa\Agent\Audit;

final class ChangeRepository {

    /**
     * Get a single action by its ID.
     */
    public function get_action(string $action_id): ?array {
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
     * Get all actions for an audit.
     *
     * @return array[]
     */
    public function get_actions_for_audit(string $audit_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE audit_id = %s ORDER BY safety_tier ASC, confidence DESC",
                $audit_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get pending actions (proposed, not yet approved/rejected).
     *
     * @return array[]
     */
    public function get_pending_actions(int $limit = 50): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE status = 'proposed' ORDER BY safety_tier ASC, confidence DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get actions that have been executed and are eligible for rollback.
     *
     * @return array[]
     */
    public function get_rollbackable_actions(int $limit = 50): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE status = 'executed' AND rollback_json IS NOT NULL ORDER BY executed_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get recent audits.
     *
     * @return array[]
     */
    public function get_recent_audits(int $limit = 10): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_audits ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get action counts grouped by status.
     *
     * @return array<string, int>
     */
    public function get_status_counts(): array {
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
     * Get actions for a specific entity (page/product/term).
     *
     * @return array[]
     */
    public function get_entity_history(string $entity_type, int $entity_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE entity_type = %s AND entity_id = %d ORDER BY created_at DESC",
                $entity_type,
                $entity_id
            ),
            ARRAY_A
        ) ?: [];
    }
}
