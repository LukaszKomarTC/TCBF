<?php
/**
 * SEO Audit Repository.
 *
 * Query interface for the seo_audits table.
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

namespace Tossa\Agent\Database;

final class SEOAuditRepository {

    /**
     * Get an audit by its UUID.
     */
    public function find(string $audit_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_audits WHERE audit_id = %s",
                $audit_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get the most recent audit.
     */
    public function latest(): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}tossa_seo_audits ORDER BY created_at DESC LIMIT 1",
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get audits with pagination.
     *
     * @return array{audits: array[], total: int}
     */
    public function paginate(int $limit = 20, int $offset = 0): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tossa_seo_audits';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $audits = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        return ['audits' => $audits, 'total' => $total];
    }

    /**
     * Get score trend over time.
     *
     * @return array<array{date: string, score: int}>
     */
    public function score_trend(int $limit = 20): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) as date, overall_score as score
                 FROM {$wpdb->prefix}tossa_seo_audits
                 WHERE overall_score IS NOT NULL AND status = 'completed'
                 ORDER BY created_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get total cost across all audits.
     */
    public function total_cost(): float {
        global $wpdb;

        return (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM {$wpdb->prefix}tossa_seo_audits"
        );
    }
}
