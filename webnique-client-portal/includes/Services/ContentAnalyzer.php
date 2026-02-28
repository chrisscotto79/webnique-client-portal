<?php
/**
 * ContentAnalyzer
 *
 * Provides: readability scoring (Flesch-Kincaid), duplicate title/meta detection,
 * keyword intent classification, and near-duplicate content flagging.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentAnalyzer
{
    // ── Readability (Flesch-Kincaid) ───────────────────────────────────────

    /**
     * Returns Flesch Reading Ease score (0–100, higher = easier).
     * 60–70 is ideal for general audiences.
     */
    public static function fleschReadingEase(string $text): float
    {
        $text = strip_tags($text);
        $text = preg_replace('/[^a-zA-Z0-9\s\.\!\?]/', '', $text);
        $text = trim($text);

        if (empty($text)) return 0.0;

        $sentences = max(1, preg_match_all('/[.!?]+/', $text, $m));
        $words_arr = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words     = max(1, count($words_arr));
        $syllables = 0;

        foreach ($words_arr as $word) {
            $syllables += self::countSyllables(strtolower($word));
        }

        $score = 206.835
            - 1.015  * ($words / $sentences)
            - 84.6   * ($syllables / $words);

        return round(max(0, min(100, $score)), 1);
    }

    public static function readabilityLabel(float $score): string
    {
        if ($score >= 70) return 'Easy';
        if ($score >= 60) return 'Standard';
        if ($score >= 50) return 'Fairly Difficult';
        if ($score >= 30) return 'Difficult';
        return 'Very Difficult';
    }

    private static function countSyllables(string $word): int
    {
        $word     = rtrim($word, 'e');
        $count    = preg_match_all('/[aeiouy]+/', $word, $m);
        return max(1, (int)$count);
    }

    // ── Duplicate Title/Meta Detection ────────────────────────────────────

    public static function findDuplicateTitles(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT title, GROUP_CONCAT(page_url SEPARATOR '|||') as urls, COUNT(*) as cnt
             FROM {$wpdb->prefix}wnq_seo_site_data
             WHERE client_id=%s AND title IS NOT NULL AND title != ''
             GROUP BY title HAVING cnt > 1
             ORDER BY cnt DESC LIMIT 50",
            $client_id
        ), ARRAY_A) ?: [];
    }

    public static function findDuplicateMeta(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT meta_description, GROUP_CONCAT(page_url SEPARATOR '|||') as urls, COUNT(*) as cnt
             FROM {$wpdb->prefix}wnq_seo_site_data
             WHERE client_id=%s AND meta_description IS NOT NULL AND meta_description != ''
             GROUP BY meta_description HAVING cnt > 1
             ORDER BY cnt DESC LIMIT 50",
            $client_id
        ), ARRAY_A) ?: [];
    }

    public static function findShortTitles(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT page_url, title, LENGTH(title) as title_len
             FROM {$wpdb->prefix}wnq_seo_site_data
             WHERE client_id=%s AND title IS NOT NULL AND LENGTH(title) BETWEEN 1 AND 20
             ORDER BY title_len ASC LIMIT 50",
            $client_id
        ), ARRAY_A) ?: [];
    }

    public static function findLongTitles(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT page_url, title, LENGTH(title) as title_len
             FROM {$wpdb->prefix}wnq_seo_site_data
             WHERE client_id=%s AND title IS NOT NULL AND LENGTH(title) > 60
             ORDER BY title_len DESC LIMIT 50",
            $client_id
        ), ARRAY_A) ?: [];
    }

    // ── Keyword Intent Classification ──────────────────────────────────────

    /**
     * Rule-based intent classification (no AI call needed for most keywords).
     * Returns: 'informational' | 'transactional' | 'navigational' | 'commercial'
     */
    public static function classifyIntent(string $keyword): string
    {
        $kw = strtolower(trim($keyword));

        $navigational = ['login', 'homepage', 'website', 'official', 'portal'];
        $informational = ['how', 'what', 'why', 'when', 'where', 'who', 'guide', 'tutorial',
                          'tips', 'ideas', 'examples', 'list', 'ways', 'steps', 'definition',
                          'meaning', 'vs', 'versus', 'difference', 'is it', 'can i', 'does'];
        $transactional = ['buy', 'order', 'purchase', 'shop', 'deal', 'discount', 'coupon',
                          'price', 'cheap', 'affordable', 'hire', 'book', 'schedule', 'quote',
                          'near me', 'free estimate', 'cost', 'service', 'company', 'contractor'];
        $commercial    = ['best', 'top', 'review', 'compare', 'comparison', 'alternative',
                          'vs ', 'rated', 'ranking'];

        foreach ($navigational  as $w) { if (str_contains($kw, $w)) return 'navigational'; }
        foreach ($transactional as $w) { if (str_contains($kw, $w)) return 'transactional'; }
        foreach ($informational as $w) { if (str_contains($kw, $w)) return 'informational'; }
        foreach ($commercial    as $w) { if (str_contains($kw, $w)) return 'commercial'; }

        return 'informational'; // default
    }

    public static function intentBadgeColor(string $intent): string
    {
        return match($intent) {
            'transactional' => '#059669',
            'commercial'    => '#0d539e',
            'informational' => '#6b7280',
            'navigational'  => '#d97706',
            default         => '#9ca3af',
        };
    }

    // ── Batch Classify & Update Keywords ──────────────────────────────────

    public static function classifyClientKeywords(string $client_id): int
    {
        global $wpdb;
        $keywords = $wpdb->get_results($wpdb->prepare(
            "SELECT id, keyword FROM {$wpdb->prefix}wnq_seo_keywords WHERE client_id=%s",
            $client_id
        ), ARRAY_A) ?: [];

        $updated = 0;
        foreach ($keywords as $kw) {
            $intent = self::classifyIntent($kw['keyword']);
            $wpdb->update(
                "{$wpdb->prefix}wnq_seo_keywords",
                ['intent' => $intent],
                ['id' => $kw['id']],
                ['%s'], ['%d']
            );
            $updated++;
        }
        return $updated;
    }

    // ── Full Content Audit for Client ──────────────────────────────────────

    public static function auditClient(string $client_id): array
    {
        return [
            'duplicate_titles'  => self::findDuplicateTitles($client_id),
            'duplicate_meta'    => self::findDuplicateMeta($client_id),
            'short_titles'      => self::findShortTitles($client_id),
            'long_titles'       => self::findLongTitles($client_id),
        ];
    }
}
