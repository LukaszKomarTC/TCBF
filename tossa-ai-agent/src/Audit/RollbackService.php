<?php
/**
 * Rollback Service.
 *
 * Handles rollback logic for different types of SEO actions:
 * - Field-level: direct metadata restoration via SEOPressAdapter.
 * - Content-level: uses WordPress revision system (v1.5+).
 *
 * @package Tossa\Agent\Audit
 */

declare(strict_types=1);

namespace Tossa\Agent\Audit;

use Tossa\Agent\SEO\SEOPressAdapter;

final class RollbackService {

    private SEOPressAdapter $seopress;
    private AuditLogger $logger;

    public function __construct(SEOPressAdapter $seopress, AuditLogger $logger) {
        $this->seopress = $seopress;
        $this->logger   = $logger;
    }

    /**
     * Rollback a single action.
     *
     * @return array{success: bool, message: string}
     */
    public function rollback(string $action_id): array {
        global $wpdb;

        $action = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tossa_seo_actions WHERE action_id = %s",
                $action_id
            ),
            ARRAY_A
        );

        if (! $action) {
            return ['success' => false, 'message' => 'Action not found.'];
        }

        if ('executed' !== $action['status']) {
            return ['success' => false, 'message' => "Cannot rollback action in '{$action['status']}' status."];
        }

        $rollback_data = json_decode($action['rollback_json'] ?? '', true);

        if (empty($rollback_data)) {
            return ['success' => false, 'message' => 'No rollback data available for this action.'];
        }

        $entity_id = (int) $action['entity_id'];
        $errors    = [];

        // Rollback each field to its previous state.
        foreach ($rollback_data as $field => $info) {
            // Support both new format {value, existed} and legacy format (plain value).
            if (is_array($info) && array_key_exists('value', $info)) {
                $success = $this->seopress->rollback_field($entity_id, $field, $info['value'], $info['existed'] ?? true);
            } else {
                $success = $this->seopress->rollback_field($entity_id, $field, $info);
            }

            if (! $success) {
                $errors[] = "Failed to rollback field: {$field}";
            }
        }

        if (! empty($errors)) {
            $this->logger->log('error', 'rollback_service', 'partial_rollback', [
                'action_id' => $action_id,
                'errors'    => $errors,
            ]);

            return [
                'success' => false,
                'message' => 'Partial rollback: ' . implode('; ', $errors),
            ];
        }

        // Mark action as rolled back.
        $wpdb->update(
            $wpdb->prefix . 'tossa_seo_actions',
            [
                'status'         => 'rolled_back',
                'rolled_back_at' => current_time('mysql'),
            ],
            ['action_id' => $action_id]
        );

        $this->logger->log('info', 'rollback_service', 'action_rolled_back', [
            'action_id' => $action_id,
            'entity_id' => $entity_id,
            'fields'    => array_keys($rollback_data),
        ]);

        return ['success' => true, 'message' => 'Action rolled back successfully.'];
    }

    /**
     * Bulk rollback all actions from a specific audit.
     *
     * Only rolls back actions that are in 'executed' status.
     *
     * @return array{success: bool, rolled_back: int, failed: int, messages: string[]}
     */
    public function rollback_audit(string $audit_id): array {
        global $wpdb;

        $actions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action_id FROM {$wpdb->prefix}tossa_seo_actions WHERE audit_id = %s AND status = 'executed'",
                $audit_id
            ),
            ARRAY_A
        );

        $rolled_back = 0;
        $failed      = 0;
        $messages    = [];

        foreach ($actions ?: [] as $action) {
            $result = $this->rollback($action['action_id']);

            if ($result['success']) {
                $rolled_back++;
            } else {
                $failed++;
                $messages[] = "{$action['action_id']}: {$result['message']}";
            }
        }

        return [
            'success'     => $failed === 0,
            'rolled_back' => $rolled_back,
            'failed'      => $failed,
            'messages'    => $messages,
        ];
    }
}
