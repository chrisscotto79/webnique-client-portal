<?php
/**
 * Google Search Console API Integration
 * 
 * Fetches keyword rankings, clicks, impressions, CTR
 * Position tracking and page performance in search
 * 
 * @package WebNique Portal
 */

namespace WNQ\API;

use WNQ\Models\AnalyticsConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleSearchConsole
{
    private array $credentials;
    private string $site_url;

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
        $this->site_url = $client_config['search_console_url'];
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

        $cache_key = "gsc_overview_{$this->site_url}_{$start_date}_{$end_date}";
        
        $cached = $this->getFromCache($cache_key);
        if ($cached !== false) {
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

        $cache_key = "gsc_keywords_{$this->site_url}_{$start_date}_{$end_date}_{$limit}";
        
        $cached = $this->getFromCache($cache_key);
        if ($cached !== false) {
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

        $cache_key = "gsc_pages_{$this->site_url}_{$start_date}_{$end_date}_{$limit}";
        
        $cached = $this->getFromCache($cache_key);
        if ($cached !== false) {
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

        $cache_key = "gsc_performance_time_{$this->site_url}_{$start_date}_{$end_date}";
        
        $cached = $this->getFromCache($cache_key);
        if ($cached !== false) {
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

        $cache_key = "gsc_devices_{$this->site_url}_{$start_date}_{$end_date}";
        
        $cached = $this->getFromCache($cache_key);
        if ($cached !== false) {
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

        $cache_key = "gsc_countries_{$this->site_url}_{$start_date}_{$end_date}_{$limit}";
        
        $cached = $this->getFromCache($cache_key);
        if ($cached !== false) {
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
        $url = 'https://searchconsole.googleapis.com/v1/urlTestingTools/mobileFriendlyTest:run';
        $url = "https://www.googleapis.com/webmasters/v3/sites/" . urlencode($this->site_url) . "/searchAnalytics/query";

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
     * Make API request with authentication
     */
    private function makeApiRequest(string $url, array $body): array
    {
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

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (isset($data['error'])) {
            throw new \Exception('API error: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    /**
     * Get OAuth access token using service account
     */
    private function getAccessToken(): string
    {
        $cache_key = 'gsc_access_token_' . md5($this->credentials['client_email']);
        $cached = get_transient($cache_key);
        
        if ($cached) {
            return $cached;
        }

        // Create JWT
        $now = time();
        $jwt_header = base64_encode(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        
        $jwt_claim = base64_encode(wp_json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]));

        $jwt_signature = '';
        $sign_input = $jwt_header . '.' . $jwt_claim;
        
        openssl_sign($sign_input, $jwt_signature, $this->credentials['private_key'], 'SHA256');
        $jwt_signature = base64_encode($jwt_signature);

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
            throw new \Exception('Failed to get access token');
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
}