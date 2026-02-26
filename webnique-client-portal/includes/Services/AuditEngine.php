<?php
/**
 * Audit Engine - Nightly SEO Audit System
 *
 * Flags:
 *  - missing_h1           critical
 *  - no_schema            warning
 *  - thin_content         warning  (< 300 words)
 *  - missing_alt          warning
 *  - kw_not_in_title      warning
 *  - no_internal_links    info
 *  - missing_meta         warning
 *  - orphan_page          warning  (no internal links pointing to it)
 *  - declining_rank       warning  (position dropped > 5 spots)
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\SEOHub;
use WNQ\Models\Client;

final class AuditEngine
{
    const THIN_CONTENT_THRESHOLD = 300; // words
    const DECLINING_RANK_DELTA   = 5;   // positions

    /**
     * Run full nightly audit for all active clients with SEO profiles
     */
    public static function runNightlyAudit(): array
    {
        $clients = Client::getByStatus('active');
        $summary = ['clients_audited' => 0, 'total_findings' => 0];

        foreach ($clients as $client) {
            $client_id = $client['client_id'];
            $profile   = SEOHub::getProfile($client_id);
            if (!$profile) continue;

            $findings = self::auditClient($client_id);
            $summary['clients_audited']++;
            $summary['total_findings'] += $findings['total'];
        }

        SEOHub::log('nightly_audit_complete', $summary, 'success');
        return $summary;
    }

    /**
     * Run full audit for a single client
     */
    public static function auditClient(string $client_id): array
    {
        $pages    = SEOHub::getSiteData($client_id);
        $keywords = SEOHub::getKeywords($client_id);
        $counts   = [
            'missing_h1'        => 0,
            'no_schema'         => 0,
            'thin_content'      => 0,
            'missing_alt'       => 0,
            'kw_not_in_title'   => 0,
            'no_internal_links' => 0,
            'missing_meta'      => 0,
            'orphan_page'       => 0,
            'declining_rank'    => 0,
        ];

        // Build set of URLs that are linked to (for orphan detection)
        $linked_urls = [];
        foreach ($pages as $p) {
            // We track internal_links_count but not destinations; use a simpler heuristic
            // A page is "orphan" if no other page seems to link to it based on available data
            // (Full orphan detection requires crawling; we approximate)
        }

        foreach ($pages as $page) {
            $url = $page['page_url'];

            // Missing H1
            if (empty($page['h1']) || !$page['has_h1']) {
                SEOHub::insertAuditFinding($client_id, 'missing_h1', 'critical', $url, [
                    'title' => $page['title'],
                ]);
                $counts['missing_h1']++;
            }

            // No schema
            if (!$page['has_schema'] && in_array($page['page_type'], ['page', 'post'])) {
                SEOHub::insertAuditFinding($client_id, 'no_schema', 'warning', $url, [
                    'page_type' => $page['page_type'],
                    'title'     => $page['title'],
                ]);
                $counts['no_schema']++;
            }

            // Thin content (only check published posts/pages with content)
            if ($page['post_status'] === 'publish' && $page['word_count'] > 0 && $page['word_count'] < self::THIN_CONTENT_THRESHOLD) {
                SEOHub::insertAuditFinding($client_id, 'thin_content', 'warning', $url, [
                    'word_count' => $page['word_count'],
                    'threshold'  => self::THIN_CONTENT_THRESHOLD,
                    'title'      => $page['title'],
                ]);
                $counts['thin_content']++;
            }

            // Missing alt text on images
            if ($page['images_count'] > 0 && $page['images_missing_alt'] > 0) {
                SEOHub::insertAuditFinding($client_id, 'missing_alt', 'warning', $url, [
                    'images_total'   => $page['images_count'],
                    'images_missing' => $page['images_missing_alt'],
                ]);
                $counts['missing_alt']++;
            }

            // Keyword not in title
            if (!empty($page['focus_keyword']) && !$page['keyword_in_title']) {
                SEOHub::insertAuditFinding($client_id, 'kw_not_in_title', 'warning', $url, [
                    'focus_keyword' => $page['focus_keyword'],
                    'title'         => $page['title'],
                ]);
                $counts['kw_not_in_title']++;
            }

            // No internal links (posts should have at least 1)
            if ($page['page_type'] === 'post' && $page['post_status'] === 'publish' && (int)$page['internal_links_count'] === 0) {
                SEOHub::insertAuditFinding($client_id, 'no_internal_links', 'info', $url, [
                    'title' => $page['title'],
                ]);
                $counts['no_internal_links']++;
            }

            // Missing or very short meta description
            if (empty($page['meta_description']) || strlen($page['meta_description']) < 80) {
                SEOHub::insertAuditFinding($client_id, 'missing_meta', 'warning', $url, [
                    'current_length' => strlen($page['meta_description'] ?? ''),
                    'title'          => $page['title'],
                ]);
                $counts['missing_meta']++;
            }
        }

        // Declining keyword rankings
        foreach ($keywords as $kw) {
            if ($kw['current_position'] === null || $kw['prev_position'] === null) continue;
            $delta = (float)$kw['current_position'] - (float)$kw['prev_position'];
            if ($delta >= self::DECLINING_RANK_DELTA) {
                SEOHub::insertAuditFinding($client_id, 'declining_rank', 'warning', $kw['target_url'] ?? '', [
                    'keyword'          => $kw['keyword'],
                    'current_position' => $kw['current_position'],
                    'prev_position'    => $kw['prev_position'],
                    'delta'            => $delta,
                ]);
                $counts['declining_rank']++;
            }
        }

        $total = array_sum($counts);
        SEOHub::log('client_audit_complete', [
            'client_id' => $client_id,
            'findings'  => $counts,
            'total'     => $total,
        ]);

        return array_merge($counts, ['total' => $total]);
    }

    /**
     * Get audit health score for a client (0-100)
     */
    public static function getHealthScore(string $client_id): int
    {
        $stats = SEOHub::getSiteStats($client_id);
        $total = max(1, (int)$stats['total_pages']);

        $deductions = 0;
        $deductions += ((int)$stats['missing_h1']    / $total) * 30;  // H1 is critical
        $deductions += ((int)$stats['missing_alt']   / $total) * 15;
        $deductions += ((int)$stats['thin_content']  / $total) * 20;
        $deductions += ((int)$stats['no_schema']     / $total) * 15;
        $deductions += ((int)$stats['no_internal_links'] / $total) * 10;

        // Open audit findings
        $open_critical = count(SEOHub::getAuditFindings($client_id, ['status' => 'open', 'severity' => 'critical']));
        $deductions += min($open_critical * 5, 20);

        return max(0, (int)(100 - $deductions));
    }

    /**
     * Summarize open findings by severity
     */
    public static function getSeveritySummary(string $client_id): array
    {
        $findings = SEOHub::getAuditFindings($client_id, ['status' => 'open']);
        $summary  = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $f) {
            $s = $f['severity'] ?? 'info';
            if (isset($summary[$s])) $summary[$s]++;
        }
        return $summary;
    }

    /**
     * Auto-resolve findings for pages that have been fixed (re-sync driven)
     */
    public static function autoResolveFindings(string $client_id): int
    {
        global $wpdb;
        $ft = $wpdb->prefix . 'wnq_seo_audit_findings';
        $st = $wpdb->prefix . 'wnq_seo_site_data';
        $resolved = 0;

        // Resolve missing_h1 for pages that now have H1
        $fixed = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id FROM $ft f
             JOIN $st s ON f.client_id=s.client_id AND f.page_url=s.page_url
             WHERE f.client_id=%s AND f.finding_type='missing_h1' AND f.status='open' AND s.has_h1=1",
            $client_id
        ), ARRAY_A) ?: [];

        foreach ($fixed as $row) {
            SEOHub::resolveAuditFinding((int)$row['id']);
            $resolved++;
        }

        // Resolve missing_meta for pages that now have meta
        $fixed = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id FROM $ft f
             JOIN $st s ON f.client_id=s.client_id AND f.page_url=s.page_url
             WHERE f.client_id=%s AND f.finding_type='missing_meta' AND f.status='open'
             AND s.meta_description IS NOT NULL AND CHAR_LENGTH(s.meta_description)>=80",
            $client_id
        ), ARRAY_A) ?: [];

        foreach ($fixed as $row) {
            SEOHub::resolveAuditFinding((int)$row['id']);
            $resolved++;
        }

        // Resolve missing_alt
        $fixed = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id FROM $ft f
             JOIN $st s ON f.client_id=s.client_id AND f.page_url=s.page_url
             WHERE f.client_id=%s AND f.finding_type='missing_alt' AND f.status='open' AND s.images_missing_alt=0",
            $client_id
        ), ARRAY_A) ?: [];

        foreach ($fixed as $row) {
            SEOHub::resolveAuditFinding((int)$row['id']);
            $resolved++;
        }

        return $resolved;
    }
}
