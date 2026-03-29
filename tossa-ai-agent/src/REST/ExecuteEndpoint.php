<?php
/**
 * Execute Endpoint.
 *
 * Admin-facing REST endpoints for approving, executing, and rolling back
 * SEO actions. Authenticated via standard WordPress nonce (admin UI calls).
 *
 * POST /wp-json/tossa-agent/v1/actions/{action_id}/approve
 * POST /wp-json/tossa-agent/v1/actions/{action_id}/execute
 * POST /wp-json/tossa-agent/v1/actions/{action_id}/reject
 * POST /wp-json/tossa-agent/v1/actions/{action_id}/rollback
 * POST /wp-json/tossa-agent/v1/audit/start
 * GET  /wp-json/tossa-agent/v1/audit/{audit_id}
 * GET  /wp-json/tossa-agent/v1/actions
 *
 * @package Tossa\Agent\REST
 */

declare(strict_types=1);

namespace Tossa\Agent\REST;

use Tossa\Agent\Audit\AuditLogger;
use Tossa\Agent\Policy\PolicyEngine;
use Tossa\Agent\SEO\SEOController;

final class ExecuteEndpoint {

    private PolicyEngine $policy;
    private AuditLogger $logger;
    private SEOController $seo;

    public function __construct(PolicyEngine $policy, AuditLogger $logger, SEOController $seo) {
        $this->policy = $policy;
        $this->logger = $logger;
        $this->seo    = $seo;
    }

    /**
     * Register all admin-facing REST routes.
     */
    public function register(): void {
        $namespace = 'tossa-agent/v1';

        // Start a new audit.
        register_rest_route($namespace, '/audit/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start_audit'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        // Get audit details.
        register_rest_route($namespace, '/audit/(?P<audit_id>[a-f0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_audit'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        // List actions (with filters).
        register_rest_route($namespace, '/actions', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_actions'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        // Action state changes.
        register_rest_route($namespace, '/actions/(?P<action_id>[a-zA-Z0-9-]+)/approve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'approve_action'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        register_rest_route($namespace, '/actions/(?P<action_id>[a-zA-Z0-9-]+)/reject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'reject_action'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        register_rest_route($namespace, '/actions/(?P<action_id>[a-zA-Z0-9-]+)/execute', [
            'methods'             => 'POST',
            'callback'            => [$this, 'execute_action'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        register_rest_route($namespace, '/actions/(?P<action_id>[a-zA-Z0-9-]+)/rollback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rollback_action'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        // Dashboard stats.
        register_rest_route($namespace, '/dashboard/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'dashboard_stats'],
            'permission_callback' => [$this, 'check_admin'],
        ]);
    }

    /**
     * Permission check: current user must be admin.
     */
    public function check_admin(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Start a new SEO audit.
     */
    public function start_audit(\WP_REST_Request $request): \WP_REST_Response {
        if ($this->policy->is_killed()) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Kill switch is active.',
            ], 503);
        }

        $result = $this->seo->start_audit('manual');

        $this->logger->log('info', 'execute_endpoint', 'audit_triggered', [
            'audit_id' => $result['audit_id'],
            'user_id'  => get_current_user_id(),
        ]);

        return new \WP_REST_Response($result);
    }

    /**
     * Get audit details and its actions.
     */
    public function get_audit(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $audit_id = $request->get_param('audit_id');

        $audit = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_audits WHERE audit_id = %s",
                $audit_id
            ),
            ARRAY_A
        );

        if (! $audit) {
            return new \WP_REST_Response(['message' => 'Audit not found.'], 404);
        }

        $actions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE audit_id = %s ORDER BY safety_tier ASC, confidence DESC",
                $audit_id
            ),
            ARRAY_A
        );

        $audit['actions'] = $actions;

        return new \WP_REST_Response($audit);
    }

    /**
     * List actions with optional filters.
     */
    public function list_actions(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $table = $wpdb->prefix . 'tossa_seo_actions';
        $where = '1=1';
        $args  = [];

        if ($status = $request->get_param('status')) {
            $where .= ' AND status = %s';
            $args[] = $status;
        }

        if ($tier = $request->get_param('tier')) {
            $where .= ' AND safety_tier = %s';
            $args[] = $tier;
        }

        if ($audit_id = $request->get_param('audit_id')) {
            $where .= ' AND audit_id = %s';
            $args[] = $audit_id;
        }

        $limit  = min((int) ($request->get_param('per_page') ?: 50), 100);
        $offset = (int) ($request->get_param('offset') ?: 0);

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;

        $actions = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        $total   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...array_slice($args, 0, -2)));

