<?php
/**
 * Read-only Responsive Search Ad audit and website claim verification.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) exit;

final class PpcAdAuditService
{
    public static function claimConfig(string $client_id): array
    {
        $stored = get_option('wnq_ppc_claim_sources_' . md5($client_id), []);
        return is_array($stored) ? $stored : [];
    }

    public static function saveClaimConfig(string $client_id, string $website, string $raw): bool
    {
        $site_host = self::host($website);
        $claims = [];
        foreach (preg_split('/\R+/', $raw) ?: [] as $line) {
            [$claim, $source] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $claim = sanitize_text_field($claim);
            $source = esc_url_raw($source);
            if ($claim === '' || $source === '' || $site_host === '' || !self::sameHost($site_host, self::host($source))) continue;
            $claims[] = ['claim' => $claim, 'source' => $source];
        }
        $claims = array_slice($claims, 0, 100);
        if (trim($raw) !== '' && !$claims) return false;
        $key = 'wnq_ppc_claim_sources_' . md5($client_id);
        return update_option($key, $claims, false) || get_option($key) === $claims;
    }

    public function report(string $client_id, string $customer_id, array $client, bool $refresh = false): array
    {
        $cache_key = 'wnq_ppc_ads_' . md5($client_id . '|' . $customer_id);
        if (!$refresh && is_array($cached = get_transient($cache_key))) return $cached;

        $today = current_datetime()->format('Y-m-d');
        $start = current_datetime()->modify('-29 days')->format('Y-m-d');
        $inventory_query = new GoogleAdsQueryService();
        $inventory = $inventory_query->select($customer_id, "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, ad_group.id, ad_group.name, ad_group.status, ad_group_ad.resource_name, ad_group_ad.status, ad_group_ad.primary_status, ad_group_ad.ad_strength, ad_group_ad.policy_summary.approval_status, ad_group_ad.policy_summary.review_status, ad_group_ad.policy_summary.policy_topic_entries, ad_group_ad.ad.id, ad_group_ad.ad.type, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions, ad_group_ad.ad.responsive_search_ad.path1, ad_group_ad.ad.responsive_search_ad.path2 FROM ad_group_ad WHERE campaign.advertising_channel_type = 'SEARCH' AND campaign.status != 'REMOVED' AND ad_group.status != 'REMOVED' AND ad_group_ad.status != 'REMOVED' ORDER BY campaign.name, ad_group.name LIMIT 2000");
        if ($inventory_query->errors()) {
            $unavailable = self::unavailable(self::safeErrors($inventory_query->errors()));
            $unavailable['claim_config'] = self::claimConfig($client_id);
            return $unavailable;
        }

        $stats_query = new GoogleAdsQueryService();
        $stats_rows = $stats_query->select($customer_id, "SELECT ad_group_ad.ad.id, metrics.impressions, metrics.clicks, metrics.conversions FROM ad_group_ad WHERE campaign.advertising_channel_type = 'SEARCH' AND segments.date BETWEEN '{$start}' AND '{$today}' LIMIT 2000");
        $stats = [];
        foreach ($stats_rows as $row) {
            $id = (string)($row['adGroupAd']['ad']['id'] ?? '');
            if ($id !== '') $stats[$id] = self::metrics((array)($row['metrics'] ?? []));
        }

        $asset_query = new GoogleAdsQueryService();
        $asset_rows = $asset_query->select($customer_id, "SELECT campaign.id, ad_group.id, ad_group_ad.ad.id, ad_group_ad_asset_view.enabled, ad_group_ad_asset_view.field_type, ad_group_ad_asset_view.performance_label, ad_group_ad_asset_view.pinned_field, ad_group_ad_asset_view.source, asset.id, asset.text_asset.text FROM ad_group_ad_asset_view WHERE campaign.advertising_channel_type = 'SEARCH' AND ad_group_ad_asset_view.enabled = TRUE LIMIT 10000");
        $assets = [];
        foreach ($asset_rows as $row) {
            $id = (string)($row['adGroupAd']['ad']['id'] ?? '');
            $view = (array)($row['adGroupAdAssetView'] ?? []);
            $asset = (array)($row['asset'] ?? []);
            if ($id === '') continue;
            $assets[$id][] = [
                'id' => sanitize_text_field((string)($asset['id'] ?? '')),
                'text' => sanitize_text_field((string)($asset['textAsset']['text'] ?? '')),
                'field_type' => strtolower(sanitize_key((string)($view['fieldType'] ?? 'unknown'))),
                'performance_label' => strtolower(sanitize_key((string)($view['performanceLabel'] ?? 'unknown'))),
                'pinned_field' => strtolower(sanitize_key((string)($view['pinnedField'] ?? ''))),
                'source' => strtolower(sanitize_key((string)($view['source'] ?? 'unknown'))),
            ];
        }

        $all_urls = [];
        foreach ($inventory as $row) {
            foreach ((array)($row['adGroupAd']['ad']['finalUrls'] ?? []) as $url) {
                $url = esc_url_raw((string)$url);
                if ($url !== '') $all_urls[$url] = true;
            }
        }
        $url_health = self::checkUrls(array_keys($all_urls), $refresh);
        $website = self::url((string)($client['website'] ?? ''));
        $website_evidence = self::websiteEvidence($client_id, $website, array_keys($all_urls), $refresh);
        $claim_config = self::claimConfig($client_id);
        $brand_names = array_filter([(string)($client['company'] ?? ''), (string)($client['name'] ?? '')]);

        $ads = [];
        foreach ($inventory as $row) {
            $campaign = (array)($row['campaign'] ?? []);
            $ad_group = (array)($row['adGroup'] ?? []);
            $link = (array)($row['adGroupAd'] ?? []);
            $ad = (array)($link['ad'] ?? []);
            if (strtolower((string)($ad['type'] ?? '')) !== 'responsive_search_ad') continue;
            $id = sanitize_text_field((string)($ad['id'] ?? ''));
            $headlines = self::textAssets((array)($ad['responsiveSearchAd']['headlines'] ?? []));
            $descriptions = self::textAssets((array)($ad['responsiveSearchAd']['descriptions'] ?? []));
            $urls = array_values(array_filter(array_map('esc_url_raw', (array)($ad['finalUrls'] ?? []))));
            $record = [
                'id' => $id,
                'campaign_id' => sanitize_text_field((string)($campaign['id'] ?? '')),
                'campaign' => sanitize_text_field((string)($campaign['name'] ?? 'Campaign')),
                'campaign_status' => strtolower(sanitize_key((string)($campaign['status'] ?? 'unknown'))),
                'ad_group_id' => sanitize_text_field((string)($ad_group['id'] ?? '')),
                'ad_group' => sanitize_text_field((string)($ad_group['name'] ?? 'Ad group')),
                'ad_group_status' => strtolower(sanitize_key((string)($ad_group['status'] ?? 'unknown'))),
                'status' => strtolower(sanitize_key((string)($link['status'] ?? 'unknown'))),
                'primary_status' => strtolower(sanitize_key((string)($link['primaryStatus'] ?? 'unknown'))),
                'ad_strength' => strtolower(sanitize_key((string)($link['adStrength'] ?? 'unknown'))),
                'approval_status' => strtolower(sanitize_key((string)($link['policySummary']['approvalStatus'] ?? 'unknown'))),
                'review_status' => strtolower(sanitize_key((string)($link['policySummary']['reviewStatus'] ?? 'unknown'))),
                'policy_topics' => self::policyTopics((array)($link['policySummary']['policyTopicEntries'] ?? [])),
                'headlines' => $headlines,
                'descriptions' => $descriptions,
                'path1' => sanitize_text_field((string)($ad['responsiveSearchAd']['path1'] ?? '')),
                'path2' => sanitize_text_field((string)($ad['responsiveSearchAd']['path2'] ?? '')),
                'final_urls' => $urls,
                'url_health' => array_intersect_key($url_health, array_flip($urls)),
                'assets' => (array)($assets[$id] ?? []),
            ] + ($stats[$id] ?? self::metrics([]));
            $record += self::auditAd($record, $claim_config, $website_evidence, $brand_names);
            $ads[] = $record;
        }

        $result = [
            'available' => true,
            'status' => ($stats_query->errors() || $asset_query->errors() || empty($website_evidence['available'])) ? 'partial' : 'ready',
            'message' => trim(implode(' ', array_filter([
                $stats_query->errors() ? 'Thirty-day ad performance is unavailable.' : '',
                $asset_query->errors() ? 'Asset performance labels are unavailable.' : '',
                empty($website_evidence['available']) ? (string)($website_evidence['message'] ?? '') : '',
            ]))),
            'ads' => $ads,
            'counts' => self::counts($ads),
            'findings' => self::findings($ads),
            'claim_config' => $claim_config,
            'website_evidence' => $website_evidence,
            'period' => 'Last 30 days',
            'configuration_period' => 'Current configuration',
        ];
        set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        return $result;
    }

    public static function auditAd(array $ad, array $claim_config = [], array $website_evidence = [], array $brand_names = []): array
    {
        $headlines = (array)($ad['headlines'] ?? []);
        $descriptions = (array)($ad['descriptions'] ?? []);
        $texts = array_merge(array_column($headlines, 'text'), array_column($descriptions, 'text'));
        $normalized = array_map([self::class, 'normalize'], $texts);
        $issues = [];
        $serving = ($ad['status'] ?? '') === 'enabled' && ($ad['campaign_status'] ?? '') === 'enabled' && ($ad['ad_group_status'] ?? '') === 'enabled';
        if (in_array($ad['approval_status'] ?? '', ['disapproved'], true)) $issues[] = ['severity' => $serving ? 'critical' : 'warning', 'type' => 'disapproval', 'message' => $serving ? 'An enabled ad is disapproved.' : 'A non-serving ad is disapproved; review it before re-enabling.'];
        elseif (!in_array($ad['approval_status'] ?? '', ['approved', 'approved_limited'], true)) $issues[] = ['severity' => 'warning', 'type' => 'policy_limited', 'message' => 'The ad is not fully approved.'];
        foreach ((array)($ad['url_health'] ?? []) as $health) if (($health['status'] ?? '') === 'broken') $issues[] = ['severity' => $serving ? 'critical' : 'warning', 'type' => 'broken_url', 'message' => $serving ? 'An enabled ad final URL failed the destination check.' : 'A non-serving ad final URL failed the destination check.'];
        if (!$headlines || !$descriptions || empty($ad['final_urls'])) $issues[] = ['severity' => $serving ? 'critical' : 'warning', 'type' => 'incomplete_ad', 'message' => 'Required RSA copy or destination data is missing.'];
        if (count($headlines) < 8) $issues[] = ['severity' => 'opportunity', 'type' => 'headline_coverage', 'message' => count($headlines) . ' of 15 headline slots are populated.'];
        if (count($descriptions) < 3) $issues[] = ['severity' => 'opportunity', 'type' => 'description_coverage', 'message' => count($descriptions) . ' of 4 description slots are populated.'];
        if (count($normalized) !== count(array_unique(array_filter($normalized)))) $issues[] = ['severity' => 'warning', 'type' => 'duplicate_copy', 'message' => 'Duplicate RSA text reduces asset variety.'];
        if (array_filter($headlines, static fn(array $asset): bool => self::charLength((string)($asset['text'] ?? '')) > 30) || array_filter($descriptions, static fn(array $asset): bool => self::charLength((string)($asset['text'] ?? '')) > 90)) $issues[] = ['severity' => 'warning', 'type' => 'copy_length', 'message' => 'One or more assets exceed the expected RSA character limit.'];
        if (preg_grep('/(?:\[[^]]+\]|<[^>]+>|\b(?:xxxx|todo|insert here|your (?:brand|name|company)|placeholder|lorem ipsum)\b)/i', $texts)) $issues[] = ['severity' => 'warning', 'type' => 'placeholder_copy', 'message' => 'Template or placeholder language was detected in RSA copy.'];
        if (preg_grep('/\{keyword:[^}]+\}/i', $texts)) $issues[] = ['severity' => 'warning', 'type' => 'dynamic_keyword_insertion', 'message' => 'Dynamic keyword insertion needs manual copy review.'];
        if (preg_grep('/\b(limited time|this weekend only|year[- ]end|holiday (sale|special|savings)|(?:spring|summer|fall|winter) (sale|special|savings))\b/i', $texts)) $issues[] = ['severity' => 'warning', 'type' => 'seasonal_copy', 'message' => 'Time-sensitive promotional language needs a current-date check.'];
        if (preg_grep('/\bfree\b/i', $texts)) $issues[] = ['severity' => 'warning', 'type' => 'free_offer_language', 'message' => '“Free” promotional language needs agency review even when factually verified.'];
        if (in_array($ad['ad_strength'] ?? '', ['poor'], true)) $issues[] = ['severity' => 'warning', 'type' => 'poor_ad_strength', 'message' => 'Google Ads reports Poor ad strength.'];
        $low_assets = array_filter((array)($ad['assets'] ?? []), static fn(array $asset): bool => ($asset['performance_label'] ?? '') === 'low');
        if ($low_assets) $issues[] = ['severity' => 'opportunity', 'type' => 'low_assets', 'message' => count($low_assets) . ' asset(s) have a Google Ads Low performance label.'];
        $automatic_assets = array_filter((array)($ad['assets'] ?? []), static fn(array $asset): bool => ($asset['source'] ?? '') === 'automatically_created');
        if ($automatic_assets) $issues[] = ['severity' => 'opportunity', 'type' => 'automatic_assets', 'message' => count($automatic_assets) . ' automatically created asset(s) are linked to this RSA.'];
        $brand_present = !$brand_names;
        foreach ($brand_names as $brand) if (self::normalize($brand) !== '' && str_contains(implode(' ', $normalized), self::normalize($brand))) $brand_present = true;
        if (!$brand_present) $issues[] = ['severity' => 'opportunity', 'type' => 'brand_coverage', 'message' => 'The client business name was not found in the RSA copy.'];

        $claims = [];
        foreach ($texts as $text) {
            $category = self::claimCategory((string)$text);
            if ($category === '') continue;
            $verification = self::verifyClaim((string)$text, $claim_config, $website_evidence);
            $claims[] = ['text' => (string)$text, 'category' => $category] + $verification;
        }
        $unverified = array_filter($claims, static fn(array $claim): bool => empty($claim['verified']));
        if ($unverified) $issues[] = ['severity' => 'warning', 'type' => 'claim_verification', 'message' => count($unverified) . ' factual claim(s) need source verification.'];

        $order = ['critical' => 0, 'warning' => 1, 'opportunity' => 2, 'healthy' => 3];
        usort($issues, static fn(array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);
        return ['severity' => $issues[0]['severity'] ?? 'healthy', 'issues' => $issues, 'claims' => $claims];
    }

    private static function findings(array $ads): array
    {
        $groups = [];
        foreach ($ads as $ad) foreach ((array)$ad['issues'] as $issue) {
            $key = (string)$issue['type'];
            if (!isset($groups[$key])) $groups[$key] = ['severity' => $issue['severity'], 'message' => $issue['message'], 'count' => 0, 'campaign_id' => (string)$ad['campaign_id']];
            $groups[$key]['count']++;
        }
        $findings = [];
        foreach ($groups as $type => $group) $findings[] = [
            'severity' => $group['severity'],
            'title' => ucwords(str_replace('_', ' ', $type)),
            'evidence' => $group['count'] . ' RSA(s): ' . $group['message'],
            'period' => in_array($type, ['low_assets'], true) ? 'Current Google Ads asset labels' : 'Current configuration',
            'action' => self::actionFor($type),
            'confidence' => in_array($type, ['disapproval', 'broken_url', 'low_assets'], true) ? .98 : .82,
            'campaign_id' => $group['campaign_id'],
            'section' => 'ppc-ads',
        ];
        if (!$ads) $findings[] = ['severity' => 'warning', 'title' => 'No Responsive Search Ads returned', 'evidence' => 'Google Ads returned no RSAs for the linked account’s non-removed Search campaigns.', 'period' => 'Current configuration', 'action' => 'Confirm the account uses Search campaigns and that the linked customer ID is correct.', 'confidence' => .95, 'campaign_id' => '', 'section' => 'ppc-ads'];
        elseif (!$findings) $findings[] = ['severity' => 'healthy', 'title' => 'No urgent RSA issues detected', 'evidence' => count($ads) . ' RSA(s) passed the available Phase 4 checks.', 'period' => 'Current configuration', 'action' => 'Continue monitoring policy status, destinations, and asset performance.', 'confidence' => .85, 'campaign_id' => '', 'section' => 'ppc-ads'];
        return $findings;
    }

    private static function actionFor(string $type): string
    {
        $actions = [
            'disapproval' => 'Review the reported Google policy topics before drafting any replacement copy.',
            'broken_url' => 'Verify the destination manually, then repair the page or prepare a gated final-URL proposal.',
            'claim_verification' => 'Verify each claim against the client website and save its exact source URL before reuse.',
            'low_assets' => 'Review Low assets alongside sufficient performance evidence before proposing replacements.',
            'dynamic_keyword_insertion' => 'Confirm every possible insertion remains accurate, readable, and policy-safe.',
            'seasonal_copy' => 'Confirm the promotion is current; do not remove it solely because the phrase was detected.',
            'free_offer_language' => 'Confirm this language fits the agency’s customer-acquisition strategy before retaining it.',
            'placeholder_copy' => 'Replace template language only with copy grounded in verified client sources.',
            'automatic_assets' => 'Review automatically created assets for accuracy before proposing any opt-out or replacement.',
        ];
        return $actions[$type] ?? 'Open the affected RSA and review the evidence before proposing a change.';
    }

    private static function claimCategory(string $text): string
    {
        $patterns = [
            'ranking_or_rating' => '/(?:#\s*1|number one|best|top[ -]?rated|\d(?:\.\d)?\s*(?:star|★)|\d+\+?\s*reviews?)/i',
            'credential' => '/\b(licensed|insured|bonded|certified|accredited|approved)\b/i',
            'availability' => '/\b(24\s*\/\s*7|same[ -]?day|emergency service|open \d|weekends?)\b/i',
            'guarantee' => '/\b(warranty|guarantee|guaranteed)\b/i',
            'history_or_scale' => '/\b(since \d{4}|\d+\+? years?|family[ -]?owned|locally owned|\d+\+? (?:customers?|projects?|jobs?))\b/i',
            'price_or_promotion' => '/(?:\bfree\b|\bfinancing\b|\bdiscount\b|\bsave\b|\$\s*\d+|\d+\s*%\s*off)/i',
        ];
        foreach ($patterns as $category => $pattern) if (preg_match($pattern, $text)) return $category;
        return '';
    }

    private static function verifyClaim(string $text, array $claim_config, array $website_evidence): array
    {
        $needle = self::normalize($text);
        foreach ($claim_config as $claim) {
            $approved = self::normalize((string)($claim['claim'] ?? ''));
            if ($approved !== '' && (str_contains($needle, $approved) || str_contains($approved, $needle))) return ['verified' => true, 'source' => esc_url_raw((string)($claim['source'] ?? '')), 'verification' => 'approved_source'];
        }
        foreach ((array)($website_evidence['pages'] ?? []) as $page) {
            if ($needle !== '' && str_contains((string)($page['text'] ?? ''), $needle)) return ['verified' => true, 'source' => esc_url_raw((string)($page['url'] ?? '')), 'verification' => 'website_exact'];
        }
        return ['verified' => false, 'source' => '', 'verification' => 'needs_review'];
    }

    private static function websiteEvidence(string $client_id, string $website, array $final_urls, bool $refresh): array
    {
        $host = self::host($website);
        if ($host === '') return ['available' => false, 'message' => 'Claim verification is unavailable because this client has no valid website URL.', 'pages' => []];
        $urls = [$website];
        foreach ($final_urls as $url) if (self::sameHost($host, self::host((string)$url))) $urls[] = (string)$url;
        foreach (self::claimConfig($client_id) as $claim) if (self::sameHost($host, self::host((string)($claim['source'] ?? '')))) $urls[] = (string)$claim['source'];
        $urls = array_slice(array_values(array_unique(array_filter(array_map('esc_url_raw', $urls)))), 0, 5);
        $cache_key = 'wnq_ppc_claim_site_' . md5($client_id . '|' . implode('|', $urls));
        if (!$refresh && is_array($cached = get_transient($cache_key))) return $cached;
        $pages = [];
        foreach ($urls as $url) {
            $response = wp_safe_remote_get($url, ['timeout' => 5, 'redirection' => 3, 'limit_response_size' => 500000, 'user-agent' => 'Golden Web Marketing PPC Claim Verifier']);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) continue;
            $html = (string)wp_remote_retrieve_body($response);
            $html = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?: $html;
            $text = self::normalize(wp_strip_all_tags($html, true));
            if ($text !== '') $pages[] = ['url' => $url, 'text' => substr($text, 0, 200000)];
        }
        $result = $pages ? ['available' => true, 'message' => '', 'pages' => $pages] : ['available' => false, 'message' => 'The client website could not be read, so factual claims require manual source verification.', 'pages' => []];
        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
        return $result;
    }

    private static function checkUrls(array $urls, bool $refresh): array
    {
        $results = [];
        foreach (array_slice($urls, 0, 10) as $url) {
            $key = 'wnq_ppc_url_' . md5((string)$url);
            if (!$refresh && is_array($cached = get_transient($key))) { $results[$url] = $cached; continue; }
            $response = wp_safe_remote_head((string)$url, ['timeout' => 4, 'redirection' => 3, 'user-agent' => 'Golden Web Marketing PPC Destination Checker']);
            $code = is_wp_error($response) ? 0 : (int)wp_remote_retrieve_response_code($response);
            if (in_array($code, [403, 405], true)) {
                $response = wp_safe_remote_get((string)$url, ['timeout' => 4, 'redirection' => 3, 'limit_response_size' => 1024, 'user-agent' => 'Golden Web Marketing PPC Destination Checker']);
                $code = is_wp_error($response) ? 0 : (int)wp_remote_retrieve_response_code($response);
            }
            $result = ['status' => ($code >= 200 && $code < 400) ? 'healthy' : 'broken', 'http_code' => $code, 'message' => is_wp_error($response) ? sanitize_text_field($response->get_error_message()) : ''];
            $results[$url] = $result;
            set_transient($key, $result, 6 * HOUR_IN_SECONDS);
        }
        return $results;
    }

    private static function textAssets(array $assets): array
    {
        $result = [];
        foreach ($assets as $asset) {
            $asset = (array)$asset;
            $result[] = ['text' => sanitize_text_field((string)($asset['text'] ?? '')), 'pinned_field' => strtolower(sanitize_key((string)($asset['pinnedField'] ?? '')))];
        }
        return $result;
    }

    private static function policyTopics(array $entries): array
    {
        $topics = [];
        foreach ($entries as $entry) {
            $entry = (array)$entry;
            $topic = sanitize_text_field((string)($entry['topic'] ?? ''));
            if ($topic !== '') $topics[] = $topic;
        }
        return array_values(array_unique($topics));
    }

    private static function counts(array $ads): array
    {
        $counts = ['total' => count($ads), 'enabled' => 0, 'critical' => 0, 'warning' => 0, 'opportunity' => 0, 'healthy' => 0, 'unverified_claims' => 0, 'low_assets' => 0];
        foreach ($ads as $ad) {
            if (($ad['status'] ?? '') === 'enabled' && ($ad['campaign_status'] ?? '') === 'enabled' && ($ad['ad_group_status'] ?? '') === 'enabled') $counts['enabled']++;
            $severity = (string)($ad['severity'] ?? 'healthy');
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
            $counts['unverified_claims'] += count(array_filter((array)($ad['claims'] ?? []), static fn(array $claim): bool => empty($claim['verified'])));
            $counts['low_assets'] += count(array_filter((array)($ad['assets'] ?? []), static fn(array $asset): bool => ($asset['performance_label'] ?? '') === 'low'));
        }
        return $counts;
    }

    private static function metrics(array $metrics): array
    {
        return ['impressions' => (int)($metrics['impressions'] ?? 0), 'clicks' => (int)($metrics['clicks'] ?? 0), 'conversions' => round((float)($metrics['conversions'] ?? 0), 2)];
    }

    private static function unavailable(string $message): array
    {
        return ['available' => false, 'status' => 'unavailable', 'message' => $message ?: 'Google Ads could not load the RSA audit right now.', 'ads' => [], 'counts' => [], 'findings' => [], 'claim_config' => [], 'website_evidence' => []];
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

    private static function normalize(string $value): string
    {
        $value = strtolower(wp_strip_all_tags($value));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '');
    }

    private static function charLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function host(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        return strtolower((string)(wp_parse_url($url, PHP_URL_HOST) ?: ''));
    }

    private static function url(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        return esc_url_raw($url);
    }

    private static function sameHost(string $left, string $right): bool
    {
        return preg_replace('/^www\./', '', strtolower($left)) === preg_replace('/^www\./', '', strtolower($right));
    }
}
