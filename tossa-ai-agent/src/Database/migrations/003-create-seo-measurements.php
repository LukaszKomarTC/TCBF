<?php
/**
 * Migration 003 — Create seo_measurements table.
 *
 * Tracks before/after metrics for executed actions so the dashboard
 * can show which changes actually improved performance.
 *
 * @package Tossa\Agent\Database
 */

declare(strict_types=1);

return static function (string $prefix, string $charset_collate): string {
    $table = $prefix . 'tossa_seo_measurements';

    return "CREATE TABLE {$table} (
        id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action_id               VARCHAR(50)     NOT NULL,
        measurement_window_start DATETIME       NOT NULL,
        measurement_window_end  DATETIME        NOT NULL,
        metric_name             VARCHAR(60)     NOT NULL,
        before_value            VARCHAR(255)    NULL,
        after_value             VARCHAR(255)    NULL,
        delta                   DECIMAL(10,4)   NULL,
        confidence              DECIMAL(3,2)    NULL,
        evidence_source         VARCHAR(50)     NULL,
        notes                   TEXT            NULL,
        created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_action (action_id),
        KEY idx_metric (metric_name),
        KEY idx_window (measurement_window_start, measurement_window_end)
    ) {$charset_collate};";
};
