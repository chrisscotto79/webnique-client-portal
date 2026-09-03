<?php
/**
 * Run: php tests/ppc-phase1-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$options = [];

function get_option(string $key, $default = false)
{
    global $options;
    return $options[$key] ?? $default;
}

function update_option(string $key, $value, bool $autoload = false): bool
{
    global $options;
    $changed = !array_key_exists($key, $options) || $options[$key] !== $value;
    $options[$key] = $value;
    return $changed;
}

function delete_option(string $key): bool
{
    global $options;
    unset($options[$key]);
    return true;
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?: '';
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function wp_json_encode($value): string
{
    return (string)json_encode($value);
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'phase-one-test-site-salt';
}

require_once dirname(__DIR__) . '/includes/Services/GoogleAdsClient.php';
require_once dirname(__DIR__) . '/includes/Services/GoogleAdsCredentials.php';
require_once dirname(__DIR__) . '/includes/Services/GoogleAdsQueryService.php';

use WNQ\Services\GoogleAdsCredentials;
use WNQ\Services\GoogleAdsQueryService;

function assertPpc(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertPpc(GoogleAdsQueryService::isReadOnlyQuery('SELECT campaign.id FROM campaign LIMIT 1'), 'SELECT queries should be allowed.');
assertPpc(!GoogleAdsQueryService::isReadOnlyQuery('UPDATE campaign SET status = 1'), 'UPDATE queries must be blocked.');
assertPpc(!GoogleAdsQueryService::isReadOnlyQuery('SELECT campaign.id FROM campaign; DELETE campaign'), 'Multiple/mutating statements must be blocked.');

$credentials = [
    'developer_token' => 'developer-test-value',
    'manager_customer_id' => '123-456-7890',
    'oauth_client_id' => 'oauth-client-test-value',
    'oauth_client_secret' => 'oauth-secret-test-value',
    'refresh_token' => 'refresh-test-value',
    'access_level' => 'basic',
];
assertPpc(GoogleAdsCredentials::save($credentials, false), 'Credentials should save successfully.');
$encrypted = (string)get_option('wnq_ppc_google_ads_credentials_v1', '');
assertPpc($encrypted !== '', 'Encrypted credential payload should exist.');
foreach ($credentials as $key => $plaintext) {
    if ($key !== 'manager_customer_id' && $key !== 'access_level') {
        assertPpc(!str_contains($encrypted, (string)$plaintext), "Encrypted payload must not expose {$key}.");
    }
}
$restored = GoogleAdsCredentials::get();
assertPpc($restored['developer_token'] === $credentials['developer_token'], 'Encrypted credentials should decrypt correctly.');
assertPpc($restored['manager_customer_id'] === '1234567890', 'Customer IDs should be normalized to ten digits.');
assertPpc(GoogleAdsCredentials::isConfigured($restored), 'Complete credentials should report configured.');

$client_portal_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Models/ClientPortal.php');
$ppc_admin_source = (string)file_get_contents(dirname(__DIR__) . '/admin/PpcIntelligenceAdmin.php');
$dashboard_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Controllers/DashboardController.php');
assertPpc(!str_contains($client_portal_source, '$ads->matchClient('), 'Reports must not automatically fuzzy-match client accounts.');
assertPpc(str_contains($ppc_admin_source, 'hash_equals'), 'PPC mapping must verify an exact discovered customer ID.');
assertPpc(str_contains($ppc_admin_source, 'gwm_manage_ppc') || str_contains($ppc_admin_source, 'currentUserCanManagePpc'), 'PPC admin actions must use the dedicated capability.');
assertPpc(str_contains($dashboard_source, 'Permissions::currentUserCanManagePpc()'), 'Google Ads mapping endpoint must require the dedicated PPC capability.');
assertPpc(!preg_match('/WP_REST_Response\s*\(\s*GoogleAdsCredentials::get/s', $dashboard_source), 'REST responses must not return shared Google Ads credentials.');

echo "PPC Phase 1 regression checks passed.\n";
