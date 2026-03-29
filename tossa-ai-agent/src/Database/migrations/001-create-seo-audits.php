<?php
/**
 * Migration 001 — Create seo_audits table.
 *
 * Stores one row per SEO audit run (manual or scheduled).
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

return static function (string $prefix, string $charset_collate): string {
    $table = $prefix . 'tossa_seo_audits';

    return "CREATE TABLE {$table} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        audit_id        VARCHAR(36)     NOT NULL,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        source          VARCHAR(20)     NOT NULL DEFAULT 'manual',
        status          VARCHAR(20)     NOT NULL DEFAULT 'running',
        summary         TEXT            NULL,
        overall_score   TINYINT UNSIGNED NULL,
        issue_count     INT UNSIGNED    NULL DEFAULT 0,
        action_count    INT UNSIGNED    NULL DEFAULT 0,
        input_hash      VARCHAR(64)     NULL,
        provider_model  VARCHAR(50)     NULL,
        tokens_in       INT UNSIGNED    NULL DEFAULT 0,
        tokens_out      INT UNSIGNED    NULL DEFAULT 0,
        cost_usd        DECIMAL(8,4)    NULL DEFAULT 0.0000,
        raw_response    LONGTEXT        NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_audit_id (audit_id),
        KEY idx_created (created_at),
        KEY idx_status  (status)
    ) {$charset_collate};";
};
