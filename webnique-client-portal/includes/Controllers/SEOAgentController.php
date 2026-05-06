<?php
/**
 * SEO Agent REST Controller
 *
 * Handles incoming requests from the WebNique SEO Agent plugin installed
 * on client WordPress sites. All routes require a valid API key.
 *
 * Endpoints (base: /wp-json/wnq/v1/agent):
 *  POST /sync          - Receive full site data sync from client plugin
 *  POST /ping          - Heartbeat / plugin version check
 *  GET  /instructions  - Reserved compatibility endpoint
 *  POST /ack           - Reserved compatibility acknowledgement endpoint
 *
 * @package WebNique Portal
 */

namespace WNQ\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\SEOHub;
use WNQ\Services\AuditEngine;

final class SEOAgentController
{
    public static function registerRoutes(): void
    {
        register_rest_route('wnq/v1', '/agent/ping', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handlePing'],
            'permission_callback' => [self::class, 'authenticateKey'],
        ]);

        register_rest_route('wnq/v1', '/agent/sync', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleSync'],
            'permission_callback' => [self::class, 'authenticateKey'],
        ]);

        register_rest_route('wnq/v1', '/agent/instructions', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleInstructions'],
            'permission_callback' => [self::class, 'authenticateKey'],
        ]);

        register_rest_route('wnq/v1', '/agent/ack', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleAck'],
            'permission_callback' => [self::class, 'authenticateKey'],
        ]);
    }

    // ── Authentication ─────────────────────────────────────────────────────

    public static function authenticateKey(\WP_REST_Request $request): bool|\WP_Error
    {
        $key = $request->get_header('X-WNQ-Api-Key')
            ?? $request->get_param('api_key')
            ?? '';

        if (empty($key)) {
            return new \WP_Error('missing_api_key', 'API key is required.', ['status' => 401]);
        }

        $agent = SEOHub::validateAgentKey(sanitize_text_field($key));
        if (!$agent) {
            return new \WP_Error('invalid_api_key', 'Invalid or revoked API key.', ['status' => 403]);
        }

        // Store agent context for use in handlers
        $request->set_param('__agent', $agent);
        return true;
    }

    // ── Handlers ───────────────────────────────────────────────────────────

    /**
     * POST /agent/ping - Heartbeat
     */
    public static function handlePing(\WP_REST_Request $request): \WP_REST_Response
    {
        $agent      = $request->get_param('__agent');
        $client_id  = $agent['client_id'];
        $body       = $request->get_json_params() ?? [];

        // Update plugin version info
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wnq_seo_agent_keys',
            [
                'wp_version'     => sanitize_text_field($body['wp_version'] ?? ''),
                'plugin_version' => sanitize_text_field($body['plugin_version'] ?? ''),
                'site_url'       => esc_url_raw($body['site_url'] ?? $agent['site_url']),
            ],
            ['api_key' => $agent['api_key']]
        );

        SEOHub::log('agent_ping', ['client_id' => $client_id, 'site_url' => $body['site_url'] ?? '']);

        return new \WP_REST_Response([
            'status'  => 'ok',
            'hub_time'=> current_time('mysql'),
            'client_id' => $client_id,
        ], 200);
    }

    /**
     * POST /agent/sync - Receive site data
     *
     * Expected body:
     * {
     *   "pages": [
     *     {
     *       "page_url": "https://...",
     *       "page_type": "post|page|product",
     *       "post_id": 123,
     *       "post_status": "publish",
     *       "title": "...",
     *       "meta_description": "...",
     *       "h1": "...",
     *       "focus_keyword": "...",
     *       "word_count": 850,
     *       "internal_links_count": 3,
     *       "images_count": 4,
     *       "images_missing_alt": 1,
     *       "schema_types": ["LocalBusiness"],
     *       "keyword_in_title": true,
     *       "keyword_in_meta": true,
     *       "keyword_in_h1": true,
     *       "categories": ["Services"],
     *       "tags": [],
     *       "featured_image_url": "...",
     *       "last_modified": "2026-01-15 10:00:00"
     *     }
     *   ],
     *   "site_info": {
     *     "site_url": "...",
     *     "wp_version": "6.4",
     *     "total_posts": 45,
     *     "total_pages": 12
     *   }
     * }
     */
    public static function handleSync(\WP_REST_Request $request): \WP_REST_Response
    {
        $agent     = $request->get_param('__agent');
        $client_id = $agent['client_id'];
        $body      = $request->get_json_params() ?? [];

        if (empty($body['pages']) || !is_array($body['pages'])) {
            return new \WP_REST_Response(['error' => 'No pages data provided'], 400);
        }

        // Validate and cap pages array
        $pages = array_slice($body['pages'], 0, 500);

        $synced = SEOHub::upsertSiteData($client_id, $pages);

        // Trigger auto-resolve for fixed issues
        AuditEngine::autoResolveFindings($client_id);

        // Update last sync time on profile
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wnq_seo_profiles',
            ['last_sync' => current_time('mysql')],
            ['client_id' => $client_id]
        );

        SEOHub::log('agent_sync', [
            'client_id'   => $client_id,
            'pages_synced'=> $synced,
            'entity_type' => 'site_data',
        ]);

        return new \WP_REST_Response([
            'status'       => 'synced',
            'pages_synced' => $synced,
            'synced_at'    => current_time('mysql'),
        ], 200);
    }

    /**
     * GET /agent/instructions - Reserved compatibility endpoint.
     * Old automation instructions were retired with the Service + City workflow.
     */
    public static function handleInstructions(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'instructions' => [],
            'count'        => 0,
        ], 200);
    }

    /**
     * POST /agent/ack - Acknowledge instruction execution
     */
    public static function handleAck(\WP_REST_Request $request): \WP_REST_Response
    {
        $agent     = $request->get_param('__agent');
        $client_id = $agent['client_id'];
        $body      = $request->get_json_params() ?? [];

        $job_id = (int)($body['job_id'] ?? 0);
        $status = sanitize_text_field($body['status'] ?? 'executed');

        if (!$job_id) {
            return new \WP_REST_Response(['error' => 'Missing job_id'], 400);
        }

        SEOHub::log('agent_ack_instruction', [
            'client_id'   => $client_id,
            'entity_id'   => $job_id,
            'entity_type' => 'content_job',
            'exec_status' => $status,
        ], $status === 'executed' ? 'success' : 'failed', 'api');

        return new \WP_REST_Response(['status' => 'acknowledged'], 200);
    }
}
