<?php
/**
 * Migration 002 — Create seo_actions table.
 *
 * Stores individual SEO actions proposed by the AI, their approval status,
 * execution state, before/after snapshots, and rollback payloads.
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

return static function (string $prefix, string $charset_collate): string {
    $table = $prefix . 'tossa_seo_actions';

    return "CREATE TABLE {$table} (
        id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action_id           VARCHAR(50)     NOT NULL,
        audit_id            VARCHAR(36)     NOT NULL,
        created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        entity_type         VARCHAR(30)     NOT NULL DEFAULT 'post',
        entity_id           BIGINT UNSIGNED NULL,
        action_type         VARCHAR(100)    NOT NULL,
        safety_tier         CHAR(1)         NOT NULL DEFAULT 'A',
        evidence_source     VARCHAR(50)     NULL,
        rationale           TEXT            NULL,
        confidence          DECIMAL(3,2)    NULL,
        expected_impact     VARCHAR(20)     NULL,
        target_metric       VARCHAR(60)     NULL,
        payload_json        LONGTEXT        NULL,
        before_json         LONGTEXT        NULL,
        after_json          LONGTEXT        NULL,
        rollback_json       LONGTEXT        NULL,
        status              VARCHAR(20)     NOT NULL DEFAULT 'proposed',
        approved_by         BIGINT UNSIGNED NULL,
        approved_at         DATETIME        NULL,
        executed_at         DATETIME        NULL,
        verified_at         DATETIME        NULL,
        verification_ok     TINYINT(1)      NULL,
        rolled_back_at      DATETIME        NULL,
        error_message       TEXT            NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_action_id (action_id),
        KEY idx_audit    (audit_id),
        KEY idx_status   (status),
        KEY idx_entity   (entity_type, entity_id),
        KEY idx_tier     (safety_tier)
    ) {$charset_collate};";
};
