<?php
/**
 * Audit Logger.
 *
 * Structured logging to the agent_log table. Every significant event in the
 * plugin is recorded here for debugging, compliance, and review.
 *
 * @package Tossa\Agent\Audit
 */

declare(strict_types=1);

namespace Tossa\Agent\Audit;

final class AuditLogger {

    /**
     * Log an event.
     *
     * @param string $level   'info' | 'warning' | 'error' | 'debug'
     * @param string $source  Component that generated the event.
     * @param string $event   Event name (e.g., 'audit_started', 'action_executed').
     * @param array  $context Additional structured data.
     */
    public function log(
        string $level,
        string $source,
        string $event,
        array $context = [],
        ?string $request_id = null,
        ?string $correlation_id = null,
    ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'tossa_agent_log',
            [
                'request_id'     => $request_id,
                'correlation_id' => $correlation_id,
                'created_at'     => current_time('mysql'),
                'level'          => $level,
                'source'         => $source,
                'event'          => $event,
                'message'        => $context['message'] ?? null,
                'context_json'   => ! empty($context) ? wp_json_encode($context) : null,
                'user_id'        => get_current_user_id() ?: null,
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'latency_ms'     => $context['latency_ms'] ?? null,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d']
        );
    }

    /**
     * Query log entries with filters.
     *
     * @param array $filters Optional filters: level, source, event, since, until, limit, offset.
     * @return array{entries: array, total: int}
     */
    public function query(array $filters = []): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tossa_agent_log';
        $where = '1=1';
        $args  = [];

        if (! empty($filters['level'])) {
            $where .= ' AND level = %s';
            $args[] = $filters['level'];
        }

        if (! empty($filters['source'])) {
            $where .= ' AND source = %s';
            $args[] = $filters['source'];
        }

        if (! empty($filters['event'])) {
            $where .= ' AND event = %s';
            $args[] = $filters['event'];
        }

        if (! empty($filters['since'])) {
            $where .= ' AND created_at >= %s';
            $args[] = $filters['since'];
        }

        if (! empty($filters['until'])) {
            $where .= ' AND created_at <= %s';
            $args[] = $filters['until'];
        }

        $limit  = min((int) ($filters['limit'] ?? 100), 500);
        $offset = (int) ($filters['offset'] ?? 0);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = empty($args)
            ? (int) $wpdb->get_var($count_sql)
            : (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$args));

        $query_args   = array_merge($args, [$limit, $offset]);
        $entries_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $entries      = $wpdb->get_results($wpdb->prepare($entries_sql, ...$query_args), ARRAY_A);

        return [
            'entries' => $entries,
            'total'   => $total,
        ];
    }

    /**
     * Purge log entries older than a given number of days.
     *
     * @param int $days Keep entries newer than this many days.
     * @return int Number of rows deleted.
     */
    public function purge(int $days = 90): int {
        global $wpdb;

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tossa_agent_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}
