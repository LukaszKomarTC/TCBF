<?php
/**
 * Settings Page.
 *
 * Admin settings for agent configuration, SEO objectives, Google integrations,
 * and truth firewall rules.
 *
 * @package Tossa\Agent\Admin
 */

declare(strict_types=1);

namespace Tossa\Agent\Admin;

final class SettingsPage {

    /**
     * Render the settings page.
     */
    public static function render(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Handle form save.
        if (isset($_POST['tossa_agent_settings_nonce']) && wp_verify_nonce($_POST['tossa_agent_settings_nonce'], 'tossa_agent_save_settings')) {
            self::save_settings();
        }

        $active_tab = sanitize_text_field($_GET['tab'] ?? 'agent');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tossa AI Agent Settings', 'tossa-ai-agent'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=tossa-ai-agent-settings&tab=agent" class="nav-tab <?php echo 'agent' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Agent Configuration', 'tossa-ai-agent'); ?>
                </a>
                <a href="?page=tossa-ai-agent-settings&tab=seo" class="nav-tab <?php echo 'seo' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('SEO Objectives', 'tossa-ai-agent'); ?>
                </a>
                <a href="?page=tossa-ai-agent-settings&tab=truth" class="nav-tab <?php echo 'truth' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Truth Firewall', 'tossa-ai-agent'); ?>
                </a>
                <a href="?page=tossa-ai-agent-settings&tab=google" class="nav-tab <?php echo 'google' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Google Integrations', 'tossa-ai-agent'); ?>
                </a>
            </nav>

            <form method="post" action="">
                <?php wp_nonce_field('tossa_agent_save_settings', 'tossa_agent_settings_nonce'); ?>
                <input type="hidden" name="active_tab" value="<?php echo esc_attr($active_tab); ?>">

                <?php
                match ($active_tab) {
                    'seo'    => self::render_seo_tab(),
                    'truth'  => self::render_truth_tab(),
                    'google' => self::render_google_tab(),
                    default  => self::render_agent_tab(),
                };
                ?>

