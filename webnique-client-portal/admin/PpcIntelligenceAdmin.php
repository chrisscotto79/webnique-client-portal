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
use WNQ\Services\PpcDiagnosticService;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcIntelligenceAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSubmenu'], 20);
        add_action('admin_init', [self::class, 'maybeUpgrade']);
        add_action('admin_post_wnq_ppc_save_credentials', [self::class, 'handleSaveCredentials']);
        add_action('admin_post_wnq_ppc_test_credentials', [self::class, 'handleTestCredentials']);
        add_action('admin_post_wnq_ppc_save_mapping', [self::class, 'handleSaveMapping']);
        add_action('admin_post_wnq_ppc_test_account', [self::class, 'handleTestAccount']);
        add_action('admin_post_wnq_ppc_disconnect_account', [self::class, 'handleDisconnect']);
    }

    public static function addSubmenu(): void
    {
        add_submenu_page(
            'wnq-portal',
            'PPC Management',
            'PPC Management',
            'gwm_manage_ppc',
            'wnq-ppc-management',
            [self::class, 'renderManagementPage']
        );
    }

    public static function renderManagementPage(): void
    {
        if (!self::canManage()) {
            wp_die(__('You do not have permission to manage PPC Intelligence.', 'webnique-portal'), '', ['response' => 403]);
        }

        $clients = Client::getAll();
        $credential_status = GoogleAdsCredentials::status();
        ?>
        <div class="wrap wnq-ppc-management">
            <h1>PPC Management</h1>
            <p>Manage each client’s exact Google Ads account connection. This internal section is read-only and is never shown to clients.</p>

            <?php if (empty($credential_status['configured'])): ?>
                <div class="notice notice-warning inline"><p><strong>Google Ads credentials need attention.</strong> Open any client below to securely configure and test the shared MCC connection.</p></div>
            <?php endif; ?>

            <?php if (!$clients): ?>
                <div class="notice notice-info inline"><p>No clients are available yet.</p></div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Client</th><th>Google Ads account</th><th>Customer ID</th><th>Status</th><th>Last sync</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($clients as $client): ?>
                        <?php $connection = PpcAccount::getByClientId((string)$client['client_id']); ?>
                        <tr>
                            <td><strong><?php echo esc_html((string)($client['company'] ?: $client['name'])); ?></strong><br><small><?php echo esc_html((string)$client['client_id']); ?></small></td>
                            <td><?php echo esc_html((string)($connection['account_name'] ?? 'Not connected')); ?></td>
                            <td><?php echo esc_html(!empty($connection['customer_id']) ? self::formatCustomerId((string)$connection['customer_id']) : '—'); ?></td>
                            <td><?php echo esc_html(ucfirst((string)($connection['connection_status'] ?? 'not connected'))); ?></td>
                            <td><?php echo esc_html(!empty($connection['last_sync_at']) ? self::displayDate((string)$connection['last_sync_at']) : 'Never'); ?></td>
                            <td><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wnq-clients&action=edit&id=' . (int)$client['id'] . '&client_tab=ppc')); ?>">Manage PPC</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <style>.wnq-ppc-management{max-width:1200px}.wnq-ppc-management>p{font-size:14px;color:#50575e;margin-bottom:18px}.wnq-ppc-management .notice.inline{margin:12px 0 18px}.wnq-ppc-management table{margin-top:18px}.wnq-ppc-management th,.wnq-ppc-management td{vertical-align:middle}@media(max-width:782px){.wnq-ppc-management table{display:block;overflow-x:auto}}</style>
        <?php
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
        $diagnostics = null;

        if (!empty($credential_status['configured'])) {
            $query = new GoogleAdsQueryService();
            $accounts = $query->discoverAccounts(!empty($_GET['refresh_accounts']));
            $discovery_error = implode(' ', $query->errors());
        }
        if (!empty($connection['customer_id']) && !empty($credential_status['configured'])) {
            $diagnostics = (new PpcDiagnosticService())->dashboard(
                (string)$connection['customer_id'],
                !empty($_GET['refresh_ppc'])
            );
            if (!empty($_GET['refresh_ppc'])) {
                $available_modules = array_filter($diagnostics, static fn($module): bool => is_array($module) && !empty($module['available']));
                if ($available_modules) {
                    PpcAccount::recordTest($client_id, true);
                }
            }
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

            <?php if ($connection && !empty($connection['customer_id'])): ?>
                <?php self::renderDiagnostics($diagnostics); ?>
            <?php endif; ?>

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

    private static function renderDiagnostics(?array $dashboard): void
    {
        $dashboard = is_array($dashboard) ? $dashboard : [];
        ?>
        <section class="wnq-diagnostics-shell">
            <div class="wnq-diagnostics-head">
                <div><span class="wnq-ppc-eyebrow">Phase 2</span><h2>Account intelligence</h2><p>Live, read-only Google Ads diagnostics. Each module loads independently.</p></div>
                <a class="button" href="<?php echo esc_url(add_query_arg('refresh_ppc', '1')); ?>">Refresh diagnostics</a>
            </div>
            <nav class="wnq-module-nav">
                <a href="#ppc-account">Account</a><a href="#ppc-conversions">Conversions</a><a href="#ppc-changes">Changes</a><a href="#ppc-share">Impression share</a><a href="#ppc-budgets">Budgets</a>
            </nav>
            <?php self::renderAccountDiagnostic((array)($dashboard['account_diagnostic'] ?? [])); ?>
            <?php self::renderConversionHealth((array)($dashboard['conversion_health'] ?? [])); ?>
            <?php self::renderChangeHistory((array)($dashboard['change_history'] ?? [])); ?>
            <?php self::renderImpressionShare((array)($dashboard['impression_share'] ?? [])); ?>
            <?php self::renderBudgetAnalysis((array)($dashboard['budget_analysis'] ?? [])); ?>
        </section>
        <?php
    }

    private static function renderAccountDiagnostic(array $module): void
    {
        ?>
        <article class="wnq-module" id="ppc-account"><div class="wnq-module-title"><div><h3>Account Diagnostic</h3><p>Performance by reporting window and campaign.</p></div><?php self::moduleStatus($module); ?></div>
        <?php if (empty($module['available'])): self::unavailable($module); else: ?>
            <?php if (!empty($module['message'])): ?><p class="wnq-module-note"><?php echo esc_html((string)$module['message']); ?></p><?php endif; ?>
            <div class="wnq-period-grid">
            <?php foreach (['today' => 'Today', 'last_7_days' => 'Last 7 days', 'last_30_days' => 'Last 30 days', 'current_month' => 'Current month', 'previous_month' => 'Previous month'] as $key => $label): $m = (array)($module['periods'][$key] ?? []); ?>
                <div class="wnq-period-card"><strong><?php echo esc_html($label); ?></strong><span><?php echo esc_html(self::money((float)($m['spend'] ?? 0))); ?> spend</span><b><?php echo esc_html(number_format_i18n((float)($m['conversions'] ?? 0), 2)); ?> conversions</b><small><?php echo esc_html(number_format_i18n((int)($m['clicks'] ?? 0))); ?> clicks · <?php echo esc_html(self::percent((float)($m['ctr'] ?? 0))); ?> CTR · <?php echo esc_html(self::money((float)($m['cpa'] ?? 0))); ?> CPA</small></div>
            <?php endforeach; ?>
            </div>
            <?php $counts = (array)($module['campaign_counts'] ?? []); ?><p class="wnq-module-summary"><strong><?php echo esc_html((string)($counts['enabled'] ?? 0)); ?> enabled</strong> · <?php echo esc_html((string)($counts['paused'] ?? 0)); ?> paused · <?php echo esc_html((string)($counts['total'] ?? 0)); ?> campaigns returned</p>
            <div class="wnq-table-scroll"><table><thead><tr><th>Campaign</th><th>Status</th><th>Type</th><th>Daily budget</th><th>Spend</th><th>Clicks</th><th>CTR</th><th>Conversions</th><th>CPA</th></tr></thead><tbody>
            <?php if (empty($module['campaigns'])): ?><tr><td colspan="9">No campaign activity returned for the last 30 days.</td></tr><?php else: foreach ($module['campaigns'] as $row): ?>
                <tr><td><strong><?php echo esc_html((string)$row['name']); ?></strong></td><td><?php self::pill((string)$row['status']); ?></td><td><?php echo esc_html(self::label((string)$row['type'])); ?></td><td><?php echo esc_html(self::money((float)$row['daily_budget'])); ?></td><td><?php echo esc_html(self::money((float)$row['spend'])); ?></td><td><?php echo esc_html(number_format_i18n((int)$row['clicks'])); ?></td><td><?php echo esc_html(self::percent((float)$row['ctr'])); ?></td><td><?php echo esc_html(number_format_i18n((float)$row['conversions'], 2)); ?></td><td><?php echo esc_html(self::money((float)$row['cpa'])); ?></td></tr>
            <?php endforeach; endif; ?></tbody></table></div>
        <?php endif; ?></article>
        <?php
    }

    private static function renderConversionHealth(array $module): void
    {
        ?>
        <article class="wnq-module" id="ppc-conversions"><div class="wnq-module-title"><div><h3>Conversion Tracking Health</h3><p>Conversion configuration, recent activity, dates, devices, and hours.</p></div><?php self::moduleStatus($module); ?></div>
        <?php if (empty($module['available'])): self::unavailable($module); else: ?>
            <p class="wnq-module-note"><?php echo esc_html((string)($module['message'] ?? '')); ?></p>
            <div class="wnq-health-grid"><?php foreach ((array)($module['counts'] ?? []) as $key => $count): ?><div><span><?php echo esc_html(self::label((string)$key)); ?></span><strong><?php echo esc_html((string)$count); ?></strong></div><?php endforeach; ?></div>
            <div class="wnq-split-grid">
                <div><h4>Conversions by device</h4><?php self::barList((array)($module['devices'] ?? []), 'conversion'); ?></div>
                <div><h4>Conversions by hour</h4><?php $hours = []; foreach ((array)($module['hours'] ?? []) as $hour => $value) $hours[self::hourLabel((int)$hour)] = $value; self::barList($hours, 'conversion'); ?></div>
            </div>
            <div class="wnq-table-scroll"><table><thead><tr><th>Conversion action</th><th>Health</th><th>Type</th><th>Goal</th><th>Status</th><th>Attribution</th><th>Last conversion</th><th>7 days</th><th>30 days</th></tr></thead><tbody>
            <?php if (empty($module['actions'])): ?><tr><td colspan="9">No conversion actions were returned.</td></tr><?php else: foreach ($module['actions'] as $row): ?>
                <tr><td><strong><?php echo esc_html((string)$row['name']); ?></strong><br><small><?php echo esc_html(self::label((string)$row['category'])); ?><?php if (!empty($row['campaigns'])): ?> · <?php echo esc_html(implode(', ', array_slice((array)$row['campaigns'], 0, 3))); ?><?php endif; ?></small></td><td><?php self::pill((string)$row['classification']); ?></td><td><?php echo esc_html(self::label((string)$row['type'])); ?></td><td><?php echo !empty($row['primary']) ? 'Primary' : 'Secondary'; ?></td><td><?php echo esc_html(self::label((string)$row['status'])); ?><br><small><?php echo !empty($row['included_in_conversions']) ? 'Included in Conversions' : 'Not included'; ?></small></td><td><?php echo esc_html(self::label((string)$row['attribution_model'])); ?></td><td><?php echo esc_html((string)($row['last_conversion_date'] ?: 'No data in 90 days')); ?></td><td><?php echo esc_html(number_format_i18n((float)$row['conversions_7'], 2)); ?></td><td><?php echo esc_html(number_format_i18n((float)$row['conversions_30'], 2)); ?></td></tr>
            <?php endforeach; endif; ?></tbody></table></div>
            <details class="wnq-detail" open><summary>Key-event activity by date, time, and device</summary><div class="wnq-table-scroll"><table><thead><tr><th>Date</th><th>Hour</th><th>Device</th><th>Key event</th><th>Conversions</th></tr></thead><tbody>
            <?php if (empty($module['activity'])): ?><tr><td colspan="5">No conversion activity was returned for the last 90 days.</td></tr><?php else: foreach ($module['activity'] as $row): ?>
                <tr><td><?php echo esc_html((string)$row['date']); ?></td><td><?php echo esc_html(self::hourLabel((int)$row['hour'])); ?></td><td><?php echo esc_html(self::label((string)$row['device'])); ?></td><td><strong><?php echo esc_html((string)$row['action_name']); ?></strong></td><td><?php echo esc_html(number_format_i18n((float)$row['conversions'], 2)); ?></td></tr>
            <?php endforeach; endif; ?></tbody></table></div></details>
            <?php if (!empty($module['timeline'])): ?><details class="wnq-detail"><summary>Daily conversion timeline</summary><div class="wnq-timeline"><?php foreach (array_reverse((array)$module['timeline'], true) as $date => $value): ?><span><b><?php echo esc_html((string)$date); ?></b><?php echo esc_html(number_format_i18n((float)$value, 2)); ?></span><?php endforeach; ?></div></details><?php endif; ?>
        <?php endif; ?></article>
        <?php
    }

    private static function renderChangeHistory(array $module): void
    {
        ?>
        <article class="wnq-module" id="ppc-changes"><div class="wnq-module-title"><div><h3>Change History</h3><p>Recent account changes reported by Google Ads.</p></div><?php self::moduleStatus($module); ?></div>
        <?php if (empty($module['available'])): self::unavailable($module); else: ?>
            <div class="wnq-table-scroll"><table><thead><tr><th>Date and time</th><th>Resource</th><th>Operation</th><th>Campaign / ad group</th><th>Changed fields</th><th>Changed by</th></tr></thead><tbody>
            <?php if (empty($module['changes'])): ?><tr><td colspan="6">No changes were returned for the last 30 days.</td></tr><?php else: foreach ($module['changes'] as $row): ?>
                <tr><td><?php echo esc_html((string)$row['date_time']); ?></td><td><?php echo esc_html(self::label((string)$row['resource_type'])); ?></td><td><?php self::pill((string)$row['operation']); ?></td><td><?php echo esc_html((string)($row['campaign'] ?: $row['ad_group'] ?: '—')); ?></td><td><?php echo esc_html(implode(', ', array_slice((array)$row['fields'], 0, 8)) ?: 'Not specified'); ?></td><td><?php echo esc_html((string)($row['user_email'] ?: self::label((string)$row['client_type']))); ?></td></tr>
            <?php endforeach; endif; ?></tbody></table></div>
        <?php endif; ?></article>
        <?php
    }

    private static function renderImpressionShare(array $module): void
    {
        ?>
        <article class="wnq-module" id="ppc-share"><div class="wnq-module-title"><div><h3>Impression Share</h3><p>Search visibility lost to budget or ad rank during the last 30 days.</p></div><?php self::moduleStatus($module); ?></div>
        <?php if (empty($module['available'])): self::unavailable($module); else: ?>
            <p class="wnq-module-note"><?php echo esc_html((string)$module['message']); ?></p><div class="wnq-table-scroll"><table><thead><tr><th>Campaign</th><th>Status</th><th>Impressions</th><th>Search impression share</th><th>Lost to budget</th><th>Lost to rank</th></tr></thead><tbody>
            <?php if (empty($module['campaigns'])): ?><tr><td colspan="6">No eligible Search campaign impression-share data was returned.</td></tr><?php else: foreach ($module['campaigns'] as $row): ?>
                <tr><td><strong><?php echo esc_html((string)$row['name']); ?></strong></td><td><?php self::pill((string)$row['status']); ?></td><td><?php echo esc_html(number_format_i18n((int)$row['impressions'])); ?></td><td><?php echo esc_html(self::nullablePercent($row['impression_share'])); ?></td><td><?php echo esc_html(self::nullablePercent($row['lost_budget'])); ?></td><td><?php echo esc_html(self::nullablePercent($row['lost_rank'])); ?></td></tr>
            <?php endforeach; endif; ?></tbody></table></div>
        <?php endif; ?></article>
        <?php
    }

    private static function renderBudgetAnalysis(array $module): void
    {
        ?>
        <article class="wnq-module" id="ppc-budgets"><div class="wnq-module-title"><div><h3>Budget Analysis</h3><p>Current-month pacing and projections. No budgets can be changed here.</p></div><?php self::moduleStatus($module); ?></div>
        <?php if (empty($module['available'])): self::unavailable($module); else: ?>
            <p class="wnq-module-note"><?php echo esc_html((string)$module['message']); ?></p><div class="wnq-table-scroll"><table><thead><tr><th>Campaign</th><th>Status</th><th>Daily budget</th><th>Monthly capacity</th><th>Month spend</th><th>Projected spend</th><th>Pacing</th><th>Conversions</th></tr></thead><tbody>
            <?php if (empty($module['campaigns'])): ?><tr><td colspan="8">No campaign budget data was returned.</td></tr><?php else: foreach ($module['campaigns'] as $row): ?>
                <tr><td><strong><?php echo esc_html((string)$row['name']); ?></strong><?php if (!empty($row['shared_budget'])): ?><br><small>Shared budget</small><?php endif; ?></td><td><?php self::pill((string)$row['status']); ?></td><td><?php echo esc_html(self::money((float)$row['daily_budget'])); ?></td><td><?php echo esc_html(self::money((float)$row['monthly_capacity'])); ?></td><td><?php echo esc_html(self::money((float)$row['month_spend'])); ?></td><td><?php echo esc_html(self::money((float)$row['projected_spend'])); ?></td><td><?php self::pill((string)$row['pace_status']); ?><br><small><?php echo esc_html(self::percent((float)$row['pace'])); ?> of capacity</small></td><td><?php echo esc_html(number_format_i18n((float)$row['conversions'], 2)); ?><br><small><?php echo esc_html((string)$row['recommendation']); ?></small></td></tr>
            <?php endforeach; endif; ?></tbody></table></div>
        <?php endif; ?></article>
        <?php
    }

    private static function moduleStatus(array $module): void
    {
        $status = !empty($module['available']) ? (string)($module['status'] ?? 'ready') : 'unavailable';
        self::pill($status);
    }

    private static function unavailable(array $module): void
    {
        ?><div class="wnq-unavailable"><strong>Unavailable</strong><span><?php echo esc_html((string)($module['message'] ?? 'Google Ads did not return this report.')); ?></span></div><?php
    }

    private static function barList(array $values, string $noun): void
    {
        if (!$values) { echo '<p class="description">No recent data.</p>'; return; }
        $max = max(array_map('floatval', $values)) ?: 1;
        echo '<div class="wnq-bars">';
        foreach (array_slice($values, 0, 12, true) as $label => $value) {
            $width = min(100, ((float)$value / $max) * 100);
            echo '<div><span>' . esc_html(self::label((string)$label)) . '</span><i><b style="width:' . esc_attr((string)$width) . '%"></b></i><strong>' . esc_html(number_format_i18n((float)$value, 2)) . ' ' . esc_html($noun) . (1.0 === (float)$value ? '' : 's') . '</strong></div>';
        }
        echo '</div>';
    }

    private static function pill(string $value): void
    {
        $key = sanitize_key($value);
        echo '<span class="wnq-pill is-' . esc_attr($key) . '">' . esc_html(self::label($value)) . '</span>';
    }

    private static function money(float $value): string { return '$' . number_format_i18n($value, 2); }
    private static function percent(float $value): string { return number_format_i18n($value * 100, 1) . '%'; }
    private static function nullablePercent($value): string { return is_numeric($value) ? self::percent((float)$value) : 'Not available'; }
    private static function label(string $value): string { return ucwords(str_replace(['_', '-'], ' ', $value)); }
    private static function hourLabel(int $hour): string
    {
        $date = \DateTimeImmutable::createFromFormat('!H', (string)max(0, min(23, $hour)));
        return $date ? $date->format('g a') : (string)$hour;
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
        .wnq-ppc-intelligence{max-width:1200px}.wnq-ppc-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;background:linear-gradient(135deg,#07131c,#0d539e);color:#fff;padding:26px;border-radius:12px;margin:18px 0}.wnq-ppc-hero h2{color:#fff;font-size:26px;margin:3px 0 7px}.wnq-ppc-hero p{margin:0;color:#dbeafe}.wnq-ppc-eyebrow{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#f3cf55}.wnq-read-only-badge{background:#f3cf55;color:#07131c;border-radius:999px;padding:6px 11px;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap}.wnq-ppc-status-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.wnq-ppc-status{background:#fff;border:1px solid #dcdcde;border-left:4px solid #dba617;border-radius:8px;padding:14px}.wnq-ppc-status.is-ok{border-left-color:#1f9d55}.wnq-ppc-status span{display:block;color:#646970;font-size:11px;text-transform:uppercase;font-weight:700;margin-bottom:5px}.wnq-ppc-status strong{display:block;color:#1d2327;font-size:14px;overflow-wrap:anywhere}.wnq-ppc-layout{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}.wnq-ppc-card,.wnq-module{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px}.wnq-ppc-card h3,.wnq-module h3{font-size:17px;margin:0 0 5px}.wnq-ppc-card label{display:block;margin:14px 0}.wnq-ppc-card label>span{display:block;font-weight:600;margin-bottom:5px}.wnq-ppc-card input,.wnq-ppc-card select{width:100%;max-width:none}.wnq-ppc-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.wnq-ppc-actions-separated{border-top:1px solid #eee;padding-top:16px;justify-content:space-between}.wnq-ppc-actions form{margin:0}.wnq-ppc-test-form{margin-top:12px}.wnq-account-details{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #e5e7eb;border-radius:8px;margin:16px 0}.wnq-account-details div{padding:12px;border-bottom:1px solid #e5e7eb}.wnq-account-details div:nth-last-child(-n+2){border-bottom:0}.wnq-account-details dt{font-size:11px;color:#646970;text-transform:uppercase;font-weight:700}.wnq-account-details dd{margin:4px 0 0;font-weight:600}.wnq-ppc-card .notice.inline{margin:14px 0;padding:1px 12px}.wnq-ppc-card .button-link-delete{color:#b32d2e}.wnq-diagnostics-shell{margin:18px 0}.wnq-diagnostics-head,.wnq-module-title{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.wnq-diagnostics-head{background:#07131c;color:#fff;padding:22px;border-radius:10px 10px 0 0}.wnq-diagnostics-head h2{color:#fff;margin:3px 0}.wnq-diagnostics-head p,.wnq-module-title p{margin:0;color:#646970}.wnq-diagnostics-head p{color:#cbd5e1}.wnq-module-nav{display:flex;gap:4px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;padding:9px}.wnq-module-nav a{padding:7px 11px;text-decoration:none;font-weight:600}.wnq-module{margin-top:14px;scroll-margin-top:42px}.wnq-pill{display:inline-block;border-radius:999px;padding:4px 8px;background:#e5e7eb;color:#374151;font-size:11px;font-weight:700;white-space:nowrap}.wnq-pill.is-ready,.wnq-pill.is-enabled,.wnq-pill.is-healthy,.wnq-pill.is-on_track{background:#dcfce7;color:#166534}.wnq-pill.is-unavailable,.wnq-pill.is-configuration_issue,.wnq-pill.is-over{background:#fee2e2;color:#991b1b}.wnq-pill.is-partial,.wnq-pill.is-warning,.wnq-pill.is-stale,.wnq-pill.is-under{background:#fef3c7;color:#92400e}.wnq-period-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0}.wnq-period-card{border:1px solid #e5e7eb;border-radius:8px;padding:13px}.wnq-period-card>*{display:block}.wnq-period-card>strong{font-size:11px;text-transform:uppercase;color:#646970}.wnq-period-card span{margin-top:8px}.wnq-period-card b{font-size:16px;margin:4px 0}.wnq-period-card small{color:#646970}.wnq-module-summary,.wnq-module-note{color:#646970}.wnq-table-scroll{overflow:auto;margin-top:14px}.wnq-module table{width:100%;border-collapse:collapse;min-width:760px}.wnq-module th,.wnq-module td{text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;vertical-align:top}.wnq-module th{font-size:11px;text-transform:uppercase;color:#646970}.wnq-health-grid{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.wnq-health-grid div{min-width:120px;background:#f8fafc;border-radius:8px;padding:10px}.wnq-health-grid span{display:block;font-size:11px;text-transform:uppercase;color:#646970}.wnq-health-grid strong{font-size:20px}.wnq-split-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:18px 0}.wnq-bars>div{display:grid;grid-template-columns:110px 1fr 110px;align-items:center;gap:8px;margin:7px 0}.wnq-bars i{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden}.wnq-bars i b{display:block;height:100%;background:#0d539e}.wnq-bars strong{font-size:11px}.wnq-unavailable{display:flex;gap:10px;align-items:center;background:#f8fafc;border-left:4px solid #94a3b8;padding:14px;margin-top:14px}.wnq-detail{margin-top:14px}.wnq-detail summary{cursor:pointer;font-weight:600}.wnq-timeline{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:10px}.wnq-timeline span{display:flex;justify-content:space-between;background:#f8fafc;padding:8px}.wnq-timeline b{font-size:11px}@media(max-width:1000px){.wnq-period-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:900px){.wnq-ppc-status-grid{grid-template-columns:repeat(2,1fr)}.wnq-ppc-layout,.wnq-split-grid{grid-template-columns:1fr}}@media(max-width:600px){.wnq-ppc-hero,.wnq-diagnostics-head{flex-direction:column}.wnq-ppc-status-grid,.wnq-account-details,.wnq-period-grid,.wnq-timeline{grid-template-columns:1fr}.wnq-account-details div:nth-last-child(2){border-bottom:1px solid #e5e7eb}.wnq-bars>div{grid-template-columns:85px 1fr}.wnq-bars strong{grid-column:2}}
        </style>
        <?php
    }
}
