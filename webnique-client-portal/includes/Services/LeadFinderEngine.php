<?php
/**
 * Lead Finder Engine
 *
 * Orchestrates the prospect-discovery pipeline using manual URL input.
 * The two-phase approach keeps individual AJAX calls short so PHP never times out:
 *
 * Phase 1 – queueManualSearch()
 *   Parses and validates the URL list, stores candidates in a transient, and
 *   returns immediately with a batch_id.
 *
 * Phase 2 – processNextManual()
 *   Called once per URL by the browser (looped via JS).
 *   Each call processes exactly one URL:
 *     1. Dedup check by URL hash
 *     2. Homepage fetch
 *     3. Business name extraction (from page title)
 *     4. Franchise detection
 *     5. SEO scoring
 *     6. Email extraction
 *     7. Social media extraction
 *     8. DB insert
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\Lead;

final class LeadFinderEngine
{
    private const QUEUE_TTL = DAY_IN_SECONDS;

    // ── Phase 1: Queue ───────────────────────────────────────────────────────

    /**
     * Parse the raw URL list (one per line) and store candidates in a transient.
     * Returns immediately with a batch_id — no web-crawling happens here.
     *
     * Supported formats (one per line):
     *   https://example.com
     *   Business Name | https://example.com
     *
     * @param array{urls: string, industry: string} $params
     * @return array{ok: bool, batch_id?: string, total?: int, error?: string}
     */
    public static function queueManualSearch(array $params): array
    {
        $raw      = $params['urls']     ?? '';
        $industry = sanitize_text_field($params['industry'] ?? '');

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $raw))
        ));

        $candidates = [];
        foreach ($lines as $line) {
            $name = '';
            $url  = $line;
            if (strpos($line, '|') !== false) {
                [$name, $url] = array_map('trim', explode('|', $line, 2));
            }
            $url = esc_url_raw($url);
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue;
            $candidates[] = [
                'url'      => $url,
                'name'     => sanitize_text_field($name),
                'industry' => $industry,
            ];
        }

        if (empty($candidates)) {
            return ['ok' => false, 'error' => 'No valid URLs found — make sure each line is a full URL (https://…)'];
        }

        $batch_id = wp_generate_uuid4();
        set_transient('wnq_lead_manual_queue_' . $batch_id, [
            'candidates' => $candidates,
            'next_index' => 0,
            'stats'      => self::emptyStats(count($candidates)),
        ], self::QUEUE_TTL);

        return ['ok' => true, 'batch_id' => $batch_id, 'total' => count($candidates)];
    }

    // ── Phase 2: Process one URL ─────────────────────────────────────────────

    /**
     * Process the next unprocessed URL from a queued batch.
     * One AJAX call per URL — no PHP timeout risk.
     *
     * @param string $batch_id   Returned by queueManualSearch()
     * @param array  $filter_params min_seo_score
     * @return array{ok: bool, done: bool, progress: int, total: int, stats: array, error?: string}
     */
    public static function processNextManual(string $batch_id, array $filter_params): array
    {
        $queue = get_transient('wnq_lead_manual_queue_' . $batch_id);
        if (!$queue) {
            return ['ok' => false, 'done' => true, 'error' => 'Batch not found or expired'];
        }

        $candidates = $queue['candidates'];
        $next       = (int)$queue['next_index'];
        $total      = count($candidates);
        $stats      = $queue['stats'];

        if ($next >= $total) {
            delete_transient('wnq_lead_manual_queue_' . $batch_id);
            return ['ok' => true, 'done' => true, 'progress' => $total, 'total' => $total, 'stats' => $stats];
        }

        // Advance transient index BEFORE processing to prevent infinite loops if
        // a site causes an abort/timeout — next call will skip to the next URL.
        $done = ($next + 1 >= $total);
        $queue['next_index'] = $next + 1;
        if ($done) {
            delete_transient('wnq_lead_manual_queue_' . $batch_id);
        } else {
            set_transient('wnq_lead_manual_queue_' . $batch_id, $queue, self::QUEUE_TTL);
        }

        try {
            $outcome = self::processSingleUrl(
                $candidates[$next]['url'],
                $candidates[$next]['name'],
                $candidates[$next]['industry'],
                $filter_params
            );
        } catch (\Throwable $e) {
            $outcome = 'low_seo';
            error_log(sprintf(
                'WNQ Lead Finder: candidate %d/%d threw %s: %s (in %s:%d)',
                $next + 1, $total,
                get_class($e), $e->getMessage(),
                $e->getFile(), $e->getLine()
            ));
        }

        $stats = self::updateStats($stats, $outcome);

        if (!$done) {
            $q = get_transient('wnq_lead_manual_queue_' . $batch_id);
            if ($q) {
                $q['stats'] = $stats;
                set_transient('wnq_lead_manual_queue_' . $batch_id, $q, self::QUEUE_TTL);
            }
        }

        return [
            'ok'       => true,
            'done'     => $done,
            'progress' => $next + 1,
            'total'    => $total,
            'stats'    => $stats,
        ];
    }

    // ── Core Processing Logic ────────────────────────────────────────────────

    /**
     * Process a single URL through the full qualification and enrichment pipeline.
     *
     * @return string One of: duplicate | no_website | franchise | low_seo | saved
     */
    private static function processSingleUrl(
        string $url,
        string $business_name,
        string $industry,
        array $filter_params
    ): string {
        $min_seo = max(0, (int)($filter_params['min_seo_score'] ?? 2));

        // Dedup: use md5(url) as place_id so the UNIQUE KEY catches repeated submissions
        $url_key = md5($url);
        if (Lead::findByPlaceId($url_key)) {
            return 'duplicate';
        }

        // Fetch homepage HTML (reused by all enrichers — one HTTP request)
        $homepage_html = self::fetchHtml($url);
        if (!$homepage_html) {
            return 'no_website';
        }

        // Extract business name from <title> if not provided
        if (empty($business_name)) {
            if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $homepage_html, $m)) {
                $business_name = sanitize_text_field(html_entity_decode(trim($m[1])));
            }
            if (empty($business_name)) {
                $business_name = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
            }
        }

        // Franchise detection (name + homepage content)
        if (LeadEnrichmentService::isFranchise($business_name, $homepage_html)) {
            return 'franchise';
        }

        // SEO scoring
        $seo = LeadSEOScorer::scoreWebsiteFromHtml($homepage_html);
        if (!$seo['ok'] || $seo['score'] < $min_seo) {
            return 'low_seo';
        }

        // Enrichment
        $email_data = LeadEmailExtractor::extractEmail($url, $homepage_html);
        $social     = LeadEnrichmentService::extractSocialMedia($url, $homepage_html);

        $insert_id = Lead::insert([
            'place_id'         => $url_key,
            'business_name'    => sanitize_text_field($business_name),
            'industry'         => sanitize_text_field($industry),
            'owner_first'      => '',
            'owner_last'       => '',
            'website'          => esc_url_raw($url),
            'address'          => '',
            'city'             => '',
            'state'            => '',
            'zip'              => '',
            'phone'            => '',
            'email'            => sanitize_email($email_data['email']),
            'email_source'     => $email_data['source'] ? esc_url_raw($email_data['source']) : '',
            'rating'           => 0,
            'review_count'     => 0,
            'social_facebook'  => $social['facebook']  ? esc_url_raw($social['facebook'])  : '',
            'social_instagram' => $social['instagram'] ? esc_url_raw($social['instagram']) : '',
            'social_linkedin'  => $social['linkedin']  ? esc_url_raw($social['linkedin'])  : '',
            'social_twitter'   => $social['twitter']   ? esc_url_raw($social['twitter'])   : '',
            'social_youtube'   => $social['youtube']   ? esc_url_raw($social['youtube'])   : '',
            'social_tiktok'    => $social['tiktok']    ? esc_url_raw($social['tiktok'])    : '',
            'seo_score'        => (int)$seo['score'],
            'seo_issues'       => $seo['issues'],
            'status'           => 'new',
        ]);

        if (!$insert_id) {
            global $wpdb;
            error_log('WNQ Lead Finder: DB insert failed for "' . $business_name . '" — ' . $wpdb->last_error);
            return 'low_seo';
        }

        return 'saved';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function fetchHtml(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout'             => 8,
            'user-agent'          => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'           => false,
            'redirection'         => 3,
            'limit_response_size' => 512000, // 500 KB
        ]);

        if (is_wp_error($response)) return '';
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return '';

        return wp_remote_retrieve_body($response);
    }

    private static function emptyStats(int $found = 0): array
    {
        return [
            'found'       => $found,
            'franchise'   => 0,
            'duplicate'   => 0,
            'no_website'  => 0,
            'low_seo'     => 0,
            'saved'       => 0,
        ];
    }

    private static function updateStats(array $stats, string $outcome): array
    {
        if ($outcome === 'franchise')  { $stats['franchise']++;  }
        if ($outcome === 'duplicate')  { $stats['duplicate']++;  }
        if ($outcome === 'no_website') { $stats['no_website']++; }
        if ($outcome === 'low_seo')    { $stats['low_seo']++;    }
        if ($outcome === 'saved')      { $stats['saved']++;      }
        return $stats;
    }
}
