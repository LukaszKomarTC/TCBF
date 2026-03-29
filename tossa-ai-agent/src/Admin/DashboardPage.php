<?php
/**
 * Dashboard Page.
 *
 * Main overview: site health summary, recent audits, pending actions,
 * quick-start controls.
 *
 * @package Tossa\Agent\Admin
 */

declare(strict_types=1);

namespace Tossa\Agent\Admin;

use Tossa\Agent\Audit\ChangeRepository;

final class DashboardPage {

    /**
     * Render the dashboard.
     */
    public static function render(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $repo           = new ChangeRepository();
        $recent_audits  = $repo->get_recent_audits(5);
        $status_counts  = $repo->get_status_counts();
        $pending        = $repo->get_pending_actions(10);
        $kill_switch    = (bool) get_option('tossa_agent_kill_switch', false);
        $execution_mode = get_option('tossa_agent_execution_mode', 'propose-and-approve');
        $vps_url        = get_option('tossa_agent_vps_url', '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tossa AI Agent Dashboard', 'tossa-ai-agent'); ?></h1>

            <?php if ($kill_switch): ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Emergency Kill Switch is ACTIVE. All agent activity is paused.', 'tossa-ai-agent'); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if (empty($vps_url)): ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('VPS Agent URL is not configured. Go to Settings to set it up.', 'tossa-ai-agent'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Status Cards -->
            <div class="tossa-dashboard-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0;">
                <div class="card" style="padding: 16px; background: white; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Pending Review', 'tossa-ai-agent'); ?></h3>
                    <p style="font-size: 2em; margin: 0; font-weight: bold;"><?php echo (int) ($status_counts['proposed'] ?? 0); ?></p>
                </div>
                <div class="card" style="padding: 16px; background: white; border: 1px solid #ccd0d4; border-left: 4px solid #00a32a;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Executed', 'tossa-ai-agent'); ?></h3>
                    <p style="font-size: 2em; margin: 0; font-weight: bold;"><?php echo (int) ($status_counts['executed'] ?? 0); ?></p>
                </div>
                <div class="card" style="padding: 16px; background: white; border: 1px solid #ccd0d4; border-left: 4px solid #dba617;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Mode', 'tossa-ai-agent'); ?></h3>
                    <p style="font-size: 1.2em; margin: 0;"><?php echo esc_html(ucwords(str_replace('-', ' ', $execution_mode))); ?></p>
                </div>
                <div class="card" style="padding: 16px; background: white; border: 1px solid #ccd0d4; border-left: 4px solid #72aee6;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Total Audits', 'tossa-ai-agent'); ?></h3>
                    <p style="font-size: 2em; margin: 0; font-weight: bold;"><?php echo count($recent_audits); ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card" style="padding: 20px; background: white; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Quick Actions', 'tossa-ai-agent'); ?></h2>
                <p>
                    <button type="button" class="button button-primary" id="tossa-start-audit" <?php echo $kill_switch || empty($vps_url) ? 'disabled' : ''; ?>>
                        <?php esc_html_e('Run SEO Audit', 'tossa-ai-agent'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tossa-ai-agent-actions')); ?>" class="button">
                        <?php esc_html_e('Review Actions', 'tossa-ai-agent'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tossa-ai-agent-settings')); ?>" class="button">
                        <?php esc_html_e('Settings', 'tossa-ai-agent'); ?>
                    </a>
                </p>
                <div id="tossa-audit-status" style="display: none; margin-top: 10px;"></div>
            </div>

            <!-- Recent Audits -->
            <div class="card" style="padding: 20px; background: white; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Recent Audits', 'tossa-ai-agent'); ?></h2>
                <?php if (empty($recent_audits)): ?>
                    <p><?php esc_html_e('No audits yet. Run your first SEO audit to get started.', 'tossa-ai-agent'); ?></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Date', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Source', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Score', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Issues', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Actions', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Status', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Cost', 'tossa-ai-agent'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_audits as $audit): ?>
                                <tr>
                                    <td><?php echo esc_html($audit['created_at']); ?></td>
                                    <td><?php echo esc_html(ucfirst($audit['source'])); ?></td>
                                    <td><strong><?php echo $audit['overall_score'] !== null ? esc_html($audit['overall_score'] . '/100') : '—'; ?></strong></td>
                                    <td><?php echo (int) $audit['issue_count']; ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=tossa-ai-agent-actions&audit_id=' . $audit['audit_id'])); ?>">
                                            <?php echo (int) $audit['action_count']; ?> <?php esc_html_e('actions', 'tossa-ai-agent'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html(ucfirst($audit['status'])); ?></td>
                                    <td>$<?php echo esc_html(number_format((float) $audit['cost_usd'], 4)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Pending Actions -->
            <?php if (! empty($pending)): ?>
                <div class="card" style="padding: 20px; background: white; border: 1px solid #ccd0d4;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Actions Awaiting Review', 'tossa-ai-agent'); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Tier', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Type', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Page', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Confidence', 'tossa-ai-agent'); ?></th>
                                <th><?php esc_html_e('Rationale', 'tossa-ai-agent'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $action): ?>
                                <tr>
                                    <td><span class="tossa-tier tossa-tier-<?php echo esc_attr(strtolower($action['safety_tier'])); ?>"><?php echo esc_html($action['safety_tier']); ?></span></td>
                                    <td><code><?php echo esc_html($action['action_type']); ?></code></td>
                                    <td><?php echo $action['entity_id'] ? esc_html(get_the_title((int) $action['entity_id'])) : '—'; ?></td>
                                    <td><?php echo $action['confidence'] ? esc_html(round((float) $action['confidence'] * 100)) . '%' : '—'; ?></td>
                                    <td><?php echo esc_html(wp_trim_words($action['rationale'] ?? '', 15)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tossa-ai-agent-actions&status=proposed')); ?>" class="button">
                            <?php esc_html_e('Review All Pending Actions', 'tossa-ai-agent'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <script>
        document.getElementById('tossa-start-audit')?.addEventListener('click', function() {
            this.disabled = true;
            this.textContent = '<?php echo esc_js(__('Starting audit...', 'tossa-ai-agent')); ?>';
            const statusDiv = document.getElementById('tossa-audit-status');
            statusDiv.style.display = 'block';

            wp.apiFetch({ path: '/tossa-agent/v1/audit/start', method: 'POST' })
                .then(res => {
                    statusDiv.innerHTML = '<div class="notice notice-success inline"><p>' +
                        '<?php echo esc_js(__('Audit started! ID: ', 'tossa-ai-agent')); ?>' + res.audit_id +
                        ' (' + res.page_count + ' pages). Results will appear when the VPS agent responds.</p></div>';
                })
                .catch(err => {
                    statusDiv.innerHTML = '<div class="notice notice-error inline"><p>' +
                        '<?php echo esc_js(__('Failed: ', 'tossa-ai-agent')); ?>' + (err.message || 'Unknown error') + '</p></div>';
                    this.disabled = false;
                    this.textContent = '<?php echo esc_js(__('Run SEO Audit', 'tossa-ai-agent')); ?>';
                });
        });
        </script>
        <?php
    }
}
