<?php
/**
 * API Sync
 *
 * Handles secure communication between the client WordPress site
 * and the WebNique SEO Hub. All requests are authenticated via API key.
 *
 * The plugin is a DATA RELAY only. No SEO analysis runs here.
 *
 * @package WebNique SEO Agent
 */

namespace WNQA;

if (!defined('ABSPATH')) {
    exit;
}

class APISync
{
    private string $hub_url;
    private string $api_key;
    private int    $timeout;

    public function __construct()
    {
        $config          = get_option('wnqa_config', []);
        $this->hub_url   = rtrim($config['hub_url'] ?? '', '/');
        $this->api_key   = $config['api_key'] ?? '';
        $this->timeout   = 30;
    }

    /**
     * Sync full site data to hub
     */
    public function syncSiteData(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Plugin not configured (missing hub URL or API key)'];
        }

        $collector = new DataCollector();
        $pages     = $collector->collectAll();

        if (empty($pages)) {
            return ['success' => false, 'message' => 'No pages to sync'];
        }

        // Send in batches of 50 to avoid timeout
        $batches    = array_chunk($pages, 50);
        $synced     = 0;
        $last_error = '';

        foreach ($batches as $batch) {
            $response = $this->post('/wp-json/wnq/v1/agent/sync', [
                'pages'     => $batch,
                'site_info' => [
                    'site_url'   => home_url(),
                    'wp_version' => get_bloginfo('version'),
                    'total_posts'=> wp_count_posts('post')->publish ?? 0,
                    'total_pages'=> wp_count_posts('page')->publish ?? 0,
                ],
            ]);

            if ($response['success']) {
                $synced += $response['data']['pages_synced'] ?? 0;
            } else {
                $last_error = $response['message'];
            }
        }

        // Update last sync time
        update_option('wnqa_last_sync', current_time('mysql'));
        update_option('wnqa_last_sync_count', $synced);

        $this->log('sync', $synced . ' pages synced');

        if ($synced > 0) {
            return ['success' => true, 'message' => "Synced $synced pages to hub"];
        }
        return ['success' => false, 'message' => $last_error ?: 'Sync failed'];
    }

    /**
     * Heartbeat ping to hub
     */
    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Not configured'];
        }

        $response = $this->post('/wp-json/wnq/v1/agent/ping', [
            'site_url'       => home_url(),
            'wp_version'     => get_bloginfo('version'),
            'plugin_version' => WNQA_VERSION,
        ]);

        if ($response['success']) {
            update_option('wnqa_last_ping', current_time('mysql'));
            update_option('wnqa_hub_time', $response['data']['hub_time'] ?? '');
            return ['success' => true, 'message' => 'Connected to hub successfully'];
        }

        return ['success' => false, 'message' => $response['message'] ?? 'Connection failed'];
    }

    /**
     * Fetch pending instructions from hub
     */
    public function fetchInstructions(): array
    {
        if (!$this->isConfigured()) return [];

        $response = $this->get('/wp-json/wnq/v1/agent/instructions');
        if (!$response['success']) return [];

        return $response['data']['instructions'] ?? [];
    }

    /**
     * Acknowledge instruction execution
     */
    public function ackInstruction(int $job_id, string $status = 'executed'): bool
    {
        $response = $this->post('/wp-json/wnq/v1/agent/ack', [
            'job_id' => $job_id,
            'status' => $status,
        ]);
        return $response['success'];
    }

    // ── HTTP Helpers ────────────────────────────────────────────────────────

    private function post(string $endpoint, array $data): array
    {
        if (empty($this->hub_url) || empty($this->api_key)) {
            return ['success' => false, 'message' => 'Not configured'];
        }

        $url      = $this->hub_url . $endpoint;
        $response = wp_remote_post($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'X-WNQ-Api-Key' => $this->api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'WebNique-SEO-Agent/' . WNQA_VERSION,
            ],
            'body' => wp_json_encode($data),
        ]);

        return $this->parseResponse($response);
    }

    private function get(string $endpoint): array
    {
        $url      = $this->hub_url . $endpoint;
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'X-WNQ-Api-Key' => $this->api_key,
                'User-Agent'    => 'WebNique-SEO-Agent/' . WNQA_VERSION,
            ],
        ]);

        return $this->parseResponse($response);
    }

    private function parseResponse($response): array
    {
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message(), 'data' => []];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?? [];

        if ($code === 200 || $code === 201) {
            return ['success' => true, 'data' => $body, 'message' => 'OK'];
        }

        $error_msg = $body['message'] ?? $body['error'] ?? "HTTP $code";
        return ['success' => false, 'message' => $error_msg, 'data' => $body];
    }

    private function isConfigured(): bool
    {
        return !empty($this->hub_url) && !empty($this->api_key);
    }

    private function log(string $action, string $message): void
    {
        $logs   = get_option('wnqa_sync_log', []);
        $logs[] = ['time' => current_time('mysql'), 'action' => $action, 'message' => $message];
        // Keep last 50 log entries
        if (count($logs) > 50) {
            $logs = array_slice($logs, -50);
        }
        update_option('wnqa_sync_log', $logs);
    }
}
