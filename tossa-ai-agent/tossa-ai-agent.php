<?php
/**
 * Plugin Name: Tossa AI Agent
 * Plugin URI:  https://github.com/LukaszKomarTC/TCBF
 * Description: AI-assisted SEO operations platform for Tossa Cycling. Uses SEOPress as the SEO surface, Claude on a VPS as the reasoning layer, and this plugin as the control/approval/audit layer.
 * Version:     0.1.0
 * Author:      Tossa Cycling
 * Author URI:  https://tossacycling.com
 * License:     GPL-2.0-or-later
 * Text Domain: tossa-ai-agent
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.0
 *
 * @package Tossa\Agent
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('TOSSA_AGENT_VERSION', '0.1.0');
define('TOSSA_AGENT_FILE', __FILE__);
define('TOSSA_AGENT_DIR', plugin_dir_path(__FILE__));
define('TOSSA_AGENT_URL', plugin_dir_url(__FILE__));
define('TOSSA_AGENT_BASENAME', plugin_basename(__FILE__));

// PSR-4 autoloader via Composer.
$autoloader = TOSSA_AGENT_DIR . 'vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

/**
 * Boot the plugin.
 */
function tossa_agent_boot(): void {
    // Minimum dependency check: SEOPress must be active.
    if (! tossa_agent_check_dependencies()) {
        return;
    }

    $plugin = new \Tossa\Agent\Plugin();
    $plugin->init();
}
add_action('plugins_loaded', 'tossa_agent_boot');

/**
 * Activation hook — run migrations.
 */
function tossa_agent_activate(): void {
    if (! class_exists(\Tossa\Agent\Database\Migrator::class)) {
        return;
    }

    $migrator = new \Tossa\Agent\Database\Migrator();
    $migrator->run();

    // Schedule recurring audit job if Action Scheduler is available.
    if (function_exists('as_schedule_recurring_action') && false === as_next_scheduled_action('tossa_agent_weekly_audit')) {
        as_schedule_recurring_action(time(), WEEK_IN_SECONDS, 'tossa_agent_weekly_audit', [], 'tossa-ai-agent');
    }

    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'tossa_agent_activate');

/**
 * Deactivation hook — unschedule jobs.
 */
function tossa_agent_deactivate(): void {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('tossa_agent_weekly_audit');
        as_unschedule_all_actions('tossa_agent_execute_action');
        as_unschedule_all_actions('tossa_agent_measure_impact');
    }

    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'tossa_agent_deactivate');

/**
 * Verify required plugins are active.
 */
function tossa_agent_check_dependencies(): bool {
    $missing = [];

    // SEOPress is required.
    if (! defined('SEOPRESS_VERSION') && ! function_exists('seopress_init')) {
        $missing[] = 'SEOPress';
    }

    if (! empty($missing)) {
        add_action('admin_notices', function () use ($missing) {
            $list = implode(', ', $missing);
            printf(
                '<div class="notice notice-error"><p><strong>Tossa AI Agent</strong> requires the following plugins: %s</p></div>',
                esc_html($list)
            );
        });
        return false;
    }

    return true;
}
