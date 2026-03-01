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

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            place_id      VARCHAR(255)    NOT NULL DEFAULT '',
            business_name VARCHAR(255)    NOT NULL DEFAULT '',
            industry      VARCHAR(150)    NOT NULL DEFAULT '',
            city          VARCHAR(150)    NOT NULL DEFAULT '',
            address       VARCHAR(500)    NOT NULL DEFAULT '',
            phone         VARCHAR(50)     NOT NULL DEFAULT '',
            website       VARCHAR(500)    NOT NULL DEFAULT '',
            rating        DECIMAL(3,1)    NOT NULL DEFAULT 0,
            review_count  INT UNSIGNED    NOT NULL DEFAULT 0,
            seo_score     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            seo_issues    TEXT,
            email         VARCHAR(255)    NOT NULL DEFAULT '',
            email_source  VARCHAR(500)    NOT NULL DEFAULT '',
            status        VARCHAR(20)     NOT NULL DEFAULT 'new',
            notes         TEXT,
            scraped_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            contacted_at  DATETIME        DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   place_id (place_id),
            KEY          industry_city (industry(50), city(50)),
            KEY          status (status),
            KEY          seo_score (seo_score)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ── Write Operations ────────────────────────────────────────────────────

    public static function insert(array $data): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wnq_leads',
            [
                'place_id'      => $data['place_id']      ?? '',
                'business_name' => $data['business_name'] ?? '',
                'industry'      => $data['industry']      ?? '',
                'city'          => $data['city']          ?? '',
                'address'       => $data['address']       ?? '',
                'phone'         => $data['phone']         ?? '',
                'website'       => $data['website']       ?? '',
                'rating'        => (float)($data['rating']       ?? 0),
                'review_count'  => (int)($data['review_count']   ?? 0),
                'seo_score'     => (int)($data['seo_score']      ?? 0),
                'seo_issues'    => wp_json_encode($data['seo_issues'] ?? []),
                'email'         => $data['email']         ?? '',
                'email_source'  => $data['email_source']  ?? '',
                'status'        => $data['status']        ?? 'new',
                'notes'         => $data['notes']         ?? '',
                'scraped_at'    => current_time('mysql'),
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

        $allowed_orderby = ['id', 'review_count', 'seo_score', 'rating', 'business_name', 'scraped_at'];
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
        if (!empty($args['status']))   { $where[] = 'status = %s';   $params[] = $args['status']; }
        if (isset($args['min_seo_score'])) { $where[] = 'seo_score >= %d'; $params[] = (int)$args['min_seo_score']; }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

        return (int)$wpdb->get_var(empty($params) ? $sql : $wpdb->prepare($sql, $params));
    }

    public static function getStats(): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_leads';
        return [
            'total'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
            'new'        => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='new'"),
            'contacted'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='contacted'"),
            'qualified'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='qualified'"),
            'closed'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='closed'"),
            'with_email' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE email != ''"),
        ];
    }

    public static function getDistinctValues(string $column): array
    {
        global $wpdb;
        if (!in_array($column, ['industry', 'city', 'status'], true)) return [];
        $table = $wpdb->prefix . 'wnq_leads';
        return $wpdb->get_col(
            "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} != '' ORDER BY {$column} ASC"
        ) ?: [];
    }

    // ── CSV Export ──────────────────────────────────────────────────────────

    public static function exportCsv(array $args = []): void
    {
        $rows = self::getAll(array_merge($args, ['limit' => 9999, 'offset' => 0]));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wnq-leads-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Business', 'Industry', 'City', 'Address', 'Phone', 'Website', 'Rating', 'Reviews', 'SEO Score', 'SEO Issues', 'Email', 'Status', 'Notes', 'Scraped At']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['business_name'],
                $row['industry'],
                $row['city'],
                $row['address'],
                $row['phone'],
                $row['website'],
                $row['rating'],
                $row['review_count'],
                $row['seo_score'],
                implode(', ', (array)$row['seo_issues']),
                $row['email'],
                $row['status'],
                $row['notes'],
                $row['scraped_at'],
            ]);
        }

        fclose($out);
        exit;
    }
}
