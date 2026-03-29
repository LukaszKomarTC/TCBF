<?php
/**
 * SEO Controller.
 *
 * Orchestrates the SEO audit lifecycle: inventory collection, sending data to
 * VPS, receiving analysis results, storing actions, and executing approved ones.
 *
 * @package Tossa\Agent\SEO
 */

declare(strict_types=1);

namespace Tossa\Agent\SEO;

use Tossa\Agent\Audit\AuditLogger;
use Tossa\Agent\Policy\PolicyEngine;

final class SEOController {

    private PolicyEngine $policy;
    private AuditLogger $logger;
    private SEOPressAdapter $seopress;
    private ContentInventory $inventory;
    private MetadataWriter $writer;
    private RenderVerifier $verifier;

    public function __construct(PolicyEngine $policy, AuditLogger $logger) {
        $this->policy    = $policy;
        $this->logger    = $logger;
        $this->seopress  = new SEOPressAdapter();
        $this->inventory = new ContentInventory($this->seopress);
        $this->writer    = new MetadataWriter($this->seopress, $this->logger);
        $this->verifier  = new RenderVerifier();
    }

    /**
     * Start a new SEO audit.
     *
     * Collects inventory and sends it to the VPS agent for analysis.
     *
     * @param string $source 'manual' or 'scheduled'.
     * @return array{audit_id: string, status: string, page_count: int}
     */
    public function start_audit(string $source = 'manual'): array {
        $audit_id = wp_generate_uuid4();

        $this->logger->log('info', 'seo_controller', 'audit_started', [
            'audit_id' => $audit_id,
            'source'   => $source,
        ]);

        // Set transient so admin bar shows audit status.
        set_transient('tossa_agent_audit_running', $audit_id, 30 * MINUTE_IN_SECONDS);

        // Collect site inventory.
        $inventory = $this->inventory->collect();

        // Store audit record.
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'tossa_seo_audits',
            [
                'audit_id'    => $audit_id,
                'created_at'  => current_time('mysql'),
                'source'      => $source,
                'status'      => 'collecting',
                'input_hash'  => hash('sha256', wp_json_encode($inventory)),
                'issue_count' => 0,
                'action_count' => 0,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d']
        );

        // Send to VPS agent.
        $sent = $this->send_to_vps($audit_id, $inventory);

        if (! $sent) {
            $wpdb->update(
                $wpdb->prefix . 'tossa_seo_audits',
                ['status' => 'failed'],
                ['audit_id' => $audit_id],
                ['%s'],
                ['%s']
            );

            delete_transient('tossa_agent_audit_running');

            $this->logger->log('error', 'seo_controller', 'audit_send_failed', [
                'audit_id' => $audit_id,
            ]);
        }

        return [
            'audit_id'   => $audit_id,
            'status'     => $sent ? 'sent' : 'failed',
            'page_count' => count($inventory['pages']),
        ];
    }

    /**
     * Receive audit results from VPS agent.
     *
     * @param string $audit_id The audit this result belongs to.
     * @param array  $result   Structured analysis from Claude.
     */
    public function receive_results(string $audit_id, array $result): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tossa_seo_audits';

        // Update audit record.
        $wpdb->update(
            $table,
            [
                'status'         => 'completed',
                'summary'        => $result['summary'] ?? '',
                'overall_score'  => $result['overall_score'] ?? null,
                'issue_count'    => count($result['issues'] ?? []),
                'action_count'   => count($result['actions'] ?? []),
                'provider_model' => $result['model'] ?? '',
                'tokens_in'      => $result['tokens_in'] ?? 0,
                'tokens_out'     => $result['tokens_out'] ?? 0,
                'cost_usd'       => $result['cost_usd'] ?? 0,
                'raw_response'   => wp_json_encode($result),
            ],
            ['audit_id' => $audit_id],
            ['%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%f', '%s'],
            ['%s']
        );