        return new \WP_REST_Response([
            'actions' => $actions,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
        ]);
    }

    /**
     * Approve an action (changes status from 'proposed' to 'approved').
     */
    public function approve_action(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $action_id = $request->get_param('action_id');
        $table     = $wpdb->prefix . 'tossa_seo_actions';

        $updated = $wpdb->update(
            $table,
            [
                'status'      => 'approved',
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql'),
            ],
            [
                'action_id' => $action_id,
                'status'    => 'proposed',
            ]
        );

        if (! $updated) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Action not found or not in proposed status.',
            ], 400);
        }

        $this->logger->log('info', 'execute_endpoint', 'action_approved', [
            'action_id' => $action_id,
            'user_id'   => get_current_user_id(),
        ]);

        return new \WP_REST_Response(['success' => true, 'message' => 'Action approved.']);
    }

    /**
     * Reject an action.
     */
    public function reject_action(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $action_id = $request->get_param('action_id');
        $reason    = $request->get_param('reason') ?? '';

        $updated = $wpdb->update(
            $wpdb->prefix . 'tossa_seo_actions',
            [
                'status'        => 'rejected',
                'approved_by'   => get_current_user_id(),
                'approved_at'   => current_time('mysql'),
                'error_message' => sanitize_text_field($reason),
            ],
            [
                'action_id' => $action_id,
                'status'    => 'proposed',
            ]
        );

        if (! $updated) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Action not found or not in proposed status.',
            ], 400);
        }

        return new \WP_REST_Response(['success' => true, 'message' => 'Action rejected.']);
    }

    /**
     * Execute an approved action.
     */
    public function execute_action(\WP_REST_Request $request): \WP_REST_Response {
        if ($this->policy->is_killed()) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Kill switch is active.',
            ], 503);
        }

        $action_id = $request->get_param('action_id');
        $result    = $this->seo->execute_action($action_id);

        return new \WP_REST_Response($result, $result['success'] ? 200 : 400);
    }

    /**
     * Rollback an executed action.
     */
    public function rollback_action(\WP_REST_Request $request): \WP_REST_Response {
        $action_id = $request->get_param('action_id');
        $result    = $this->seo->rollback_action($action_id);

        return new \WP_REST_Response($result, $result['success'] ? 200 : 400);
    }

    /**
     * Dashboard statistics.
     */
    public function dashboard_stats(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $audits_table  = $wpdb->prefix . 'tossa_seo_audits';
        $actions_table = $wpdb->prefix . 'tossa_seo_actions';

        $latest_audit = $wpdb->get_row("SELECT * FROM {$audits_table} ORDER BY created_at DESC LIMIT 1", ARRAY_A);

        $action_stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$actions_table} GROUP BY status",
            ARRAY_A
        );

        $tier_stats = $wpdb->get_results(
            "SELECT safety_tier, COUNT(*) as count FROM {$actions_table} WHERE status = 'proposed' GROUP BY safety_tier",
            ARRAY_A
        );

        $total_audits = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$audits_table}");
        $total_cost   = (float) $wpdb->get_var("SELECT SUM(cost_usd) FROM {$audits_table}");

        return new \WP_REST_Response([
            'latest_audit'  => $latest_audit,
            'action_stats'  => $action_stats,
            'tier_stats'    => $tier_stats,
            'total_audits'  => $total_audits,
            'total_cost'    => round($total_cost, 4),
            'kill_switch'   => (bool) get_option('tossa_agent_kill_switch', false),
            'execution_mode' => get_option('tossa_agent_execution_mode', 'propose-and-approve'),
        ]);
    }
}
