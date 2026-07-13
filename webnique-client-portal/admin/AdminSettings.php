<?php
/**
 * Admin Settings Page
 *
 * @package Golden Web Marketing Portal
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
        $stripe_publishable_key = get_option('wnq_stripe_test_publishable_key', '');
        $stripe_has_secret_key = (string)get_option('wnq_stripe_test_secret_key', '') !== '';
        $stripe_has_webhook_secret = (string)get_option('wnq_stripe_webhook_secret', '') !== '';
        $stripe_webhook_url = rest_url('wnq/v1/notifications/stripe');
        $google_ads_has_developer_token = (string)get_option('wnq_google_ads_developer_token', '') !== '';
        $google_ads_access_level = get_option('wnq_google_ads_access_level', 'test');
        $google_ads_manager_customer_id = get_option('wnq_google_ads_manager_customer_id', '');
        $google_ads_has_oauth_client_id = (string)get_option('wnq_google_ads_oauth_client_id', '') !== '';
        $google_ads_has_oauth_client_secret = (string)get_option('wnq_google_ads_oauth_client_secret', '') !== '';
        $google_ads_has_refresh_token = (string)get_option('wnq_google_ads_refresh_token', '') !== '';
        $google_ads_test = get_transient('wnq_google_ads_test_' . get_current_user_id());
        if (is_array($google_ads_test)) {
            delete_transient('wnq_google_ads_test_' . get_current_user_id());
        }
        $telegram_enabled = (bool)get_option('wnq_telegram_enabled', false);
        $telegram_has_token = (string)get_option('wnq_telegram_bot_token', '') !== '';
        $telegram_chat_id = (string)get_option('wnq_telegram_chat_id', '');
        $telegram_event_defaults = class_exists('WNQ\\Services\\NotificationManager')
            ? \WNQ\Services\NotificationManager::eventDefaults()
            : [];
        $telegram_stored_events = get_option('wnq_telegram_events', []);
        $telegram_events = array_merge($telegram_event_defaults, is_array($telegram_stored_events) ? $telegram_stored_events : []);
        $telegram_event_labels = [
            'tasks' => 'New Golden Web Marketing tasks',
            'support_messages' => 'Client support messages',
            'client_requests' => 'Client service requests',
            'learning_requests' => 'Learning center requests',
            'payments' => 'Payments and payment failures',
            'payment_due' => 'Client payment due-date reminders',
            'expense_due' => 'Monthly expense reminders',
            'ads_spend' => 'Google Ads spend thresholds (set per client)',
            'ads_connection' => 'Google Ads connection problems',
            'overdue_tasks' => 'Daily overdue agency task summary',
        ];
        $telegram_commands = class_exists('WNQ\Services\NotificationManager')
            ? \WNQ\Services\NotificationManager::botCommands()
            : [];
        $telegram_last_sent = (string)get_option('wnq_telegram_last_sent_at', '');
        $telegram_last_check = (string)get_option('wnq_telegram_last_check_at', '');
        $telegram_last_error = (string)get_option('wnq_telegram_last_error', '');
        $telegram_webhook_enabled = (bool)get_option('wnq_telegram_webhook_enabled', true);
        $telegram_webhook_active = (bool)get_option('wnq_telegram_webhook_active', false);
        $telegram_webhook_last_sync = (string)get_option('wnq_telegram_webhook_last_sync_at', '');
        $telegram_webhook_last_error = (string)get_option('wnq_telegram_webhook_last_error', '');
        $telegram_last_command_check = (string)get_option('wnq_telegram_last_command_check_at', '');
        $telegram_webhook_endpoint = rest_url('wnq/v1/notifications/telegram');
        $telegram_test = get_transient('wnq_telegram_test_' . get_current_user_id());
        if (is_array($telegram_test)) {
            delete_transient('wnq_telegram_test_' . get_current_user_id());
        }
        $telegram_discovery = get_transient('wnq_telegram_discovery_' . get_current_user_id());
        if (is_array($telegram_discovery)) {
            delete_transient('wnq_telegram_discovery_' . get_current_user_id());
        }
        $notification_check = get_transient('wnq_notification_check_' . get_current_user_id());
        if (is_array($notification_check)) {
            delete_transient('wnq_notification_check_' . get_current_user_id());
        }
        $telegram_command_sync = get_transient('wnq_telegram_command_sync_' . get_current_user_id());
        if (is_array($telegram_command_sync)) {
            delete_transient('wnq_telegram_command_sync_' . get_current_user_id());
        }
        $telegram_webhook_sync = get_transient('wnq_telegram_webhook_sync_' . get_current_user_id());
        if (is_array($telegram_webhook_sync)) {
            delete_transient('wnq_telegram_webhook_sync_' . get_current_user_id());
        }
        $ai_settings = class_exists('WNQ\\Services\\AIEngine')
            ? \WNQ\Services\AIEngine::getSettings()
            : [];
        $ai_provider = sanitize_key((string)($ai_settings['provider'] ?? 'openai'));
        $ai_provider_names = [
            'openai' => 'OpenAI',
            'groq' => 'Groq',
            'together' => 'Together AI',
            'xai' => 'xAI',
        ];
        $ai_provider_name = $ai_provider_names[$ai_provider] ?? ucfirst($ai_provider);
        $ai_model = sanitize_text_field((string)($ai_settings[$ai_provider . '_model'] ?? ''));
        $ai_configured = trim((string)($ai_settings[$ai_provider . '_api_key'] ?? '')) !== '';
        $seo_enabled = function_exists('wnq_seo_features_enabled') && wnq_seo_features_enabled();

        ?>
        <div class="wrap wnq-settings-page">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>Golden Web Marketing Portal settings saved.</p></div>
            <?php endif; ?>
            <?php if (is_array($google_ads_test)): ?>
                <div class="notice <?php echo !empty($google_ads_test['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><strong>Google Ads connection:</strong> <?php echo esc_html((string)($google_ads_test['message'] ?? 'Connection test completed.')); ?></p>
                </div>
            <?php endif; ?>
            <?php if (is_array($telegram_test)): ?>
                <div class="notice <?php echo !empty($telegram_test['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><strong>Telegram connection:</strong> <?php echo esc_html((string)($telegram_test['message'] ?? 'Connection test completed.')); ?></p>
                </div>
            <?php endif; ?>
            <?php if (is_array($telegram_discovery)): ?>
                <div class="notice <?php echo !empty($telegram_discovery['ok']) ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
                    <p><strong>Telegram groups:</strong> <?php echo esc_html((string)($telegram_discovery['message'] ?? 'Group discovery completed.')); ?></p>
                </div>
            <?php endif; ?>
            <?php if (is_array($notification_check)): ?>
                <div class="notice <?php echo !empty($notification_check['ok']) ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
                    <p><strong>Notification checks:</strong> <?php echo esc_html((string)($notification_check['message'] ?? 'Scheduled checks completed.')); ?></p>
                </div>
            <?php endif; ?>
            <?php if (is_array($telegram_command_sync)): ?>
                <div class="notice <?php echo !empty($telegram_command_sync['ok']) ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
                    <p><strong>Telegram commands:</strong> <?php echo esc_html((string)($telegram_command_sync['message'] ?? 'Command sync completed.')); ?></p>
                </div>
            <?php endif; ?>
            <?php if (is_array($telegram_webhook_sync)): ?>
                <div class="notice <?php echo !empty($telegram_webhook_sync['ok']) ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
                    <p><strong>Telegram instant replies:</strong> <?php echo esc_html((string)($telegram_webhook_sync['message'] ?? 'Webhook setup completed.')); ?></p>
                </div>
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
                        <h2>Stripe Connection</h2>
                        <p class="description">Stored server-side for testing and verified payment webhooks. These values are not exposed in the frontend portal.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="wnq_stripe_test_publishable_key">Test Publishable Key</label></th>
                                <td><input type="text" name="wnq_stripe_test_publishable_key" id="wnq_stripe_test_publishable_key" value="<?php echo esc_attr($stripe_publishable_key); ?>" class="large-text" placeholder="pk_test_..."></td>
                            </tr>
                            <tr>
                                <th><label for="wnq_stripe_test_secret_key">Test Secret Key</label></th>
                                <td>
                                    <input type="password" name="wnq_stripe_test_secret_key" id="wnq_stripe_test_secret_key" value="" class="large-text" placeholder="<?php echo esc_attr($stripe_has_secret_key ? 'Saved - leave blank to keep current secret key' : 'sk_test_...'); ?>" autocomplete="new-password">
                                    <?php if ($stripe_has_secret_key): ?><label class="wnq-inline-check"><input type="checkbox" name="wnq_stripe_clear_secret_key" value="1"> Clear saved secret key</label><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_stripe_webhook_secret">Webhook Signing Secret</label></th>
                                <td>
                                    <input type="password" name="wnq_stripe_webhook_secret" id="wnq_stripe_webhook_secret" value="" class="large-text" placeholder="<?php echo esc_attr($stripe_has_webhook_secret ? 'Saved - leave blank to keep current webhook secret' : 'whsec_...'); ?>" autocomplete="new-password">
                                    <?php if ($stripe_has_webhook_secret): ?><label class="wnq-inline-check"><input type="checkbox" name="wnq_stripe_clear_webhook_secret" value="1"> Clear saved webhook secret</label><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Stripe Webhook URL</th>
                                <td>
                                    <input type="url" value="<?php echo esc_attr($stripe_webhook_url); ?>" class="large-text code" readonly>
                                    <p class="description">Add this endpoint in Stripe for <code>invoice.paid</code>, <code>invoice.payment_failed</code>, <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code>, and <code>checkout.session.completed</code>.</p>
                                    <p class="description">Payments are matched to clients using Stripe metadata named <code>wnq_client_id</code> or <code>client_id</code>, Checkout's client reference ID, or the client's saved billing email.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="settings-panel">
                        <h2>Google Ads API</h2>
                        <p class="description">Internal, read-only reporting connection. Credentials stay server-side and are never sent to the client portal JavaScript. Use the developer token, MCC ID, OAuth client, OAuth secret, and OAuth refresh token here.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="wnq_google_ads_developer_token">Developer Token</label></th>
                                <td>
                                    <input type="password" name="wnq_google_ads_developer_token" id="wnq_google_ads_developer_token" value="" class="large-text" placeholder="<?php echo esc_attr($google_ads_has_developer_token ? 'Saved - leave blank to keep current token' : 'Enter Google Ads developer token'); ?>" autocomplete="off">
                                    <?php if ($google_ads_has_developer_token): ?>
                                        <label class="wnq-inline-check">
                                            <input type="checkbox" name="wnq_google_ads_clear_developer_token" value="1">
                                            Clear saved token
                                        </label>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_google_ads_manager_customer_id">Manager Customer ID</label></th>
                                <td>
                                    <input type="text" name="wnq_google_ads_manager_customer_id" id="wnq_google_ads_manager_customer_id" value="<?php echo esc_attr($google_ads_manager_customer_id); ?>" class="regular-text" placeholder="725-731-6543">
                                    <p class="description">Your Google Ads manager account ID. The API uses this to list child/client accounts.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_google_ads_access_level">Access Level</label></th>
                                <td>
                                    <select name="wnq_google_ads_access_level" id="wnq_google_ads_access_level">
                                        <option value="test" <?php selected($google_ads_access_level, 'test'); ?>>Test Account Access</option>
                                        <option value="basic" <?php selected($google_ads_access_level, 'basic'); ?>>Basic Access</option>
                                        <option value="standard" <?php selected($google_ads_access_level, 'standard'); ?>>Standard Access</option>
                                    </select>
                                    <p class="description">This is a portal display label because Google does not expose the developer-token access tier through the reporting API. After Google approves Basic Access, select Basic Access here and save.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_google_ads_oauth_client_id">OAuth Client ID</label></th>
                                <td>
                                    <input type="password" name="wnq_google_ads_oauth_client_id" id="wnq_google_ads_oauth_client_id" value="" class="large-text" placeholder="<?php echo esc_attr($google_ads_has_oauth_client_id ? 'Saved - leave blank to keep current client ID' : 'OAuth client ID'); ?>" autocomplete="off">
                                    <?php if ($google_ads_has_oauth_client_id): ?><label class="wnq-inline-check"><input type="checkbox" name="wnq_google_ads_clear_oauth_client_id" value="1"> Clear saved client ID</label><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_google_ads_oauth_client_secret">OAuth Client Secret</label></th>
                                <td>
                                    <input type="password" name="wnq_google_ads_oauth_client_secret" id="wnq_google_ads_oauth_client_secret" value="" class="large-text" placeholder="<?php echo esc_attr($google_ads_has_oauth_client_secret ? 'Saved - leave blank to keep current secret' : 'OAuth client secret'); ?>" autocomplete="off">
                                    <?php if ($google_ads_has_oauth_client_secret): ?><label class="wnq-inline-check"><input type="checkbox" name="wnq_google_ads_clear_oauth_client_secret" value="1"> Clear saved client secret</label><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_google_ads_refresh_token">OAuth Refresh Token</label></th>
                                <td>
                                    <input type="password" name="wnq_google_ads_refresh_token" id="wnq_google_ads_refresh_token" value="" class="large-text" placeholder="<?php echo esc_attr($google_ads_has_refresh_token ? 'Saved - leave blank to keep current refresh token' : 'OAuth refresh token'); ?>" autocomplete="off">
                                    <p class="description">Required by Google Ads API for read-only reports. The developer token alone cannot fetch account data.</p>
                                    <?php if ($google_ads_has_refresh_token): ?><label class="wnq-inline-check"><input type="checkbox" name="wnq_google_ads_clear_refresh_token" value="1"> Clear saved refresh token</label><?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wnq-ads-help">
                            <strong>Required connection</strong>
                            <span>Developer token</span><span>Manager customer ID</span><span>OAuth client ID</span><span>OAuth client secret</span><span>OAuth refresh token</span>
                            <p>An API key and service account are not used by this OAuth reporting connection. The OAuth refresh token must be generated from a Google account that can view the manager account and its linked client accounts.</p>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h2>Telegram Notifications</h2>
                        <p class="description">Internal operational alerts sent to a private Telegram group. The bot token stays server-side and is never exposed in the client portal.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th>Enable Notifications</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wnq_telegram_enabled" value="1" <?php checked($telegram_enabled); ?>>
                                        Allow the portal to send enabled internal alerts
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_telegram_bot_token">Bot Token</label></th>
                                <td>
                                    <input type="password" name="wnq_telegram_bot_token" id="wnq_telegram_bot_token" value="" class="large-text" placeholder="<?php echo esc_attr($telegram_has_token ? 'Saved - leave blank to keep current token' : 'Paste the BotFather token'); ?>" autocomplete="new-password">
                                    <?php if ($telegram_has_token): ?>
                                        <label class="wnq-inline-check"><input type="checkbox" name="wnq_telegram_clear_bot_token" value="1"> Clear saved token</label>
                                    <?php endif; ?>
                                    <p class="description">Generate a fresh token in BotFather if a previous token appeared in a screenshot or message.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wnq_telegram_chat_id">Group Chat ID</label></th>
                                <td>
                                    <input type="text" name="wnq_telegram_chat_id" id="wnq_telegram_chat_id" value="<?php echo esc_attr($telegram_chat_id); ?>" class="regular-text" placeholder="-1001234567890" inputmode="numeric">
                                    <p class="description">Add the bot to the group and send <code>/start@YourBotUsername</code> there before finding the group. Telegram group IDs are negative and may begin with <code>-100</code>.</p>
                                    <?php if (!empty($telegram_discovery['chats']) && is_array($telegram_discovery['chats'])): ?>
                                        <div class="wnq-telegram-groups" aria-label="Discovered Telegram groups">
                                            <?php foreach ($telegram_discovery['chats'] as $chat): ?>
                                                <button type="button" class="button wnq-telegram-group" data-chat-id="<?php echo esc_attr((string)($chat['id'] ?? '')); ?>">
                                                    <?php echo esc_html((string)($chat['title'] ?? 'Telegram group')); ?>
                                                    <code><?php echo esc_html((string)($chat['id'] ?? '')); ?></code>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Instant Bot Replies</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wnq_telegram_webhook_enabled" value="1" <?php checked($telegram_webhook_enabled); ?>>
                                        Use secure instant delivery for commands and Golden AI questions
                                    </label>
                                    <p class="description">Recommended. Telegram sends each question directly to WordPress instead of waiting for site traffic to trigger cron. Cron remains available as an automatic fallback.</p>
                                    <p class="description"><strong>Webhook endpoint:</strong> <code><?php echo esc_html($telegram_webhook_endpoint); ?></code></p>
                                </td>
                            </tr>
                            <tr>
                                <th>Alert Types</th>
                                <td>
                                    <div class="wnq-notification-events">
                                        <?php foreach ($telegram_event_labels as $event_key => $event_label): ?>
                                            <label>
                                                <input type="checkbox" name="wnq_telegram_events[<?php echo esc_attr($event_key); ?>]" value="1" <?php checked(!empty($telegram_events[$event_key])); ?>>
                                                <?php echo esc_html($event_label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        </table>
                        <div class="wnq-notification-health">
                            <span><strong>Reply delivery</strong><?php echo esc_html($telegram_webhook_active ? 'Instant webhook' : 'Cron fallback'); ?></span>
                            <span><strong>Last question check</strong><?php echo esc_html($telegram_last_command_check !== '' ? $telegram_last_command_check : 'Never'); ?></span>
                            <span><strong>Last alert</strong><?php echo esc_html($telegram_last_sent !== '' ? $telegram_last_sent : 'Never'); ?></span>
                            <span><strong>Last scheduled check</strong><?php echo esc_html($telegram_last_check !== '' ? $telegram_last_check : 'Never'); ?></span>
                            <span><strong>Webhook sync</strong><?php echo esc_html($telegram_webhook_last_sync !== '' ? $telegram_webhook_last_sync : 'Never'); ?></span>
                            <span class="<?php echo ($telegram_last_error !== '' || $telegram_webhook_last_error !== '') ? 'has-error' : ''; ?>"><strong>Last delivery issue</strong><?php echo esc_html($telegram_last_error !== '' ? $telegram_last_error : ($telegram_webhook_last_error !== '' ? $telegram_webhook_last_error : 'None')); ?></span>
                        </div>
                        <div class="wnq-ai-assistant">
                            <div class="wnq-ai-assistant-head">
                                <div>
                                    <span>GOLDEN AI ASSISTANT</span>
                                    <h3>Read-only answers from WordPress</h3>
                                </div>
                                <span class="status-pill <?php echo $ai_configured ? 'active' : 'inactive'; ?>"><?php echo $ai_configured ? 'AI ready' : 'AI setup needed'; ?></span>
                            </div>
                            <p>Golden uses the existing <?php echo esc_html($ai_provider_name); ?> provider<?php echo $ai_model !== '' ? ' (' . esc_html($ai_model) . ')' : ''; ?>. It can read approved client, billing, Money Management, task, report, request, CRM, and Google Ads data, but natural-language questions cannot change WordPress.</p>
                            <div class="wnq-ai-assistant-grid">
                                <span><strong>Natural question</strong><code>Hey Golden: when is Lucas payment date?</code></span>
                                <span><strong>Reliable command</strong><code>/ask What is the Golden package onboarding SOP?</code></span>
                                <span><strong>Add internal knowledge</strong><a href="<?php echo esc_url(admin_url('admin.php?page=wnq-ai-knowledge')); ?>">Open AI Knowledge Base</a></span>
                                <span><strong>Telegram group privacy</strong>Disable privacy for the bot in BotFather to use “Hey Golden.” <code>/ask</code> works without that change.</span>
                            </div>
                            <p class="description"><?php echo $telegram_webhook_active ? 'Questions are delivered to WordPress instantly through a signed Telegram webhook.' : 'Instant delivery is not active, so WordPress Cron is being used as a fallback and may depend on site traffic.'; ?> No API keys, tokens, passwords, or raw credentials are included in AI context.</p>
                            <?php if (!$ai_configured): ?><p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wnq-seo-hub-settings')); ?>">Configure Existing AI Provider</a></p><?php endif; ?>
                        </div>
                        <div class="wnq-command-guide">
                            <div>
                                <h3>Bot Commands</h3>
                                <p>AI questions are read-only. Explicit task commands can add or complete agency tasks. Client CRM activity is not sent as a notification.</p>
                            </div>
                            <div class="wnq-command-grid">
                                <?php foreach ($telegram_commands as $command => $description): ?>
                                    <span><code>/<?php echo esc_html($command); ?></code><?php echo esc_html($description); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
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
                        <button type="submit" name="wnq_test_google_ads" value="1" class="button button-secondary button-large">Save &amp; Test Google Ads</button>
                        <button type="submit" name="wnq_find_telegram_groups" value="1" class="button button-secondary button-large">Save &amp; Find Telegram Groups</button>
                        <button type="submit" name="wnq_test_telegram" value="1" class="button button-secondary button-large">Save &amp; Test Telegram</button>
                        <button type="submit" name="wnq_sync_telegram_commands" value="1" class="button button-secondary button-large">Save &amp; Sync Bot Commands</button>
                        <button type="submit" name="wnq_sync_telegram_webhook" value="1" class="button button-secondary button-large">Save &amp; Repair Instant Replies</button>
                        <button type="submit" name="wnq_run_notification_checks" value="1" class="button button-secondary button-large">Save &amp; Run Alert Checks</button>
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
                        <dt>Google Ads Token</dt>
                        <dd><span class="status-pill <?php echo $google_ads_has_developer_token ? 'active' : 'inactive'; ?>"><?php echo $google_ads_has_developer_token ? 'Saved' : 'Missing'; ?></span></dd>
                        <dt>Google Ads Access</dt>
                        <dd><?php echo esc_html(ucfirst((string)$google_ads_access_level)); ?></dd>
                        <dt>Google Ads OAuth</dt>
                        <dd><span class="status-pill <?php echo ($google_ads_has_oauth_client_id && $google_ads_has_oauth_client_secret && $google_ads_has_refresh_token) ? 'active' : 'inactive'; ?>"><?php echo ($google_ads_has_oauth_client_id && $google_ads_has_oauth_client_secret && $google_ads_has_refresh_token) ? 'Saved' : 'Missing'; ?></span></dd>
                        <dt>Telegram Alerts</dt>
                        <dd><span class="status-pill <?php echo ($telegram_enabled && $telegram_has_token && $telegram_chat_id !== '') ? 'active' : 'inactive'; ?>"><?php echo ($telegram_enabled && $telegram_has_token && $telegram_chat_id !== '') ? 'Enabled' : 'Not configured'; ?></span></dd>
                        <dt>Golden AI</dt>
                        <dd><span class="status-pill <?php echo $ai_configured ? 'active' : 'inactive'; ?>"><?php echo esc_html($ai_configured ? $ai_provider_name . ' ready' : 'Provider key missing'); ?></span></dd>
                        <dt>Stripe Webhook</dt>
                        <dd><span class="status-pill <?php echo $stripe_has_webhook_secret ? 'active' : 'inactive'; ?>"><?php echo $stripe_has_webhook_secret ? 'Ready' : 'Not configured'; ?></span></dd>
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
        .wnq-inline-check {
            display: block;
            margin-top: 8px;
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
        .wnq-ads-help {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 14px;
            margin-top: 12px;
            border-left: 4px solid #d7b846;
            background: #fffaf0;
        }
        .wnq-ads-help strong, .wnq-ads-help p { flex-basis: 100%; margin: 0; }
        .wnq-ads-help span { padding: 4px 8px; border-radius: 4px; background: #fff; border: 1px solid #e5d9ae; }
        .wnq-telegram-groups {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .wnq-telegram-group code {
            margin-left: 6px;
        }
        .wnq-notification-events {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 10px 18px;
            max-width: 760px;
        }
        .wnq-notification-events label {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .wnq-money-input {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
        }
        .wnq-money-input input { width: 150px; }
        .wnq-notification-health {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 16px;
        }
        .wnq-notification-health span {
            display: grid;
            gap: 4px;
            padding: 12px;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            background: #f6f7f7;
        }
        .wnq-notification-health .has-error { border-color: #d63638; background: #fcf0f1; }
        .wnq-ai-assistant {
            margin-top: 16px;
            padding: 18px;
            border: 1px solid #d9c468;
            border-radius: 8px;
            background: #fffdf5;
        }
        .wnq-ai-assistant-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }
        .wnq-ai-assistant-head h3 { margin: 4px 0 0; font-size: 18px; }
        .wnq-ai-assistant-head > div > span { color: #806500; font-size: 11px; font-weight: 800; letter-spacing: .08em; }
        .wnq-ai-assistant > p { color: #50575e; line-height: 1.55; }
        .wnq-ai-assistant-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .wnq-ai-assistant-grid > span {
            display: grid;
            gap: 6px;
            padding: 12px;
            border: 1px solid #e7dfbf;
            border-radius: 6px;
            background: #fff;
        }
        .wnq-ai-assistant-grid code { overflow-wrap: anywhere; }
        .wnq-command-guide {
            display: grid;
            grid-template-columns: minmax(220px, .7fr) minmax(320px, 1.3fr);
            gap: 20px;
            align-items: start;
            margin-top: 16px;
            padding: 18px;
            border: 1px solid #e4d7a8;
            border-radius: 8px;
            background: linear-gradient(135deg, #fffdf6, #f8f4e7);
        }
        .wnq-command-guide h3, .wnq-command-guide p { margin: 0; }
        .wnq-command-guide p { margin-top: 6px; color: #646970; }
        .wnq-command-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .wnq-command-grid span {
            display: grid;
            grid-template-columns: 86px 1fr;
            gap: 8px;
            align-items: center;
            min-width: 0;
            padding: 9px 10px;
            border: 1px solid #e5e1d5;
            border-radius: 6px;
            background: #fff;
        }
        .wnq-command-grid code { color: #755d00; font-weight: 700; }
        @media (max-width: 960px) {
            .wnq-settings-layout {
                grid-template-columns: 1fr;
            }
            .wnq-notification-events, .wnq-notification-health, .wnq-ai-assistant-grid, .wnq-command-guide, .wnq-command-grid { grid-template-columns: 1fr; }
        }
        </style>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.wnq-telegram-group');
            if (!button) {
                return;
            }
            var input = document.getElementById('wnq_telegram_chat_id');
            if (input) {
                input.value = button.getAttribute('data-chat-id') || '';
                input.focus();
            }
        });
        </script>
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
        update_option('wnq_stripe_test_publishable_key', sanitize_text_field($_POST['wnq_stripe_test_publishable_key'] ?? ''));
        if (!empty($_POST['wnq_stripe_clear_secret_key'])) {
            update_option('wnq_stripe_test_secret_key', '', false);
        } else {
            $stripe_secret_key = sanitize_text_field(wp_unslash($_POST['wnq_stripe_test_secret_key'] ?? ''));
            if ($stripe_secret_key !== '') {
                update_option('wnq_stripe_test_secret_key', $stripe_secret_key, false);
            }
        }
        if (!empty($_POST['wnq_stripe_clear_webhook_secret'])) {
            update_option('wnq_stripe_webhook_secret', '', false);
        } else {
            $stripe_webhook_secret = sanitize_text_field(wp_unslash($_POST['wnq_stripe_webhook_secret'] ?? ''));
            if ($stripe_webhook_secret !== '') {
                update_option('wnq_stripe_webhook_secret', $stripe_webhook_secret, false);
            }
        }
        $previous_telegram_token = (string)get_option('wnq_telegram_bot_token', '');
        $previous_telegram_chat_id = (string)get_option('wnq_telegram_chat_id', '');
        update_option('wnq_telegram_enabled', !empty($_POST['wnq_telegram_enabled']), false);
        update_option('wnq_telegram_webhook_enabled', !empty($_POST['wnq_telegram_webhook_enabled']), false);
        $telegram_event_defaults = class_exists('WNQ\\Services\\NotificationManager')
            ? \WNQ\Services\NotificationManager::eventDefaults()
            : [];
        $posted_telegram_events = is_array($_POST['wnq_telegram_events'] ?? null) ? wp_unslash($_POST['wnq_telegram_events']) : [];
        $telegram_events = [];
        foreach ($telegram_event_defaults as $event_key => $default_value) {
            $telegram_events[$event_key] = !empty($posted_telegram_events[$event_key]);
        }
        update_option('wnq_telegram_events', $telegram_events, false);
        $telegram_chat_id = sanitize_text_field(wp_unslash($_POST['wnq_telegram_chat_id'] ?? ''));
        if ($telegram_chat_id !== '' && preg_match('/^-?\d+$/', $telegram_chat_id) !== 1) {
            $telegram_chat_id = '';
        }
        update_option('wnq_telegram_chat_id', $telegram_chat_id, false);
        if (!empty($_POST['wnq_telegram_clear_bot_token'])) {
            update_option('wnq_telegram_bot_token', '', false);
        } else {
            $telegram_token = sanitize_text_field(wp_unslash($_POST['wnq_telegram_bot_token'] ?? ''));
            if ($telegram_token !== '') {
                update_option('wnq_telegram_bot_token', $telegram_token, false);
            }
        }
        if (
            $previous_telegram_token !== (string)get_option('wnq_telegram_bot_token', '')
            || $previous_telegram_chat_id !== (string)get_option('wnq_telegram_chat_id', '')
        ) {
            delete_option('wnq_telegram_update_offset');
            delete_option('wnq_telegram_commands_hash');
            delete_option('wnq_telegram_webhook_hash');
            delete_option('wnq_telegram_webhook_active');
            delete_option('wnq_telegram_webhook_processed_updates');
            delete_transient('wnq_telegram_webhook_retry_lock');
        }
        $google_ads_token = sanitize_text_field(wp_unslash($_POST['wnq_google_ads_developer_token'] ?? ''));
        $clear_google_ads_token = !empty($_POST['wnq_google_ads_clear_developer_token']);
        if ($clear_google_ads_token) {
            update_option('wnq_google_ads_developer_token', '', false);
        } elseif ($google_ads_token !== '') {
            update_option('wnq_google_ads_developer_token', $google_ads_token, false);
        }
        $google_ads_access_level = sanitize_key($_POST['wnq_google_ads_access_level'] ?? 'test');
        if (!in_array($google_ads_access_level, ['test', 'basic', 'standard'], true)) {
            $google_ads_access_level = 'test';
        }
        update_option('wnq_google_ads_access_level', $google_ads_access_level, false);
        $google_ads_manager_customer_id = preg_replace('/[^0-9-]/', '', sanitize_text_field($_POST['wnq_google_ads_manager_customer_id'] ?? ''));
        update_option('wnq_google_ads_manager_customer_id', $google_ads_manager_customer_id, false);
        delete_transient('wnq_google_ads_accounts_' . md5($google_ads_manager_customer_id));
        foreach ([
            'wnq_google_ads_oauth_client_id',
            'wnq_google_ads_oauth_client_secret',
            'wnq_google_ads_refresh_token',
        ] as $secret_option) {
            $clear_key = 'wnq_google_ads_clear_' . str_replace('wnq_google_ads_', '', $secret_option);
            if (!empty($_POST[$clear_key])) {
                update_option($secret_option, '', false);
                continue;
            }
            $secret_value = sanitize_text_field(wp_unslash($_POST[$secret_option] ?? ''));
            if ($secret_value !== '') {
                update_option($secret_option, $secret_value, false);
            }
        }
        update_option('wnq_support_name', sanitize_text_field($_POST['wnq_support_name'] ?? ''));
        update_option('wnq_support_title', sanitize_text_field($_POST['wnq_support_title'] ?? ''));
        update_option('wnq_support_email', sanitize_email($_POST['wnq_support_email'] ?? ''));
        update_option('wnq_support_phone', sanitize_text_field($_POST['wnq_support_phone'] ?? ''));
        update_option('wnq_support_phone_display', sanitize_text_field($_POST['wnq_support_phone_display'] ?? ''));

        if (class_exists('WNQ\\Services\\NotificationManager')) {
            \WNQ\Services\NotificationManager::syncSchedule();
        }

        $tested = false;
        if (!empty($_POST['wnq_test_google_ads'])) {
            $tested = true;
            if (!class_exists('WNQ\\Services\\GoogleAdsClient')) {
                require_once WNQ_PORTAL_PATH . 'includes/Services/GoogleAdsClient.php';
            }
            $ads = new \WNQ\Services\GoogleAdsClient([
                'developer_token' => (string)get_option('wnq_google_ads_developer_token', ''),
                'manager_customer_id' => (string)get_option('wnq_google_ads_manager_customer_id', ''),
                'oauth_client_id' => (string)get_option('wnq_google_ads_oauth_client_id', ''),
                'oauth_client_secret' => (string)get_option('wnq_google_ads_oauth_client_secret', ''),
                'refresh_token' => (string)get_option('wnq_google_ads_refresh_token', ''),
            ]);
            set_transient('wnq_google_ads_test_' . get_current_user_id(), $ads->connectionTest(), 5 * MINUTE_IN_SECONDS);
        }

        $telegram_tested = false;
        $telegram_groups_found = false;
        if (!empty($_POST['wnq_find_telegram_groups'])) {
            $telegram_groups_found = true;
            if (!class_exists('WNQ\\Services\\TelegramNotifier')) {
                require_once WNQ_PORTAL_PATH . 'includes/Services/TelegramNotifier.php';
            }
            $telegram = new \WNQ\Services\TelegramNotifier();
            if ((bool)get_option('wnq_telegram_webhook_active', false)) {
                $telegram->deleteWebhook();
                delete_option('wnq_telegram_webhook_hash');
                delete_option('wnq_telegram_webhook_active');
            }
            $discovery = $telegram->discoverChats();
            if (!empty($discovery['ok']) && count((array)($discovery['chats'] ?? [])) === 1) {
                $chat = reset($discovery['chats']);
                if (is_array($chat) && preg_match('/^-?\d+$/', (string)($chat['id'] ?? '')) === 1) {
                    update_option('wnq_telegram_chat_id', (string)$chat['id'], false);
                }
            }
            set_transient('wnq_telegram_discovery_' . get_current_user_id(), $discovery, 5 * MINUTE_IN_SECONDS);
            if (class_exists('WNQ\\Services\\NotificationManager')) {
                \WNQ\Services\NotificationManager::syncWebhook(true);
            }
        }

        if (!empty($_POST['wnq_test_telegram'])) {
            $telegram_tested = true;
            if (!class_exists('WNQ\\Services\\TelegramNotifier')) {
                require_once WNQ_PORTAL_PATH . 'includes/Services/TelegramNotifier.php';
            }
            $telegram = new \WNQ\Services\TelegramNotifier();
            set_transient(
                'wnq_telegram_test_' . get_current_user_id(),
                $telegram->send('Golden Web Marketing portal notifications are connected.'),
                5 * MINUTE_IN_SECONDS
            );
        }

        $telegram_commands_synced = false;
        if (!empty($_POST['wnq_sync_telegram_commands'])) {
            $telegram_commands_synced = true;
            if (!class_exists('WNQ\\Services\\NotificationManager')) {
                require_once WNQ_PORTAL_PATH . 'includes/Services/NotificationManager.php';
            }
            set_transient(
                'wnq_telegram_command_sync_' . get_current_user_id(),
                \WNQ\Services\NotificationManager::syncBotCommands(true),
                5 * MINUTE_IN_SECONDS
            );
        }

        $telegram_webhook_synced = false;
        if (!empty($_POST['wnq_sync_telegram_webhook'])) {
            $telegram_webhook_synced = true;
            if (!class_exists('WNQ\\Services\\NotificationManager')) {
                require_once WNQ_PORTAL_PATH . 'includes/Services/NotificationManager.php';
            }
            set_transient(
                'wnq_telegram_webhook_sync_' . get_current_user_id(),
                \WNQ\Services\NotificationManager::syncWebhook(true),
                5 * MINUTE_IN_SECONDS
            );
        }

        $notification_checks_run = false;
        if (!empty($_POST['wnq_run_notification_checks'])) {
            $notification_checks_run = true;
            if (!class_exists('WNQ\\Services\\NotificationManager')) {
                require_once WNQ_PORTAL_PATH . 'includes/Services/NotificationManager.php';
            }
            set_transient(
                'wnq_notification_check_' . get_current_user_id(),
                \WNQ\Services\NotificationManager::runScheduledChecks(true),
                5 * MINUTE_IN_SECONDS
            );
        }

        wp_redirect(add_query_arg([
            'page' => 'wnq-portal',
            'settings-updated' => 'true',
            'ads-tested' => $tested ? 'true' : 'false',
            'telegram-groups-found' => $telegram_groups_found ? 'true' : 'false',
            'telegram-tested' => $telegram_tested ? 'true' : 'false',
            'telegram-commands-synced' => $telegram_commands_synced ? 'true' : 'false',
            'telegram-webhook-synced' => $telegram_webhook_synced ? 'true' : 'false',
            'notification-checks-run' => $notification_checks_run ? 'true' : 'false',
        ], admin_url('admin.php')));
        exit;
    }
}