        // Store individual actions.
        $actions_table = $wpdb->prefix . 'tossa_seo_actions';
        foreach ($result['actions'] ?? [] as $action) {
            // Run through policy engine.
            $verdict = $this->policy->evaluate($action);

            $wpdb->insert(
                $actions_table,
                [
                    'action_id'       => $action['action_id'] ?? wp_generate_uuid4(),
                    'audit_id'        => $audit_id,
                    'created_at'      => current_time('mysql'),
                    'entity_type'     => $action['entity_type'] ?? 'post',
                    'entity_id'       => $action['entity_id'] ?? null,
                    'action_type'     => $action['action_type'] ?? '',
                    'safety_tier'     => $verdict['effective_tier'],
                    'evidence_source' => $action['evidence_source'] ?? null,
                    'rationale'       => $action['rationale'] ?? '',
                    'confidence'      => $action['confidence'] ?? null,
                    'expected_impact' => $action['expected_impact'] ?? null,
                    'target_metric'   => $action['target_metric'] ?? null,
                    'payload_json'    => wp_json_encode($action['payload'] ?? []),
                    'before_json'     => wp_json_encode($action['before'] ?? []),
                    'status'          => $verdict['allowed'] ? 'proposed' : 'rejected',
                    'error_message'   => $verdict['allowed'] ? null : $verdict['reason'],
                ],
                ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }

        delete_transient('tossa_agent_audit_running');

        $this->logger->log('info', 'seo_controller', 'audit_completed', [
            'audit_id'     => $audit_id,
            'actions'      => count($result['actions'] ?? []),
            'overall_score' => $result['overall_score'] ?? null,
        ]);
    }

    /**
     * Execute a single approved action.
     *
     * @param string $action_id The action to execute.
     * @return array{success: bool, message: string, verified: bool|null}
     */
    public function execute_action(string $action_id): array {
        return $this->writer->execute($action_id);
    }

    /**
     * Rollback a previously executed action.
     *
     * @param string $action_id The action to rollback.
     * @return array{success: bool, message: string}
     */
    public function rollback_action(string $action_id): array {
        return $this->writer->rollback($action_id);
    }

    /**
     * Get the SEOPress adapter (for REST endpoints that need it).
     */
    public function adapter(): SEOPressAdapter {
        return $this->seopress;
    }

    /**
     * Send inventory data to VPS agent via signed webhook.
     */
    private function send_to_vps(string $audit_id, array $inventory): bool {
        $vps_url = get_option('tossa_agent_vps_url', '');

        if (empty($vps_url)) {
            $this->logger->log('warning', 'seo_controller', 'vps_url_not_configured', [
                'audit_id' => $audit_id,
            ]);
            return false;
        }

        $payload = wp_json_encode([
            'audit_id'  => $audit_id,
            'inventory' => $inventory,
            'objectives' => $this->get_seo_objectives(),
        ]);

        $timestamp = time();
        $nonce     = wp_generate_uuid4();
        $secret    = get_option('tossa_agent_webhook_secret', '');
        $signature = hash_hmac('sha256', $timestamp . $nonce . $payload, $secret);

        $response = wp_remote_post(
            trailingslashit($vps_url) . 'api/seo-audit',
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type'          => 'application/json',
                    'Authorization'         => 'Bearer ' . $secret,
                    'X-Tossa-Timestamp'     => (string) $timestamp,
                    'X-Tossa-Nonce'         => $nonce,
                    'X-Tossa-Signature'     => $signature,
                    'X-Tossa-Schema-Version' => '1.0',
                ],
                'body' => $payload,
            ]
        );

        if (is_wp_error($response)) {
            $this->logger->log('error', 'seo_controller', 'vps_request_failed', [
                'audit_id' => $audit_id,
                'error'    => $response->get_error_message(),
            ]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    /**
     * Get stored SEO objectives for the audit prompt.
     */
    private function get_seo_objectives(): array {
        return [
            'primary_keywords'   => get_option('tossa_agent_primary_keywords', ''),
            'secondary_keywords' => get_option('tossa_agent_secondary_keywords', ''),
            'target_audience'    => get_option('tossa_agent_target_audience', ''),
            'seo_goal'           => get_option('tossa_agent_seo_goal', ''),
            'geo_target'         => get_option('tossa_agent_geo_target', ''),
            'content_language'   => get_option('tossa_agent_content_language', 'en'),
            'competitor_urls'    => get_option('tossa_agent_competitor_urls', ''),
            'brand_voice'        => get_option('tossa_agent_brand_voice', ''),
            'forbidden_claims'   => get_option('tossa_agent_forbidden_claims', []),
        ];
    }
}
