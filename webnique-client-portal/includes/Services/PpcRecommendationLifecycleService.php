<?php
/** Synchronizes current PPC evidence into persistent recommendation records. */
namespace WNQ\Services;

use WNQ\Models\PpcRecommendation;

if (!defined('ABSPATH')) exit;

final class PpcRecommendationLifecycleService
{
    public static function sync(string $client_id, string $customer_id, array $investigations, array $search_terms, array $keywords): array
    {
        $items = [];
        foreach ((array)($investigations['cases'] ?? []) as $case) {
            $items[] = self::item(
                self::category((string)($case['problem'] ?? '')),
                (string)($case['recommendation'] ?? ''),
                (string)($case['severity'] ?? 'opportunity'),
                (float)($case['data_confidence'] ?? 0),
                (float)($case['recommendation_confidence'] ?? 0),
                (string)($case['period'] ?? ''),
                (string)($case['campaign_id'] ?? ''),
                '',
                '',
                ['problem' => (string)($case['problem'] ?? ''), 'observation' => (string)($case['observation'] ?? ''), 'root_cause_status' => (string)($case['root_cause_status'] ?? ''), 'root_cause' => (string)($case['root_cause'] ?? ''), 'hypotheses' => (array)($case['hypotheses'] ?? [])]
            );
        }
        foreach ((array)($search_terms['terms'] ?? []) as $term) {
            $action = (string)($term['recommended_action'] ?? '');
            if (!in_array($action, ['negative_exact', 'negative_phrase', 'add_as_keyword', 'human_review', 'investigate'], true)) continue;
            $category = $action === 'add_as_keyword' ? 'keyword_opportunity' : ($action === 'investigate' ? 'search_term_waste' : ((string)($term['classification'] ?? '') === 'geographic_conflict' ? 'geographic_issue' : 'search_term_waste'));
            $item = self::item($category, (string)($term['reason'] ?? 'Review this search term.'), $action === 'add_as_keyword' ? 'opportunity' : 'warning', min(.8, (float)($term['confidence'] ?? 0)), (float)($term['confidence'] ?? 0), (string)($search_terms['period'] ?? 'Last 30 days'), (string)($term['campaign_id'] ?? ''), (string)($term['ad_group_id'] ?? ''), 'search-term:' . strtolower((string)($term['query'] ?? '')), ['query' => (string)($term['query'] ?? ''), 'action' => $action, 'clicks' => (int)($term['clicks'] ?? 0), 'cost' => (float)($term['cost'] ?? 0), 'conversions' => (float)($term['conversions'] ?? 0)]);
            $item['campaign_name'] = (string)($term['campaign'] ?? '');
            $items[] = $item;
        }
        foreach ((array)($keywords['conflicts'] ?? []) as $conflict) {
            $conflict_identity = ((string)($conflict['shared_set_resource'] ?? '') ?: (string)($conflict['scope'] ?? 'negative')) . '|positive:' . strtolower((string)($conflict['positive'] ?? '')) . '|negative:' . strtolower((string)($conflict['negative'] ?? ''));
            $item = self::item('negative_conflict', (string)($conflict['recommendation'] ?? 'Investigate negative-keyword routing.'), (string)($conflict['priority'] ?? '') === 'high' ? 'warning' : 'opportunity', .95, .75, 'Current configuration', (string)($conflict['campaign_id'] ?? ''), (string)($conflict['ad_group_id'] ?? ''), $conflict_identity, ['positive' => (string)($conflict['positive'] ?? ''), 'negative' => (string)($conflict['negative'] ?? ''), 'scope' => (string)($conflict['scope'] ?? ''), 'shared_set' => (string)($conflict['shared_set_name'] ?? ''), 'evidence' => (string)($conflict['evidence'] ?? '')]);
            $item['campaign_name'] = (string)($conflict['campaign'] ?? '');
            $items[] = $item;
        }
        foreach ((array)($keywords['non_serving'] ?? []) as $keyword) {
            $item = self::item('non_serving_keyword', (string)($keyword['reason'] ?? 'Keep and investigate.'), 'opportunity', .9, .55, 'Last 180 days', (string)($keyword['campaign_id'] ?? ''), (string)($keyword['ad_group_id'] ?? ''), 'keyword:' . (string)($keyword['criterion_id'] ?? ''), ['keyword' => (string)($keyword['keyword'] ?? ''), 'status_reasons' => (array)($keyword['status_reasons'] ?? []), 'impressions' => 0]);
            $item['campaign_name'] = (string)($keyword['campaign'] ?? '');
            $items[] = $item;
        }
        $items = array_values(array_filter($items, static fn(array $item): bool => $item['recommendation_text'] !== ''));
        foreach ($items as &$item) {
            // Recommendation keys are globally unique in storage, so the exact client/account
            // identity must be part of the stable evidence identity.
            $item['recommendation_key'] = hash('sha256', $client_id . '|' . $customer_id . '|' . $item['recommendation_key']);
        }
        unset($item);
        PpcRecommendation::sync($client_id, $customer_id, $items);
        return PpcRecommendation::forClient($client_id);
    }

    private static function item(string $category, string $text, string $severity, float $data_confidence, float $recommendation_confidence, string $period, string $campaign_id, string $ad_group_id, string $entity_id, array $evidence): array
    {
        $identity = implode('|', [$category, preg_replace('/\D+/', '', $campaign_id), preg_replace('/\D+/', '', $ad_group_id), strtolower($entity_id), strtolower($text)]);
        return [
            'recommendation_key' => hash('sha256', $identity),
            'category' => $category,
            'recommendation_text' => $text,
            'severity' => $severity,
            'data_confidence' => $data_confidence,
            'recommendation_confidence' => $recommendation_confidence,
            'estimated_impact' => 'Impact is not assumed. Compare pre/post performance after a documented implementation.',
            'reporting_period' => $period,
            'campaign_id' => $campaign_id,
            'ad_group_id' => $ad_group_id,
            'entity_id' => $entity_id,
            'evidence' => $evidence,
        ];
    }

    public static function category(string $text): string
    {
        $rules = [
            'conversion' => 'conversion_tracking', 'search-term' => 'search_term_waste', 'search term' => 'search_term_waste',
            'negative' => 'negative_conflict', 'non-serving' => 'non_serving_keyword', 'keyword' => 'keyword_opportunity',
            'budget' => 'budget_pacing', 'impression share' => 'impression_share', 'rank' => 'ad_rank',
            'rsa' => 'rsa_quality', 'ad strength' => 'rsa_quality', 'policy' => 'policy_issue', 'claim' => 'claim_verification',
            'destination' => 'landing_page_issue', 'landing' => 'landing_page_issue', 'lead quality' => 'lead_quality',
            'device' => 'device_performance', 'hour' => 'schedule_performance', 'schedule' => 'schedule_performance',
            'geographic' => 'geographic_issue', 'change' => 'change_history',
        ];
        foreach ($rules as $needle => $category) if (stripos($text, $needle) !== false) return $category;
        return 'other';
    }
}
