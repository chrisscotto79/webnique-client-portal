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
 * Batch / pulse mode: runBatch() processes a small number of pages per call
 * (default 5) and returns a `remaining` count so the UI can keep pulsing
 * until done = true.
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

    /** Pages processed per batch call (keeps AJAX responses fast). */
    const BATCH_SIZE = 5;

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Process one small batch of fixable pages for a client.
     *
     * Each call processes up to BATCH_SIZE pages, marks their findings resolved,
     * and returns counts + remaining so the UI can show a live progress bar.
     *
     * The caller should keep calling until done === true.
     *
     * @return array{fixed:int, failed:int, skipped:int, remaining:int, done:bool, error?:string}
     */
    public static function runBatch(string $client_id): array
    {
        $profile = SEOHub::getProfile($client_id);
        if (!$profile) {
            return ['fixed' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0, 'done' => true, 'error' => 'No SEO profile found'];
        }

        $client   = Client::getByClientId($client_id) ?? [];
        $biz_name = $client['company'] ?? $client['name'] ?? $client_id;
        $services = implode(', ', (array)($profile['primary_services'] ?? []));
        $location = implode(', ', (array)($profile['service_locations'] ?? []));
        $phone    = $profile['phone'] ?? '';

        // Active agent keys
        $agent_keys = array_values(array_filter(
            SEOHub::getAgentKeys($client_id),
            function ($k) { return ($k['status'] ?? '') === 'active'; }
        ));

        if (empty($agent_keys)) {
            return ['fixed' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0, 'done' => true, 'error' => 'No active agent sites'];
        }

        // Load all open fixable findings in one query
        $all_findings = SEOHub::getAuditFindings($client_id, ['status' => 'open']);
        $fixable      = array_filter($all_findings, function ($f) {
            return in_array($f['finding_type'], self::FIXABLE_TYPES, true) && !empty($f['page_url']);
        });

        // Group by page URL
        $pages_by_url = [];
        foreach ($fixable as $f) {
            $pages_by_url[$f['page_url']][] = $f;
        }

        $total_fixable_pages = count($pages_by_url);

        if ($total_fixable_pages === 0) {
            return ['fixed' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0, 'done' => true];
        }

        // Take only BATCH_SIZE pages this call
        $batch       = array_slice($pages_by_url, 0, self::BATCH_SIZE, true);
        $fixed       = 0;
        $failed      = 0;
        $skipped     = 0;

        foreach ($batch as $page_url => $page_findings) {
            $page_data = self::getPageData($client_id, $page_url);
            if (!$page_data || empty($page_data['post_id'])) {
                // Resolve findings we can't fix so they don't block progress forever
                foreach ($page_findings as $f) {
                    SEOHub::resolveAuditFinding((int)$f['id']);
                }
                $skipped++;
                continue;
            }

            $agent = self::findAgentForUrl($page_url, $agent_keys);
            if (!$agent) {
                $skipped++;
                continue;
            }

            $finding_types = array_column($page_findings, 'finding_type');
            $payload       = ['post_id' => (int)$page_data['post_id']];

            // AI fixes (one call covers both meta + title)
            $needs_ai = array_intersect($finding_types, ['missing_meta', 'kw_not_in_title']);
            if (!empty($needs_ai)) {
                $ai = self::generateMetaFixes($page_data, $profile, $biz_name, $services, $location, $finding_types);
                if ($ai['success']) {
                    if (!empty($ai['seo_title']))        $payload['seo_title']        = $ai['seo_title'];
                    if (!empty($ai['meta_description'])) $payload['meta_description'] = $ai['meta_description'];
                }
            }

            // Schema fix (programmatic — no AI)
            if (in_array('no_schema', $finding_types, true)) {
                $schema = self::buildSchema($page_data, $biz_name, $services, $location, $phone);
                if ($schema) $payload['schema_json'] = $schema;
            }

            // H1 fix — send existing title; agent un-hides it
            if (in_array('missing_h1', $finding_types, true)) {
                $h1 = !empty($payload['seo_title']) ? $payload['seo_title'] : ($page_data['title'] ?? '');
                if ($h1) $payload['h1_title'] = $h1;
            }

            // Alt text fix — agent scans and updates images
            if (in_array('missing_alt', $finding_types, true) && (int)($page_data['images_missing_alt'] ?? 0) > 0) {
                $payload['fix_missing_alt'] = true;
            }

            // Focus keyword passthrough
            if (!empty($page_data['focus_keyword']) && !isset($payload['focus_keyword'])) {
                $payload['focus_keyword'] = $page_data['focus_keyword'];
            }

            // Skip if nothing to fix beyond post_id
            if (count($payload) <= 1) {
                foreach ($page_findings as $f) {
                    SEOHub::resolveAuditFinding((int)$f['id']);
                }
                $skipped++;
                continue;
            }

            $push = self::pushFix($agent, $payload);

            if ($push['success']) {
                foreach ($page_findings as $f) {
                    SEOHub::resolveAuditFinding((int)$f['id']);
                }
                // Mirror the fix back into wnq_seo_site_data so the health score
                // and next audit reflect the improvement without waiting for a re-sync.
                self::updateSiteDataAfterFix($client_id, $page_url, $payload, $page_data['page_type'] ?? 'page');
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
                    'client_id' => $client_id,
                    'entity_type' => 'audit_finding',
                    'page_url'  => $page_url,
                    'error'     => $push['message'],
                ], 'failed', 'auto');
            }
        }

        // Re-query the true remaining count — arithmetic is unreliable because
        // failed pages keep their findings open and reappear in the next batch.
        $remaining = self::countFixablePages($client_id);

        return [
            'fixed'     => $fixed,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'remaining' => $remaining,
            'done'      => ($remaining === 0),
        ];
    }

    /**
     * Count fixable pages for a client (used to initialise the progress bar).
     */
    public static function countFixablePages(string $client_id): int
    {
        $findings = SEOHub::getAuditFindings($client_id, ['status' => 'open']);
        $urls = [];
        foreach ($findings as $f) {
            if (!empty($f['page_url']) && in_array($f['finding_type'], self::FIXABLE_TYPES, true)) {
                $urls[$f['page_url']] = true;
            }
        }
        return count($urls);
    }

    /**
     * Run auto-fix for every active client (cron usage).
     */
    public static function runForAllClients(): array
    {
        $clients           = Client::getByStatus('active');
        $clients_processed = 0;
        $total_fixed       = 0;
        $total_failed      = 0;

        foreach ($clients as $c) {
            if (!SEOHub::getProfile($c['client_id'])) continue;
            // Run a single batch per client during cron
            $result        = self::runBatch($c['client_id']);
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

    // ── Fix Generators ───────────────────────────────────────────────────────

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

        if (!$result['success']) return ['success' => false];

        $content = $result['content'];
        $fixes   = ['success' => true];

        if (in_array('kw_not_in_title', $finding_types, true) && preg_match('/^TITLE:\s*(.+)$/m', $content, $m)) {
            $fixes['seo_title'] = trim($m[1]);
        }
        if (in_array('missing_meta', $finding_types, true) && preg_match('/^META:\s*(.+)$/m', $content, $m)) {
            $fixes['meta_description'] = trim($m[1]);
        }

        return $fixes;
    }

    private static function buildSchema(array $page, string $biz_name, string $services, string $location, string $phone): string
    {
        $page_type = $page['page_type'] ?? 'page';
        $url       = $page['page_url'] ?? '';
        $title     = $page['title'] ?? $biz_name;
        $desc      = $page['meta_description'] ?? '';
        $modified  = $page['last_modified'] ?? date('Y-m-d');

        if ($page_type === 'post') {
            $schema = [
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => $title,
                'description'      => $desc,
                'url'              => $url,
                'dateModified'     => substr($modified, 0, 10),
                'author'           => ['@type' => 'Organization', 'name' => $biz_name],
                'publisher'        => ['@type' => 'Organization', 'name' => $biz_name],
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
            $local = ['@type' => 'LocalBusiness', 'name' => $biz_name];
            if ($location) $local['address']     = $location;
            if ($phone)    $local['telephone']   = $phone;
            if ($services) $local['description'] = $services;

            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'WebPage',
                'name'        => $title,
                'description' => $desc,
                'url'         => $url,
                'publisher'   => $local,
            ];
        }

        $json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json ?: '';
    }

    // ── Data Helpers ─────────────────────────────────────────────────────────

    private static function getPageData(string $client_id, string $page_url): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_site_data WHERE client_id = %s AND page_url = %s LIMIT 1",
                $client_id,
                $page_url
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    private static function findAgentForUrl(string $page_url, array $agent_keys): ?array
    {
        foreach ($agent_keys as $key) {
            $site = rtrim($key['site_url'] ?? '', '/');
            if ($site && strpos($page_url, $site) === 0) return $key;
        }
        return $agent_keys[0] ?? null;
    }

    /**
     * After a successful fix push, write the changed values back into
     * wnq_seo_site_data on the hub side so that:
     *  - getHealthScore() / getSiteStats() reflect the improvement immediately
     *  - The next auditClient() run does NOT re-create findings for the same issues
     *  - autoResolveFindings() joins also work correctly
     */
    private static function updateSiteDataAfterFix(
        string $client_id,
        string $page_url,
        array  $payload,
        string $page_type
    ): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'wnq_seo_site_data';
        $updates = [];

        // Meta description fixed → write new value; audit checks CHAR_LENGTH >= 80
        if (!empty($payload['meta_description'])) {
            $updates['meta_description'] = $payload['meta_description'];
        }

        // Schema added → mark has_schema = 1 and record the schema type
        if (!empty($payload['schema_json'])) {
            $updates['has_schema'] = 1;
            $type = ($page_type === 'post') ? 'BlogPosting' : (($page_type === 'product') ? 'Product' : 'WebPage');
            $updates['schema_types'] = wp_json_encode([$type]);
        }

        // H1 added → write new h1 text and set has_h1 = 1
        if (!empty($payload['h1_title'])) {
            $updates['h1']     = $payload['h1_title'];
            $updates['has_h1'] = 1;
        }

        // Alt texts fixed → set images_missing_alt = 0
        if (!empty($payload['fix_missing_alt'])) {
            $updates['images_missing_alt'] = 0;
        }

        // Focus keyword stored so future audits can check keyword_in_title correctly
        if (!empty($payload['focus_keyword'])) {
            $updates['focus_keyword'] = $payload['focus_keyword'];
            // Update keyword_in_title flag if we also changed the title
            if (!empty($payload['seo_title'])) {
                $updates['keyword_in_title'] = (strpos(
                    strtolower($payload['seo_title']),
                    strtolower($payload['focus_keyword'])
                ) !== false) ? 1 : 0;
            }
        }

        if (empty($updates)) {
            return;
        }

        $updates['last_synced'] = current_time('mysql');

        $wpdb->update(
            $table,
            $updates,
            ['client_id' => $client_id, 'page_url' => $page_url]
        );
    }

    private static function pushFix(array $agent, array $payload): array
    {
        $endpoint = rtrim($agent['site_url'], '/') . '/wp-json/wnq-agent/v1/fix-seo';

        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json', 'X-WNQ-Api-Key' => $agent['api_key']],
            'body'    => wp_json_encode($payload),
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
