<?php
/**
 * Action Review Page.
 *
 * Admin interface for reviewing, approving, rejecting, and executing
 * SEO actions proposed by the AI agent.
 *
 * @package Tossa\Agent\Admin
 */

declare(strict_types=1);

namespace Tossa\Agent\Admin;

use Tossa\Agent\Audit\ChangeRepository;
use Tossa\Agent\SEO\DiffRenderer;

final class ActionReviewPage {

    /**
     * Render the action review page.
     */
    public static function render(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $repo     = new ChangeRepository();
        $diff     = new DiffRenderer();
        $audit_id = sanitize_text_field($_GET['audit_id'] ?? '');
        $status   = sanitize_text_field($_GET['status'] ?? '');
        $tier     = sanitize_text_field($_GET['tier'] ?? '');

        // Fetch actions based on filters.
        global $wpdb;
        $table = $wpdb->prefix . 'tossa_seo_actions';
        $where = '1=1';
        $args  = [];

        if ($audit_id) {
            $where .= ' AND audit_id = %s';
            $args[] = $audit_id;
        }
        if ($status) {
            $where .= ' AND status = %s';
            $args[] = $status;
        }
        if ($tier) {
            $where .= ' AND safety_tier = %s';
            $args[] = $tier;
        }

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY safety_tier ASC, confidence DESC";
        $actions = empty($args)
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        $status_counts = $repo->get_status_counts();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Action Review', 'tossa-ai-agent'); ?></h1>

            <!-- Filters -->
            <div style="margin: 15px 0;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=tossa-ai-agent-actions')); ?>" class="button <?php echo empty($status) ? 'button-primary' : ''; ?>">
                    <?php esc_html_e('All', 'tossa-ai-agent'); ?>
                </a>
                <?php foreach (['proposed' => 'Pending', 'approved' => 'Approved', 'executed' => 'Executed', 'rejected' => 'Rejected', 'rolled_back' => 'Rolled Back'] as $s => $label): ?>
                    <a href="<?php echo esc_url(add_query_arg('status', $s)); ?>" class="button <?php echo $status === $s ? 'button-primary' : ''; ?>">
                        <?php echo esc_html($label); ?> (<?php echo (int) ($status_counts[$s] ?? 0); ?>)
                    </a>
                <?php endforeach; ?>

                &nbsp;&nbsp;|&nbsp;&nbsp;

                <?php foreach (['A', 'B', 'C'] as $t): ?>
                    <a href="<?php echo esc_url(add_query_arg('tier', $t)); ?>" class="button <?php echo $tier === $t ? 'button-primary' : ''; ?>">
                        <?php echo esc_html("Tier {$t}"); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($actions)): ?>
                <p><?php esc_html_e('No actions match the current filters.', 'tossa-ai-agent'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Tier', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Action', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Page', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Evidence', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Confidence', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Status', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Rationale', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Actions', 'tossa-ai-agent'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($actions as $action): ?>
                            <?php
                            $entity_title = $action['entity_id'] ? get_the_title((int) $action['entity_id']) : '—';
                            $tier_colors  = ['A' => '#00a32a', 'B' => '#dba617', 'C' => '#d63638'];
                            $tier_color   = $tier_colors[$action['safety_tier']] ?? '#999';
                            ?>
                            <tr id="action-<?php echo esc_attr($action['action_id']); ?>">
                                <td>
                                    <span style="display: inline-block; width: 28px; height: 28px; line-height: 28px; text-align: center; border-radius: 4px; background: <?php echo esc_attr($tier_color); ?>; color: white; font-weight: bold;">
                                        <?php echo esc_html($action['safety_tier']); ?>
                                    </span>
                                </td>
                                <td><code><?php echo esc_html($action['action_type']); ?></code></td>
                                <td>
                                    <?php if ($action['entity_id']): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link((int) $action['entity_id'])); ?>">
                                            <?php echo esc_html($entity_title); ?>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($action['evidence_source'] ?? '—'); ?></td>
                                <td><?php echo $action['confidence'] ? esc_html(round((float) $action['confidence'] * 100)) . '%' : '—'; ?></td>
                                <td><?php echo esc_html(ucfirst($action['status'])); ?></td>
                                <td>
                                    <?php echo esc_html(wp_trim_words($action['rationale'] ?? '', 20)); ?>
                                    <?php if ($action['payload_json']): ?>
                                        <details style="margin-top: 5px;">
                                            <summary><?php esc_html_e('Show payload', 'tossa-ai-agent'); ?></summary>
                                            <pre style="font-size: 11px; max-height: 200px; overflow: auto;"><?php echo esc_html(wp_json_encode(json_decode($action['payload_json']), JSON_PRETTY_PRINT)); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ('proposed' === $action['status']): ?>
                                        <button class="button button-primary tossa-action-btn" data-action="approve" data-id="<?php echo esc_attr($action['action_id']); ?>">
                                            <?php esc_html_e('Approve', 'tossa-ai-agent'); ?>
                                        </button>
                                        <button class="button tossa-action-btn" data-action="reject" data-id="<?php echo esc_attr($action['action_id']); ?>">
                                            <?php esc_html_e('Reject', 'tossa-ai-agent'); ?>
                                        </button>
                                    <?php elseif ('approved' === $action['status']): ?>
                                        <button class="button button-primary tossa-action-btn" data-action="execute" data-id="<?php echo esc_attr($action['action_id']); ?>">
                                            <?php esc_html_e('Execute', 'tossa-ai-agent'); ?>
                                        </button>
                                    <?php elseif ('executed' === $action['status'] && $action['rollback_json']): ?>
                                        <button class="button tossa-action-btn" data-action="rollback" data-id="<?php echo esc_attr($action['action_id']); ?>" style="color: #d63638;">
                                            <?php esc_html_e('Rollback', 'tossa-ai-agent'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        document.querySelectorAll('.tossa-action-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                const id = this.dataset.id;
                const confirmMsg = {
                    approve: '<?php echo esc_js(__('Approve this action?', 'tossa-ai-agent')); ?>',
                    reject: '<?php echo esc_js(__('Reject this action?', 'tossa-ai-agent')); ?>',
                    execute: '<?php echo esc_js(__('Execute this action? This will modify your site.', 'tossa-ai-agent')); ?>',
                    rollback: '<?php echo esc_js(__('Rollback this action? This will restore the previous value.', 'tossa-ai-agent')); ?>',
                };

                if (!confirm(confirmMsg[action])) return;

                this.disabled = true;
                this.textContent = '...';

                wp.apiFetch({
                    path: `/tossa-agent/v1/actions/${id}/${action}`,
                    method: 'POST',
                }).then(() => {
                    location.reload();
                }).catch(err => {
                    alert('Error: ' + (err.message || 'Unknown'));
                    this.disabled = false;
                });
            });
        });
        </script>
        <?php
    }
}
