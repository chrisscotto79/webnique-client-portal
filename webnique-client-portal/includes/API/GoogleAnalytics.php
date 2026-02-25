<?php
/**
 * Google Analytics API Integration
 * 
 * Connects to GA4 API and fetches analytics data
 * Uses service account authentication
 * 
 * @package WebNique Portal
 */

namespace WNQ\API;

use WNQ\Models\AnalyticsConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleAnalytics
{
    private array $credentials;
    private string $property_id;
    private ?object $client = null;

    /**
     * Constructor
     */
    public function __construct(string $client_id)
    {
        $creds = AnalyticsConfig::getCredentials();
        $config = AnalyticsConfig::getClientConfig($client_id);

        if (!$creds || !$config) {
            throw new \Exception('Analytics not configured for this client');
        }

        $this->credentials = $creds['credentials'];
        $this->property_id = $config['ga4_property_id'];
    }

    /**
     * Get overview stats
     */
    public function getOverviewStats(string $start_date = '30daysAgo', string $end_date = 'today'): array
    {
        $cache_key = "ga_overview_{$this->property_id}_{$start_date}_{$end_date}";
        
        // Check cache first
        $cached = AnalyticsCache::get($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'totalUsers'],
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'bounceRate'],
                    ['name' => 'newUsers'],
                ],
                'dimensions' => []
            ]);

            $result = $this->parseOverviewData($data);
            
            // Get comparison data (previous period)
            $comparison = $this->getComparisonData($start_date, $end_date);
            $result['comparison'] = $comparison;

            // Cache for 1 hour
            AnalyticsCache::set($cache_key, $result, 3600);

            return $result;

        } catch (\Exception $e) {
            error_log('GA Overview Error: ' . $e->getMessage());
            return $this->getEmptyOverview();
        }
    }

    /**
     * Get traffic sources breakdown
     */
    public function getTrafficSources(string $start_date = '30daysAgo', string $end_date = 'today'): array
    {
        $cache_key = "ga_sources_{$this->property_id}_{$start_date}_{$end_date}";
        
        $cached = AnalyticsCache::get($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                ],
                'dimensions' => [
                    ['name' => 'sessionDefaultChannelGroup']
                ],
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true]
                ],
                'limit' => 10
            ]);

            $result = $this->parseTrafficSourcesData($data);
            AnalyticsCache::set($cache_key, $result, 3600);

            return $result;

        } catch (\Exception $e) {
            error_log('GA Traffic Sources Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get page analytics
     */
    public function getTopPages(string $start_date = '30daysAgo', string $end_date = 'today', int $limit = 10): array
    {
        $cache_key = "ga_pages_{$this->property_id}_{$start_date}_{$end_date}_{$limit}";
        
        $cached = AnalyticsCache::get($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'screenPageViews'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'bounceRate'],
                ],
                'dimensions' => [
                    ['name' => 'pagePath'],
                    ['name' => 'pageTitle']
                ],
                'orderBys' => [
                    ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]
                ],
                'limit' => $limit
            ]);

            $result = $this->parseTopPagesData($data);
            AnalyticsCache::set($cache_key, $result, 3600);

            return $result;

        } catch (\Exception $e) {
            error_log('GA Top Pages Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get visitor trends over time
     */
    public function getVisitorTrends(string $start_date = '30daysAgo', string $end_date = 'today'): array
    {
        $cache_key = "ga_trends_{$this->property_id}_{$start_date}_{$end_date}";
        
        $cached = AnalyticsCache::get($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'totalUsers'],
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                ],
                'dimensions' => [
                    ['name' => 'date']
                ],
                'orderBys' => [
                    ['dimension' => ['dimensionName' => 'date']]
                ]
            ]);

            $result = $this->parseVisitorTrendsData($data);
            AnalyticsCache::set($cache_key, $result, 3600);

            return $result;

        } catch (\Exception $e) {
            error_log('GA Visitor Trends Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get device breakdown
     */
    public function getDeviceStats(string $start_date = '30daysAgo', string $end_date = 'today'): array
    {
        $cache_key = "ga_devices_{$this->property_id}_{$start_date}_{$end_date}";
        
        $cached = AnalyticsCache::get($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                ],
                'dimensions' => [
                    ['name' => 'deviceCategory']
                ]
            ]);

            $result = $this->parseDeviceData($data);
            AnalyticsCache::set($cache_key, $result, 3600);

            return $result;

        } catch (\Exception $e) {
            error_log('GA Device Stats Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get event data (phone clicks, form submissions)
     */
    public function getEventData(string $event_name, string $start_date = '30daysAgo', string $end_date = 'today'): array
    {
        $cache_key = "ga_event_{$event_name}_{$this->property_id}_{$start_date}_{$end_date}";
        
        $cached = AnalyticsCache::get($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'eventCount'],
                ],
                'dimensions' => [
                    ['name' => 'eventName']
                ],
                'dimensionFilter' => [
                    'filter' => [
                        'fieldName' => 'eventName',
                        'stringFilter' => [
                            'value' => $event_name
                        ]
                    ]
                ]
            ]);

            $result = $this->parseEventData($data);
            AnalyticsCache::set($cache_key, $result, 3600);

            return $result;

        } catch (\Exception $e) {
            error_log('GA Event Data Error: ' . $e->getMessage());
            return ['count' => 0, 'trend' => 0];
        }
    }

    /**
     * Make API request to GA4
     */
    private function makeRequest(array $request_body): array
    {
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";
        
        $access_token = $this->getAccessToken();
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($request_body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || isset($data['error'])) {
            $error_msg = $data['error']['message'] ?? 'Unknown error';
            throw new \Exception('API error: ' . $error_msg);
        }

        return $data;
    }

    /**
     * Get access token using service account
     */
    private function getAccessToken(): string
    {
        // Check transient cache
        $cached_token = get_transient('wnq_ga_access_token');
        if ($cached_token) {
            return $cached_token;
        }

        // Create JWT
        $now = time();
        $jwt_header = base64_encode(wp_json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT'
        ]));

        $jwt_claim = base64_encode(wp_json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]));

        $signature_input = $jwt_header . '.' . $jwt_claim;
        
        // Sign with private key
        openssl_sign(
            $signature_input,
            $signature,
            $this->credentials['private_key'],
            'SHA256'
        );

        $jwt = $signature_input . '.' . base64_encode($signature);

        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Token request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['access_token'])) {
            throw new \Exception('Failed to get access token');
        }

        // Cache token for 50 minutes (expires in 60)
        set_transient('wnq_ga_access_token', $body['access_token'], 3000);

        return $body['access_token'];
    }

    /**
     * Parse overview data
     */
    private function parseOverviewData(array $data): array
    {
        if (!isset($data['rows'][0])) {
            return $this->getEmptyOverview();
        }

        $row = $data['rows'][0];
        $metrics = $row['metricValues'];

        return [
            'total_users' => intval($metrics[0]['value'] ?? 0),
            'sessions' => intval($metrics[1]['value'] ?? 0),
            'page_views' => intval($metrics[2]['value'] ?? 0),
            'avg_session_duration' => floatval($metrics[3]['value'] ?? 0),
            'bounce_rate' => floatval($metrics[4]['value'] ?? 0) * 100,
            'new_users' => intval($metrics[5]['value'] ?? 0),
        ];
    }

    /**
     * Parse traffic sources data
     */
    private function parseTrafficSourcesData(array $data): array
    {
        if (!isset($data['rows'])) {
            return [];
        }

        $sources = [];
        $total_sessions = 0;

        foreach ($data['rows'] as $row) {
            $sessions = intval($row['metricValues'][0]['value']);
            $total_sessions += $sessions;
        }

        foreach ($data['rows'] as $row) {
            $channel = $row['dimensionValues'][0]['value'];
            $sessions = intval($row['metricValues'][0]['value']);
            $users = intval($row['metricValues'][1]['value']);

            $sources[] = [
                'channel' => $this->formatChannelName($channel),
                'sessions' => $sessions,
                'users' => $users,
                'percentage' => $total_sessions > 0 ? round(($sessions / $total_sessions) * 100, 1) : 0
            ];
        }

        return $sources;
    }

    /**
     * Parse top pages data
     */
    private function parseTopPagesData(array $data): array
    {
        if (!isset($data['rows'])) {
            return [];
        }

        $pages = [];

        foreach ($data['rows'] as $row) {
            $pages[] = [
                'path' => $row['dimensionValues'][0]['value'],
                'title' => $row['dimensionValues'][1]['value'] ?? 'Untitled',
                'views' => intval($row['metricValues'][0]['value']),
                'avg_time' => floatval($row['metricValues'][1]['value'] ?? 0),
                'bounce_rate' => floatval($row['metricValues'][2]['value'] ?? 0) * 100,
            ];
        }

        return $pages;
    }

    /**
     * Parse visitor trends data
     */
    private function parseVisitorTrendsData(array $data): array
    {
        if (!isset($data['rows'])) {
            return [];
        }

        $trends = [];

        foreach ($data['rows'] as $row) {
            $date = $row['dimensionValues'][0]['value'];
            $formatted_date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);

            $trends[] = [
                'date' => $formatted_date,
                'users' => intval($row['metricValues'][0]['value']),
                'sessions' => intval($row['metricValues'][1]['value']),
                'page_views' => intval($row['metricValues'][2]['value']),
            ];
        }

        return $trends;
    }

    /**
     * Parse device data
     */
    private function parseDeviceData(array $data): array
    {
        if (!isset($data['rows'])) {
            return [];
        }

        $devices = [];
        $total_sessions = 0;

        foreach ($data['rows'] as $row) {
            $total_sessions += intval($row['metricValues'][0]['value']);
        }

        foreach ($data['rows'] as $row) {
            $device = $row['dimensionValues'][0]['value'];
            $sessions = intval($row['metricValues'][0]['value']);

            $devices[] = [
                'device' => ucfirst($device),
                'sessions' => $sessions,
                'percentage' => $total_sessions > 0 ? round(($sessions / $total_sessions) * 100, 1) : 0
            ];
        }

        return $devices;
    }

    /**
     * Parse event data
     */
    private function parseEventData(array $data): array
    {
        if (!isset($data['rows'][0])) {
            return ['count' => 0, 'trend' => 0];
        }

        $count = intval($data['rows'][0]['metricValues'][0]['value'] ?? 0);

        return [
            'count' => $count,
            'trend' => 0 // Calculate trend in comparison method
        ];
    }

    /**
     * Get comparison data for previous period
     */
    private function getComparisonData(string $start_date, string $end_date): array
    {
        // Calculate previous period dates
        // For simplicity, we'll compare to previous 30 days
        $prev_end = date('Y-m-d', strtotime($start_date) - 86400);
        $prev_start = date('Y-m-d', strtotime($prev_end) - (strtotime($end_date) - strtotime($start_date)));

        try {
            $prev_data = $this->getOverviewStats($prev_start, $prev_end);
            return $prev_data;
        } catch (\Exception $e) {
            return $this->getEmptyOverview();
        }
    }

    /**
     * Format channel name for display
     */
    private function formatChannelName(string $channel): string
    {
        $map = [
            'Organic Search' => 'Organic Search',
            'Direct' => 'Direct',
            'Referral' => 'Referral',
            'Social' => 'Social Media',
            'Paid Search' => 'Paid Ads',
            'Display' => 'Display Ads',
            'Email' => 'Email',
            'Organic Social' => 'Social Media',
        ];

        return $map[$channel] ?? $channel;
    }

    /**
     * Get empty overview structure
     */
    private function getEmptyOverview(): array
    {
        return [
            'total_users' => 0,
            'sessions' => 0,
            'page_views' => 0,
            'avg_session_duration' => 0,
            'bounce_rate' => 0,
            'new_users' => 0,
            'comparison' => []
        ];
    }
}