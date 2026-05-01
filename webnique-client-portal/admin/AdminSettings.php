<?php
/**
 * Admin Settings Page
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminSettings
{
    public static function render(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $saved = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true';
        $support_name = get_option('wnq_support_name', 'Christopher Scotto');
        $support_title = get_option('wnq_support_title', 'Head Developer');
        $support_email = get_option('wnq_support_email', get_option('admin_email'));
        $support_phone = get_option('wnq_support_phone', '+14439948595');
        $support_phone_display = get_option('wnq_support_phone_display', '(443) 994-8595');
        $portal_page_url = get_option('wnq_portal_page_url', home_url('/client-portal/'));
        $brand_primary = get_option('wnq_brand_primary', '#0d539e');
        $default_billing_cycle = get_option('wnq_default_billing_cycle', 'monthly');
        $seo_enabled = function_exists('wnq_seo_features_enabled') && wnq_seo_features_enabled();

        ?>
        <div class="wrap wnq-settings-page">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>WebNique Portal settings saved.</p></div>
            <?php endif; ?>

            <div class="wnq-settings-layout">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wnq-settings-form">
                    <?php wp_nonce_field('wnq_save_portal_settings', 'wnq_nonce'); ?>
                    <input type="hidden" name="action" value="wnq_save_portal_settings">

                    <div class="settings-panel">
                        <h2>Client Portal</h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="wnq_portal_page_url">Portal Page URL</label></th>
                                <td>
                                    <input type="url" name="wnq_portal_page_url" id="wnq_portal_page_url" value="<?php echo esc_attr($portal_page_url); ?>" class="regular-text">
                                    <p class="description">The page where the <code>[wnq_portal]</code> shortcode lives.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_brand_primary">Primary Brand Color</label></th>
                                <td>
                                    <input type="text" name="wnq_brand_primary" id="wnq_brand_primary" value="<?php echo esc_attr($brand_primary); ?>" class="regular-text" pattern="^#[0-9a-fA-F]{6}$">
                                    <p class="description">Hex color used as the default portal accent.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_default_billing_cycle">Default Billing Cycle</label></th>
                                <td>
                                    <select name="wnq_default_billing_cycle" id="wnq_default_billing_cycle">
                                        <option value="monthly" <?php selected($default_billing_cycle, 'monthly'); ?>>Monthly</option>
                                        <option value="quarterly" <?php selected($default_billing_cycle, 'quarterly'); ?>>Quarterly</option>
                                        <option value="annually" <?php selected($default_billing_cycle, 'annually'); ?>>Annually</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="settings-panel">
                        <h2>Support Contact</h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="wnq_support_name">Support Name</label></th>
                                <td><input type="text" name="wnq_support_name" id="wnq_support_name" value="<?php echo esc_attr($support_name); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="wnq_support_title">Support Title</label></th>
                                <td><input type="text" name="wnq_support_title" id="wnq_support_title" value="<?php echo esc_attr($support_title); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="wnq_support_email">Support Email</label></th>
                                <td><input type="email" name="wnq_support_email" id="wnq_support_email" value="<?php echo esc_attr($support_email); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="wnq_support_phone">Support Phone Link</label></th>
                                <td>
                                    <input type="text" name="wnq_support_phone" id="wnq_support_phone" value="<?php echo esc_attr($support_phone); ?>" class="regular-text" placeholder="+15555551212">
                                    <p class="description">Use a tel-friendly format for buttons and links.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_support_phone_display">Support Phone Display</label></th>
                                <td><input type="text" name="wnq_support_phone_display" id="wnq_support_phone_display" value="<?php echo esc_attr($support_phone_display); ?>" class="regular-text" placeholder="(555) 555-1212"></td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">Save Settings</button>
                    </p>
                </form>

                <aside class="settings-panel settings-status">
                    <h2>Portal Status</h2>
                    <dl>
                        <dt>Plugin Version</dt>
                        <dd><?php echo esc_html(defined('WNQ_PORTAL_VERSION') ? WNQ_PORTAL_VERSION : 'Unknown'); ?></dd>
                        <dt>SEO OS Features</dt>
                        <dd><span class="status-pill <?php echo $seo_enabled ? 'active' : 'inactive'; ?>"><?php echo $seo_enabled ? 'Enabled' : 'Disabled'; ?></span></dd>
                        <dt>Finance Tracking</dt>
                        <dd><span class="status-pill active">Enabled</span></dd>
                        <dt>Firebase Project</dt>
                        <dd><?php echo esc_html(defined('FIREBASE_PROJECT_ID') && FIREBASE_PROJECT_ID ? FIREBASE_PROJECT_ID : 'Not configured'); ?></dd>
                        <dt>Analytics Admin</dt>
                        <dd><?php echo class_exists('WNQ\\Admin\\AnalyticsAdmin') ? 'Available' : 'Loads on demand'; ?></dd>
                    </dl>
                    <p class="description">SEO OS is intentionally disabled unless <code>WNQ_ENABLE_SEO_FEATURES</code> is set to true.</p>
                </aside>
            </div>
        </div>

        <style>
        .wnq-settings-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 20px;
            align-items: start;
            margin-top: 18px;
        }
        .settings-panel {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .settings-panel h2 {
            margin: 0 0 12px;
        }
        .settings-status dl {
            margin: 0;
        }
        .settings-status dt {
            color: #646970;
            font-weight: 600;
            margin-top: 14px;
        }
        .settings-status dd {
            margin: 4px 0 0;
            font-size: 14px;
        }
        .status-pill {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 12px;
        }
        .status-pill.active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-pill.inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        @media (max-width: 960px) {
            .wnq-settings-layout {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }

    public static function handleSaveSettings(): void
    {
        if (!isset($_POST['wnq_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wnq_nonce'])), 'wnq_save_portal_settings')) {
            wp_die('Invalid settings request.');
        }

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $brand_primary = sanitize_text_field($_POST['wnq_brand_primary'] ?? '#0d539e');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand_primary)) {
            $brand_primary = '#0d539e';
        }

        $billing_cycle = sanitize_key($_POST['wnq_default_billing_cycle'] ?? 'monthly');
        if (!in_array($billing_cycle, ['monthly', 'quarterly', 'annually'], true)) {
            $billing_cycle = 'monthly';
        }

        update_option('wnq_portal_page_url', esc_url_raw($_POST['wnq_portal_page_url'] ?? ''));
        update_option('wnq_brand_primary', $brand_primary);
        update_option('wnq_default_billing_cycle', $billing_cycle);
        update_option('wnq_support_name', sanitize_text_field($_POST['wnq_support_name'] ?? ''));
        update_option('wnq_support_title', sanitize_text_field($_POST['wnq_support_title'] ?? ''));
        update_option('wnq_support_email', sanitize_email($_POST['wnq_support_email'] ?? ''));
        update_option('wnq_support_phone', sanitize_text_field($_POST['wnq_support_phone'] ?? ''));
        update_option('wnq_support_phone_display', sanitize_text_field($_POST['wnq_support_phone_display'] ?? ''));

        wp_redirect(add_query_arg([
            'page' => 'wnq-portal',
            'settings-updated' => 'true',
        ], admin_url('admin.php')));
        exit;
    }
}
