<?php
/**
 * Lead Model
 *
 * Manages the wp_wnq_leads database table used by the Lead Finder system.
 * Stores qualified business prospects discovered via Google Places API.
 *
 * @package WebNique Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class Lead
{
    // ── Table Management ────────────────────────────────────────────────────

    public static function createTable(): void
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'wnq_leads';
        $charset = $wpdb->get_charset_collate();

        // dbDelta() handles both CREATE and ALTER (adding missing columns)
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            place_id         VARCHAR(255)     NOT NULL DEFAULT '',
            business_name    VARCHAR(255)     NOT NULL DEFAULT '',
            industry         VARCHAR(150)     NOT NULL DEFAULT '',
            owner_first      VARCHAR(100)     NOT NULL DEFAULT '',
            owner_last       VARCHAR(100)     NOT NULL DEFAULT '',
            website          VARCHAR(500)     NOT NULL DEFAULT '',
            address          VARCHAR(500)     NOT NULL DEFAULT '',
            city             VARCHAR(150)     NOT NULL DEFAULT '',
            state            VARCHAR(50)      NOT NULL DEFAULT '',
            zip              VARCHAR(20)      NOT NULL DEFAULT '',
            phone            VARCHAR(50)      NOT NULL DEFAULT '',
            email            VARCHAR(255)     NOT NULL DEFAULT '',
            email_source     VARCHAR(500)     NOT NULL DEFAULT '',
            rating           DECIMAL(3,1)     NOT NULL DEFAULT 0,
            review_count     INT UNSIGNED     NOT NULL DEFAULT 0,
            social_facebook  VARCHAR(500)     NOT NULL DEFAULT '',
            social_instagram VARCHAR(500)     NOT NULL DEFAULT '',
            social_linkedin  VARCHAR(500)     NOT NULL DEFAULT '',
            social_twitter   VARCHAR(500)     NOT NULL DEFAULT '',
            social_youtube   VARCHAR(500)     NOT NULL DEFAULT '',
            social_tiktok    VARCHAR(500)     NOT NULL DEFAULT '',
            seo_score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            seo_issues       TEXT,
            status           VARCHAR(20)      NOT NULL DEFAULT 'new',
            notes            TEXT,
            scraped_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            contacted_at     DATETIME         DEFAULT NULL,
            exported_at      DATETIME         DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   place_id (place_id),
            KEY          industry_city (industry(50), city(50)),
            KEY          status (status),
            KEY          seo_score (seo_score)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ── Migration ───────────────────────────────────────────────────────────

    /**
     * Returns true if the table is missing any columns added after v1.
     */
    public static function tableNeedsMigration(): bool
    {
        global $wpdb;
        $cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}wnq_leads", 0);
        if (empty($cols)) return false; // table doesn't exist yet
        return !in_array('owner_first', $cols, true)
            || !in_array('exported_at', $cols, true);
    }

    /**
     * ALTER TABLE to add any v2 columns that are missing on the existing table.
     */
    public static function runMigration(): void
    {
        global $wpdb;
        $table    = $wpdb->prefix . 'wnq_leads';
        $existing = $wpdb->get_col("DESCRIBE {$table}", 0);

        $columns = [
            'owner_first'      => "VARCHAR(100) NOT NULL DEFAULT '' AFTER industry",
            'owner_last'       => "VARCHAR(100) NOT NULL DEFAULT '' AFTER owner_first",
            'state'            => "VARCHAR(50)  NOT NULL DEFAULT '' AFTER city",
            'zip'              => "VARCHAR(20)  NOT NULL DEFAULT '' AFTER state",
            'social_facebook'  => "VARCHAR(500) NOT NULL DEFAULT '' AFTER review_count",
            'social_instagram' => "VARCHAR(500) NOT NULL DEFAULT '' AFTER social_facebook",
            'social_linkedin'  => "VARCHAR(500) NOT NULL DEFAULT '' AFTER social_instagram",
            'social_twitter'   => "VARCHAR(500) NOT NULL DEFAULT '' AFTER social_linkedin",
            'social_youtube'   => "VARCHAR(500) NOT NULL DEFAULT '' AFTER social_twitter",
            'social_tiktok'    => "VARCHAR(500) NOT NULL DEFAULT '' AFTER social_youtube",
            'exported_at'      => "DATETIME DEFAULT NULL AFTER contacted_at",
        ];

        foreach ($columns as $col => $definition) {
            if (!in_array($col, $existing, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
            }
        }
    }

    // ── Write Operations ────────────────────────────────────────────────────

    public static function insert(array $data): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wnq_leads',
            [
                'place_id'         => $data['place_id']         ?? '',
                'business_name'    => $data['business_name']    ?? '',
                'industry'         => $data['industry']         ?? '',
                'owner_first'      => $data['owner_first']      ?? '',
                'owner_last'       => $data['owner_last']       ?? '',
                'website'          => $data['website']          ?? '',
                'address'          => $data['address']          ?? '',
                'city'             => $data['city']             ?? '',
                'state'            => $data['state']            ?? '',
                'zip'              => $data['zip']              ?? '',
                'phone'            => $data['phone']            ?? '',
                'email'            => $data['email']            ?? '',
                'email_source'     => $data['email_source']     ?? '',
                'rating'           => (float)($data['rating']        ?? 0),
                'review_count'     => (int)($data['review_count']    ?? 0),
                'social_facebook'  => $data['social_facebook']  ?? '',
                'social_instagram' => $data['social_instagram'] ?? '',
                'social_linkedin'  => $data['social_linkedin']  ?? '',
                'social_twitter'   => $data['social_twitter']   ?? '',
                'social_youtube'   => $data['social_youtube']   ?? '',
                'social_tiktok'    => $data['social_tiktok']    ?? '',
                'seo_score'        => (int)($data['seo_score']  ?? 0),
                'seo_issues'       => wp_json_encode($data['seo_issues'] ?? []),
                'status'           => $data['status']           ?? 'new',
                'notes'            => $data['notes']            ?? '',
                'scraped_at'       => current_time('mysql'),
            ]
        );
        return (int)$wpdb->insert_id;
    }

    public static function updateStatus(int $id, string $status, string $notes = ''): void
    {
        global $wpdb;
        $data = ['status' => $status];
        if ($status === 'contacted') {
            $data['contacted_at'] = current_time('mysql');
        }
        if ($notes !== '') {
            $data['notes'] = $notes;
        }
        $wpdb->update($wpdb->prefix . 'wnq_leads', $data, ['id' => $id]);
    }

    public static function updateNotes(int $id, string $notes): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'wnq_leads', ['notes' => $notes], ['id' => $id]);
    }

    public static function updateEmail(int $id, string $email, string $source): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wnq_leads',
            ['email' => $email, 'email_source' => $source],
            ['id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wnq_leads', ['id' => $id]);
    }

    /**
     * Update the status of multiple leads in a single query.
     * Also stamps contacted_at for status='contacted'.
     *
     * @param int[]  $ids
     * @param string $status  One of: new | contacted | qualified | closed
     */
    public static function bulkUpdateStatus(array $ids, string $status): void
    {
        global $wpdb;
        if (empty($ids)) return;
        if (!in_array($status, ['new', 'contacted', 'qualified', 'closed'], true)) return;

        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);

        if ($status === 'contacted') {
            $now = current_time('mysql');
            $wpdb->query(
                "UPDATE {$wpdb->prefix}wnq_leads
                 SET status = '{$status}', contacted_at = '{$now}'
                 WHERE id IN ({$in})"
            );
        } else {
            $wpdb->query(
                "UPDATE {$wpdb->prefix}wnq_leads
                 SET status = '{$status}'
                 WHERE id IN ({$in})"
            );
        }
    }

    /**
     * Delete multiple leads in a single query.
     *
     * @param int[] $ids
     */
    public static function bulkDelete(array $ids): void
    {
        global $wpdb;
        if (empty($ids)) return;
        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);
        $wpdb->query("DELETE FROM {$wpdb->prefix}wnq_leads WHERE id IN ({$in})");
    }

    /**
     * Stamp a batch of lead IDs as exported (sets exported_at to now).
     * Only stamps leads that haven't been exported yet so the first-export date is preserved.
     *
     * @param int[] $ids
     */
    public static function markExported(array $ids): void
    {
        global $wpdb;
        if (empty($ids)) return;
        $ids   = array_map('intval', $ids);
        $in    = implode(',', $ids);
        $now   = current_time('mysql');
        $wpdb->query(
            "UPDATE {$wpdb->prefix}wnq_leads
             SET exported_at = '{$now}'
             WHERE id IN ({$in}) AND exported_at IS NULL"
        );
    }

    // ── Read Operations ─────────────────────────────────────────────────────

    public static function findByPlaceId(string $place_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_leads WHERE place_id = %s",
                $place_id
            ),
            ARRAY_A
        );
        if (!$row) return null;
        $row['seo_issues'] = json_decode($row['seo_issues'] ?? '[]', true) ?: [];
        return $row;
    }

    /**
     * Secondary dedup: check if a business with the same name in the same city already exists.
     */
    public static function existsByNameAndCity(string $business_name, string $city): bool
    {
        global $wpdb;
        return (bool)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wnq_leads WHERE business_name = %s AND city = %s LIMIT 1",
                $business_name,
                $city
            )
        );
    }

    public static function getAll(array $args = []): array
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'wnq_leads';
        $where  = ['1=1'];
        $params = [];

        if (!empty($args['industry'])) {
            $where[]  = 'industry = %s';
            $params[] = $args['industry'];
        }
        if (!empty($args['city'])) {
            $where[]  = 'city = %s';
            $params[] = $args['city'];
        }
        if (!empty($args['state'])) {
            $where[]  = 'state = %s';
            $params[] = $args['state'];
        }
        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if (isset($args['min_seo_score'])) {
            $where[]  = 'seo_score >= %d';
            $params[] = (int)$args['min_seo_score'];
        }
        if (!empty($args['has_email'])) {
            $where[] = "email != ''";
        }
        if (!empty($args['not_exported'])) {
            $where[] = "exported_at IS NULL";
        }

        $allowed_orderby = ['id', 'review_count', 'seo_score', 'rating', 'business_name', 'scraped_at', 'city', 'state', 'industry'];
        $orderby = in_array($args['orderby'] ?? '', $allowed_orderby, true) ? $args['orderby'] : 'scraped_at';
        $order   = ($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit   = max(1, (int)($args['limit']  ?? 50));
        $offset  = max(0, (int)($args['offset'] ?? 0));

        $where_sql = implode(' AND ', $where);
        $params[]  = $limit;
        $params[]  = $offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $params
            ),
            ARRAY_A
        ) ?: [];

        foreach ($rows as &$row) {
            $row['seo_issues'] = json_decode($row['seo_issues'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    public static function count(array $args = []): int
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'wnq_leads';
        $where  = ['1=1'];
        $params = [];

        if (!empty($args['industry'])) { $where[] = 'industry = %s'; $params[] = $args['industry']; }
        if (!empty($args['city']))     { $where[] = 'city = %s';     $params[] = $args['city']; }
        if (!empty($args['state']))    { $where[] = 'state = %s';    $params[] = $args['state']; }
        if (!empty($args['status']))   { $where[] = 'status = %s';   $params[] = $args['status']; }
        if (isset($args['min_seo_score'])) { $where[] = 'seo_score >= %d'; $params[] = (int)$args['min_seo_score']; }
        if (!empty($args['has_email'])) { $where[] = "email != ''"; }
        if (!empty($args['not_exported'])) { $where[] = "exported_at IS NULL"; }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

        return (int)$wpdb->get_var(empty($params) ? $sql : $wpdb->prepare($sql, $params));
    }

    public static function getStats(): array
    {
        global $wpdb;
        $t    = $wpdb->prefix . 'wnq_leads';
        $cols = $wpdb->get_col("DESCRIBE {$t}", 0);
        $has_exported_col = in_array('exported_at', $cols, true);

        return [
            'total'        => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
            'new'          => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='new'"),
            'contacted'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='contacted'"),
            'qualified'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='qualified'"),
            'closed'       => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='closed'"),
            'with_email'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE email != ''"),
            'not_exported' => $has_exported_col
                ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE exported_at IS NULL")
                : (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
        ];
    }

    public static function getDistinctValues(string $column): array
    {
        global $wpdb;
        if (!in_array($column, ['industry', 'city', 'state', 'status'], true)) return [];
        $table = $wpdb->prefix . 'wnq_leads';
        return $wpdb->get_col(
            "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} != '' ORDER BY {$column} ASC"
        ) ?: [];
    }

    // ── GHL-Compatible CSV Export ───────────────────────────────────────────

    /**
     * Export leads as a CSV formatted for Go High Level contact import.
     * Column order matches standard GHL CSV import template.
     */
    public static function exportCsv(array $args = []): void
    {
        // Always sort by industry first, then by business name — makes GHL imports tidy
        $rows = self::getAll(array_merge($args, [
            'limit'   => 9999,
            'offset'  => 0,
            'orderby' => 'industry',
            'order'   => 'ASC',
        ]));

        // Mark all exported leads (first export date only — won't overwrite existing)
        self::markExported(array_column($rows, 'id'));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wnq-leads-ghl-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');

        // GHL-compatible headers
        fputcsv($out, [
            'Company Name',
            'Email',
            'Phone',
            'Website',
            'Address',
            'City',
            'State',
            'Postal Code',
            'Tags',
            'Source',
            'Notes',
            'Facebook',
            'Instagram',
            'LinkedIn',
            'Twitter',
            'YouTube',
            'TikTok',
            'Industry',
            'Stars',
            'Review Count',
            'Has Phone',
            'Temporarily Closed',
            'SEO Score',
            'SEO Issues',
            'Status',
            'Scraped Date',
            'Exported Date',
        ]);

        $current_industry = null;

        foreach ($rows as $row) {
            $issues_str = implode(' | ', array_map(
                fn($i) => \WNQ\Services\LeadSEOScorer::issueLabel($i),
                (array)$row['seo_issues']
            ));

            $is_temp_closed = stripos((string)($row['notes'] ?? ''), 'temporarily closed') !== false;

            $tags = array_filter([
                $row['industry'],
                'seo-score-' . $row['seo_score'],
                $row['state'] ? 'state-' . strtolower($row['state']) : '',
                !$row['phone'] ? 'no-phone' : '',
                $is_temp_closed ? 'temporarily-closed' : '',
                'webnique-lead',
            ]);

            // Insert a blank separator row + industry heading each time the industry changes
            if ($row['industry'] !== $current_industry) {
                if ($current_industry !== null) {
                    fputcsv($out, []); // blank spacer row
                }
                fputcsv($out, ['=== ' . strtoupper($row['industry'] ?: 'UNCATEGORIZED') . ' ===']);
                $current_industry = $row['industry'];
            }

            fputcsv($out, [
                $row['business_name'],
                $row['email'],
                $row['phone'],
                $row['website'],
                $row['address'],
                $row['city'],
                $row['state'],
                $row['zip'],
                implode(', ', $tags),
                'WebNique Lead Finder',
                $row['notes'],
                $row['social_facebook'],
                $row['social_instagram'],
                $row['social_linkedin'],
                $row['social_twitter'],
                $row['social_youtube'],
                $row['social_tiktok'],
                $row['industry'],
                $row['rating'],
                $row['review_count'],
                $row['phone'] ? 'Yes' : 'No',
                $is_temp_closed ? 'Yes' : 'No',
                $row['seo_score'],
                $issues_str,
                $row['status'],
                $row['scraped_at'],
                $row['exported_at'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }
}
