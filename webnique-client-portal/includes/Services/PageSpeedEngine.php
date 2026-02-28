<?php
/**
 * PageSpeedEngine — Google PageSpeed Insights / Core Web Vitals
 *
 * Calls the PageSpeed Insights API to fetch performance scores and
 * Core Web Vitals (LCP, CLS, FID/INP, FCP, TTFB) for client pages.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class PageSpeedEngine
{
    const API_BASE     = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    const CACHE_HOURS  = 24;
    const TABLE        = 'wnq_pagespeed_results';

    // ── Table Creation ─────────────────────────────────────────────────────

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE . " (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            url varchar(2083) NOT NULL,
            strategy varchar(10) NOT NULL DEFAULT 'mobile',
            performance_score tinyint DEFAULT NULL,
            seo_score tinyint DEFAULT NULL,
            accessibility_score tinyint DEFAULT NULL,
            best_practices_score tinyint DEFAULT NULL,
            lcp_ms int DEFAULT NULL,
            fid_ms int DEFAULT NULL,
            cls_score decimal(5,3) DEFAULT NULL,
            fcp_ms int DEFAULT NULL,
            ttfb_ms int DEFAULT NULL,
            inp_ms int DEFAULT NULL,
            raw_data longtext DEFAULT NULL,
            checked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_url_strategy (url(100), strategy),
            KEY idx_checked_at (checked_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ── API Key ────────────────────────────────────────────────────────────

    private static function getApiKey(): string
    {
        $settings = get_option('wnq_ai_settings', []);
        return $settings['psi_api_key'] ?? '';
    }

    // ── Analyze Single URL ─────────────────────────────────────────────────

    public static function analyze(string $url, string $strategy = 'mobile'): array
    {
        $cache_key = 'wnq_psi_' . md5($url . $strategy);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $api_url = add_query_arg([
            'url'      => urlencode($url),
            'strategy' => $strategy,
            'category' => ['performance', 'seo', 'accessibility', 'best-practices'],
        ], self::API_BASE);

        $api_key = self::getApiKey();
        if ($api_key) {
            $api_url = add_query_arg('key', $api_key, $api_url);
        }

        // Rebuild multi-value category param correctly
        $base   = self::API_BASE . '?url=' . urlencode($url) . '&strategy=' . $strategy;
        $base  .= '&category=performance&category=seo&category=accessibility&category=best-practices';
        if ($api_key) $base .= '&key=' . $api_key;

        $response = wp_remote_get($base, ['timeout' => 30]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['error' => "PSI API returned HTTP $code"];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['lighthouseResult'])) {
            return ['error' => 'Invalid PSI response'];
        }

        $lhr        = $body['lighthouseResult'];
        $categories = $lhr['categories'] ?? [];
        $audits     = $lhr['audits'] ?? [];

        $result = [
            'url'                 => $url,
            'strategy'            => $strategy,
            'performance_score'   => isset($categories['performance']['score']) ? (int)round($categories['performance']['score'] * 100) : null,
            'seo_score'           => isset($categories['seo']['score']) ? (int)round($categories['seo']['score'] * 100) : null,
            'accessibility_score' => isset($categories['accessibility']['score']) ? (int)round($categories['accessibility']['score'] * 100) : null,
            'best_practices_score'=> isset($categories['best-practices']['score']) ? (int)round($categories['best-practices']['score'] * 100) : null,
            'lcp_ms'              => self::extractMs($audits, 'largest-contentful-paint'),
            'fid_ms'              => self::extractMs($audits, 'max-potential-fid'),
            'cls_score'           => self::extractNumeric($audits, 'cumulative-layout-shift'),
            'fcp_ms'              => self::extractMs($audits, 'first-contentful-paint'),
            'ttfb_ms'             => self::extractMs($audits, 'server-response-time'),
            'inp_ms'              => self::extractMs($audits, 'interaction-to-next-paint'),
            'opportunities'       => self::extractOpportunities($audits),
        ];

        set_transient($cache_key, $result, self::CACHE_HOURS * HOUR_IN_SECONDS);
        return $result;
    }

    // ── Store Result ───────────────────────────────────────────────────────

    public static function storeResult(string $client_id, array $result): void
    {
        global $wpdb;
        $table = "{$wpdb->prefix}" . self::TABLE;

        // Keep last 3 results per URL/strategy
        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE client_id=%s AND url=%s AND strategy=%s",
            $client_id, $result['url'], $result['strategy']
        ));
        if ($count >= 3) {
            $oldest = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE client_id=%s AND url=%s AND strategy=%s ORDER BY checked_at ASC LIMIT 1",
                $client_id, $result['url'], $result['strategy']
            ));
            if ($oldest) $wpdb->delete($table, ['id' => $oldest]);
        }

        $opps = $result['opportunities'] ?? [];
        unset($result['opportunities']);

        $wpdb->insert($table, [
            'client_id'            => $client_id,
            'url'                  => $result['url'],
            'strategy'             => $result['strategy'],
            'performance_score'    => $result['performance_score'],
            'seo_score'            => $result['seo_score'],
            'accessibility_score'  => $result['accessibility_score'],
            'best_practices_score' => $result['best_practices_score'],
            'lcp_ms'               => $result['lcp_ms'],
            'fid_ms'               => $result['fid_ms'],
            'cls_score'            => $result['cls_score'],
            'fcp_ms'               => $result['fcp_ms'],
            'ttfb_ms'              => $result['ttfb_ms'],
            'inp_ms'               => $result['inp_ms'],
            'raw_data'             => wp_json_encode($opps),
        ]);
    }

    // ── Batch Analyze Client Pages ─────────────────────────────────────────

    public static function analyzeClientPages(string $client_id, int $limit = 5): array
    {
        global $wpdb;

        // Get top pages (homepage + highest-traffic pages from site data)
        $profile = \WNQ\Models\SEOHub::getProfile($client_id);
        $keys     = \WNQ\Models\SEOHub::getAgentKeys($client_id);
        $site_url = '';
        foreach ($keys as $k) {
            if ($k['status'] === 'active') {
                $site_url = $k['site_url'];
                break;
            }
        }
        if (!$site_url) return ['error' => 'No active agent key found for client'];

        // Get pages with most internal links (likely important pages)
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT page_url FROM {$wpdb->prefix}wnq_seo_site_data
             WHERE client_id=%s ORDER BY internal_links_count DESC LIMIT %d",
            $client_id, $limit
        ), ARRAY_A) ?: [];

        // Always include homepage
        $urls = [$site_url];
        foreach ($pages as $p) {
            if (!in_array($p['page_url'], $urls)) $urls[] = $p['page_url'];
        }
        $urls = array_slice($urls, 0, $limit);

        $results = [];
        foreach ($urls as $url) {
            $r = self::analyze($url, 'mobile');
            if (!isset($r['error'])) {
                self::storeResult($client_id, $r);
                $results[] = $r;
            }
        }
        return $results;
    }

    // ── Get Stored Results ─────────────────────────────────────────────────

    public static function getResults(string $client_id, int $limit = 20): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE . "
             WHERE client_id=%s ORDER BY checked_at DESC LIMIT %d",
            $client_id, $limit
        ), ARRAY_A) ?: [];
    }

    public static function getLatestByUrl(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.* FROM {$wpdb->prefix}" . self::TABLE . " r
             INNER JOIN (
                SELECT url, strategy, MAX(checked_at) as max_at
                FROM {$wpdb->prefix}" . self::TABLE . "
                WHERE client_id=%s GROUP BY url, strategy
             ) latest ON r.url=latest.url AND r.strategy=latest.strategy AND r.checked_at=latest.max_at
             WHERE r.client_id=%s ORDER BY r.performance_score ASC LIMIT 50",
            $client_id, $client_id
        ), ARRAY_A) ?: [];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function extractMs(array $audits, string $key): ?int
    {
        $val = $audits[$key]['numericValue'] ?? null;
        return $val !== null ? (int)round((float)$val) : null;
    }

    private static function extractNumeric(array $audits, string $key): ?float
    {
        $val = $audits[$key]['numericValue'] ?? null;
        return $val !== null ? round((float)$val, 3) : null;
    }

    private static function extractOpportunities(array $audits): array
    {
        $opps = [];
        foreach ($audits as $key => $audit) {
            if (($audit['score'] ?? 1) < 0.9 && isset($audit['title']) && isset($audit['description'])) {
                $opps[] = [
                    'id'          => $key,
                    'title'       => $audit['title'],
                    'description' => $audit['description'],
                    'score'       => $audit['score'] ?? null,
                    'savings_ms'  => $audit['details']['overallSavingsMs'] ?? null,
                ];
            }
        }
        usort($opps, fn($a, $b) => ($b['savings_ms'] ?? 0) <=> ($a['savings_ms'] ?? 0));
        return array_slice($opps, 0, 10);
    }

    // ── CWV Grade ─────────────────────────────────────────────────────────

    public static function cwvGrade(array $result): string
    {
        $score = $result['performance_score'] ?? null;
        if ($score === null) return 'N/A';
        if ($score >= 90) return 'Good';
        if ($score >= 50) return 'Needs Improvement';
        return 'Poor';
    }

    public static function lcpGrade(?int $lcp_ms): string
    {
        if ($lcp_ms === null) return '—';
        if ($lcp_ms <= 2500) return 'Good';
        if ($lcp_ms <= 4000) return 'Needs Improvement';
        return 'Poor';
    }

    public static function clsGrade(?float $cls): string
    {
        if ($cls === null) return '—';
        if ($cls <= 0.1) return 'Good';
        if ($cls <= 0.25) return 'Needs Improvement';
        return 'Poor';
    }
}
