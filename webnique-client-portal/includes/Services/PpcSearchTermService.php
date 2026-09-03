<?php
/**
 * Read-only search-query retrieval, classification, and geo analysis.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

use WNQ\Models\PpcProposal;

if (!defined('ABSPATH')) exit;

final class PpcSearchTermService
{
    public static function config(string $client_id, array $client = []): array
    {
        $stored = get_option('wnq_ppc_search_config_' . md5($client_id), []);
        $stored = is_array($stored) ? $stored : [];
        $profile = class_exists('WNQ\\Models\\SEOHub') ? \WNQ\Models\SEOHub::getProfile($client_id) : null;
        $profile = is_array($profile) ? $profile : [];
        $default_services = array_merge(self::list($client['active_services'] ?? ''), self::list($profile['primary_services'] ?? []));
        $default_areas = array_merge(self::list(implode(', ', array_filter([$client['city'] ?? '', $client['state'] ?? '']))), self::list($profile['service_locations'] ?? []));
        $services = self::list(array_key_exists('services', $stored) ? $stored['services'] : $default_services);
        $target = self::list(array_key_exists('target_areas', $stored) ? $stored['target_areas'] : $default_areas);
        return [
            'brand_names' => self::list([$client['company'] ?? '', $client['name'] ?? '']),
            'services' => $services,
            'target_areas' => $target,
            'excluded_areas' => self::list($stored['excluded_areas'] ?? ''),
            'competitors' => self::list($stored['competitors'] ?? ''),
            'excluded_terms' => self::list($stored['excluded_terms'] ?? ''),
        ];
    }

    public static function saveConfig(string $client_id, array $data): bool
    {
        $config = [];
        foreach (['services', 'target_areas', 'excluded_areas', 'competitors', 'excluded_terms'] as $key) $config[$key] = self::list($data[$key] ?? '');
        return update_option('wnq_ppc_search_config_' . md5($client_id), $config, false) || get_option('wnq_ppc_search_config_' . md5($client_id)) === $config;
    }

    public function report(string $client_id, string $customer_id, array $client, bool $refresh = false): array
    {
        $cache_key = 'wnq_ppc_sqr_' . md5($client_id . '|' . $customer_id);
        if (!$refresh && is_array($cached = get_transient($cache_key))) return $cached;
        $today = current_datetime()->format('Y-m-d');
        $start = current_datetime()->modify('-29 days')->format('Y-m-d');
        $query = new GoogleAdsQueryService();
        $rows = $query->select($customer_id, "SELECT search_term_view.search_term, segments.keyword.info.text, segments.keyword.info.match_type, campaign.id, campaign.name, ad_group.id, ad_group.name, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions FROM search_term_view WHERE segments.date BETWEEN '{$start}' AND '{$today}' ORDER BY metrics.cost_micros DESC LIMIT 2000");
        if ($query->errors()) return ['available' => false, 'message' => self::safeErrors($query->errors()), 'terms' => [], 'period' => 'Last 30 days'];
        $config = self::config($client_id, $client);
        $terms = [];
        foreach ($rows as $row) {
            $view = (array)($row['searchTermView'] ?? []);
            $segments = (array)($row['segments'] ?? []);
            $campaign = (array)($row['campaign'] ?? []);
            $ad_group = (array)($row['adGroup'] ?? []);
            $metrics = (array)($row['metrics'] ?? []);
            $term = [
                'query' => sanitize_text_field((string)($view['searchTerm'] ?? '')),
                'keyword' => sanitize_text_field((string)($segments['keyword']['info']['text'] ?? '')),
                'match_type' => strtolower(sanitize_key((string)($segments['keyword']['info']['matchType'] ?? 'unknown'))),
                'campaign_id' => sanitize_text_field((string)($campaign['id'] ?? '')),
                'campaign' => sanitize_text_field((string)($campaign['name'] ?? 'Campaign')),
                'ad_group_id' => sanitize_text_field((string)($ad_group['id'] ?? '')),
                'ad_group' => sanitize_text_field((string)($ad_group['name'] ?? 'Ad group')),
                'impressions' => (int)($metrics['impressions'] ?? 0), 'clicks' => (int)($metrics['clicks'] ?? 0),
                'cost' => round(((float)($metrics['costMicros'] ?? 0)) / 1000000, 2), 'conversions' => round((float)($metrics['conversions'] ?? 0), 2),
            ];
            $term['cpa'] = $term['conversions'] > 0 ? round($term['cost'] / $term['conversions'], 2) : 0;
            $term += self::classify($term['query'], $config, $term);
            $term['proposal_key'] = hash('sha256', $client_id . '|' . $customer_id . '|' . strtolower($term['query']) . '|' . $term['campaign_id'] . '|' . $term['recommended_action']);
            $terms[] = $term;
        }
        PpcProposal::sync($client_id, $customer_id, $terms);
        $statuses = PpcProposal::statuses($client_id);
        foreach ($terms as &$term) $term += $statuses[$term['proposal_key']] ?? ['id' => 0, 'status' => 'not_proposed'];
        unset($term);
        $result = ['available' => true, 'status' => 'ready', 'message' => '', 'terms' => $terms, 'period' => 'Last 30 days', 'config' => $config, 'counts' => array_count_values(array_column($terms, 'classification')), 'findings' => self::findings($terms)];
        set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        return $result;
    }

    public static function classify(string $query, array $config, array $metrics = []): array
    {
        $q = strtolower($query);
        if (preg_match('/\b(job|jobs|career|careers|salary|hiring|employment|indeed)\b/i', $q)) return self::result('employment', .98, 'negative_phrase', 'Employment intent is normally unrelated to customer acquisition.');
        if (preg_match('/\b(diy|do it yourself|how to|tutorial|course|training|certification)\b/i', $q)) return self::result('diy', .92, 'negative_phrase', 'DIY or training intent is unlikely to request service.');
        if (self::contains($q, $config['excluded_terms'] ?? [])) return self::result('unrelated', .96, 'negative_exact', 'Matches an agency-confirmed excluded term.');
        if (self::contains($q, $config['brand_names'] ?? [])) return self::result('high_intent', .98, 'keep', 'Matches the client’s own business name.');
        if (self::contains($q, $config['competitors'] ?? [])) return self::result('competitor', .92, 'investigate', 'Matches a configured competitor name.');
        if (self::contains($q, $config['target_areas'] ?? [])) return self::result('relevant', .97, 'keep', 'Matches a configured target service area.');
        if (self::contains($q, $config['excluded_areas'] ?? [])) return self::result('geographic_conflict', .95, 'human_review', 'Matches an explicitly excluded geographic area; human review is required.');
        if (preg_match('/\b(login|sign in|pay bill|customer service|phone number|hours|reviews?|comparison)\b/i', $q)) return self::result('low_intent', .78, 'watch', 'The query is related but does not show clear new-customer purchase intent.');
        if (preg_match('/\b(what|why|when|guide|ideas|meaning|definition|average|typical|reddit|forum)\b/i', $q)) return self::result('informational', .82, 'watch', 'The query appears informational; performance evidence should determine action.');
        $service_match = self::contains($q, $config['services'] ?? []);
        if ($service_match && preg_match('/\b(near me|company|service|contractor|quote|estimate|hire|emergency|same day|price|cost)\b/i', $q)) return self::result('high_intent', .9, !empty($metrics['conversions']) ? 'add_as_keyword' : 'keep', 'Service language and commercial intent are both present.');
        if ($service_match) return self::result('relevant', .82, 'keep', 'Matches a configured client service.');
        return self::result('requires_human_review', .45, 'human_review', 'No reliable service, geographic, or intent rule matched.');
    }

    private static function findings(array $terms): array
    {
        $negative_candidates = array_filter($terms, static fn(array $term): bool => in_array($term['recommended_action'], ['negative_exact', 'negative_phrase'], true) && $term['status'] === 'pending');
        $waste = array_sum(array_column($negative_candidates, 'cost'));
        $opportunities = array_filter($terms, static fn(array $term): bool => $term['recommended_action'] === 'add_as_keyword');
        $findings = [];
        if ($waste > 0) $findings[] = ['severity' => 'warning', 'title' => 'Search-term waste requires review', 'evidence' => count($negative_candidates) . ' proposed negative(s) account for $' . number_format($waste, 2) . ' in spend.', 'period' => 'Last 30 days', 'action' => 'Review the proposed negatives; approval remains internal and read-only.', 'confidence' => .9, 'campaign_id' => ''];
        if ($opportunities) $findings[] = ['severity' => 'opportunity', 'title' => 'Converted high-intent queries found', 'evidence' => count($opportunities) . ' high-intent converted search term(s) may deserve exact-keyword review.', 'period' => 'Last 30 days', 'action' => 'Review query relevance and existing keyword coverage.', 'confidence' => .82, 'campaign_id' => ''];
        return $findings;
    }

    private static function safeErrors(array $errors): string
    {
        $message = implode(' ', $errors);
        $credentials = GoogleAdsCredentials::get();
        foreach (['developer_token', 'oauth_client_id', 'oauth_client_secret', 'refresh_token'] as $key) {
            $secret = (string)($credentials[$key] ?? '');
            if ($secret !== '') $message = str_replace($secret, '[redacted]', $message);
        }
        return sanitize_text_field($message);
    }

    private static function result(string $classification, float $confidence, string $action, string $reason): array { return ['classification' => $classification, 'confidence' => $confidence, 'recommended_action' => $action, 'reason' => $reason]; }
    private static function contains(string $query, array $values): bool
    {
        foreach ($values as $value) {
            $value = strtolower(trim((string)$value));
            if ($value !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($value, '/') . '(?![a-z0-9])/i', $query)) return true;
        }
        return false;
    }
    private static function list($value): array
    {
        if (is_array($value)) $items = $value;
        else { $decoded = json_decode((string)$value, true); $items = is_array($decoded) ? $decoded : (preg_split('/[\r\n,]+/', (string)$value) ?: []); }
        return array_values(array_unique(array_filter(array_map(static fn($item): string => strtolower(sanitize_text_field(trim((string)$item))), $items))));
    }
}
