<?php
/**
 * Logs Page.
 *
 * Displays the agent's operational log with filtering and pagination.
 *
 * @package Tossa\Agent\Admin
 */

declare(strict_types=1);

namespace Tossa\Agent\Admin;

use Tossa\Agent\Audit\AuditLogger;

final class LogsPage {

    /**
     * Render the logs page.
     */
    public static function render(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $logger = new AuditLogger();

        $filters = [
            'level'  => sanitize_text_field($_GET['level'] ?? ''),
            'source' => sanitize_text_field($_GET['source'] ?? ''),
            'limit'  => 50,
            'offset' => (int) ($_GET['offset'] ?? 0),
        ];

        $result  = $logger->query(array_filter($filters));
        $entries = $result['entries'];
        $total   = $result['total'];
        $offset  = $filters['offset'];
        $limit   = $filters['limit'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Agent Logs', 'tossa-ai-agent'); ?></h1>

            <!-- Filters -->
            <div style="margin: 15px 0;">
                <?php foreach (['info', 'warning', 'error', 'debug'] as $level): ?>
                    <a href="<?php echo esc_url(add_query_arg('level', $level)); ?>"
                       class="button <?php echo ($filters['level'] ?? '') === $level ? 'button-primary' : ''; ?>">
                        <?php echo esc_html(ucfirst($level)); ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tossa-ai-agent-logs')); ?>" class="button">
                    <?php esc_html_e('Clear Filters', 'tossa-ai-agent'); ?>
                </a>
                <span style="margin-left: 20px; color: #666;">
                    <?php printf(esc_html__('Showing %d-%d of %d entries', 'tossa-ai-agent'), $offset + 1, min($offset + $limit, $total), $total); ?>
                </span>
            </div>

            <?php if (empty($entries)): ?>
                <p><?php esc_html_e('No log entries match the current filters.', 'tossa-ai-agent'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Level', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Source', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Event', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Message', 'tossa-ai-agent'); ?></th>
                            <th><?php esc_html_e('Context', 'tossa-ai-agent'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $level_colors = [
                                'info'    => '#2271b1',
                                'warning' => '#dba617',
                                'error'   => '#d63638',
                                'debug'   => '#999',
                            ];
                            $color = $level_colors[$entry['level']] ?? '#999';
                            ?>
                            <tr>
                                <td style="white-space: nowrap;"><?php echo esc_html($entry['created_at']); ?></td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 8px; border-radius: 3px; background: <?php echo esc_attr($color); ?>; color: white; font-size: 11px;">
                                        <?php echo esc_html(strtoupper($entry['level'])); ?>
                                    </span>
                                </td>
                                <td><code><?php echo esc_html($entry['source']); ?></code></td>
                                <td><code><?php echo esc_html($entry['event']); ?></code></td>
                                <td><?php echo esc_html($entry['message'] ?? '—'); ?></td>
                                <td>
                                    <?php if ($entry['context_json']): ?>
                                        <details>
                                            <summary><?php esc_html_e('Show', 'tossa-ai-agent'); ?></summary>
                                            <pre style="font-size: 11px; max-height: 150px; overflow: auto;"><?php echo esc_html(wp_json_encode(json_decode($entry['context_json']), JSON_PRETTY_PRINT)); ?></pre>
                                        </details>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div style="margin-top: 15px;">
                    <?php if ($offset > 0): ?>
                        <a href="<?php echo esc_url(add_query_arg('offset', max(0, $offset - $limit))); ?>" class="button">
                            &laquo; <?php esc_html_e('Previous', 'tossa-ai-agent'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($offset + $limit < $total): ?>
                        <a href="<?php echo esc_url(add_query_arg('offset', $offset + $limit)); ?>" class="button">
                            <?php esc_html_e('Next', 'tossa-ai-agent'); ?> &raquo;
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
