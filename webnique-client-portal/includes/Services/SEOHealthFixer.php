<?php
/**
 * SEO Health Fixer
 *
 * Reads open audit findings, uses AI to generate fixes (meta descriptions,
 * SEO titles, focus keywords), then pushes them to the client's agent site
 * via the /wp-json/wnq-agent/v1/fix-seo endpoint.
 *
 * Fixable finding types:
 *  - missing_meta      → AI generates an optimised meta description
 *  - kw_not_in_title   → AI rewrites the SEO title to include the focus keyword
 *
 * Up to MAX_PER_CLIENT pages are fixed per call to avoid timeout / rate limits.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\SEOHub;
use WNQ\Models\Client;

final class SEOHealthFixer
{
    /** Finding types this fixer handles. */
    const FIXABLE_TYPES = ['missing_meta', 'kw_not_in_title'];

    /** Maximum pages to fix per client per single run (throttles AI + HTTP calls). */
    const MAX_PER_CLIENT = 10;

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Run auto-fix for every active client that has an SEO profile and agent keys.
     * Called by a cron job or from the dashboard "Fix All" button.
     */
    public static function runForAllClients(): array
    {
        $clients = Client::getByStatus('active');
        $clients_processed = 0;
        $total_fixed  = 0;
        $total_failed = 0;

        foreach ($clients as $c) {
            if (!SEOHub::getProfile($c['client_id'])) continue;
            $result = self::runForClient($c['client_id']);
            $total_fixed  += $result['fixed'];
            $total_failed += $result['failed'];
            $clients_processed++;
        }

        return [
            'clients_processed' => $clients_processed,
            'fixed'             => $total_fixed,
            'failed'            => $total_failed,
        ];
    }

    /**
     * Run auto-fix for a single client.
     *
     * @return array{fixed: int, failed: int, skipped: int, error?: string}
     */
    public static function runForClient(string $client_id): array
    {
        $profile = SEOHub::getProfile($client_id);
        if (!$profile) {
            return ['fixed' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'No SEO profile found'];
        }

        // Build context strings for the AI prompt
        $client   = Client::getByClientId($client_id) ?? [];
        $biz_name = $client['company'] ?? $client['name'] ?? $client_id;
        $services = implode(', ', (array)($profile['primary_services'] ?? []));
        $location = implode(', ', (array)($profile['service_locations'] ?? []));

        // All open findings for this client
        $findings = SEOHub::getAuditFindings($client_id, ['status' => 'open']);

        // Keep only the types we can fix automatically
        $fixable = array_filter($findings, function ($f) {
            return in_array($f['finding_type'], self::FIXABLE_TYPES, true);
        });

        if (empty($fixable)) {
            return ['fixed' => 0, 'failed' => 0, 'skipped' => 0];
        }

        // Group findings by page URL so we make one AI call per page
        $pages_by_url = [];
        foreach ($fixable as $f) {
            $url = $f['page_url'] ?? '';
            if (!$url) continue;
            $pages_by_url[$url][] = $f;
        }

        // Active agent keys for this client
        $agent_keys = array_filter(SEOHub::getAgentKeys($client_id), function ($k) {
            return ($k['status'] ?? '') === 'active';
        });

        if (empty($agent_keys)) {
            return ['fixed' => 0, 'failed' => 0, 'skipped' => count($pages_by_url), 'error' => 'No active agent sites'];
        }

        $fixed     = 0;
        $failed    = 0;
        $skipped   = 0;
        $processed = 0;

        foreach ($pages_by_url as $page_url => $page_findings) {
            if ($processed >= self::MAX_PER_CLIENT) {
                $skipped++;
                continue;
            }

            // Fetch cached page data from the hub DB
            $page_data = self::getPageData($client_id, $page_url);
            if (!$page_data || empty($page_data['post_id'])) {
                $skipped++;
                continue;
            }

            // Identify which agent site owns this URL
            $agent = self::findAgentForUrl($page_url, array_values($agent_keys));
            if (!$agent) {
                $skipped++;
                continue;
            }

            // Determine which fixes are needed for this page
            $finding_types = array_column($page_findings, 'finding_type');

            // Generate the fixes via AI
            $ai = self::generateFixes($page_data, $profile, $biz_name, $services, $location, $finding_types);
            if (!$ai['success'] || empty($ai['fixes'])) {
                $skipped++;
                continue;
            }

            // Build the payload for the agent endpoint
            $payload = array_merge(['post_id' => (int)$page_data['post_id']], $ai['fixes']);

            // Also set focus keyword if the page has one (always useful for SEO plugins)
            if (!empty($page_data['focus_keyword']) && !isset($payload['focus_keyword'])) {
                $payload['focus_keyword'] = $page_data['focus_keyword'];
            }

            // Push fix to the client's WordPress site
            $push = self::pushFix($agent, $payload);
            $processed++;

            if ($push['success']) {
                // Resolve findings for this page
                foreach ($page_findings as $f) {
                    SEOHub::resolveAuditFinding((int)$f['id']);
                }
                $fixed++;
                SEOHub::log('seo_auto_fix', [
                    'client_id'   => $client_id,
                    'entity_type' => 'audit_finding',
                    'page_url'    => $page_url,
                    'post_id'     => $page_data['post_id'],
                    'fixes'       => implode(', ', array_keys($ai['fixes'])),
                ], 'success', 'auto');
            } else {
                $failed++;
                SEOHub::log('seo_auto_fix', [
                    'client_id'   => $client_id,
                    'entity_type' => 'audit_finding',
                    'page_url'    => $page_url,
                    'error'       => $push['message'],
                ], 'failed', 'auto');
            }
        }

        return [
            'fixed'   => $fixed,
            'failed'  => $failed,
            'skipped' => $skipped,
        ];
    }

    // ── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Use the existing meta_tags AI prompt to generate SEO title and/or
     * meta description for a page, depending on which findings are present.
     */
    private static function generateFixes(
        array  $page,
        array  $profile,
        string $biz_name,
        string $services,
        string $location,
        array  $finding_types
    ): array {
        $current_title = $page['title'] ?? '';
        $current_meta  = $page['meta_description'] ?? '';
        $focus_kw      = $page['focus_keyword'] ?? '';

        $vars = [
            'business_name' => $biz_name,
            'page_type'     => $page['page_type'] ?? 'page',
            'topic'         => $current_title ?: $page['page_url'],
            'keyword'       => $focus_kw ?: $services,
            'location'      => $location,
            'current_title' => $current_title,
            'current_meta'  => !empty($current_meta) ? $current_meta : 'None',
        ];

        $result = AIEngine::generate('meta_tags', $vars);
        if (!$result['success']) {
            return ['success' => false];
        }

        $content = $result['content'];
        $fixes   = [];

        // Parse TITLE: line
        if (
            in_array('kw_not_in_title', $finding_types, true)
            && preg_match('/^TITLE:\s*(.+)$/m', $content, $m)
        ) {
            $fixes['seo_title'] = trim($m[1]);
        }

        // Parse META: line
        if (
            in_array('missing_meta', $finding_types, true)
            && preg_match('/^META:\s*(.+)$/m', $content, $m)
        ) {
            $fixes['meta_description'] = trim($m[1]);
        }

        return ['success' => true, 'fixes' => $fixes];
    }

    /**
     * Fetch page data from the synced wnq_seo_site_data table.
     */
    private static function getPageData(string $client_id, string $page_url): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_site_data
                 WHERE client_id = %s AND page_url = %s LIMIT 1",
                $client_id,
                $page_url
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Find the agent key whose site_url is a prefix of the page URL.
     * Falls back to the first active key if no prefix match is found.
     */
    private static function findAgentForUrl(string $page_url, array $agent_keys): ?array
    {
        foreach ($agent_keys as $key) {
            $site = rtrim($key['site_url'] ?? '', '/');
            if ($site && strpos($page_url, $site) === 0) {
                return $key;
            }
        }
        return $agent_keys[0] ?? null;
    }

    /**
     * POST the fix payload to the agent's REST endpoint.
     */
    private static function pushFix(array $agent, array $payload): array
    {
        $endpoint = rtrim($agent['site_url'], '/') . '/wp-json/wnq-agent/v1/fix-seo';

        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'X-WNQ-Api-Key' => $agent['api_key'],
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 || $code === 201) {
            return ['success' => true, 'message' => $body['status'] ?? 'ok'];
        }

        return [
            'success' => false,
            'message' => 'HTTP ' . $code . ' — ' . ($body['error'] ?? substr(wp_remote_retrieve_body($response), 0, 200)),
        ];
    }
}
