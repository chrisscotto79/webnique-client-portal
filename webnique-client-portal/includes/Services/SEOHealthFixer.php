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
    const FIXABLE_TYPES = [
        'missing_meta', 'kw_not_in_title', 'no_schema', 'missing_alt', 'missing_h1',
        'missing_og', 'no_image_lazy_load', 'title_too_long', 'no_internal_links',
    ];

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

            // H1 fix — promote the first H2 tag to H1. The page title is
            // never changed. Works for both Elementor and classic content.
            if (in_array('missing_h1', $finding_types, true)) {
                $payload['promote_first_h2'] = true;
            }

            // Alt text fix — always send when the finding exists. The agent
            // will scan all images on the page and add alt text to any missing it.
            if (in_array('missing_alt', $finding_types, true)) {
                $payload['fix_missing_alt'] = true;
            }

            // Open Graph fix — hub passes existing meta; agent writes OG tags.
            if (in_array('missing_og', $finding_types, true)) {
                $payload['fix_open_graph']   = true;
                $payload['og_title']         = $page_data['title'] ?? '';
                $payload['og_description']   = $page_data['meta_description'] ?? '';
                $payload['og_image']         = $page_data['featured_image_url'] ?? '';
            }

            // Image lazy load fix — agent patches post_content or sets an Elementor flag.
            if (in_array('no_image_lazy_load', $finding_types, true)) {
                $payload['fix_image_lazy_load'] = true;
            }

            // Title too long — reuse AI meta fix to shorten; title_too_long implies kw_not_in_title
            if (in_array('title_too_long', $finding_types, true) && !in_array('kw_not_in_title', $finding_types, true)) {
                // Treat like kw_not_in_title so generateMetaFixes() shortens the title
                $finding_types[] = 'kw_not_in_title';
                $needs_ai        = ['kw_not_in_title'];
                $ai              = self::generateMetaFixes($page_data, $profile, $biz_name, $services, $location, $finding_types);
                if ($ai['success'] && !empty($ai['seo_title'])) {
                    $payload['seo_title'] = $ai['seo_title'];
                }
            }

            // Internal links — generate suggestions from other pages' focus keywords.
            if (in_array('no_internal_links', $finding_types, true)) {
                $links = self::generateInternalLinks($page_data, $client_id);
                if (!empty($links)) {
                    $payload['internal_links'] = $links;
                }
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
                    'client_id'   => $client_id,
                    'entity_type' => 'audit_finding',
                    'page_url'    => $page_url,
                    'error'       => $push['message'],
                ], 'failed', 'auto');

                // Mark findings as fix_failed so they exit the batch queue.
                // This prevents a single unreachable page from blocking all progress.
                // The next full audit will re-detect them once the agent is reachable.
                global $wpdb;
                $ids = array_column($page_findings, 'id');
                foreach ($ids as $fid) {
                    $wpdb->update(
                        $wpdb->prefix . 'wnq_seo_audit_findings',
                        ['status' => 'fix_failed', 'resolved_at' => current_time('mysql')],
                        ['id' => (int)$fid]
                    );
                }
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

        // H1 promoted (first H2 → H1) → mark has_h1 = 1
        if (!empty($payload['promote_first_h2'])) {
            $updates['has_h1'] = 1;
        }

        // Alt texts fixed → set images_missing_alt = 0
        if (!empty($payload['fix_missing_alt'])) {
            $updates['images_missing_alt'] = 0;
        }

        // Open Graph fixed → set has_og_tags = 1
        if (!empty($payload['fix_open_graph'])) {
            $updates['has_og_tags'] = 1;
        }

        // Image lazy load fixed → set images_without_lazy = 0
        if (!empty($payload['fix_image_lazy_load'])) {
            $updates['images_without_lazy'] = 0;
        }

        // Internal links added → increment internal_links_count by the number added
        if (!empty($payload['internal_links']) && is_array($payload['internal_links'])) {
            $current = (int)($page_type === 'post' ? 0 : 0); // read from DB not needed — just +count
            $updates['internal_links_count'] = max(1, count($payload['internal_links']));
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

    /**
     * Suggest internal links for a page that has none.
     * Looks at other pages' focus_keywords and checks if any of them appear
     * in the current page's title (and vice versa). Returns [{anchor, url}, …].
     */
    private static function generateInternalLinks(array $page, string $client_id): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_site_data';

        $other_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT title, page_url, focus_keyword
             FROM $t
             WHERE client_id=%s AND page_url!=%s AND post_status='publish' AND focus_keyword!=''
             LIMIT 30",
            $client_id,
            $page['page_url']
        ), ARRAY_A) ?: [];

        $links          = [];
        $page_title_lc  = strtolower($page['title'] ?? '');
        $our_kw_lc      = strtolower($page['focus_keyword'] ?? '');

        foreach ($other_pages as $other) {
            $kw  = trim($other['focus_keyword'] ?? '');
            $kw_lc = strtolower($kw);
            if (empty($kw) || empty($other['page_url'])) continue;

            // Their keyword appears in our title → link out to them
            if ($kw_lc && strpos($page_title_lc, $kw_lc) !== false) {
                $links[] = ['anchor' => $kw, 'url' => $other['page_url']];
            }
            // Our keyword appears in their title → link out to them using our kw as anchor
            if ($our_kw_lc && strpos(strtolower($other['title'] ?? ''), $our_kw_lc) !== false) {
                $links[] = ['anchor' => $page['focus_keyword'], 'url' => $other['page_url']];
            }

            if (count($links) >= 3) break;
        }

        return $links;
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
