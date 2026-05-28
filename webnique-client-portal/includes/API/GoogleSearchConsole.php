<?php
/**
 * Google Search Console API Integration
 * 
 * Fetches keyword rankings, clicks, impressions, CTR
 * Position tracking and page performance in search
 * 
 * @package Golden Web Marketing Portal
 */

namespace WNQ\API;

use WNQ\Models\AnalyticsConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleSearchConsole
{
    private array $credentials;
    private string $configured_site_url;
    private string $site_url;
    private bool $site_url_resolved = false;
    private array $errors = [];

    /**
     * Initialize with credentials
     */
    public function __construct(string $client_id)
    {
        $config_data = AnalyticsConfig::getCredentials();
        $client_config = AnalyticsConfig::getClientConfig($client_id);

        if (!$config_data || !$client_config) {
            throw new \Exception('Search Console not configured for this client');
        }

        $this->credentials = $config_data['credentials'];
        $this->configured_site_url = self::normalizePropertyInput((string)($client_config['search_console_url'] ?? ''));
        $this->site_url = $this->configured_site_url;
    }

    /**
     * Get overview statistics
     */
    public function getOverviewStats(string $start_date = null, string $end_date = null): array
    {
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = date('Y-m-d', strtotime('-3 days')); // GSC has 2-3 day delay
        }

        $cache_key = "gsc_overview_{$this->configured_site_url}_{$start_date}_{$end_date}";
        
        $cached = $this->getFromCache($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->query($start_date, $end_date);

            // Get previous period for comparison
            $prev_data = $this->getPreviousPeriodData($start_date, $end_date);

            $result = [
                'clicks' => [
                    'value' => $data['clicks'] ?? 0,
                    'previous' => $prev_data['clicks'] ?? 0,
                    'change' => $this->calculateChange($data['clicks'] ?? 0, $prev_data['clicks'] ?? 0),
                ],
                'impressions' => [
                    'value' => $data['impressions'] ?? 0,
                    'previous' => $prev_data['impressions'] ?? 0,
                    'change' => $this->calculateChange($data['impressions'] ?? 0, $prev_data['impressions'] ?? 0),
                ],
                'ctr' => [
                    'value' => round(($data['ctr'] ?? 0) * 100, 2),
                    'previous' => round(($prev_data['ctr'] ?? 0) * 100, 2),
                    'change' => $this->calculateChange($data['ctr'] ?? 0, $prev_data['ctr'] ?? 0),
                ],
                'position' => [
                    'value' => round($data['position'] ?? 0, 1),
                    'previous' => round($prev_data['position'] ?? 0, 1),
                    'change' => $this->calculatePositionChange($data['position'] ?? 0, $prev_data['position'] ?? 0),
                ],
                'period' => [
                    'start' => $start_date,
                    'end' => $end_date,
                ],
            ];

            $this->saveToCache($cache_key, $result);
            return $result;

        } catch (\Exception $e) {
            $this->recordError('overview', $e->getMessage());
            error_log('GSC Overview Error: ' . $e->getMessage());
            return $this->getEmptyOverviewStats();
        }
    }

    /**
     * Get keyword rankings
     */
    public function getKeywordRankings(
        string $start_date = null,
        string $end_date = null,
        int $limit = 20
    ): array {
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = date('Y-m-d', strtotime('-3 days'));
        }

        $cache_key = "gsc_keywords_{$this->configured_site_url}_{$start_date}_{$end_date}_{$limit}";
        
        $cached = $this->getFromCache($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->query($start_date, $end_date, ['query'], $limit);

            $result = [];

            if (isset($data['rows'])) {
                foreach ($data['rows'] as $row) {
                    $keyword = $row['keys'][0] ?? '';
                    
                    $result[] = [
                        'keyword' => $keyword,
                        'position' => round($row['position'] ?? 0, 1),
                        'clicks' => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                    ];
                }
            }

            // Sort by clicks descending
            usort($result, function($a, $b) {
                return $b['clicks'] - $a['clicks'];
            });

            $this->saveToCache($cache_key, $result);
            return $result;

        } catch (\Exception $e) {
            $this->recordError('keywords', $e->getMessage());
            error_log('GSC Keywords Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get top pages in search results
     */
    public function getTopPages(
        string $start_date = null,
        string $end_date = null,
        int $limit = 10
    ): array {
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = date('Y-m-d', strtotime('-3 days'));
        }

        $cache_key = "gsc_pages_{$this->configured_site_url}_{$start_date}_{$end_date}_{$limit}";
        
        $cached = $this->getFromCache($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->query($start_date, $end_date, ['page'], $limit);

            $result = [];

            if (isset($data['rows'])) {
                foreach ($data['rows'] as $row) {
                    $page = $row['keys'][0] ?? '';
                    
                    $result[] = [
                        'page' => $page,
                        'position' => round($row['position'] ?? 0, 1),
                        'clicks' => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                    ];
                }
            }

            // Sort by clicks descending
            usort($result, function($a, $b) {
                return $b['clicks'] - $a['clicks'];
            });

            $this->saveToCache($cache_key, $result);
            return $result;

        } catch (\Exception $e) {
            $this->recordError('pages', $e->getMessage());
            error_log('GSC Pages Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get performance over time
     */
    public function getPerformanceOverTime(
        string $start_date = null,
        string $end_date = null
    ): array {
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = date('Y-m-d', strtotime('-3 days'));
        }

        $cache_key = "gsc_performance_time_{$this->configured_site_url}_{$start_date}_{$end_date}";
        
        $cached = $this->getFromCache($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->query($start_date, $end_date, ['date']);

            $result = [];

            if (isset($data['rows'])) {
                foreach ($data['rows'] as $row) {
                    $date = $row['keys'][0] ?? '';
                    
                    $result[] = [
                        'date' => $date,
                        'clicks' => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                        'position' => round($row['position'] ?? 0, 1),
                    ];
                }
            }

            // Sort by date
            usort($result, function($a, $b) {
                return strcmp($a['date'], $b['date']);
            });

            $this->saveToCache($cache_key, $result);
            return $result;

        } catch (\Exception $e) {
            $this->recordError('performance_over_time', $e->getMessage());
            error_log('GSC Performance Over Time Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get device breakdown
     */
    public function getDeviceBreakdown(
        string $start_date = null,
        string $end_date = null
    ): array {
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = date('Y-m-d', strtotime('-3 days'));
        }

        $cache_key = "gsc_devices_{$this->configured_site_url}_{$start_date}_{$end_date}";
        
        $cached = $this->getFromCache($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->query($start_date, $end_date, ['device']);

            $result = [];

            if (isset($data['rows'])) {
                foreach ($data['rows'] as $row) {
                    $device = $row['keys'][0] ?? '';
                    
                    $result[] = [
                        'device' => ucfirst($device),
                        'clicks' => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                        'position' => round($row['position'] ?? 0, 1),
                    ];
                }
            }

            $this->saveToCache($cache_key, $result);
            return $result;

        } catch (\Exception $e) {
            $this->recordError('devices', $e->getMessage());
            error_log('GSC Device Breakdown Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get country breakdown
     */
    public function getCountryBreakdown(
        string $start_date = null,
        string $end_date = null,
        int $limit = 10
    ): array {
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = date('Y-m-d', strtotime('-3 days'));
        }

        $cache_key = "gsc_countries_{$this->configured_site_url}_{$start_date}_{$end_date}_{$limit}";
        
        $cached = $this->getFromCache($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->query($start_date, $end_date, ['country'], $limit);

            $result = [];

            if (isset($data['rows'])) {
                foreach ($data['rows'] as $row) {
                    $country = $row['keys'][0] ?? '';
                    
                    $result[] = [
                        'country' => $this->getCountryName($country),
                        'country_code' => $country,
                        'clicks' => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                        'position' => round($row['position'] ?? 0, 1),
                    ];
                }
            }

            // Sort by clicks
            usort($result, function($a, $b) {
                return $b['clicks'] - $a['clicks'];
            });

            $this->saveToCache($cache_key, $result);
            return $result;

        } catch (\Exception $e) {
            $this->recordError('countries', $e->getMessage());
            error_log('GSC Country Breakdown Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Make Search Console API query
     */
    private function query(
        string $start_date,
        string $end_date,
        array $dimensions = [],
        int $row_limit = 1000
    ): array {
        $site_url = $this->getResolvedSiteUrl();
        $url = "https://www.googleapis.com/webmasters/v3/sites/" . urlencode($site_url) . "/searchAnalytics/query";

        $body = [
            'startDate' => $start_date,
            'endDate' => $end_date,
            'rowLimit' => $row_limit,
        ];

        if (!empty($dimensions)) {
            $body['dimensions'] = $dimensions;
        }

        return $this->makeApiRequest($url, $body);
    }

    /**
     * Resolve saved Search Console config to a property the service account can access.
     *
     * Search Console treats domain properties (sc-domain:example.com) and URL-prefix
     * properties (https://example.com/) as different siteUrl values. Client setup often
     * grants the service account to one but saves the other, so we list accessible
     * properties and fall back to the matching property for the same domain.
     */
    private function getResolvedSiteUrl(): string
    {
        if ($this->site_url_resolved) {
            return $this->site_url;
        }

        if ($this->configured_site_url === '') {
            throw new \Exception('Search Console property is empty for this client.');
        }

        $sites = $this->listAccessibleSites();
        if (empty($sites)) {
            throw new \Exception(
                'The Google service account does not have access to any Search Console properties. ' .
                'Add ' . ($this->credentials['client_email'] ?? 'the service account') . ' as a Full user in Search Console.'
            );
        }

        $match = self::findMatchingSiteUrl($this->configured_site_url, $sites);
        if ($match === '') {
            $domain = self::propertyDomain($this->configured_site_url);
            $same_domain = array_values(array_filter(array_map(
                static fn($site) => (string)($site['siteUrl'] ?? ''),
                $sites
            ), static fn($site_url) => self::propertyDomain($site_url) === $domain));
            $visible = !empty($same_domain)
                ? implode(', ', array_slice($same_domain, 0, 5))
                : implode(', ', array_slice(array_map(static fn($site) => (string)($site['siteUrl'] ?? ''), $sites), 0, 5));

            throw new \Exception(
                "Configured Search Console property '{$this->configured_site_url}' is not accessible to the service account. " .
                ($visible !== '' ? "Accessible properties include: {$visible}." : 'No accessible properties were returned.')
            );
        }

        $this->site_url = $match;
        $this->site_url_resolved = true;

        return $this->site_url;
    }

    private function listAccessibleSites(): array
    {
        $cache_key = 'gsc_sites_' . md5((string)($this->credentials['client_email'] ?? ''));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $data = $this->makeGetRequest('https://www.googleapis.com/webmasters/v3/sites');
        $sites = is_array($data['siteEntry'] ?? null) ? $data['siteEntry'] : [];
        set_transient($cache_key, $sites, 900);

        return $sites;
    }

    private static function findMatchingSiteUrl(string $configured, array $sites): string
    {
        $configured = self::normalizePropertyInput($configured);
        $configured_domain = self::propertyDomain($configured);
        $matches = [];

        foreach ($sites as $site) {
            $site_url = self::normalizePropertyInput((string)($site['siteUrl'] ?? ''));
            if ($site_url === '') {
                continue;
            }

            if ($site_url === $configured) {
                return $site_url;
            }

            if ($configured_domain !== '' && self::propertyDomain($site_url) === $configured_domain) {
                $matches[] = [
                    'site_url' => $site_url,
                    'score' => self::propertyMatchScore($configured, $site_url),
                ];
            }
        }

        if (empty($matches)) {
            return '';
        }

        usort($matches, static fn($a, $b) => $b['score'] <=> $a['score']);
        return (string)$matches[0]['site_url'];
    }

    private static function propertyMatchScore(string $configured, string $site_url): int
    {
        $score = 0;
        $configured_is_domain = str_starts_with($configured, 'sc-domain:');
        $site_is_domain = str_starts_with($site_url, 'sc-domain:');

        if ($configured_is_domain === $site_is_domain) {
            $score += 30;
        }
        if (str_starts_with($site_url, 'https://')) {
            $score += 20;
        }
        if (self::urlPathIsRoot($site_url)) {
            $score += 10;
        }

        return $score;
    }

    private static function normalizePropertyInput(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with(strtolower($value), 'sc-domain:')) {
            return 'sc-domain:' . self::normalizeHost(substr($value, strlen('sc-domain:')));
        }

        if (!preg_match('#^https?://#i', $value) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:\/.*)?$/i', $value)) {
            $value = 'https://' . $value;
        }

        $parts = wp_parse_url($value);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $value;
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = self::normalizeHost((string)$parts['host'], false);
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        if ($path === '') {
            $path = '/';
        }

        return $scheme . '://' . $host . $path . $query;
    }

    private static function propertyDomain(string $site_url): string
    {
        $site_url = self::normalizePropertyInput($site_url);
        if (str_starts_with($site_url, 'sc-domain:')) {
            return self::normalizeHost(substr($site_url, strlen('sc-domain:')));
        }

        $host = (string)(wp_parse_url($site_url, PHP_URL_HOST) ?: '');
        return self::normalizeHost($host);
    }

    private static function normalizeHost(string $host, bool $strip_www = true): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host);
        $host = strtok($host, '/?:#') ?: $host;
        $host = trim($host, " \t\n\r\0\x0B.");

        if ($strip_www && str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    private static function urlPathIsRoot(string $site_url): bool
    {
        if (str_starts_with($site_url, 'sc-domain:')) {
            return true;
        }

        $path = (string)(wp_parse_url($site_url, PHP_URL_PATH) ?: '/');
        return $path === '' || $path === '/';
    }

    /**
     * Make API request with authentication
     */
    private function makeApiRequest(string $url, array $body): array
    {
        $cache_key = 'gsc_access_token_' . md5($this->credentials['client_email']);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $access_token = $this->getAccessToken();

            $response = wp_remote_post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new \Exception('API request failed: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);

            if ($code === 200 && is_array($data) && !isset($data['error'])) {
                return $data;
            }

            if (in_array((int)$code, [401, 403], true) && $attempt === 0) {
                delete_transient($cache_key);
                continue;
            }

            $error_msg = is_array($data) ? ($data['error']['message'] ?? 'Unknown error') : 'Invalid JSON response';
            throw new \Exception('API error: ' . $error_msg);
        }

        throw new \Exception('API request failed after refreshing the access token');
    }

    private function makeGetRequest(string $url): array
    {
        $cache_key = 'gsc_access_token_' . md5($this->credentials['client_email']);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $access_token = $this->getAccessToken();

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new \Exception('API request failed: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);

            if ($code === 200 && is_array($data) && !isset($data['error'])) {
                return $data;
            }

            if (in_array((int)$code, [401, 403], true) && $attempt === 0) {
                delete_transient($cache_key);
                continue;
            }

            $error_msg = is_array($data) ? ($data['error']['message'] ?? 'Unknown error') : 'Invalid JSON response';
            throw new \Exception('API error: ' . $error_msg);
        }

        throw new \Exception('API request failed after refreshing the access token');
    }

    /**
     * Get OAuth access token using service account
     */
    private function getAccessToken(): string
    {
        $cache_key = 'gsc_access_token_' . md5($this->credentials['client_email']);
        $cached = get_transient($cache_key);
        
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Create JWT
        $now = time();
        $jwt_header = self::base64UrlEncode(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        
        $jwt_claim = self::base64UrlEncode(wp_json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]));

        $jwt_signature = '';
        $sign_input = $jwt_header . '.' . $jwt_claim;
        
        if (!openssl_sign($sign_input, $jwt_signature, $this->credentials['private_key'], 'SHA256')) {
            throw new \Exception('JWT signing failed. Check the private_key in your service account JSON.');
        }
        $jwt_signature = self::base64UrlEncode($jwt_signature);

        $jwt = $jwt_header . '.' . $jwt_claim . '.' . $jwt_signature;

        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Token request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['access_token'])) {
            throw new \Exception('Failed to get access token: ' . ($data['error_description'] ?? $data['error'] ?? 'Unknown'));
        }

        // Cache for 50 minutes
        set_transient($cache_key, $data['access_token'], 3000);

        return $data['access_token'];
    }

    /**
     * Get previous period data for comparison
     */
    private function getPreviousPeriodData(string $start_date, string $end_date): array
    {
        $days_diff = floor((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24));
        $prev_end = date('Y-m-d', strtotime($start_date . ' -1 day'));
        $prev_start = date('Y-m-d', strtotime($prev_end . ' -' . $days_diff . ' days'));

        try {
            return $this->query($prev_start, $prev_end);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate percentage change
     */
    private function calculateChange(float $current, float $previous): array
    {
        if ($previous == 0) {
            return ['percentage' => 0, 'direction' => 'neutral'];
        }

        $change = (($current - $previous) / $previous) * 100;

        return [
            'percentage' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
        ];
    }

    /**
     * Calculate position change (lower is better)
     */
    private function calculatePositionChange(float $current, float $previous): array
    {
        if ($previous == 0) {
            return ['difference' => 0, 'direction' => 'neutral'];
        }

        $change = $previous - $current; // Reverse: lower position is better

        return [
            'difference' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
        ];
    }

    /**
     * Get country name from code
     */
    private function getCountryName(string $code): string
    {
        $countries = [
            'usa' => 'United States',
            'gbr' => 'United Kingdom',
            'can' => 'Canada',
            'aus' => 'Australia',
            'deu' => 'Germany',
            'fra' => 'France',
            'esp' => 'Spain',
            'ita' => 'Italy',
            'mex' => 'Mexico',
            'bra' => 'Brazil',
        ];

        return $countries[strtolower($code)] ?? $code;
    }

    /**
     * Get empty overview stats
     */
    private function getEmptyOverviewStats(): array
    {
        return [
            'clicks' => ['value' => 0, 'previous' => 0, 'change' => ['percentage' => 0, 'direction' => 'neutral']],
            'impressions' => ['value' => 0, 'previous' => 0, 'change' => ['percentage' => 0, 'direction' => 'neutral']],
            'ctr' => ['value' => 0, 'previous' => 0, 'change' => ['percentage' => 0, 'direction' => 'neutral']],
            'position' => ['value' => 0, 'previous' => 0, 'change' => ['difference' => 0, 'direction' => 'neutral']],
        ];
    }

    /**
     * Cache methods
     */
    private function getFromCache(string $key)
    {
        return get_transient('wnq_gsc_' . md5($key));
    }

    private function saveToCache(string $key, $data, int $expiration = 3600): void
    {
        set_transient('wnq_gsc_' . md5($key), $data, $expiration);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    private function recordError(string $context, string $message): void
    {
        $this->errors[$context] = $message;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
