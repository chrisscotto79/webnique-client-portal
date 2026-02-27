<?php
/**
 * SEO Health Fixer
 *
 * Reads open audit findings, generates fixes (via AI where needed or
 * programmatically where AI is unnecessary), then pushes them to the
 * client's agent site via POST /wp-json/wnq-agent/v1/fix-seo.
 *
 * Fixable finding types:
 *  - missing_meta      → AI generates meta description
 *  - kw_not_in_title   → AI rewrites SEO title with focus keyword
 *  - no_schema         → Programmatic JSON-LD generated from page data + profile
 *  - missing_h1        → Existing page title sent as H1; agent un-hides it
 *  - missing_alt       → Agent updates featured image + attachment alt texts
 *
 * AI calls are minimised: one call per page (meta_tags prompt covers both
 * missing_meta and kw_not_in_title). Schema and alt/H1 fixes are generated
 * without AI, avoiding rate-limit issues on large sites.
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
    /** All finding types this fixer can handle automatically. */
    const FIXABLE_TYPES = ['missing_meta', 'kw_not_in_title', 'no_schema', 'missing_alt', 'missing_h1'];

    /** Max pages to fix per client per run (increased — non-AI fixes are cheap). */
    const MAX_PER_CLIENT = 50;

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Run auto-fix for every active client that has an SEO profile and agent keys.
     */
    public static function runForAllClients(): array
    {
        $clients           = Client::getByStatus('active');
        $clients_processed = 0;
        $total_fixed       = 0;
        $total_failed      = 0;

        foreach ($clients as $c) {
            if (!SEOHub::getProfile($c['client_id'])) continue;
            $result        = self::runForClient($c['client_id']);
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

        // Build context strings used in AI + schema generation
        $client   = Client::getByClientId($client_id) ?? [];
        $biz_name = $client['company'] ?? $client['name'] ?? $client_id;
        $services = implode(', ', (array)($profile['primary_services'] ?? []));
        $location = implode(', ', (array)($profile['service_locations'] ?? []));
        $phone    = $profile['phone'] ?? '';

        // Load all open fixable findings
        $all_findings = SEOHub::getAuditFindings($client_id, ['status' => 'open']);
        $fixable      = array_filter($all_findings, function ($f) {
            return in_array($f['finding_type'], self::FIXABLE_TYPES, true);
        });

        if (empty($fixable)) {
            return ['fixed' => 0, 'failed' => 0, 'skipped' => 0];
        }

        // Group findings by page URL — one push per page
        $pages_by_url = [];
        foreach ($fixable as $f) {
            $url = $f['page_url'] ?? '';
            if (!$url) continue;
            $pages_by_url[$url][] = $f;
        }

        // Active agent keys for this client
        $agent_keys = array_values(array_filter(
            SEOHub::getAgentKeys($client_id),
            function ($k) { return ($k['status'] ?? '') === 'active'; }
        ));

        if (empty($agent_keys)) {
            return [
                'fixed'   => 0,
                'failed'  => 0,
                'skipped' => count($pages_by_url),
                'error'   => 'No active agent sites configured',
            ];
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

            // Find the agent site that owns this page URL
            $agent = self::findAgentForUrl($page_url, $agent_keys);
            if (!$agent) {
                $skipped++;
                continue;
            }

            $finding_types = array_column($page_findings, 'finding_type');

            // Build the fix payload — mix of AI-generated and programmatic fixes
            $payload = ['post_id' => (int)$page_data['post_id']];

            // ── AI-based fixes (one call covers both meta + title) ──────────
            $needs_ai = array_intersect($finding_types, ['missing_meta', 'kw_not_in_title']);
            if (!empty($needs_ai)) {
                $ai = self::generateMetaFixes($page_data, $profile, $biz_name, $services, $location, $finding_types);
                if ($ai['success']) {
                    if (!empty($ai['seo_title']))       $payload['seo_title']       = $ai['seo_title'];
                    if (!empty($ai['meta_description'])) $payload['meta_description'] = $ai['meta_description'];
                }
            }

            // ── Schema fix (programmatic — no AI call) ──────────────────────
            if (in_array('no_schema', $finding_types, true)) {
                $schema = self::buildSchema($page_data, $biz_name, $services, $location, $phone);
                if ($schema) {
                    $payload['schema_json'] = $schema;
                }
            }

            // ── H1 fix (send existing title — agent will un-hide it) ────────
            if (in_array('missing_h1', $finding_types, true)) {
                $h1 = !empty($payload['seo_title'])
                    ? $payload['seo_title']
                    : ($page_data['title'] ?? '');
                if (!empty($h1)) {
                    $payload['h1_title'] = $h1;
                }
            }

            // ── Alt text fix (flag — agent finds + updates images) ──────────
            if (in_array('missing_alt', $finding_types, true) && (int)$page_data['images_missing_alt'] > 0) {
                $payload['fix_missing_alt'] = true;
            }

            // Always send focus keyword if we have one (helps SEO plugins)
            if (!empty($page_data['focus_keyword']) && !isset($payload['focus_keyword'])) {
                $payload['focus_keyword'] = $page_data['focus_keyword'];
            }

            // Nothing to do for this page
            if (count($payload) <= 1) { // only post_id
                $skipped++;
                continue;
            }

            // Push all fixes to the client's WordPress site in one HTTP request
            $push = self::pushFix($agent, $payload);
            $processed++;

            if ($push['success']) {
                foreach ($page_findings as $f) {
                    SEOHub::resolveAuditFinding((int)$f['id']);
                }
                $fixed++;
                SEOHub::log('seo_auto_fix', [
                    'client_id'   => $client_id,
                    'entity_type' => 'audit_finding',
                    'page_url'    => $page_url,
                    'post_id'     => $page_data['post_id'],
                    'fixes'       => implode(', ', array_diff(array_keys($payload), ['post_id'])),
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

    // ── Fix Generators ───────────────────────────────────────────────────────

    /**
     * AI: generate optimised SEO title and/or meta description via meta_tags prompt.
     * One AI call covers both missing_meta and kw_not_in_title simultaneously.
     */
    private static function generateMetaFixes(
        array  $page,
        array  $profile,
        string $biz_name,
        string $services,
        string $location,
        array  $finding_types
    ): array {
        $result = AIEngine::generate('meta_tags', [
            'business_name' => $biz_name,
            'page_type'     => $page['page_type'] ?? 'page',
            'topic'         => $page['title'] ?: ($page['page_url'] ?? ''),
            'keyword'       => $page['focus_keyword'] ?: $services,
            'location'      => $location,
            'current_title' => $page['title'] ?? '',
            'current_meta'  => !empty($page['meta_description']) ? $page['meta_description'] : 'None',
        ]);

        if (!$result['success']) {
            return ['success' => false];
        }

        $content = $result['content'];
        $fixes   = ['success' => true];

        if (
            in_array('kw_not_in_title', $finding_types, true)
            && preg_match('/^TITLE:\s*(.+)$/m', $content, $m)
        ) {
            $fixes['seo_title'] = trim($m[1]);
        }

        if (
            in_array('missing_meta', $finding_types, true)
            && preg_match('/^META:\s*(.+)$/m', $content, $m)
        ) {
            $fixes['meta_description'] = trim($m[1]);
        }

        return $fixes;
    }

    /**
     * Programmatic schema builder — no AI call needed, based on page type.
     *
     * Returns a JSON string (without <script> wrapper) ready for
     * _wnq_schema_json post meta.
     */
    private static function buildSchema(
        array  $page,
        string $biz_name,
        string $services,
        string $location,
        string $phone
    ): string {
        $page_type = $page['page_type'] ?? 'page';
        $url       = $page['page_url'] ?? '';
        $title     = $page['title'] ?? $biz_name;
        $desc      = $page['meta_description'] ?? '';
        $modified  = $page['last_modified'] ?? date('Y-m-d');

        if ($page_type === 'post') {
            // BlogPosting schema for blog articles
            $schema = [
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => $title,
                'description'      => $desc,
                'url'              => $url,
                'dateModified'     => substr($modified, 0, 10),
                'author'           => ['@type' => 'Organization', 'name' => $biz_name],
                'publisher'        => [
                    '@type' => 'Organization',
                    'name'  => $biz_name,
                ],
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            ];
        } elseif ($page_type === 'product') {
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $title,
                'description' => $desc,
                'url'         => $url,
                'brand'       => ['@type' => 'Organization', 'name' => $biz_name],
            ];
        } else {
            // WebPage + LocalBusiness for service / landing pages
            $local = [
                '@type' => 'LocalBusiness',
                'name'  => $biz_name,
            ];
            if ($location) $local['address'] = $location;
            if ($phone)    $local['telephone'] = $phone;
            if ($services) $local['description'] = $services;

            $schema = [
                '@context'  => 'https://schema.org',
                '@type'     => 'WebPage',
                'name'      => $title,
                'description'=> $desc,
                'url'       => $url,
                'publisher' => $local,
            ];
        }

        $json = wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json ?: '';
    }

    // ── Data Helpers ─────────────────────────────────────────────────────────

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
     * Find the agent key whose site_url prefix matches the page URL.
     * Falls back to the first active agent key.
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
     * POST the fix payload to the agent's /fix-seo REST endpoint.
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
