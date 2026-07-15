<?php
/**
 * Minimal Google Ads REST client for read-only client portal reporting.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleAdsClient
{
    private const API_VERSION = 'v24';
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const ADS_BASE_URL = 'https://googleads.googleapis.com/';

    private array $settings;
    private array $errors = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function isConfigured(): bool
    {
        return $this->setting('developer_token') !== ''
            && $this->setting('manager_customer_id') !== ''
            && $this->setting('oauth_client_id') !== ''
            && $this->setting('oauth_client_secret') !== ''
            && $this->setting('refresh_token') !== '';
    }

    public function errors(): array
    {
        return array_values(array_unique(array_filter($this->errors)));
    }

    public function listManagerAccounts(bool $refresh = false): array
    {
        $cache_key = 'wnq_google_ads_accounts_' . md5($this->setting('manager_customer_id'));
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $manager_id = $this->customerId($this->setting('manager_customer_id'));
        if ($manager_id === '') {
            $this->errors[] = 'Google Ads manager customer ID is missing.';
            return [];
        }

        $error_count = count($this->errors);
        $rows = $this->search($manager_id, "SELECT customer_client.client_customer, customer_client.descriptive_name, customer_client.id, customer_client.manager, customer_client.status, customer_client.currency_code, customer_client.time_zone FROM customer_client WHERE customer_client.status != 'CANCELED'");
        $accounts = [];
        foreach ($rows as $row) {
            $client = $row['customerClient'] ?? [];
            $id = $this->customerId((string)($client['id'] ?? $client['clientCustomer'] ?? ''));
            if ($id === '' || !empty($client['manager'])) {
                continue;
            }
            $accounts[] = [
                'customer_id' => $id,
                'name' => sanitize_text_field((string)($client['descriptiveName'] ?? '')),
                'status' => sanitize_text_field((string)($client['status'] ?? '')),
                'currency_code' => sanitize_text_field((string)($client['currencyCode'] ?? '')),
                'time_zone' => sanitize_text_field((string)($client['timeZone'] ?? '')),
            ];
        }

        if (count($this->errors) === $error_count) {
            set_transient($cache_key, $accounts, 30 * MINUTE_IN_SECONDS);
        }
        return $accounts;
    }

    public function matchClient(array $client, bool $refresh = false): ?array
    {
        $accounts = $this->listManagerAccounts($refresh);
        $targets = array_filter([
            (string)($client['company'] ?? ''),
            (string)($client['name'] ?? ''),
            (string)($client['client_id'] ?? ''),
        ]);

        $best = null;
        foreach ($accounts as $account) {
            $score = 0;
            foreach ($targets as $target) {
                $score = max($score, $this->similarityScore($target, (string)$account['name']));
            }
            if (!$best || $score > $best['match_score']) {
                $best = array_merge($account, ['match_score' => $score]);
            }
        }

        return $best;
    }

    public function connectionTest(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'accounts' => [],
                'message' => 'Google Ads credentials are incomplete.',
            ];
        }

        $accounts = $this->listManagerAccounts(true);
        $errors = $this->errors();
        return [
            'ok' => empty($errors),
            'accounts' => $accounts,
            'message' => empty($errors)
                ? sprintf('Connected successfully. %d client account%s found.', count($accounts), count($accounts) === 1 ? '' : 's')
                : implode(' ', $errors),
        ];
    }

    /**
     * Verify the shared OAuth credentials without querying a client account.
     *
     * @return array{ok: bool, message: string}
     */
    public function authenticationTest(bool $refresh = false): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Google Ads OAuth credentials are incomplete.',
            ];
        }

        if ($refresh) {
            delete_transient($this->accessTokenCacheKey());
        }

        $token = $this->accessToken();
        $errors = $this->errors();
        return [
            'ok' => $token !== '' && $errors === [],
            'message' => $token !== '' && $errors === []
                ? 'Google OAuth authentication succeeded.'
                : (implode(' ', $errors) ?: 'Google OAuth authentication failed.'),
        ];
    }

    public function accountPerformance(string $customer_id, bool $refresh = false): array
    {
        $today = current_datetime()->setTime(0, 0);
        return $this->accountPerformanceForRange(
            $customer_id,
            $today->modify('-30 days')->format('Y-m-d'),
            $today->format('Y-m-d'),
            $refresh
        );
    }

    public function accountPerformanceForRange(string $customer_id, string $start_date, string $end_date, bool $refresh = false, bool $include_details = true): array
    {
        $customer_id = $this->customerId($customer_id);
        if ($customer_id === '') {
            return $this->emptyPerformance();
        }

        $start_date = $this->validDate($start_date);
        $end_date = $this->validDate($end_date);
        if ($start_date === '' || $end_date === '' || $start_date > $end_date) {
            $this->errors[] = 'Google Ads reporting dates are invalid.';
            return $this->emptyPerformance();
        }

        $date_where = $this->reportingDateWhere($start_date, $end_date);

        $cache_key = 'wnq_google_ads_report_range_' . md5($customer_id . '|' . $start_date . '|' . $end_date . '|' . (int)$include_details);
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $query = "SELECT campaign.id, campaign.name, campaign.status, metrics.clicks, metrics.impressions, metrics.ctr, metrics.average_cpc, metrics.cost_micros, metrics.conversions FROM campaign WHERE " . $date_where . " ORDER BY metrics.cost_micros DESC LIMIT 100";
        $error_count = count($this->errors);
        $rows = $this->search($customer_id, $query);
        $summary = [
            'spend' => 0.0,
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0.0,
            'conversions' => 0.0,
            'conversion_rate' => 0.0,
            'cost_per_conversion' => 0.0,
        ];
        $campaigns = [];
        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? [];
            $metrics = $row['metrics'] ?? [];
            $spend = ((float)($metrics['costMicros'] ?? 0)) / 1000000;
            $clicks = (int)($metrics['clicks'] ?? 0);
            $impressions = (int)($metrics['impressions'] ?? 0);
            $conversions = (float)($metrics['conversions'] ?? 0);
            $summary['spend'] += $spend;
            $summary['clicks'] += $clicks;
            $summary['impressions'] += $impressions;
            $summary['conversions'] += $conversions;
            $campaigns[] = [
                'id' => sanitize_text_field((string)($campaign['id'] ?? '')),
                'name' => sanitize_text_field((string)($campaign['name'] ?? 'Campaign')),
                'status' => strtolower(sanitize_text_field((string)($campaign['status'] ?? 'unknown'))),
                'spend' => $spend,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => (float)($metrics['ctr'] ?? 0),
                'average_cpc' => ((float)($metrics['averageCpc'] ?? 0)) / 1000000,
                'conversions' => $conversions,
            ];
        }

        $summary['ctr'] = $summary['impressions'] > 0 ? $summary['clicks'] / $summary['impressions'] : 0;
        $summary['conversion_rate'] = $summary['clicks'] > 0 ? $summary['conversions'] / $summary['clicks'] : 0;
        $summary['cost_per_conversion'] = $summary['conversions'] > 0 ? $summary['spend'] / $summary['conversions'] : 0;

        $detail_reports = $include_details && count($this->errors) === $error_count
            ? [
                'search_terms' => $this->searchTerms($customer_id, $date_where),
                'keywords' => $this->keywords($customer_id, $date_where),
                'landing_pages' => $this->landingPages($customer_id, $date_where),
                'devices' => $this->devices($customer_id, $date_where),
            ]
            : [
                'search_terms' => [],
                'keywords' => [],
                'landing_pages' => [],
                'devices' => [],
            ];

        $result = [
            'summary' => $summary,
            'campaigns' => $campaigns,
        ] + $detail_reports;
        if (!$this->errors()) {
            set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        }
        return $result;
    }

    public function recentSpend(string $customer_id, bool $refresh = false): float
    {
        $customer_id = $this->customerId($customer_id);
        if ($customer_id === '') {
            return 0.0;
        }

        $cache_key = 'wnq_google_ads_spend_31d_' . current_time('Ymd') . '_' . md5($customer_id);
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if (is_numeric($cached)) {
                return (float)$cached;
            }
        }

        $rows = $this->search($customer_id, "SELECT metrics.cost_micros FROM customer WHERE " . $this->reportingDateWhere());
        $spend = 0.0;
        foreach ($rows as $row) {
            $spend += ((float)($row['metrics']['costMicros'] ?? 0)) / 1000000;
        }
        if (!$this->errors()) {
            set_transient($cache_key, $spend, 15 * MINUTE_IN_SECONDS);
        }
        return $spend;
    }

    public function billingSummary(string $customer_id, bool $refresh = false): array
    {
        $customer_id = $this->customerId($customer_id);
        $empty = [
            'available' => false,
            'status' => '',
            'payments_account_id' => '',
            'payments_account_name' => '',
            'payments_profile_id' => '',
            'payments_profile_name' => '',
        ];
        if ($customer_id === '') {
            return $empty;
        }

        $cache_key = 'wnq_google_ads_billing_' . md5($customer_id);
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Billing activity such as card details and individual payments is not
        // exposed by the Ads API. This query returns only the account setup.
        $existing_errors = $this->errors;
        $rows = $this->search($customer_id, "SELECT billing_setup.status, billing_setup.payments_account_info.payments_account_id, billing_setup.payments_account_info.payments_account_name, billing_setup.payments_account_info.payments_profile_id, billing_setup.payments_account_info.payments_profile_name, billing_setup.start_date_time, billing_setup.end_date_time FROM billing_setup ORDER BY billing_setup.start_date_time DESC LIMIT 1");
        if (count($this->errors) > count($existing_errors)) {
            $this->errors = $existing_errors;
            return $empty;
        }

        $setup = $rows[0]['billingSetup'] ?? [];
        $info = is_array($setup['paymentsAccountInfo'] ?? null) ? $setup['paymentsAccountInfo'] : [];
        $result = [
            'available' => !empty($setup),
            'status' => strtolower(sanitize_key((string)($setup['status'] ?? ''))),
            'payments_account_id' => sanitize_text_field((string)($info['paymentsAccountId'] ?? '')),
            'payments_account_name' => sanitize_text_field((string)($info['paymentsAccountName'] ?? '')),
            'payments_profile_id' => sanitize_text_field((string)($info['paymentsProfileId'] ?? '')),
            'payments_profile_name' => sanitize_text_field((string)($info['paymentsProfileName'] ?? '')),
        ];
        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        return $result;
    }

    private function searchTerms(string $customer_id, string $date_where): array
    {
        $rows = $this->search($customer_id, "SELECT search_term_view.search_term, campaign.name, ad_group.name, metrics.clicks, metrics.impressions, metrics.ctr, metrics.conversions FROM search_term_view WHERE " . $date_where . " ORDER BY metrics.clicks DESC LIMIT 100");
        return array_map(static function (array $row): array {
            $metrics = $row['metrics'] ?? [];
            return [
                'term' => sanitize_text_field((string)($row['searchTermView']['searchTerm'] ?? '')),
                'campaign' => sanitize_text_field((string)($row['campaign']['name'] ?? '')),
                'ad_group' => sanitize_text_field((string)($row['adGroup']['name'] ?? '')),
                'clicks' => (int)($metrics['clicks'] ?? 0),
                'impressions' => (int)($metrics['impressions'] ?? 0),
                'ctr' => (float)($metrics['ctr'] ?? 0),
                'conversions' => (float)($metrics['conversions'] ?? 0),
            ];
        }, $rows);
    }

    private function keywords(string $customer_id, string $date_where): array
    {
        $rows = $this->search($customer_id, "SELECT ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status, campaign.name, ad_group.name, metrics.clicks, metrics.impressions, metrics.ctr, metrics.conversions FROM keyword_view WHERE " . $date_where . " ORDER BY metrics.clicks DESC LIMIT 100");
        return array_map(static function (array $row): array {
            $criterion = $row['adGroupCriterion'] ?? [];
            $metrics = $row['metrics'] ?? [];
            return [
                'keyword' => sanitize_text_field((string)($criterion['keyword']['text'] ?? '')),
                'match_type' => strtolower(sanitize_key((string)($criterion['keyword']['matchType'] ?? ''))),
                'status' => strtolower(sanitize_key((string)($criterion['status'] ?? ''))),
                'campaign' => sanitize_text_field((string)($row['campaign']['name'] ?? '')),
                'ad_group' => sanitize_text_field((string)($row['adGroup']['name'] ?? '')),
                'clicks' => (int)($metrics['clicks'] ?? 0),
                'impressions' => (int)($metrics['impressions'] ?? 0),
                'ctr' => (float)($metrics['ctr'] ?? 0),
                'conversions' => (float)($metrics['conversions'] ?? 0),
            ];
        }, $rows);
    }

    private function landingPages(string $customer_id, string $date_where): array
    {
        $rows = $this->search($customer_id, "SELECT landing_page_view.unexpanded_final_url, metrics.clicks, metrics.impressions, metrics.ctr, metrics.conversions FROM landing_page_view WHERE " . $date_where . " ORDER BY metrics.clicks DESC LIMIT 100");
        return array_map(static function (array $row): array {
            $metrics = $row['metrics'] ?? [];
            return [
                'url' => esc_url_raw((string)($row['landingPageView']['unexpandedFinalUrl'] ?? '')),
                'clicks' => (int)($metrics['clicks'] ?? 0),
                'impressions' => (int)($metrics['impressions'] ?? 0),
                'ctr' => (float)($metrics['ctr'] ?? 0),
                'conversions' => (float)($metrics['conversions'] ?? 0),
            ];
        }, $rows);
    }

    private function devices(string $customer_id, string $date_where): array
    {
        $rows = $this->search($customer_id, "SELECT segments.device, metrics.clicks, metrics.impressions, metrics.conversions FROM customer WHERE " . $date_where . " ORDER BY metrics.clicks DESC");
        return array_map(static function (array $row): array {
            $metrics = $row['metrics'] ?? [];
            return [
                'device' => strtolower(sanitize_key((string)($row['segments']['device'] ?? 'unknown'))),
                'clicks' => (int)($metrics['clicks'] ?? 0),
                'impressions' => (int)($metrics['impressions'] ?? 0),
                'conversions' => (float)($metrics['conversions'] ?? 0),
            ];
        }, $rows);
    }

    private function reportingDateWhere(string $start_date = '', string $end_date = ''): string
    {
        if ($start_date === '' || $end_date === '') {
            $today = current_datetime()->setTime(0, 0);
            $start_date = $today->modify('-30 days')->format('Y-m-d');
            $end_date = $today->format('Y-m-d');
        }
        return "segments.date BETWEEN '" . $start_date . "' AND '" . $end_date . "'";
    }

    private function validDate(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function search(string $customer_id, string $query): array
    {
        $token = $this->accessToken();
        if ($token === '') {
            return [];
        }

        $url = self::ADS_BASE_URL . self::API_VERSION . '/customers/' . rawurlencode($this->customerId($customer_id)) . '/googleAds:search';
        $response = wp_remote_post($url, [
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'developer-token' => $this->setting('developer_token'),
                'login-customer-id' => $this->customerId($this->setting('manager_customer_id')),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['query' => $query]),
        ]);

        if (is_wp_error($response)) {
            $this->errors[] = $response->get_error_message();
            return [];
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $this->errors[] = self::apiErrorMessage($body, 'Google Ads API request failed.');
            return [];
        }

        return is_array($body['results'] ?? null) ? $body['results'] : [];
    }

    private function accessToken(): string
    {
        $cache_key = $this->accessTokenCacheKey();
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->isConfigured()) {
            $this->errors[] = 'Google Ads OAuth credentials are incomplete.';
            return '';
        }

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'client_id' => $this->setting('oauth_client_id'),
                'client_secret' => $this->setting('oauth_client_secret'),
                'refresh_token' => $this->setting('refresh_token'),
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->errors[] = 'Google OAuth could not be reached: ' . sanitize_text_field($response->get_error_message());
            return '';
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        $token = (string)($body['access_token'] ?? '');
        if ($status < 200 || $status >= 300 || $token === '') {
            $this->errors[] = self::apiErrorMessage($body, 'Google OAuth token request failed.');
            return '';
        }

        set_transient($cache_key, $token, max(60, (int)($body['expires_in'] ?? 3600) - 120));
        return $token;
    }

    private function accessTokenCacheKey(): string
    {
        return 'wnq_google_ads_access_token_' . md5(implode('|', [
            $this->setting('oauth_client_id'),
            $this->setting('oauth_client_secret'),
            $this->setting('refresh_token'),
        ]));
    }

    private function similarityScore(string $left, string $right): int
    {
        $left_normal = $this->normalizeName($left);
        $right_normal = $this->normalizeName($right);
        if ($left_normal === '' || $right_normal === '') {
            return 0;
        }
        if ($left_normal === $right_normal) {
            return 100;
        }
        if (str_contains($left_normal, $right_normal) || str_contains($right_normal, $left_normal)) {
            return 92;
        }

        similar_text($left_normal, $right_normal, $percent);
        $left_tokens = array_filter(explode(' ', $left_normal));
        $right_tokens = array_filter(explode(' ', $right_normal));
        $overlap = count(array_intersect($left_tokens, $right_tokens));
        $token_score = $overlap > 0 ? min(90, (int)round(($overlap / max(1, min(count($left_tokens), count($right_tokens)))) * 90)) : 0;
        return max((int)round($percent), $token_score);
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(remove_accents($name));
        $name = preg_replace('/\b(llc|inc|co|company|the|and|&|corp|corporation|ltd|limited)\b/i', ' ', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        return trim((string)preg_replace('/\s+/', ' ', (string)$name));
    }

    private function setting(string $key): string
    {
        return trim((string)($this->settings[$key] ?? ''));
    }

    private function customerId(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private static function apiErrorMessage($body, string $fallback): string
    {
        if (is_array($body)) {
            $error = $body['error'] ?? null;
            $error_code = is_string($error)
                ? sanitize_key($error)
                : sanitize_key((string)(is_array($error) ? ($error['status'] ?? '') : ''));
            $description = sanitize_text_field((string)($body['error_description'] ?? ''));

            if ($error_code === 'invalid_grant') {
                return 'Google OAuth refresh token expired or was revoked. Generate and save a new refresh token using the Google account that can access the Ads manager account.';
            }
            if ($error_code === 'invalid_client') {
                return 'Google rejected the OAuth client ID or client secret. Confirm both values belong to the same active Web application OAuth client, then save them again.';
            }
            if ($error_code === 'unauthorized_client') {
                return 'This OAuth client is not authorized for the requested Google access. Review the OAuth consent screen and generate a new refresh token.';
            }
            if ($description !== '') {
                return $description;
            }
            if (is_array($error) && !empty($error['message'])) {
                return sanitize_text_field((string)$error['message']);
            }
            if (!empty($body['message'])) {
                return sanitize_text_field((string)$body['message']);
            }
            if ($error_code !== '') {
                return 'Google OAuth error: ' . str_replace('_', ' ', $error_code) . '.';
            }
        }
        return $fallback;
    }

    private function emptyPerformance(): array
    {
        return [
            'summary' => [
                'spend' => 0,
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => 0,
                'conversions' => 0,
                'conversion_rate' => 0,
                'cost_per_conversion' => 0,
            ],
            'campaigns' => [],
            'search_terms' => [],
            'keywords' => [],
            'landing_pages' => [],
            'devices' => [],
        ];
    }
}
