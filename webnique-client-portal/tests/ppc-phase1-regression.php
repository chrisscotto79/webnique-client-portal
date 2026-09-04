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

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('America/New_York');
}

require_once dirname(__DIR__) . '/includes/Services/GoogleAdsClient.php';
require_once dirname(__DIR__) . '/includes/Services/GoogleAdsCredentials.php';
require_once dirname(__DIR__) . '/includes/Services/GoogleAdsQueryService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcDiagnosticService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcSearchTermService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcAdAuditService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcNegativeInventoryService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcKeywordIntelligenceService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcLeadQualityService.php';
require_once dirname(__DIR__) . '/includes/Models/PpcMutationPlan.php';
require_once dirname(__DIR__) . '/includes/Models/PpcRecommendation.php';
require_once dirname(__DIR__) . '/includes/Services/PpcRecommendationPreviewService.php';
require_once dirname(__DIR__) . '/includes/Services/PpcChangeCorrelationService.php';

use WNQ\Services\GoogleAdsCredentials;
use WNQ\Services\GoogleAdsQueryService;
use WNQ\Services\PpcDiagnosticService;
use WNQ\Services\PpcSearchTermService;
use WNQ\Services\PpcAdAuditService;
use WNQ\Services\PpcNegativeInventoryService;
use WNQ\Services\PpcKeywordIntelligenceService;
use WNQ\Services\PpcLeadQualityService;
use WNQ\Models\PpcMutationPlan;
use WNQ\Models\PpcRecommendation;
use WNQ\Services\PpcRecommendationPreviewService;
use WNQ\Services\PpcChangeCorrelationService;

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
$all_scope_negatives = [
    ['scope'=>'shared_list','negative'=>'junk removal','match_type'=>'phrase','resource_name'=>'customers/1234567890/sharedCriteria/7~8','shared_set_name'=>'Agency exclusions','shared_set_id'=>'7','shared_set_resource'=>'customers/1234567890/sharedSets/7','campaign_ids'=>['1']],
    ['scope'=>'account_level','negative'=>'jobs','match_type'=>'broad','resource_name'=>'customers/1234567890/sharedCriteria/9~10','shared_set_name'=>'Account exclusions','shared_set_id'=>'9','shared_set_resource'=>'customers/1234567890/sharedSets/9','campaign_ids'=>[]],
];
$all_scope_conflicts = PpcNegativeInventoryService::conflicts($positives, $all_scope_negatives);
assertPpc(count($all_scope_conflicts) === 1 && $all_scope_conflicts[0]['scope'] === 'shared_list', 'Attached shared negative lists must participate in keyword conflict detection.');
assertPpc(PpcNegativeInventoryService::conflicts([['criterion_id'=>'3','keyword'=>'junk removal lakeland','match_type'=>'exact','campaign_id'=>'99','campaign'=>'Other','ad_group_id'=>'4','ad_group'=>'Other']], $all_scope_negatives) === [], 'A campaign shared list must not affect campaigns to which it is not attached.');
assertPpc(count(PpcNegativeInventoryService::conflicts([['criterion_id'=>'5','keyword'=>'jobs','match_type'=>'exact','campaign_id'=>'99','campaign'=>'Other','ad_group_id'=>'4','ad_group'=>'Other']], $all_scope_negatives)) === 1, 'An account-level negative list must apply across campaigns.');
assertPpc(PpcNegativeInventoryService::conflicts($positives, []) === [], 'An account with no negative lists must return no fabricated conflicts.');
$multi_campaign_keywords = [
    ['criterion_id'=>'6','keyword'=>'junk removal lakeland','match_type'=>'exact','campaign_id'=>'1','campaign'=>'Search A','ad_group_id'=>'2','ad_group'=>'Junk'],
    ['criterion_id'=>'7','keyword'=>'junk removal lakeland','match_type'=>'phrase','campaign_id'=>'3','campaign'=>'Search B','ad_group_id'=>'4','ad_group'=>'Junk'],
];
$multi_list_negatives = [
    ['scope'=>'shared_list','negative'=>'junk removal','match_type'=>'phrase','resource_name'=>'customers/1234567890/sharedCriteria/11~12','shared_set_name'=>'Service exclusions','shared_set_id'=>'11','shared_set_resource'=>'customers/1234567890/sharedSets/11','campaign_ids'=>['1','3']],
    ['scope'=>'shared_list','negative'=>'free','match_type'=>'broad','resource_name'=>'customers/1234567890/sharedCriteria/13~14','shared_set_name'=>'Free intent','shared_set_id'=>'13','shared_set_resource'=>'customers/1234567890/sharedSets/13','campaign_ids'=>['1']],
];
$multi_list_conflicts = PpcNegativeInventoryService::conflicts($multi_campaign_keywords, $multi_list_negatives);
assertPpc(count($multi_list_conflicts) === 2, 'Multiple shared lists applied across multiple campaigns must preserve the exact campaign scope without fabricating conflicts.');
assertPpc(PpcNegativeInventoryService::duplicate(['items'=>$all_scope_negatives], 'jobs', 'broad', '1', '2')['scope'] === 'account_level', 'Account-level negatives must prevent duplicate recommendations for every campaign.');
assertPpc(count(PpcNegativeInventoryService::protectedTerms('sns hauling junk removal lakeland', $geo_config)) === 3, 'Brand, service, and target-geography overlaps must be protected before preview creation.');
assertPpc(PpcLeadQualityService::saveConfig('phase-six-client', ['engagement' => "phone_click\nemail_click", 'raw_lead' => 'generate_lead', 'qualified_lead' => 'qualified_lead']), 'Lead-quality event mapping should save per client.');
$quality_config = PpcLeadQualityService::config('phase-six-client');
assertPpc($quality_config['engagement'] === ['phone_click', 'email_click'], 'Lead-quality event names should be normalized and deduplicated.');
assertPpc($quality_config['qualified_lead'] === ['qualified_lead'], 'Qualified leads must remain a distinct quality stage.');
assertPpc($quality_config['booked_work'] === [], 'An unmapped quality stage must remain distinct from a configured zero count.');
assertPpc(PpcLeadQualityService::normalizeEventName('Qualified_Lead') === 'Qualified_Lead', 'Exact GA4 event-name case must be preserved.');
assertPpc(PpcLeadQualityService::matchesCustomerId('123-456-7890', '1234567890'), 'GA4 lead evidence should accept the exact linked Google Ads customer ID.');
assertPpc(!PpcLeadQualityService::matchesCustomerId('9999999999', '1234567890'), 'GA4 lead evidence must reject another Google Ads customer ID.');
$plan_content = ['client_id' => 'client-a', 'customer_id' => '1234567890', 'entity_id' => 'campaigns/42', 'current_value' => 'PAUSED', 'proposed_value' => 'ENABLED'];
assertPpc(hash_equals(PpcMutationPlan::contentHash($plan_content), PpcMutationPlan::contentHash(array_reverse($plan_content, true))), 'Mutation preview fingerprints must be deterministic regardless of field order.');
assertPpc(PpcMutationPlan::confirmationMatches('approved', 'APPROVE'), 'Mutation approval should accept the exact human confirmation word.');
assertPpc(!PpcMutationPlan::confirmationMatches('approved', 'approve'), 'Mutation approval must reject inferred or case-insensitive confirmation.');
$audit_details = wp_json_encode(['status' => 'awaiting_approval']);
$audit_hash = hash('sha256', implode('|', ['', 7, 'preview_created', 12, '2026-09-03 13:00:00', $audit_details]));
$audit_events = [['plan_id' => 7, 'event_type' => 'preview_created', 'actor_id' => 12, 'created_at' => '2026-09-03 13:00:00', 'details_json' => $audit_details, 'previous_event_hash' => '', 'event_hash' => $audit_hash]];
assertPpc(PpcMutationPlan::verifyEventChain($audit_events), 'An intact mutation audit hash chain should validate.');
$audit_events[0]['details_json'] = wp_json_encode(['status' => 'approved']);
assertPpc(!PpcMutationPlan::verifyEventChain($audit_events), 'A modified mutation audit event must invalidate the chain.');
$recommendation_metadata = wp_json_encode(['evidence_label'=>'positive_evidence']);
$recommendation_event = ['recommendation_id'=>12,'event_type'=>'validation_refreshed','from_status'=>'monitoring','to_status'=>'monitoring','actor_id'=>7,'created_at'=>'2026-09-04 10:00:00','note'=>'Read-only before/after evidence refreshed.','metadata_json'=>$recommendation_metadata,'previous_event_hash'=>''];
$recommendation_event['event_hash'] = hash('sha256', implode('|', ['',12,'validation_refreshed','monitoring','monitoring',7,'2026-09-04 10:00:00','Read-only before/after evidence refreshed.',$recommendation_metadata]));
assertPpc(PpcRecommendation::verifyEventChain([$recommendation_event]), 'An intact recommendation lifecycle audit chain should validate.');
$recommendation_event['to_status'] = 'successful';
assertPpc(!PpcRecommendation::verifyEventChain([$recommendation_event]), 'A modified recommendation lifecycle audit event must invalidate the chain.');
$date_time_method = new ReflectionMethod(PpcRecommendation::class, 'dateTime');
$date_time_method->setAccessible(true);
date_default_timezone_set('UTC');
assertPpc($date_time_method->invoke(null, '2026-09-04T10:30') === '2026-09-04 10:30:00', 'External implementation time must remain in the WordPress site timezone.');
assertPpc($date_time_method->invoke(null, '2026-02-30T10:30') === '', 'Invalid external implementation dates must be rejected.');
$correlation_label_method = new ReflectionMethod(PpcChangeCorrelationService::class, 'evidenceLabel');
$correlation_label_method->setAccessible(true);
assertPpc($correlation_label_method->invoke(null, 1.0, 6, 2.0) === 'Observation', 'A tiny correlation sample must never be labeled strong evidence.');
assertPpc($correlation_label_method->invoke(null, .2, 15, 1.0) === 'Possible contributor', 'A moderate sample may support a conservative contributor label.');
assertPpc($correlation_label_method->invoke(null, .4, 30, 2.0) === 'Strong evidence', 'A large directional change with a robust sample may be labeled strong evidence while remaining unconfirmed.');
$candidate_report = PpcRecommendationPreviewService::candidates(
    [
        'budget_analysis' => [
            'available' => true,
            'campaigns' => [['id' => '10', 'name' => 'Shared', 'pace_status' => 'under', 'shared_budget' => true]],
        ],
    ],
    ['available' => true, 'terms' => [
        ['id' => 9, 'query' => 'junk removal jobs', 'status' => 'approved_phrase', 'recommended_action' => 'negative_phrase', 'campaign' => 'Search', 'campaign_id' => '10', 'ad_group' => 'Junk', 'ad_group_id' => '11'],
        ['id' => 10, 'query' => 'junk removal quote', 'status' => 'not_proposed', 'recommended_action' => 'add_as_keyword', 'campaign' => 'Search', 'campaign_id' => '10'],
    ]],
    ['conflicts' => [], 'non_serving' => []]
);
assertPpc(($candidate_report['counts']['ready'] ?? 0) === 1, 'An internally approved negative should be eligible for exact preview preparation.');
assertPpc(($candidate_report['counts']['needs_keyword_coverage'] ?? 0) === 1, 'Keyword opportunities must wait for existing-coverage verification.');
assertPpc(($candidate_report['counts']['shared_budget_review'] ?? 0) === 1, 'Shared-budget recommendations must remain blocked for grouped review.');

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
$analytics_source = (string)file_get_contents(dirname(__DIR__) . '/includes/API/GoogleAnalytics.php');
assertPpc(str_contains($analytics_source, 'getLeadQualityRows'), 'Phase 6 must use the existing GA4 service for lead-quality evidence.');
assertPpc(str_contains($analytics_source, "'sessionGoogleAdsCustomerId'"), 'GA4 lead-quality evidence must filter by the exact linked Google Ads customer ID.');
assertPpc(!str_contains($analytics_source, 'landingPagePlusQueryString'), 'Lead-quality evidence must not request landing-page query strings.');
assertPpc(!preg_match('/[\'\"](?:userId|userPseudoId|clientId|gclid)[\'\"]/', $analytics_source), 'GA4 reports must not request person-level or advertising identifiers.');
$mutation_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Models/PpcMutationPlan.php');
assertPpc(str_contains($mutation_source, "'APPROVE'") && str_contains($mutation_source, 'hash_equals'), 'Mutation approval must require an exact human confirmation and content fingerprint.');
assertPpc(!preg_match('/GoogleAdsClient|GoogleAdsQueryService|function\s+(?:execute|rollback)|->mutate/i', $mutation_source), 'Phase 7 must not contain a Google Ads execution or rollback endpoint.');
$preview_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Services/PpcRecommendationPreviewService.php');
assertPpc(str_contains($preview_source, 'PpcNegativeInventoryService') && str_contains($preview_source, 'inventory($customer_id, true)'), 'Recommendation preparation must re-read every applicable negative-keyword scope.');
assertPpc(str_contains($preview_source, 'positiveKeywords($customer_id)') && str_contains($preview_source, 'positive_conflicts'), 'Recommendation preparation must verify enabled positive-keyword conflicts before creating a negative preview.');
assertPpc(!preg_match('/googleAds:mutate|customers\/.+:mutate|->mutate/i', $preview_source), 'Recommendation preparation must remain SELECT-only.');
$workspace_script = (string)file_get_contents(dirname(__DIR__) . '/assets/admin/ppc-intelligence.js');
$workspace_styles = (string)file_get_contents(dirname(__DIR__) . '/assets/admin/ppc-intelligence.css');
assertPpc(str_contains($ppc_admin_source, 'data-wnq-workspace-tab') && str_contains($workspace_script, 'activateWorkspace'), 'Phase 9 must provide focused, accessible operations workspaces.');
assertPpc(str_contains($workspace_script, "event.key === 'ArrowRight'") && str_contains($workspace_styles, 'prefers-reduced-motion'), 'Phase 9 navigation must include keyboard and reduced-motion support.');
assertPpc(!preg_match('/googleAds:mutate|customers\/.+:mutate|->mutate/i', $workspace_script), 'The Phase 9 interface must not add Google Ads mutation behavior.');
assertPpc(str_contains($ppc_admin_source, 'wnq-account-strip') && str_contains($ppc_admin_source, 'wnq-agency-command'), 'Phase 11 must provide compact client and agency command surfaces.');
assertPpc(str_contains($ppc_admin_source, 'wnq-finding-row') && str_contains($ppc_admin_source, 'wnq-lifecycle-flow'), 'Phase 11 must keep decisions concise and lifecycle stages understandable.');
assertPpc(str_contains($workspace_script, 'data-wnq-table-search') && str_contains($workspace_script, 'data-wnq-finding-filter'), 'Phase 11 must provide lightweight register search and finding filters.');
assertPpc(str_contains($workspace_styles, '.wnq-ppc-intelligence .wnq-finding-row') && str_contains($workspace_styles, '.wnq-ppc-management .wnq-agency-command'), 'Phase 11 styles must remain scoped to PPC admin interfaces.');
$negative_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Services/PpcNegativeInventoryService.php');
$lifecycle_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Models/PpcRecommendation.php');
$validation_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Services/PpcRecommendationValidationService.php');
$correlation_source = (string)file_get_contents(dirname(__DIR__) . '/includes/Services/PpcChangeCorrelationService.php');
foreach (['campaign_shared_set','shared_criterion','customer_negative_criterion'] as $resource) {
    assertPpc(str_contains($negative_source, $resource), "Phase 10 negative inventory must query {$resource}.");
}
foreach (['open','investigating','ready_for_review','approved','rejected','implemented_externally','implemented_through_system','monitoring','successful','neutral','unsuccessful','superseded','cancelled'] as $status) {
    assertPpc(str_contains($lifecycle_source, "'{$status}'"), "Recommendation lifecycle must retain the {$status} state.");
}
assertPpc(str_contains($validation_source, '[7, 14, 30]') && str_contains($validation_source, 'correlation evidence, not causal proof'), 'Phase 10 must use conservative equal-length 7/14/30-day validation windows.');
assertPpc(str_contains($correlation_source, "'root_cause_status'") && str_contains($correlation_source, "'unconfirmed'"), 'Change-history correlation must not claim confirmed causation.');
assertPpc(str_contains($lifecycle_source, 'ORDER BY CASE status') && str_contains($lifecycle_source, "ELSE 7 END"), 'Active recommendation lifecycle states must sort before completed states.');
assertPpc(str_contains($lifecycle_source, "date > current_time('mysql')"), 'External implementation timestamps must reject future dates.');
assertPpc(str_contains($correlation_source, "modify('-32 days')"), 'Change correlation must fetch the complete three-day baseline for the 30-day Change Event window.');
assertPpc(str_contains($correlation_source, '$robust_sample') && str_contains($correlation_source, '$moderate_sample'), 'Change correlation labels must apply minimum-data safeguards.');
assertPpc(substr_count($ppc_admin_source, 'temporarily unavailable.') >= 6, 'Independent PPC modules must retain friendly failure isolation.');
foreach ([$negative_source,$lifecycle_source,$validation_source,$correlation_source] as $phase_ten_source) {
    assertPpc(!preg_match('/googleAds:mutate|customers\/.+:mutate|->mutate|mutateCampaign|mutateAdGroup/i', $phase_ten_source), 'Phase 10 services must remain Google Ads read-only.');
}

echo "PPC regression checks passed.\n";