                <?php submit_button(__('Save Settings', 'tossa-ai-agent')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Agent Configuration tab.
     */
    private static function render_agent_tab(): void {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="tossa_agent_vps_url"><?php esc_html_e('VPS Agent URL', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <input type="url" id="tossa_agent_vps_url" name="tossa_agent_vps_url" class="regular-text"
                           value="<?php echo esc_attr(get_option('tossa_agent_vps_url', '')); ?>"
                           placeholder="https://agent.yourdomain.com">
                    <p class="description"><?php esc_html_e('The URL of your VPS agent service.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_webhook_secret"><?php esc_html_e('Webhook Shared Secret', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <input type="password" id="tossa_agent_webhook_secret" name="tossa_agent_webhook_secret" class="regular-text"
                           value="<?php echo esc_attr(get_option('tossa_agent_webhook_secret', '')); ?>">
                    <button type="button" class="button" onclick="document.getElementById('tossa_agent_webhook_secret').value = crypto.randomUUID() + crypto.randomUUID()">
                        <?php esc_html_e('Generate', 'tossa-ai-agent'); ?>
                    </button>
                    <p class="description"><?php esc_html_e('Shared secret for HMAC webhook signing. Must match the VPS agent config.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_execution_mode"><?php esc_html_e('Execution Mode', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <select id="tossa_agent_execution_mode" name="tossa_agent_execution_mode">
                        <?php
                        $mode = get_option('tossa_agent_execution_mode', 'propose-and-approve');
                        $modes = [
                            'analysis-only'       => __('Analysis Only (no execution)', 'tossa-ai-agent'),
                            'propose-and-approve'  => __('Propose & Approve (default)', 'tossa-ai-agent'),
                            'full'                => __('Full (auto-execute Tier A)', 'tossa-ai-agent'),
                        ];
                        foreach ($modes as $value => $label) {
                            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($mode, $value, false), esc_html($label));
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_max_actions_per_hour"><?php esc_html_e('Max Actions / Hour', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <input type="number" id="tossa_agent_max_actions_per_hour" name="tossa_agent_max_actions_per_hour"
                           value="<?php echo esc_attr(get_option('tossa_agent_max_actions_per_hour', 20)); ?>" min="1" max="200">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_allowed_ips"><?php esc_html_e('Allowed VPS IPs', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <input type="text" id="tossa_agent_allowed_ips" name="tossa_agent_allowed_ips" class="regular-text"
                           value="<?php echo esc_attr(get_option('tossa_agent_allowed_ips', '')); ?>"
                           placeholder="203.0.113.1, 203.0.113.2">
                    <p class="description"><?php esc_html_e('Comma-separated. Leave empty to allow all IPs.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Emergency Kill Switch', 'tossa-ai-agent'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tossa_agent_kill_switch" value="1"
                               <?php checked(get_option('tossa_agent_kill_switch', false)); ?>>
                        <?php esc_html_e('Disable ALL agent activity immediately', 'tossa-ai-agent'); ?>
                    </label>
                    <p class="description" style="color: #d63638;"><?php esc_html_e('When enabled, no actions will be proposed, approved, or executed.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Email Notifications', 'tossa-ai-agent'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tossa_agent_email_notifications" value="1"
                               <?php checked(get_option('tossa_agent_email_notifications', false)); ?>>
                        <?php esc_html_e('Send email when audits complete', 'tossa-ai-agent'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * SEO Objectives tab.
     */
    private static function render_seo_tab(): void {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="tossa_agent_primary_keywords"><?php esc_html_e('Primary Keywords', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <textarea id="tossa_agent_primary_keywords" name="tossa_agent_primary_keywords" rows="4" class="large-text"
                    ><?php echo esc_textarea(get_option('tossa_agent_primary_keywords', '')); ?></textarea>
                    <p class="description"><?php esc_html_e('One keyword or phrase per line.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_secondary_keywords"><?php esc_html_e('Secondary Keywords', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <textarea id="tossa_agent_secondary_keywords" name="tossa_agent_secondary_keywords" rows="4" class="large-text"
                    ><?php echo esc_textarea(get_option('tossa_agent_secondary_keywords', '')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_target_audience"><?php esc_html_e('Target Audience', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <input type="text" id="tossa_agent_target_audience" name="tossa_agent_target_audience" class="large-text"
                           value="<?php echo esc_attr(get_option('tossa_agent_target_audience', '')); ?>"
                           placeholder="Cycling tourists visiting Costa Brava / Tossa de Mar">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_seo_goal"><?php esc_html_e('SEO Goal', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <textarea id="tossa_agent_seo_goal" name="tossa_agent_seo_goal" rows="3" class="large-text"
                    ><?php echo esc_textarea(get_option('tossa_agent_seo_goal', '')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_geo_target"><?php esc_html_e('Geo Target', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <input type="text" id="tossa_agent_geo_target" name="tossa_agent_geo_target" class="regular-text"
                           value="<?php echo esc_attr(get_option('tossa_agent_geo_target', '')); ?>"
                           placeholder="Tossa de Mar, Costa Brava, Catalonia">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_content_language"><?php esc_html_e('Content Language', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <select id="tossa_agent_content_language" name="tossa_agent_content_language">
                        <?php
                        $lang = get_option('tossa_agent_content_language', 'en');
                        $langs = ['en' => 'English', 'es' => 'Spanish', 'ca' => 'Catalan', 'de' => 'German', 'fr' => 'French', 'nl' => 'Dutch', 'it' => 'Italian'];
                        foreach ($langs as $code => $label) {
                            printf('<option value="%s" %s>%s</option>', esc_attr($code), selected($lang, $code, false), esc_html($label));
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_competitor_urls"><?php esc_html_e('Competitor URLs', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <textarea id="tossa_agent_competitor_urls" name="tossa_agent_competitor_urls" rows="3" class="large-text"
                    ><?php echo esc_textarea(get_option('tossa_agent_competitor_urls', '')); ?></textarea>
                    <p class="description"><?php esc_html_e('One URL per line.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_brand_voice"><?php esc_html_e('Brand Voice / Tone', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <textarea id="tossa_agent_brand_voice" name="tossa_agent_brand_voice" rows="3" class="large-text"
                    ><?php echo esc_textarea(get_option('tossa_agent_brand_voice', '')); ?></textarea>
                    <p class="description"><?php esc_html_e('Describe the desired writing tone and style constraints.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Truth Firewall tab.
     */
    private static function render_truth_tab(): void {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="tossa_agent_forbidden_claims"><?php esc_html_e('Forbidden Claims', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <textarea id="tossa_agent_forbidden_claims" name="tossa_agent_forbidden_claims" rows="6" class="large-text"
                    ><?php echo esc_textarea(implode("\n", get_option('tossa_agent_forbidden_claims', []))); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('One phrase per line. The AI will NEVER generate content containing these phrases. Built-in forbidden phrases are always active.', 'tossa-ai-agent'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tossa_agent_local_review_claims"><?php esc_html_e('Requires Local Review', 'tossa-ai-agent'); ?></label></th>
                <td>
                    <textarea id="tossa_agent_local_review_claims" name="tossa_agent_local_review_claims" rows="6" class="large-text"
                    ><?php echo esc_textarea(implode("\n", get_option('tossa_agent_local_review_claims', []))); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('One phrase per line. Content with these phrases is auto-escalated to Tier C (human review). Built-in local review phrases are always active.', 'tossa-ai-agent'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Built-in Forbidden Phrases', 'tossa-ai-agent'); ?></th>
                <td>
                    <p class="description"><?php esc_html_e('These are always active and cannot be removed:', 'tossa-ai-agent'); ?></p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <?php
                        $truth = new \Tossa\Agent\Policy\TruthFirewall();
                        foreach ($truth->get_forbidden_phrases() as $phrase) {
                            printf('<li><code>%s</code></li>', esc_html($phrase));
                        }
                        ?>
                    </ul>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Multilingual Rules', 'tossa-ai-agent'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tossa_agent_multilingual_check" value="1"
                               <?php checked(get_option('tossa_agent_multilingual_check', true)); ?>>
                        <?php esc_html_e('Warn when content changes lack a target language specification', 'tossa-ai-agent'); ?>
                    </label>
                    <br><br>
                    <label>
                        <input type="checkbox" name="tossa_agent_block_auto_translate" value="1"
                               <?php checked(get_option('tossa_agent_block_auto_translate', true)); ?>>
                        <?php esc_html_e('Block automatic translation of SEO metadata (propose only, human reviews)', 'tossa-ai-agent'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Google Integrations tab.
     */
    private static function render_google_tab(): void {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Google Search Console', 'tossa-ai-agent'); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e('GSC integration is managed on the VPS agent. Configure your service account credentials there.', 'tossa-ai-agent'); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Status:', 'tossa-ai-agent'); ?></strong>
                        <?php
                        $gsc_status = get_transient('tossa_agent_gsc_status');
                        echo $gsc_status
                            ? '<span style="color: green;">&#10003; ' . esc_html__('Connected', 'tossa-ai-agent') . '</span>'
                            : '<span style="color: #999;">' . esc_html__('Not configured or not tested', 'tossa-ai-agent') . '</span>';
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('PageSpeed Insights', 'tossa-ai-agent'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tossa_agent_psi_enabled" value="1"
                               <?php checked(get_option('tossa_agent_psi_enabled', false)); ?>>
                        <?php esc_html_e('Include PageSpeed Insights data in audits', 'tossa-ai-agent'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('PSI API key must be configured on the VPS agent.', 'tossa-ai-agent'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save settings from POST data.
     */
    private static function save_settings(): void {
        $tab = sanitize_text_field($_POST['active_tab'] ?? 'agent');

        $text_fields = [
            'tossa_agent_vps_url',
            'tossa_agent_webhook_secret',
            'tossa_agent_execution_mode',
            'tossa_agent_allowed_ips',
            'tossa_agent_primary_keywords',
            'tossa_agent_secondary_keywords',
            'tossa_agent_target_audience',
            'tossa_agent_seo_goal',
            'tossa_agent_geo_target',
            'tossa_agent_content_language',
            'tossa_agent_competitor_urls',
            'tossa_agent_brand_voice',
        ];

        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_option($field, sanitize_textarea_field($_POST[$field]));
            }
        }

        $int_fields = ['tossa_agent_max_actions_per_hour'];
        foreach ($int_fields as $field) {
            if (isset($_POST[$field])) {
                update_option($field, (int) $_POST[$field]);
            }
        }

        $bool_fields = [
            'tossa_agent_kill_switch',
            'tossa_agent_email_notifications',
            'tossa_agent_psi_enabled',
            'tossa_agent_multilingual_check',
            'tossa_agent_block_auto_translate',
        ];
        foreach ($bool_fields as $field) {
            update_option($field, isset($_POST[$field]));
        }

        // Array fields (one item per line).
        $array_fields = ['tossa_agent_forbidden_claims', 'tossa_agent_local_review_claims'];
        foreach ($array_fields as $field) {
            if (isset($_POST[$field])) {
                $lines = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST[$field]))));
                update_option($field, array_values($lines));
            }
        }

        add_settings_error('tossa_agent_settings', 'saved', __('Settings saved.', 'tossa-ai-agent'), 'success');
    }
}
