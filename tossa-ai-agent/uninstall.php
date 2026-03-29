<?php
/**
 * Tossa AI Agent — Uninstall.
 *
 * Fired when the plugin is deleted from wp-admin.
 * Cleans up custom tables, options, scheduled actions, and transients.
 *
 * @package Tossa\Agent
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Drop custom tables.
$tables = [
    $wpdb->prefix . 'tossa_seo_audits',
    $wpdb->prefix . 'tossa_seo_actions',
    $wpdb->prefix . 'tossa_seo_measurements',
    $wpdb->prefix . 'tossa_agent_log',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Remove options.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tossa_agent_%'");

// Remove transients.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tossa_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tossa_%'");

// Unschedule Action Scheduler jobs (if AS tables still exist).
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('tossa_agent_weekly_audit');
    as_unschedule_all_actions('tossa_agent_execute_action');
    as_unschedule_all_actions('tossa_agent_measure_impact');
}
