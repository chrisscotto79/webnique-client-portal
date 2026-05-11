<?php
/**
 * Google Analytics API Integration
 * 
 * Connects to GA4 API and fetches analytics data
 * Uses service account authentication
 * 
 * @package Golden Web Marketing Portal
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
    private array $errors = [];
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
    public function getOverviewStats(string $start_date = '30daysAgo', string $end_date = 'today', bool $include_comparison = true): array
    {
        $comparison_key = $include_comparison ? 'with_comparison' : 'base';
        $cache_key = "ga_overview_{$this->property_id}_{$start_date}_{$end_date}_{$comparison_key}";
        
        // Check cache first
        $cached = AnalyticsCache::get($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'totalUsers'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'sessions'],
                    ['name' => 'bounceRate'],
                ],
                'dimensions' => []
            ]);

            $result = $this->parseOverviewData($data);
            
            if ($include_comparison) {
                // Get comparison data (previous period)
                $comparison = $this->getComparisonData($start_date, $end_date);
                $result['comparison'] = $comparison;
            }

            // Cache for 1 hour
            AnalyticsCache::set($cache_key, $result, 3600);

            return $result;

        } catch (\Throwable $e) {
            $this->recordError('overview', $e->getMessage());
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
        if (is_array($cached)) {
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

        } catch (\Throwable $e) {
            $this->recordError('traffic_sources', $e->getMessage());
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
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date]
                ],
                'metrics' => [
                    ['name' => 'screenPageViews'],
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

        } catch (\Throwable $e) {
            $this->recordError('top_pages', $e->getMessage());
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
        if (is_array($cached)) {
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

        } catch (\Throwable $e) {
            $this->recordError('visitor_trends', $e->getMessage());
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
        if (is_array($cached)) {
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

        } catch (\Throwable $e) {
            $this->recordError('devices', $e->getMessage());
            error_log('GA Device Stats Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get event data (phone clicks, form submissions)
     */
    public function getEventData(string $event_name, string $start_date = '30daysAgo', string $end_date = 'today'): array
    {
        foreach ($this->getKeyEvents([$event_name], $start_date, $end_date) as $event) {
            if (($event['event_name'] ?? '') === $event_name) {
                return [
                    'count' => (int)($event['count'] ?? 0),
                    'trend' => 0,
                ];
            }
        }

        return ['count' => 0, 'trend' => 0];
    }

    /**
     * Get tracked key events in one GA4 request.
     */
    public function getKeyEvents(array $event_names, string $start_date = '30daysAgo', string $end_date = 'today'): array
    {
        $event_names = array_values(array_unique(array_filter(array_map(
            fn($event) => preg_replace('/[^a-zA-Z0-9_]/', '', (string)$event),
            $event_names
        ))));

        if (empty($event_names)) {
            return [];
        }

        sort($event_names);
        $cache_key = "ga_key_events_{$this->property_id}_{$start_date}_{$end_date}_" . md5(implode('|', $event_names));

        $cached = AnalyticsCache::get($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $display_names = [
            'phone_click' => 'Phone Clicks',
            'email_click' => 'Email Clicks',
            'social_click' => 'Social Clicks',
            'contact_page_visit' => 'Contact Page',
            'generate_lead' => 'Form Submissions',
            'purchase' => 'Purchases',
        ];

        $counts = array_fill_keys($event_names, 0);

        try {
            $data = $this->makeRequest([
                'dateRanges' => [
                    ['startDate' => $start_date, 'endDate' => $end_date],
                ],
                'dimensions' => [
                    ['name' => 'eventName'],
                ],
                'metrics' => [
                    ['name' => 'eventCount'],
                ],
                'dimensionFilter' => [
                    'filter' => [
                        'fieldName' => 'eventName',
                        'inListFilter' => [
                            'values' => $event_names,
                        ],
                    ],
                ],
                'orderBys' => [
                    ['metric' => ['metricName' => 'eventCount'], 'desc' => true],
                ],
            ]);

            foreach (($data['rows'] ?? []) as $row) {
                $event_name = (string)($row['dimensionValues'][0]['value'] ?? '');
                if (array_key_exists($event_name, $counts)) {
                    $counts[$event_name] = (int)($row['metricValues'][0]['value'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            $this->recordError('key_events', $e->getMessage());
            error_log('GA Key Events Error: ' . $e->getMessage());
        }

        $events = [];
        foreach ($event_names as $event_name) {
            $label = $display_names[$event_name] ?? ucwords(str_replace('_', ' ', $event_name));
            $events[] = [
                'event_name' => $event_name,
                'display_name' => $label,
                'label' => $label,
                'count' => (int)($counts[$event_name] ?? 0),
            ];
        }

        AnalyticsCache::set($cache_key, $events, 3600);
        return $events;
    }

    /**
     * Make API request to GA4
     */
    private function makeRequest(array $request_body): array
    {
        $property = $this->formatPropertyResource($this->property_id);
        $url = "https://analyticsdata.googleapis.com/v1beta/{$property}:runReport";

        for ($attempt = 0; $attempt < 2; $attempt++) {
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

            $code = wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && is_array($data) && !isset($data['error'])) {
                return $data;
            }

            if (in_array((int)$code, [401, 403], true) && $attempt === 0) {
                delete_transient('wnq_ga_access_token');
                continue;
            }

            $error_msg = is_array($data) ? ($data['error']['message'] ?? 'Unknown error') : 'Invalid JSON response';
            throw new \Exception('API error ' . $code . ': ' . $error_msg);
        }

        throw new \Exception('API request failed after refreshing the access token');
    }

    /**
     * Get access token using service account
     */
    private function getAccessToken(): string
    {
        // Check transient cache
        $cached_token = get_transient('wnq_ga_access_token');
        if (is_string($cached_token) && $cached_token !== '') {
            return $cached_token;
        }

        // Create JWT
        $now = time();
        $jwt_header = self::base64UrlEncode(wp_json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT'
        ]));

        $jwt_claim = self::base64UrlEncode(wp_json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]));

        $signature_input = $jwt_header . '.' . $jwt_claim;
        
        // Sign with private key
        $signed = openssl_sign(
            $signature_input,
            $signature,
            $this->credentials['private_key'],
            'SHA256'
        );

        if (!$signed) {
            throw new \Exception('Failed to sign Google Analytics service account request');
        }

        $jwt = $signature_input . '.' . self::base64UrlEncode($signature);

        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Token request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['access_token'])) {
            throw new \Exception('Failed to get access token: ' . ($body['error_description'] ?? $body['error'] ?? 'Unknown'));
        }

        // Cache token for 50 minutes (expires in 60)
        set_transient('wnq_ga_access_token', $body['access_token'], 3000);

        return $body['access_token'];
    }

    private function formatPropertyResource(string $property_id): string
    {
        $property_id = trim($property_id);
        return str_starts_with($property_id, 'properties/') ? $property_id : 'properties/' . $property_id;
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
            'page_views' => intval($metrics[1]['value'] ?? 0),
            'sessions' => intval($metrics[2]['value'] ?? 0),
            'bounce_rate' => floatval($metrics[3]['value'] ?? 0) * 100,
            'avg_session_duration' => 0,
            'new_users' => 0,
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
                'avg_time' => 0,
                'bounce_rate' => floatval($row['metricValues'][1]['value'] ?? 0) * 100,
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
        $start_ts = strtotime($start_date);
        $end_ts = strtotime($end_date);
        if (!$start_ts || !$end_ts) {
            return $this->getEmptyOverview();
        }

        $days = max(1, (int)floor(($end_ts - $start_ts) / DAY_IN_SECONDS) + 1);
        $prev_end_ts = $start_ts - DAY_IN_SECONDS;
        $prev_start_ts = $prev_end_ts - (($days - 1) * DAY_IN_SECONDS);
        $prev_end = date('Y-m-d', $prev_end_ts);
        $prev_start = date('Y-m-d', $prev_start_ts);

        try {
            $prev_data = $this->getOverviewStats($prev_start, $prev_end, false);
            return $prev_data;
        } catch (\Throwable $e) {
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

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
