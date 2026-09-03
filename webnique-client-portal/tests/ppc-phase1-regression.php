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

function wp_strip_all_tags(string $value, bool $remove_breaks = false): string
{
    return strip_tags($value);
}

function esc_url_raw(string $value): string
{
    return filter_var($value, FILTER_SANITIZE_URL) ?: '';
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'phase-one-test-site-salt';
}

require_once dirname(__DIR__) . '/includes/Services/GoogleAdsClient.php';
require_once dirname(__DIR__) . '/includes/Services/GoogleAdsCredentials.php';
require_once dirname(__DIR__) . '/includes/Services/GoogleAdsQueryService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcDiagnosticService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcSearchTermService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcAdAuditService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcKeywordIntelligenceService.php';

use WNQ\Services\GoogleAdsCredentials;
use WNQ\Services\GoogleAdsQueryService;
use WNQ\Services\PpcDiagnosticService;
use WNQ\Services\PpcSearchTermService;
use WNQ\Services\PpcAdAuditService;
use WNQ\Services\PpcKeywordIntelligenceService;

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
assertPpc(PpcDiagnosticService::classifyConversion(['status' => 'ENABLED', 'primaryForGoal' => true, 'includeInConversionsMetric' => true], ['conversions_7' => 2]) === 'healthy', 'Recent enabled conversions should classify as healthy.');
assertPpc(PpcDiagnosticService::classifyConversion(['status' => 'ENABLED'], ['conversions_7' => 0, 'conversions_30' => 0, 'last_date' => '2026-01-01']) === 'stale', 'Previously active conversions without recent volume should classify as stale.');
assertPpc(PpcDiagnosticService::classifyConversion(['status' => 'HIDDEN'], []) === 'configuration_issue', 'Disabled conversion actions should flag a configuration issue.');
$geo_config = ['brand_names' => ['sns hauling'], 'services' => ['junk removal'], 'target_areas' => ['lakeland'], 'excluded_areas' => ['tampa'], 'competitors' => ['rival hauling'], 'excluded_terms' => []];
assertPpc(PpcSearchTermService::classify('SNS Hauling', $geo_config)['classification'] === 'high_intent', 'The client’s own brand must classify as high intent.');
assertPpc(PpcSearchTermService::classify('junk removal lakeland', $geo_config)['classification'] === 'relevant', 'Configured target areas must remain relevant.');
assertPpc(PpcSearchTermService::classify('junk removal tampa', $geo_config)['recommended_action'] === 'human_review', 'Excluded geography must still require human review.');
assertPpc(PpcSearchTermService::classify('junk removal orlando', $geo_config)['classification'] === 'relevant', 'An unconfigured city must not automatically become a geo negative.');
assertPpc(PpcSearchTermService::classify('junk removal jobs', $geo_config)['recommended_action'] === 'negative_phrase', 'Employment intent should produce a reviewable negative proposal.');
assertPpc(PpcSearchTermService::classify('junk removal reviews', $geo_config)['classification'] === 'low_intent', 'Review intent should remain a watchable low-intent query.');
assertPpc(PpcSearchTermService::classify('rival hauling lakeland', $geo_config)['classification'] === 'competitor', 'Competitor intent must not be hidden by a target-area match.');
$priority = PpcDiagnosticService::prioritize(['conversion_health' => ['available' => true, 'actions' => [], 'counts' => []]]);
assertPpc(($priority[0]['severity'] ?? '') === 'critical', 'Zero active primary conversions must be a critical finding.');
$rsa = ['status' => 'enabled', 'campaign_status' => 'enabled', 'ad_group_status' => 'enabled', 'approval_status' => 'approved', 'ad_strength' => 'good', 'headlines' => [['text' => 'Licensed & Insured', 'pinned_field' => '']], 'descriptions' => [['text' => 'Request Service Today', 'pinned_field' => '']], 'final_urls' => ['https://example.com'], 'url_health' => ['https://example.com' => ['status' => 'healthy']], 'assets' => []];
$verified_rsa = PpcAdAuditService::auditAd($rsa, [], ['pages' => [['url' => 'https://example.com/about', 'text' => 'licensed insured local contractor']]], ['Example Company']);
assertPpc(!empty($verified_rsa['claims'][0]['verified']), 'An exact normalized website claim must be marked verified with its source.');
$unverified_rsa = PpcAdAuditService::auditAd($rsa, [], ['pages' => []], ['Example Company']);
assertPpc(empty($unverified_rsa['claims'][0]['verified']), 'A factual claim without website evidence must require verification.');
$rsa['approval_status'] = 'disapproved';
assertPpc(PpcAdAuditService::auditAd($rsa)['severity'] === 'critical', 'A disapproved fully enabled RSA must be critical.');
$rsa['campaign_status'] = 'paused';
assertPpc(PpcAdAuditService::auditAd($rsa)['severity'] !== 'critical', 'A paused campaign ad must not receive serving-level critical urgency.');
$positives=[['keyword'=>'junk removal lakeland','match_type'=>'exact','campaign_id'=>'1','campaign'=>'Search','ad_group_id'=>'2','ad_group'=>'Junk']];
$negatives=[['negative'=>'junk removal','match_type'=>'phrase','level'=>'campaign','campaign_id'=>'1','ad_group_id'=>'']];
assertPpc(count(PpcKeywordIntelligenceService::conflicts($positives,$negatives))===1,'Phrase negatives must detect contiguous whole-word conflicts.');
$negatives[0]['negative']='junk rem';
assertPpc(count(PpcKeywordIntelligenceService::conflicts($positives,$negatives))===0,'Phrase conflict matching must respect whole words.');

$client_portal_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Models/ClientPortal.php');
$ppc_admin_source = (string)file_get_contents(dirname(__DIR__) . '/admin/PpcIntelligenceAdmin.php');
$dashboard_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Controllers/DashboardController.php');
assertPpc(!str_contains($client_portal_source, '$ads->matchClient('), 'Reports must not automatically fuzzy-match client accounts.');
assertPpc(str_contains($ppc_admin_source, 'hash_equals'), 'PPC mapping must verify an exact discovered customer ID.');
assertPpc(str_contains($ppc_admin_source, 'gwm_manage_ppc') || str_contains($ppc_admin_source, 'currentUserCanManagePpc'), 'PPC admin actions must use the dedicated capability.');
assertPpc(str_contains($ppc_admin_source, "'wnq-ppc-management'"), 'PPC Management must be registered in the WordPress admin menu.');
assertPpc(str_contains($dashboard_source, 'Permissions::currentUserCanManagePpc()'), 'Google Ads mapping endpoint must require the dedicated PPC capability.');
assertPpc(!preg_match('/WP_REST_Response\s*\(\s*GoogleAdsCredentials::get/s', $dashboard_source), 'REST responses must not return shared Google Ads credentials.');
$proposal_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Models/PpcProposal.php');
assertPpc(!preg_match('/googleAds:(?:mutate|update)|GoogleAdsClient/i', $proposal_source), 'Proposal review must not call Google Ads mutations.');
$ad_audit_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Services/PpcAdAuditService.php');
assertPpc(!preg_match('/googleAds:(?:mutate|update)|mutateCampaign|mutateAdGroup|setAmountMicros/i', $ad_audit_source), 'Phase 4 ad auditing must not call Google Ads mutations.');
assertPpc(str_contains($ad_audit_source, 'wp_safe_remote_get') && str_contains($ad_audit_source, 'wp_safe_remote_head'), 'Destination and claim-source checks must use WordPress safe HTTP requests.');

echo "PPC regression checks passed.\n";
