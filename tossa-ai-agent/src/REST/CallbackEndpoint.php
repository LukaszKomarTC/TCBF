<?php
/**
 * Callback Endpoint.
 *
 * Receives audit results and action proposals from the VPS agent service.
 *
 * POST /wp-json/tossa-agent/v1/callback
 *
 * @package Tossa\Agent\REST
 */

declare(strict_types=1);

namespace Tossa\Agent\REST;

use Tossa\Agent\Audit\AuditLogger;
use Tossa\Agent\Policy\PolicyEngine;
use Tossa\Agent\SEO\SEOController;

final class CallbackEndpoint {

    private PolicyEngine $policy;
    private AuditLogger $logger;
    private SEOController $seo;

    public function __construct(PolicyEngine $policy, AuditLogger $logger, SEOController $seo) {
        $this->policy = $policy;
        $this->logger = $logger;
        $this->seo    = $seo;
    }

    /**
     * Register the REST route.
     */
    public function register(): void {
        register_rest_route('tossa-agent/v1', '/callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [WebhookAuth::class, 'validate'],
        ]);
    }

    /**
     * Handle incoming callback from VPS agent.
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response {
        $body = $request->get_json_params();

        $type     = $body['type'] ?? '';
        $audit_id = $body['audit_id'] ?? '';

        $this->logger->log('info', 'callback_endpoint', 'received', [
            'type'     => $type,
            'audit_id' => $audit_id,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return match ($type) {
            'seo_audit_result' => $this->handle_audit_result($audit_id, $body),
            'status_update'    => $this->handle_status_update($audit_id, $body),
            'error'            => $this->handle_error($audit_id, $body),
            default            => new \WP_REST_Response([
                'success' => false,
                'message' => "Unknown callback type: {$type}",
            ], 400),
        };
    }

    /**
     * Handle a completed SEO audit result.
     */
    private function handle_audit_result(string $audit_id, array $body): \WP_REST_Response {
        if (empty($audit_id)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Missing audit_id.',
            ], 400);
        }

        $result = $body['result'] ?? [];

        $this->seo->receive_results($audit_id, $result);

        // Notify admin via email if configured.
        $notify = get_option('tossa_agent_email_notifications', false);
        if ($notify) {
            $admin_email = get_option('admin_email');
            $score       = $result['overall_score'] ?? 'N/A';
            $actions     = count($result['actions'] ?? []);

            wp_mail(
                $admin_email,
                sprintf('[Tossa AI] SEO Audit Complete — Score: %s', $score),
                sprintf(
                    "SEO audit %s completed.\n\nScore: %s\nActions proposed: %d\n\nReview: %s",
                    $audit_id,
                    $score,
                    $actions,
                    admin_url('admin.php?page=tossa-ai-agent-actions&audit_id=' . $audit_id)
                )
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Audit results received.',
        ]);
    }

    /**
     * Handle a status update (e.g., "analyzing", "enriching").
     */
    private function handle_status_update(string $audit_id, array $body): \WP_REST_Response {
        global $wpdb;

        $status = $body['status'] ?? '';

        if ($audit_id && $status) {
            $wpdb->update(
                $wpdb->prefix . 'tossa_seo_audits',
                ['status' => sanitize_text_field($status)],
                ['audit_id' => $audit_id],
                ['%s'],
                ['%s']
            );
        }

        return new \WP_REST_Response(['success' => true]);
    }

    /**
     * Handle an error from the VPS agent.
     */
    private function handle_error(string $audit_id, array $body): \WP_REST_Response {
        $message = $body['error'] ?? 'Unknown error';

        $this->logger->log('error', 'callback_endpoint', 'vps_error', [
            'audit_id' => $audit_id,
            'error'    => $message,
        ]);

        if ($audit_id) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'tossa_seo_audits',
                ['status' => 'failed'],
                ['audit_id' => $audit_id],
                ['%s'],
                ['%s']
            );

            delete_transient('tossa_agent_audit_running');
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Error logged.',
        ]);
    }
}
