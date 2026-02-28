<?php
/**
 * CompetitorTracker
 *
 * Tracks competitor domains per client and compares keyword rankings.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class CompetitorTracker
{
    const TABLE = 'wnq_competitor_domains';

    // ── Table Creation ─────────────────────────────────────────────────────

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE . " (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            domain varchar(255) NOT NULL,
            label varchar(100) DEFAULT NULL,
            added_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_client_domain (client_id, domain(100))
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ── Competitor CRUD ────────────────────────────────────────────────────

    public static function saveCompetitors(string $client_id, array $domains): void
    {
        global $wpdb;
        $table = "{$wpdb->prefix}" . self::TABLE;

        // Remove existing, re-insert
        $wpdb->delete($table, ['client_id' => $client_id]);
        foreach ($domains as $entry) {
            $domain = sanitize_text_field($entry['domain'] ?? $entry);
            $label  = sanitize_text_field($entry['label'] ?? '');
            if (!empty($domain)) {
                $wpdb->replace($table, [
                    'client_id' => $client_id,
                    'domain'    => strtolower(trim($domain)),
                    'label'     => $label,
                ]);
            }
        }
    }

    public static function getCompetitors(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE client_id=%s ORDER BY id ASC",
            $client_id
        ), ARRAY_A) ?: [];
    }

    // ── Comparison Table ───────────────────────────────────────────────────

    /**
     * Returns a comparison of client keywords vs competitor keyword coverage.
     * Since we don't have competitor rank data directly, we compare:
     * - client's tracked keyword positions
     * - what % of keywords the client ranks in top 10 vs top 20 vs not ranking
     */
    public static function getComparison(string $client_id): array
    {
        $competitors = self::getCompetitors($client_id);
        $keywords    = \WNQ\Models\SEOHub::getKeywords($client_id);

        if (empty($keywords)) {
            return ['keywords' => [], 'competitors' => $competitors, 'summary' => []];
        }

        $client_summary = [
            'top_3'   => 0,
            'top_10'  => 0,
            'top_20'  => 0,
            'top_50'  => 0,
            'not_ranking' => 0,
        ];

        $kw_rows = [];
        foreach ($keywords as $kw) {
            $pos = $kw['current_position'] !== null ? (float)$kw['current_position'] : null;
            $row = [
                'keyword'          => $kw['keyword'],
                'cluster'          => $kw['cluster_name'],
                'client_position'  => $pos,
                'client_trend'     => self::trend($kw),
            ];
            if ($pos !== null) {
                if ($pos <= 3)  $client_summary['top_3']++;
                if ($pos <= 10) $client_summary['top_10']++;
                if ($pos <= 20) $client_summary['top_20']++;
                if ($pos <= 50) $client_summary['top_50']++;
                else            $client_summary['not_ranking']++;
            } else {
                $client_summary['not_ranking']++;
            }
            $kw_rows[] = $row;
        }

        return [
            'keywords'    => $kw_rows,
            'competitors' => $competitors,
            'summary'     => $client_summary,
            'totals'      => count($keywords),
        ];
    }

    private static function trend(array $kw): string
    {
        $cur  = $kw['current_position'];
        $prev = $kw['prev_position'];
        if ($cur === null) return '—';
        if ($prev === null) return 'new';
        $delta = (float)$prev - (float)$cur; // positive = improved
        if ($delta > 0) return '▲ +' . abs((int)$delta);
        if ($delta < 0) return '▼ ' . abs((int)$delta);
        return '=';
    }
}
