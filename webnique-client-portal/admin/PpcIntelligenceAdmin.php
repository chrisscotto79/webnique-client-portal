<?php
/**
 * Internal-only PPC Intelligence connection foundation.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Admin;

use WNQ\Core\Permissions;
use WNQ\Models\Client;
use WNQ\Models\PpcAccount;
use WNQ\Services\GoogleAdsClient;
use WNQ\Services\GoogleAdsCredentials;
use WNQ\Services\GoogleAdsQueryService;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcIntelligenceAdmin
{
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'maybeUpgrade']);
        add_action('admin_post_wnq_ppc_save_credentials', [self::class, 'handleSaveCredentials']);
        add_action('admin_post_wnq_ppc_test_credentials', [self::class, 'handleTestCredentials']);
        add_action('admin_post_wnq_ppc_save_mapping', [self::class, 'handleSaveMapping']);
        add_action('admin_post_wnq_ppc_test_account', [self::class, 'handleTestAccount']);
        add_action('admin_post_wnq_ppc_disconnect_account', [self::class, 'handleDisconnect']);
    }

    public static function maybeUpgrade(): void
    {
        PpcAccount::maybeUpgrade();
        GoogleAdsCredentials::migrateLegacy();
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap('gwm_manage_ppc')) {
            $admin->add_cap('gwm_manage_ppc');
        }
    }

    public static function canManage(): bool
    {
        return Permissions::currentUserCanManagePpc();
    }

    public static function renderClientPanel(array $client): void
    {
        if (!self::canManage()) {
            wp_die(__('You do not have permission to manage PPC Intelligence.', 'webnique-portal'), '', ['response' => 403]);
        }

        $client_id = sanitize_text_field((string)($client['client_id'] ?? ''));
        $connection = PpcAccount::getByClientId($client_id);
        $credential_status = GoogleAdsCredentials::status();
        $accounts = [];
        $discovery_error = '';

        if (!empty($credential_status['configured'])) {
            $query = new GoogleAdsQueryService();
            $accounts = $query->discoverAccounts(!empty($_GET['refresh_accounts']));
            $discovery_error = implode(' ', $query->errors());
        }

        $notice = get_transient('wnq_ppc_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('wnq_ppc_notice_' . get_current_user_id());
        }

        ?>
        <div class="wnq-ppc-intelligence">
            <?php if (is_array($notice)): ?>
                <div class="notice <?php echo !empty($notice['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html((string)($notice['message'] ?? 'PPC action completed.')); ?></p></div>
            <?php endif; ?>

            <div class="wnq-ppc-hero">
                <div>
                    <span class="wnq-ppc-eyebrow">Internal agency workspace</span>
                    <h2>PPC Intelligence</h2>
                    <p>Google Ads connection foundation for <?php echo esc_html((string)($client['company'] ?: $client['name'])); ?>. Phase 1 is strictly read-only.</p>
                </div>
                <span class="wnq-read-only-badge">Read only</span>
            </div>

            <div class="wnq-ppc-status-grid">
                <?php self::statusCard('Credentials', !empty($credential_status['configured']), !empty($credential_status['configured']) ? 'Configured securely' : 'Setup required'); ?>
                <?php self::statusCard('Client account', !empty($connection['customer_id']), !empty($connection['account_name']) ? (string)$connection['account_name'] : 'Not connected'); ?>
                <?php self::statusCard('API status', ($connection['connection_status'] ?? '') === 'connected', ucfirst((string)($connection['connection_status'] ?? 'not tested'))); ?>
                <?php self::statusCard('Last successful sync', !empty($connection['last_connected_at']), !empty($connection['last_connected_at']) ? self::displayDate((string)$connection['last_connected_at']) : 'Never'); ?>
            </div>

            <div class="wnq-ppc-layout">
                <section class="wnq-ppc-card">
                    <h3>Client account connection</h3>
                    <p class="description">Select the exact Google Ads child account belonging to this client. Automatic fuzzy matching is not used when saving.</p>

                    <?php if ($connection && !empty($connection['customer_id'])): ?>
                        <dl class="wnq-account-details">
                            <div><dt>Account name</dt><dd><?php echo esc_html((string)$connection['account_name']); ?></dd></div>
                            <div><dt>Customer ID</dt><dd><?php echo esc_html(self::formatCustomerId((string)$connection['customer_id'])); ?></dd></div>
                            <div><dt>Manager/MCC</dt><dd><?php echo esc_html(self::formatCustomerId((string)$connection['manager_customer_id'])); ?></dd></div>
                            <div><dt>Currency</dt><dd><?php echo esc_html((string)($connection['currency_code'] ?: 'Not returned')); ?></dd></div>
                            <div><dt>Timezone</dt><dd><?php echo esc_html((string)($connection['time_zone'] ?: 'Not returned')); ?></dd></div>
                            <div><dt>Account status</dt><dd><?php echo esc_html(ucfirst((string)($connection['metadata']['account_status'] ?? 'unknown'))); ?></dd></div>
                        </dl>
                        <?php if (!empty($connection['last_error'])): ?>
                            <div class="notice notice-error inline"><p><?php echo esc_html((string)$connection['last_error']); ?></p></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (empty($credential_status['configured'])): ?>
                        <div class="notice notice-warning inline"><p>Complete the shared API credentials before selecting a client account.</p></div>
                    <?php else: ?>
                        <?php if ($discovery_error !== ''): ?>
                            <div class="notice notice-error inline"><p><?php echo esc_html($discovery_error); ?></p></div>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wnq_ppc_save_mapping_' . $client_id); ?>
                            <input type="hidden" name="action" value="wnq_ppc_save_mapping">
                            <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                            <label for="wnq-ppc-customer-id"><strong>Google Ads client account</strong></label>
                            <select id="wnq-ppc-customer-id" name="customer_id" class="widefat" required>
                                <option value="">Select an accessible child account</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo esc_attr((string)$account['customer_id']); ?>" <?php selected((string)($connection['customer_id'] ?? ''), (string)$account['customer_id']); ?>>
                                        <?php echo esc_html((string)($account['name'] ?: 'Unnamed account') . ' — ' . self::formatCustomerId((string)$account['customer_id'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="wnq-ppc-actions">
                                <button type="submit" class="button button-primary">Save exact account mapping</button>
                                <a class="button" href="<?php echo esc_url(add_query_arg('refresh_accounts', '1')); ?>">Refresh account list</a>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($connection && !empty($connection['customer_id'])): ?>
                        <div class="wnq-ppc-actions wnq-ppc-actions-separated">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wnq_ppc_test_account_' . $client_id); ?>
                                <input type="hidden" name="action" value="wnq_ppc_test_account">
                                <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                                <button type="submit" class="button">Test linked account</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Disconnect this Google Ads account from the client? No Google Ads data will be changed.');">
                                <?php wp_nonce_field('wnq_ppc_disconnect_account_' . $client_id); ?>
                                <input type="hidden" name="action" value="wnq_ppc_disconnect_account">
                                <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                                <button type="submit" class="button button-link-delete">Disconnect account</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="wnq-ppc-card">
                    <h3>Shared Google Ads API credentials</h3>
                    <p class="description">Used server-side for all managed accounts. Saved secrets are encrypted and never returned to the browser.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                        <?php wp_nonce_field('wnq_ppc_save_credentials_' . $client_id); ?>
                        <input type="hidden" name="action" value="wnq_ppc_save_credentials">
                        <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                        <?php self::secretField('Developer token', 'developer_token', !empty($credential_status['has_developer_token'])); ?>
                        <label><span>Manager/MCC customer ID</span><input type="text" name="manager_customer_id" value="<?php echo esc_attr(self::formatCustomerId((string)$credential_status['manager_customer_id'])); ?>" inputmode="numeric" placeholder="123-456-7890" required></label>
                        <label><span>API access level</span><select name="access_level">
                            <?php foreach (['test' => 'Test Account Access', 'explorer' => 'Explorer Access', 'basic' => 'Basic Access', 'standard' => 'Standard Access'] as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected((string)$credential_status['access_level'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <?php self::secretField('OAuth client ID', 'oauth_client_id', !empty($credential_status['has_oauth_client_id'])); ?>
                        <?php self::secretField('OAuth client secret', 'oauth_client_secret', !empty($credential_status['has_oauth_client_secret'])); ?>
                        <?php self::secretField('OAuth refresh token', 'refresh_token', !empty($credential_status['has_refresh_token'])); ?>
                        <div class="wnq-ppc-actions"><button type="submit" class="button button-primary">Save credentials securely</button></div>
                    </form>
                    <?php if (!empty($credential_status['configured'])): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wnq-ppc-test-form">
                            <?php wp_nonce_field('wnq_ppc_test_credentials_' . $client_id); ?>
                            <input type="hidden" name="action" value="wnq_ppc_test_credentials">
                            <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                            <button type="submit" class="button">Test MCC connection</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php self::styles(); ?>
        <?php
    }

    public static function handleSaveCredentials(): void
    {
        $client_id = self::requestClientId();
        self::authorize('wnq_ppc_save_credentials_' . $client_id);
        $manager_id = preg_replace('/\D+/', '', (string)wp_unslash($_POST['manager_customer_id'] ?? '')) ?: '';
        if (strlen($manager_id) !== 10) {
            self::finish($client_id, false, 'Enter a valid 10-digit Manager/MCC customer ID.');
        }
        $saved = GoogleAdsCredentials::save([
            'developer_token'     => wp_unslash($_POST['developer_token'] ?? ''),
            'manager_customer_id' => $manager_id,
            'access_level'        => sanitize_key((string)($_POST['access_level'] ?? 'test')),
            'oauth_client_id'     => wp_unslash($_POST['oauth_client_id'] ?? ''),
            'oauth_client_secret' => wp_unslash($_POST['oauth_client_secret'] ?? ''),
            'refresh_token'       => wp_unslash($_POST['refresh_token'] ?? ''),
        ]);
        self::finish($client_id, $saved, $saved ? 'Google Ads credentials saved securely.' : 'Google Ads credentials could not be encrypted and saved.');
    }

    public static function handleTestCredentials(): void
    {
        $client_id = self::requestClientId();
        self::authorize('wnq_ppc_test_credentials_' . $client_id);
        $ads = new GoogleAdsClient(GoogleAdsCredentials::get());
        $result = $ads->connectionTest();
        self::finish($client_id, !empty($result['ok']), (string)($result['message'] ?? 'Connection test completed.'));
    }

    public static function handleSaveMapping(): void
    {
        $client_id = self::requestClientId();
        self::authorize('wnq_ppc_save_mapping_' . $client_id);
        $customer_id = preg_replace('/\D+/', '', (string)wp_unslash($_POST['customer_id'] ?? '')) ?: '';
        $query = new GoogleAdsQueryService();
        $accounts = $query->discoverAccounts(true);
        $account = null;
        foreach ($accounts as $candidate) {
            if (hash_equals((string)$candidate['customer_id'], $customer_id)) {
                $account = $candidate;
                break;
            }
        }
        if (!$account) {
            $message = implode(' ', $query->errors()) ?: 'The selected account is not accessible through the configured manager account.';
            self::finish($client_id, false, $message);
        }
        $existing_client_id = PpcAccount::clientIdForCustomerId($customer_id);
        if ($existing_client_id !== '' && !hash_equals($existing_client_id, $client_id)) {
            self::finish($client_id, false, 'That Google Ads account is already linked to another client. Disconnect it there before remapping.');
        }
        $credentials = GoogleAdsCredentials::get();
        $saved = PpcAccount::saveConnection($client_id, $account, (string)$credentials['manager_customer_id']);
        self::finish($client_id, $saved, $saved ? 'Google Ads account mapped to this client.' : 'The client account mapping could not be saved.');
    }

    public static function handleTestAccount(): void
    {
        $client_id = self::requestClientId();
        self::authorize('wnq_ppc_test_account_' . $client_id);
        $connection = PpcAccount::getByClientId($client_id);
        if (!$connection || empty($connection['customer_id'])) {
            self::finish($client_id, false, 'No Google Ads account is linked to this client.');
        }
        $query = new GoogleAdsQueryService();
        $metadata = $query->accountMetadata((string)$connection['customer_id']);
        $ok = !empty($metadata) && !$query->errors() && hash_equals((string)$connection['customer_id'], (string)($metadata['customer_id'] ?? ''));
        $error = $ok ? '' : (implode(' ', $query->errors()) ?: 'Google Ads did not return the linked account metadata.');
        PpcAccount::recordTest($client_id, $ok, $metadata, $error);
        self::finish($client_id, $ok, $ok ? 'Linked Google Ads account is accessible and metadata was refreshed.' : $error);
    }

    public static function handleDisconnect(): void
    {
        $client_id = self::requestClientId();
        self::authorize('wnq_ppc_disconnect_account_' . $client_id);
        $disconnected = PpcAccount::disconnect($client_id);
        self::finish($client_id, $disconnected, $disconnected ? 'Google Ads account disconnected. No Google Ads data was changed.' : 'The account connection could not be removed.');
    }

    private static function authorize(string $nonce_action): void
    {
        if (!self::canManage()) {
            wp_die(__('You do not have permission to manage PPC Intelligence.', 'webnique-portal'), '', ['response' => 403]);
        }
        check_admin_referer($nonce_action);
    }

    private static function requestClientId(): string
    {
        $client_id = sanitize_text_field((string)wp_unslash($_POST['client_id'] ?? ''));
        if ($client_id === '' || !Client::getByClientId($client_id)) {
            wp_die(__('Invalid client.', 'webnique-portal'), '', ['response' => 400]);
        }
        return $client_id;
    }

    private static function finish(string $client_id, bool $ok, string $message): void
    {
        set_transient('wnq_ppc_notice_' . get_current_user_id(), ['ok' => $ok, 'message' => sanitize_text_field($message)], 5 * MINUTE_IN_SECONDS);
        $client = Client::getByClientId($client_id);
        $url = add_query_arg([
            'page'       => 'wnq-clients',
            'action'     => 'edit',
            'id'         => (int)($client['id'] ?? 0),
            'client_tab' => 'ppc',
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function statusCard(string $label, bool $ok, string $value): void
    {
        ?><div class="wnq-ppc-status <?php echo $ok ? 'is-ok' : 'is-pending'; ?>"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($value); ?></strong></div><?php
    }

    private static function secretField(string $label, string $name, bool $saved): void
    {
        ?><label><span><?php echo esc_html($label); ?></span><input type="password" name="<?php echo esc_attr($name); ?>" value="" placeholder="<?php echo esc_attr($saved ? 'Saved — leave blank to keep current value' : 'Required'); ?>" autocomplete="new-password"></label><?php
    }

    private static function displayDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? wp_date('M j, Y g:i a', $timestamp) : $value;
    }

    private static function formatCustomerId(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        return strlen($digits) === 10 ? substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6) : ($digits ?: 'Not configured');
    }

    private static function styles(): void
    {
        ?>
        <style>
        .wnq-ppc-intelligence{max-width:1200px}.wnq-ppc-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;background:linear-gradient(135deg,#07131c,#0d539e);color:#fff;padding:26px;border-radius:12px;margin:18px 0}.wnq-ppc-hero h2{color:#fff;font-size:26px;margin:3px 0 7px}.wnq-ppc-hero p{margin:0;color:#dbeafe}.wnq-ppc-eyebrow{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#f3cf55}.wnq-read-only-badge{background:#f3cf55;color:#07131c;border-radius:999px;padding:6px 11px;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap}.wnq-ppc-status-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.wnq-ppc-status{background:#fff;border:1px solid #dcdcde;border-left:4px solid #dba617;border-radius:8px;padding:14px}.wnq-ppc-status.is-ok{border-left-color:#1f9d55}.wnq-ppc-status span{display:block;color:#646970;font-size:11px;text-transform:uppercase;font-weight:700;margin-bottom:5px}.wnq-ppc-status strong{display:block;color:#1d2327;font-size:14px;overflow-wrap:anywhere}.wnq-ppc-layout{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}.wnq-ppc-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px}.wnq-ppc-card h3{font-size:17px;margin:0 0 5px}.wnq-ppc-card label{display:block;margin:14px 0}.wnq-ppc-card label>span{display:block;font-weight:600;margin-bottom:5px}.wnq-ppc-card input,.wnq-ppc-card select{width:100%;max-width:none}.wnq-ppc-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.wnq-ppc-actions-separated{border-top:1px solid #eee;padding-top:16px;justify-content:space-between}.wnq-ppc-actions form{margin:0}.wnq-ppc-test-form{margin-top:12px}.wnq-account-details{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #e5e7eb;border-radius:8px;margin:16px 0}.wnq-account-details div{padding:12px;border-bottom:1px solid #e5e7eb}.wnq-account-details div:nth-last-child(-n+2){border-bottom:0}.wnq-account-details dt{font-size:11px;color:#646970;text-transform:uppercase;font-weight:700}.wnq-account-details dd{margin:4px 0 0;font-weight:600}.wnq-ppc-card .notice.inline{margin:14px 0;padding:1px 12px}.wnq-ppc-card .button-link-delete{color:#b32d2e}@media(max-width:900px){.wnq-ppc-status-grid{grid-template-columns:repeat(2,1fr)}.wnq-ppc-layout{grid-template-columns:1fr}}@media(max-width:600px){.wnq-ppc-hero{flex-direction:column}.wnq-ppc-status-grid,.wnq-account-details{grid-template-columns:1fr}.wnq-account-details div:nth-last-child(2){border-bottom:1px solid #e5e7eb}}
        </style>
        <?php
    }
}
