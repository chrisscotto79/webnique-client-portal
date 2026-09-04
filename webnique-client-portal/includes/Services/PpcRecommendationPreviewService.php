<?php
/**
 * Converts sufficiently reviewed PPC recommendations into exact safety previews.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

use WNQ\Models\PpcAccount;
use WNQ\Models\PpcMutationPlan;
use WNQ\Models\PpcProposal;
use WNQ\Models\Client;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcRecommendationPreviewService
{
    public static function candidates(array $dashboard, array $search_terms, array $keywords): array
    {
        $dashboard_available = count(array_filter($dashboard, static fn($module): bool => is_array($module) && !empty($module['available']))) > 0;
        if (!$dashboard_available && empty($search_terms['available']) && empty($keywords['available'])) {
            return ['available' => false, 'status' => 'unavailable', 'candidates' => [], 'counts' => [], 'summary' => 'Recommendation evidence is unavailable.'];
        }
        $candidates = [];
        foreach ((array)($search_terms['terms'] ?? []) as $term) {
            $action = (string)($term['recommended_action'] ?? '');
            $status = (string)($term['status'] ?? '');
            if (in_array($status, ['approved_exact', 'approved_phrase'], true) && in_array($action, ['negative_exact', 'negative_phrase'], true) && !empty($term['id'])) {
                $candidates[] = self::candidate(
                    'ready',
                    'Approved negative: ' . (string)$term['query'],
                    'Internal review is complete. Choose the exact campaign or ad-group scope, then reverify current Google Ads state.',
                    'Last 30 Days',
                    (int)($term['id'] ?? 0),
                    $term
                );
            } elseif (in_array($status, ['approved_exact', 'approved_phrase'], true) && in_array($action, ['negative_exact', 'negative_phrase'], true)) {
                $candidates[] = self::candidate('missing_identifier', 'Approved negative: ' . (string)$term['query'], 'The stored proposal ID is missing, so an exact safety preview cannot be created.', 'Last 30 Days', 0, $term);
            } elseif (in_array($action, ['negative_exact', 'negative_phrase'], true) && $status === 'pending') {
                $candidates[] = self::candidate('needs_internal_review', 'Proposed negative: ' . (string)$term['query'], 'Approve or reject this search term in Search-Term Review before creating a safety preview.', 'Last 30 Days', 0, $term);
            } elseif ($action === 'add_as_keyword') {
                $candidates[] = self::candidate('needs_keyword_coverage', 'Keyword opportunity: ' . (string)$term['query'], 'Existing keyword coverage and routing have not been proven, so automatic preview creation is blocked.', 'Last 30 Days', 0, $term);
            }
        }

        foreach ((array)($keywords['conflicts'] ?? []) as $conflict) {
            $candidates[] = self::candidate('needs_routing_review', 'Negative conflict: ' . (string)$conflict['negative'], 'A conflict is evidence to investigate, not proof that the negative should be removed. Confirm routing intent first.', 'Current Configuration', 0, $conflict);
        }
        foreach ((array)($keywords['non_serving'] ?? []) as $keyword) {
            $candidates[] = self::candidate('insufficient_data', 'Non-serving keyword: ' . (string)$keyword['keyword'], 'Zero impressions alone does not justify pausing or removing this keyword.', 'Last 180 Days', 0, $keyword);
        }
        foreach ((array)($dashboard['budget_analysis']['campaigns'] ?? []) as $campaign) {
            if (($campaign['pace_status'] ?? 'on_track') === 'on_track') {
                continue;
            }
            $readiness = !empty($campaign['shared_budget']) ? 'shared_budget_review' : 'needs_investigation';
            $reason = !empty($campaign['shared_budget'])
                ? 'This campaign uses a shared budget. Every attached campaign must be evaluated before proposing a change.'
                : 'Pacing alone does not establish a safe new budget. CPA target, lead quality, demand, and recent changes still need review.';
            $candidates[] = self::candidate($readiness, 'Budget pacing: ' . (string)$campaign['name'], $reason, 'Current Month', 0, $campaign);
        }

        $order = ['ready' => 0, 'missing_identifier' => 1, 'needs_internal_review' => 2, 'needs_routing_review' => 3, 'needs_keyword_coverage' => 4, 'shared_budget_review' => 5, 'needs_investigation' => 6, 'insufficient_data' => 7];
        usort($candidates, static fn(array $a, array $b): int => ($order[$a['readiness']] ?? 9) <=> ($order[$b['readiness']] ?? 9));
        $counts = array_count_values(array_column($candidates, 'readiness'));
        return [
            'available'  => true,
            'status'     => 'ready',
            'candidates' => array_slice($candidates, 0, 250),
            'counts'     => $counts,
            'summary'    => (int)($counts['ready'] ?? 0) . ' recommendation(s) are eligible for an exact safety preview; all others remain blocked by their stated safeguard.',
        ];
    }

    public static function createApprovedNegativePlan(string $client_id, int $proposal_id, string $scope): array
    {
        $connection = PpcAccount::getByClientId($client_id);
        $proposal = PpcProposal::getByIdForClient($proposal_id, $client_id);
        if (!$connection || !$proposal) {
            return ['ok' => false, 'message' => 'The exact client connection or reviewed recommendation could not be found.'];
        }
        $customer_id = preg_replace('/\D+/', '', (string)($connection['customer_id'] ?? '')) ?: '';
        if (strlen($customer_id) !== 10 || !hash_equals($customer_id, preg_replace('/\D+/', '', (string)$proposal['customer_id']) ?: '')) {
            return ['ok' => false, 'message' => 'The recommendation does not match this client’s current Google Ads account.'];
        }
        $status = (string)($proposal['status'] ?? '');
        if (!in_array($status, ['approved_exact', 'approved_phrase'], true)) {
            return ['ok' => false, 'message' => 'This recommendation has not completed internal negative-keyword review.'];
        }
        if (!in_array((string)($proposal['recommended_action'] ?? ''), ['negative_exact', 'negative_phrase'], true)) {
            return ['ok' => false, 'message' => 'Only a recommendation originally classified as a negative can create a negative-keyword preview.'];
        }
        if (!in_array($scope, ['campaign', 'ad_group'], true)) {
            return ['ok' => false, 'message' => 'Choose an exact campaign or ad-group scope.'];
        }

        $campaign_id = preg_replace('/\D+/', '', (string)($proposal['campaign_id'] ?? '')) ?: '';
        $ad_group_id = preg_replace('/\D+/', '', (string)($proposal['ad_group_id'] ?? '')) ?: '';
        if ($campaign_id === '' || ($scope === 'ad_group' && $ad_group_id === '')) {
            return ['ok' => false, 'message' => 'The recommendation is missing the exact Google Ads scope identifier.'];
        }
        $query_text = sanitize_text_field((string)($proposal['query_text'] ?? ''));
        $match_type = $status === 'approved_phrase' ? 'phrase' : 'exact';
        $inventory = (new PpcNegativeInventoryService())->inventory($customer_id, true);
        if (empty($inventory['available']) || !empty($inventory['errors'])) {
            return ['ok' => false, 'message' => 'Every applicable negative-keyword scope could not be verified. No preview was created.'];
        }
        $duplicate = PpcNegativeInventoryService::duplicate($inventory, $query_text, $match_type, $campaign_id, $ad_group_id);
        if ($duplicate) {
            return ['ok' => false, 'message' => 'This exact negative already applies through ' . str_replace('_', ' ', (string)$duplicate['scope']) . '. The recommendation is already resolved.'];
        }
        $client = Client::getByClientId($client_id) ?: [];
        $config = PpcSearchTermService::config($client_id, $client);
        $protected = PpcNegativeInventoryService::protectedTerms($query_text, $config);
        if ($protected) {
            return ['ok' => false, 'message' => 'This recommendation overlaps protected client brand, service, or geographic terms and requires renewed human investigation.'];
        }
        $positive_inventory = (new PpcNegativeInventoryService())->positiveKeywords($customer_id);
        if (empty($positive_inventory['available'])) {
            return ['ok' => false, 'message' => 'Enabled positive keyword coverage could not be verified. No preview was created.'];
        }
        $prospective = ['scope'=>$scope,'negative'=>$query_text,'match_type'=>$match_type,'resource_name'=>'prospective','campaign_id'=>$campaign_id,'ad_group_id'=>$ad_group_id,'campaign_ids'=>[]];
        $positive_conflicts = PpcNegativeInventoryService::conflicts((array)$positive_inventory['items'], [$prospective]);
        if ($positive_conflicts) {
            return ['ok' => false, 'message' => 'This proposed negative may block ' . count($positive_conflicts) . ' enabled positive keyword(s) in the selected scope. Renewed routing review is required.'];
        }

        $scope_id = $scope === 'campaign' ? $campaign_id : $ad_group_id;
        $scope_label = $scope === 'campaign' ? 'campaign ' . (string)$proposal['campaign_name'] : 'ad group ' . $ad_group_id . ' in campaign ' . (string)$proposal['campaign_name'];
        $parent_resource = $scope === 'campaign'
            ? "customers/{$customer_id}/campaigns/{$campaign_id}"
            : "customers/{$customer_id}/adGroups/{$ad_group_id}";
        $evidence = (array)($proposal['evidence'] ?? []);
        $input = [
            'operation'       => 'negative_keyword_add',
            'entity_type'     => 'negative_keyword',
            'entity_id'       => $parent_resource,
            'entity_name'     => $query_text,
            'current_value'   => "No identical {$match_type}-match negative found in any applicable ad-group, campaign, shared-list, or account-level scope during the current read-only verification for {$scope_label}.",
            'proposed_value'  => "Add {$match_type}-match negative keyword \"{$query_text}\" to exact {$scope} ID {$scope_id}.",
            'evidence'        => 'Internally reviewed search-term recommendation. Last 30 Days: ' . (int)($evidence['clicks'] ?? 0) . ' clicks, ' . number_format((float)($evidence['cost'] ?? 0), 2) . ' spend, ' . number_format((float)($evidence['conversions'] ?? 0), 2) . ' conversions. Classification: ' . sanitize_key((string)($proposal['classification'] ?? '')) . '; confidence: ' . number_format((float)($proposal['confidence'] ?? 0) * 100, 0) . '%.',
            'reversibility'   => 'reversible',
            'rollback_plan'   => 'Remove only the newly created negative keyword criterion using the exact resource name captured by a future execution receipt. Reverify that the criterion has not changed before rollback.',
            'request_token'   => wp_generate_uuid4(),
        ];
        return PpcMutationPlan::create($client_id, $connection, $input);
    }

    private static function candidate(string $readiness, string $title, string $reason, string $period, int $proposal_id, array $source): array
    {
        return [
            'readiness'  => $readiness,
            'title'      => sanitize_text_field($title),
            'reason'     => sanitize_text_field($reason),
            'period'     => $period,
            'proposal_id'=> $proposal_id,
            'campaign'   => sanitize_text_field((string)($source['campaign'] ?? $source['name'] ?? '')),
            'campaign_id'=> preg_replace('/\D+/', '', (string)($source['campaign_id'] ?? $source['id'] ?? '')) ?: '',
            'ad_group'   => sanitize_text_field((string)($source['ad_group'] ?? '')),
            'ad_group_id'=> preg_replace('/\D+/', '', (string)($source['ad_group_id'] ?? '')) ?: '',
        ];
    }

}
