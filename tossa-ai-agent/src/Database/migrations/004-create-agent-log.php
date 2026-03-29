<?php
/**
 * Migration 004 — Create agent_log table.
 *
 * Generic operational log for webhook callbacks, failures, retries,
 * auth checks, and execution traces.
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

return static function (string $prefix, string $charset_collate): string {
    $table = $prefix . 'tossa_agent_log';

    return "CREATE TABLE {$table} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id      VARCHAR(36)     NULL,
        correlation_id  VARCHAR(36)     NULL,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level           VARCHAR(10)     NOT NULL DEFAULT 'info',
        source          VARCHAR(50)     NOT NULL,
        event           VARCHAR(100)    NOT NULL,
        message         TEXT            NULL,
        context_json    LONGTEXT        NULL,
        user_id         BIGINT UNSIGNED NULL,
        ip_address      VARCHAR(45)     NULL,
        latency_ms      INT UNSIGNED    NULL,
        PRIMARY KEY  (id),
        KEY idx_level    (level),
        KEY idx_source   (source),
        KEY idx_event    (event),
        KEY idx_created  (created_at),
        KEY idx_request  (request_id),
        KEY idx_correlation (correlation_id)
    ) {$charset_collate};";
};
