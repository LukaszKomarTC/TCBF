<?php
/**
 * Metadata Writer.
 *
 * Executes approved SEO actions by writing metadata through the SEOPressAdapter,
 * then triggers render verification. Handles rollback of field-level changes.
 *
 * @package Tossa\Agent\SEO
 */

declare(strict_types=1);

namespace Tossa\Agent\SEO;

use Tossa\Agent\Audit\AuditLogger;

final class MetadataWriter {

    private SEOPressAdapter $seopress;
    private AuditLogger $logger;

    public function __construct(SEOPressAdapter $seopress, AuditLogger $logger) {
        $this->seopress = $seopress;
        $this->logger   = $logger;
    }

    /**
     * Execute a single action by its action_id.
     *
     * Only Tier A field-level actions are executed in v1.
     *
     * @return array{success: bool, message: string, verified: bool|null}
     */
    public function execute(string $action_id): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'tossa_seo_actions';

        // Atomic status transition: approved → executing.
        // This prevents duplicate execution if the job runs twice.
        $updated = $wpdb->update(
            $table,
            ['status' => 'executing'],
            ['action_id' => $action_id, 'status' => 'approved'],
            ['%s'],
            ['%s', '%s']
        );

        if (! $updated) {
            // Either not found, or not in 'approved' status (already executing/executed).
            $action = $wpdb->get_row(
                $wpdb->prepare("SELECT status FROM {$table} WHERE action_id = %s", $action_id),
                ARRAY_A
            );
            $current = $action['status'] ?? 'not found';
            return ['success' => false, 'message' => "Cannot execute: action status is '{$current}', expected 'approved'.", 'verified' => null];
        }

        $action = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE action_id = %s", $action_id),
            ARRAY_A
        );

        // Only execute Tier A actions automatically in v1.
        if ('A' !== $action['safety_tier']) {
            $wpdb->update($table, ['status' => 'approved'], ['action_id' => $action_id]);
            return ['success' => false, 'message' => 'Only Tier A actions can be auto-executed in v1.', 'verified' => null];
        }

        $entity_id = (int) $action['entity_id'];
        $payload   = json_decode($action['payload_json'], true) ?: [];

        // Capture before state.
        $before = $this->seopress->get_meta($entity_id);

        // Execute field writes.
        $fields_to_write = $this->extract_fields_from_payload($action['action_type'], $payload);

        if (empty($fields_to_write)) {
            $wpdb->update(
                $table,
                ['status' => 'failed', 'error_message' => 'No writable fields in payload.'],
                ['action_id' => $action_id]
            );
            return ['success' => false, 'message' => 'No writable fields in payload.', 'verified' => null];
        }

        $result = $this->seopress->set_fields($entity_id, $fields_to_write);

        if (! $result['success']) {
            $wpdb->update(
                $table,
                ['status' => 'failed', 'error_message' => 'One or more field writes failed.'],
                ['action_id' => $action_id]
            );

            $this->logger->log('error', 'metadata_writer', 'execution_failed', [
                'action_id' => $action_id,
                'results'   => $result['results'],
            ]);

            return ['success' => false, 'message' => 'Field write failed.', 'verified' => null];
        }

        // Capture after state.
        $after = $this->seopress->get_meta($entity_id);

        // Build rollback payload with field existence tracking.
        $rollback = [];
        foreach ($fields_to_write as $field => $value) {
            $field_result = $result['results'][$field] ?? [];
            $rollback[$field] = [
                'value'  => $field_result['previous_value'] ?? null,
                'existed' => $field_result['field_existed'] ?? true,
            ];
        }

        // Update action record.
        $wpdb->update(
            $table,
            [
                'status'        => 'executed',
                'executed_at'   => current_time('mysql'),
                'before_json'   => wp_json_encode($before),
                'after_json'    => wp_json_encode($after),
                'rollback_json' => wp_json_encode($rollback),
            ],
            ['action_id' => $action_id]
        );

        $this->logger->log('info', 'metadata_writer', 'action_executed', [
            'action_id' => $action_id,
            'entity_id' => $entity_id,
            'fields'    => array_keys($fields_to_write),
        ]);

        // Schedule render verification.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + 60, // Verify 1 minute after write (cache clearing).
                'tossa_agent_verify_render',
                ['action_id' => $action_id],
                'tossa-ai-agent'
            );
        }

        return [
            'success'  => true,
            'message'  => 'Action executed successfully.',
            'verified' => null, // Will be set after render verification.
        ];
    }

    /**
     * Rollback a previously executed action.
     *
     * @return array{success: bool, message: string}
     */
    public function rollback(string $action_id): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'tossa_seo_actions';
        $action = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE action_id = %s", $action_id),
            ARRAY_A
        );

        if (! $action) {
            return ['success' => false, 'message' => 'Action not found.'];
        }

        if ('executed' !== $action['status']) {
            return ['success' => false, 'message' => "Cannot rollback: status is '{$action['status']}'."];
        }

        $rollback_data = json_decode($action['rollback_json'], true);

        if (empty($rollback_data)) {
            return ['success' => false, 'message' => 'No rollback data available.'];
        }

        $entity_id = (int) $action['entity_id'];

        foreach ($rollback_data as $field => $info) {
            // Support both new format {value, existed} and legacy format (plain value).
            if (is_array($info) && array_key_exists('value', $info)) {
                $this->seopress->rollback_field($entity_id, $field, $info['value'], $info['existed'] ?? true);
            } else {
                $this->seopress->rollback_field($entity_id, $field, $info);
            }
        }

        $wpdb->update(
            $table,
            [
                'status'         => 'rolled_back',
                'rolled_back_at' => current_time('mysql'),
            ],
            ['action_id' => $action_id]
        );

        $this->logger->log('info', 'metadata_writer', 'action_rolled_back', [
            'action_id' => $action_id,
            'entity_id' => $entity_id,
            'fields'    => array_keys($rollback_data),
        ]);

        return ['success' => true, 'message' => 'Action rolled back successfully.'];
    }

    /**
     * Map action_type + payload to concrete field => value pairs.
     *
     * @return array<string, string>
     */
    private function extract_fields_from_payload(string $action_type, array $payload): array {
        return match ($action_type) {
            'set_meta_title'        => ['title' => $payload['title'] ?? ''],
            'set_meta_description'  => ['description' => $payload['description'] ?? ''],
            'set_canonical'         => ['canonical' => $payload['canonical'] ?? ''],
            'set_robots'            => array_filter([
                'noindex'  => $payload['noindex'] ?? null,
                'nofollow' => $payload['nofollow'] ?? null,
            ]),
            'set_social_title'      => array_filter([
                'social_fb_title' => $payload['fb_title'] ?? null,
                'social_tw_title' => $payload['tw_title'] ?? null,
            ]),
            'set_social_description' => array_filter([
                'social_fb_desc' => $payload['fb_description'] ?? null,
                'social_tw_desc' => $payload['tw_description'] ?? null,
            ]),
            'set_focus_keyword'     => ['focus_keyword' => $payload['keyword'] ?? ''],
            'set_noindex'           => ['noindex' => $payload['value'] ?? 'yes'],
            'set_nofollow'          => ['nofollow' => $payload['value'] ?? 'yes'],
            'fix_missing_meta'      => array_filter([
                'title'       => $payload['title'] ?? null,
                'description' => $payload['description'] ?? null,
            ]),
            default                 => [],
        };
    }
}
