<?php
/**
 * Plugin bootstrap.
 *
 * Registers all hooks, REST routes, admin pages, and scheduled jobs.
 *
 * @package Tossa\Agent
 */

declare(strict_types=1);

namespace Tossa\Agent;

use Tossa\Agent\Admin\SettingsPage;
use Tossa\Agent\Admin\DashboardPage;
use Tossa\Agent\Admin\ActionReviewPage;
use Tossa\Agent\Admin\LogsPage;
use Tossa\Agent\Audit\AuditLogger;
use Tossa\Agent\Database\Migrator;
use Tossa\Agent\Jobs\SEOAuditJob;
use Tossa\Agent\Jobs\ExecuteActionJob;
use Tossa\Agent\Jobs\MeasureImpactJob;
use Tossa\Agent\Policy\PolicyEngine;
use Tossa\Agent\REST\CallbackEndpoint;
use Tossa\Agent\REST\ExecuteEndpoint;
use Tossa\Agent\SEO\SEOController;

final class Plugin {

    private PolicyEngine $policy;
    private AuditLogger $logger;
    private SEOController $seo;

    /**
     * Wire everything together and register WordPress hooks.
     */
    public function init(): void {
        // Run pending migrations on upgrade.
        add_action('admin_init', [$this, 'maybe_migrate']);

        // Core services.
        $this->policy = new PolicyEngine();
        $this->logger = new AuditLogger();
        $this->seo    = new SEOController($this->policy, $this->logger);

        // REST API.
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Admin pages.
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Action Scheduler jobs.
        add_action('tossa_agent_weekly_audit', [SEOAuditJob::class, 'handle']);
        add_action('tossa_agent_execute_action', [ExecuteActionJob::class, 'handle'], 10, 1);
        add_action('tossa_agent_measure_impact', [MeasureImpactJob::class, 'handle'], 10, 1);

        // Admin-bar indicator when an audit is running.
        add_action('admin_bar_menu', [$this, 'admin_bar_status'], 100);
    }

    /**
     * Run DB migrations when plugin version changes.
     */
    public function maybe_migrate(): void {
        $installed = get_option('tossa_agent_db_version', '0');
        if (version_compare($installed, TOSSA_AGENT_VERSION, '<')) {
            (new Migrator())->run();
        }
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes(): void {
        $callback = new CallbackEndpoint($this->policy, $this->logger, $this->seo);
        $callback->register();

        $execute = new ExecuteEndpoint($this->policy, $this->logger, $this->seo);
        $execute->register();
    }

    /**
     * Register admin menu pages.
     */
    public function register_admin_pages(): void {
        // Top-level menu.
        add_menu_page(
            __('Tossa AI Agent', 'tossa-ai-agent'),
            __('Tossa AI', 'tossa-ai-agent'),
            'manage_options',
            'tossa-ai-agent',
            [DashboardPage::class, 'render'],
            'dashicons-superhero-alt',
            30
        );

        // Sub-pages.
        add_submenu_page(
            'tossa-ai-agent',
            __('Dashboard', 'tossa-ai-agent'),
            __('Dashboard', 'tossa-ai-agent'),
            'manage_options',
            'tossa-ai-agent',
            [DashboardPage::class, 'render']
        );

        add_submenu_page(
            'tossa-ai-agent',
            __('Action Review', 'tossa-ai-agent'),
            __('Action Review', 'tossa-ai-agent'),
            'manage_options',
            'tossa-ai-agent-actions',
            [ActionReviewPage::class, 'render']
        );

        add_submenu_page(
            'tossa-ai-agent',
            __('Settings', 'tossa-ai-agent'),
            __('Settings', 'tossa-ai-agent'),
            'manage_options',
            'tossa-ai-agent-settings',
            [SettingsPage::class, 'render']
        );

        add_submenu_page(
            'tossa-ai-agent',
            __('Logs', 'tossa-ai-agent'),
            __('Logs', 'tossa-ai-agent'),
            'manage_options',
            'tossa-ai-agent-logs',
            [LogsPage::class, 'render']
        );
    }

    /**
     * Enqueue admin CSS/JS on our pages only.
     */
    public function enqueue_admin_assets(string $hook): void {
        if (false === strpos($hook, 'tossa-ai-agent')) {
            return;
        }

        wp_enqueue_style(
            'tossa-agent-admin',
            TOSSA_AGENT_URL . 'assets/css/admin.css',
            [],
            TOSSA_AGENT_VERSION
        );

        wp_enqueue_script(
            'tossa-agent-admin',
            TOSSA_AGENT_URL . 'assets/js/admin.js',
            ['wp-api-fetch', 'wp-element', 'wp-components', 'wp-i18n'],
            TOSSA_AGENT_VERSION,
            true
        );

        wp_localize_script('tossa-agent-admin', 'tossaAgent', [
            'restUrl'  => rest_url('tossa-agent/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'version'  => TOSSA_AGENT_VERSION,
        ]);
    }

    /**
     * Show audit-running indicator in admin bar.
     */
    public function admin_bar_status(\WP_Admin_Bar $bar): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $running = get_transient('tossa_agent_audit_running');
        if ($running) {
            $bar->add_node([
                'id'    => 'tossa-agent-status',
                'title' => __('SEO Audit Running...', 'tossa-ai-agent'),
                'href'  => admin_url('admin.php?page=tossa-ai-agent'),
                'meta'  => ['class' => 'tossa-agent-running'],
            ]);
        }
    }
}
