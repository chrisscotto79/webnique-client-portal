<?php
/**
 * SEO Hub Models - Centralized AI-Powered SEO Operating System
 *
 * Tables:
 *  wnq_seo_profiles       - Client SEO targeting config
 *  wnq_seo_agent_keys     - API keys for client site plugins
 *  wnq_seo_site_data      - Page-level data synced from client plugins
 *  wnq_seo_keywords       - Keyword tracking clusters & rankings
 *  wnq_seo_audit_findings - Nightly audit flags
 *  wnq_seo_reports        - Monthly performance reports
 *  wnq_seo_automation_log - Traceability log for all automation actions
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class SEOHub
{
    private static bool $report_schema_checked = false;
    private static bool $profile_schema_checked = false;

    /* ═══════════════════════════════════════════
     *  TABLE CREATION
     * ═══════════════════════════════════════════ */

    public static function createTables(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // --- SEO Profiles (per-client targeting config) ---
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_seo_profiles (
            id            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id     varchar(100) NOT NULL,
            primary_services   longtext DEFAULT NULL COMMENT 'JSON array of services',
            service_locations  longtext DEFAULT NULL COMMENT 'JSON array of locations',
            keyword_clusters   longtext DEFAULT NULL COMMENT 'JSON: {cluster_name: [kws]}',
            brand_notes        text DEFAULT NULL,
            phone              varchar(50) DEFAULT NULL,
            email              varchar(255) DEFAULT NULL,
            content_tone       varchar(100) DEFAULT 'professional',
            gsc_property       varchar(500) DEFAULT NULL,
            ga_property        varchar(255) DEFAULT NULL,
            last_gsc_sync      datetime DEFAULT NULL,
            last_sync          datetime DEFAULT NULL,
            created_at         datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at         datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) $c;");

        // --- Agent API Keys (for client WordPress sites) ---
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_seo_agent_keys (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id   varchar(100) NOT NULL,
            api_key     varchar(64) NOT NULL,
            site_url    varchar(500) NOT NULL,
            site_url_locked tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
            site_name   varchar(255) DEFAULT NULL,
            wp_version  varchar(20) DEFAULT NULL,
            plugin_version varchar(20) DEFAULT NULL,
            last_ping   datetime DEFAULT NULL,
            status      varchar(20) DEFAULT 'active',
            created_at  datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY api_key (api_key),
            KEY client_id (client_id),
            KEY status (status)
        ) $c;");

        // --- Site Data (page snapshots from client plugin) ---
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_seo_site_data (
            id                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id           varchar(100) NOT NULL,
            page_url            varchar(1000) NOT NULL,
            page_type           varchar(50) DEFAULT 'page' COMMENT 'post|page|product|archive',
            post_id             bigint(20) DEFAULT NULL,
            post_status         varchar(20) DEFAULT 'publish',
            title               varchar(500) DEFAULT NULL,
            meta_description    text DEFAULT NULL,
            h1                  varchar(500) DEFAULT NULL,
            focus_keyword       varchar(255) DEFAULT NULL,
            word_count          int(11) DEFAULT 0,
            internal_links_count int(11) DEFAULT 0,
            images_count        int(11) DEFAULT 0,
            images_missing_alt  int(11) DEFAULT 0,
            images_without_lazy int(11) DEFAULT 0,
            has_og_tags         tinyint(1) DEFAULT 0,
            title_length        smallint(6) DEFAULT 0,
            has_schema          tinyint(1) DEFAULT 0,
            schema_types        varchar(500) DEFAULT NULL COMMENT 'JSON array',
            has_h1              tinyint(1) DEFAULT 0,
            keyword_in_title    tinyint(1) DEFAULT 0,
            keyword_in_meta     tinyint(1) DEFAULT 0,
            keyword_in_h1       tinyint(1) DEFAULT 0,
            categories          text DEFAULT NULL COMMENT 'JSON array',
            tags                text DEFAULT NULL COMMENT 'JSON array',
            featured_image_url  varchar(1000) DEFAULT NULL,
            last_modified       datetime DEFAULT NULL,
            last_synced         datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_page (client_id, page_url(500)),
            KEY client_id (client_id),
            KEY page_type (page_type),
            KEY focus_keyword (focus_keyword)
        ) $c;");

        // --- Keywords ---
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_seo_keywords (
            id               bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id        varchar(100) NOT NULL,
            keyword          varchar(500) NOT NULL,
            cluster_name     varchar(255) DEFAULT NULL,
            service_category varchar(255) DEFAULT NULL,
            location         varchar(255) DEFAULT NULL,
            target_url       varchar(1000) DEFAULT NULL,
            current_position decimal(6,1) DEFAULT NULL,
            prev_position    decimal(6,1) DEFAULT NULL,
            impressions      int(11) DEFAULT 0,
            clicks           int(11) DEFAULT 0,
            avg_position     decimal(6,1) DEFAULT NULL,
            ctr              decimal(5,2) DEFAULT NULL,
            search_volume    int(11) DEFAULT NULL,
            difficulty       int(3) DEFAULT NULL,
            indexed          tinyint(1) DEFAULT 0,
            has_content      tinyint(1) DEFAULT 0,
            content_gap      tinyint(1) DEFAULT 0 COMMENT '1=no page targeting this kw',
            last_gsc_update  datetime DEFAULT NULL,
            position_history longtext DEFAULT NULL COMMENT 'JSON array of {date,position}',
            intent           varchar(20) DEFAULT NULL COMMENT 'informational|transactional|commercial|navigational',
            notes            text DEFAULT NULL,
            created_at       datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at       datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY cluster_name (cluster_name),
            KEY service_category (service_category),
            KEY content_gap (content_gap),
            KEY intent (intent)
        ) $c;");

        // --- Audit Findings ---
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_seo_audit_findings (
            id           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id    varchar(100) NOT NULL,
            page_url     varchar(1000) DEFAULT NULL,
            finding_type varchar(100) NOT NULL COMMENT 'missing_h1|no_schema|thin_content|missing_alt|kw_not_in_title|declining_rank|missing_meta|no_internal_links|orphan_page',
            severity     varchar(20) DEFAULT 'warning' COMMENT 'critical|warning|info',
            finding_data longtext DEFAULT NULL COMMENT 'JSON details',
            status       varchar(20) DEFAULT 'open' COMMENT 'open|resolved|ignored',
            detected_at  datetime DEFAULT CURRENT_TIMESTAMP,
            resolved_at  datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY finding_type (finding_type),
            KEY severity (severity),
            KEY status (status),
            KEY detected_at (detected_at)
        ) $c;");

        // --- Reports ---
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_seo_reports (
            id             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id      varchar(100) NOT NULL,
            report_type    varchar(50) DEFAULT 'monthly' COMMENT 'monthly|quarterly|audit|custom',
            period_start   date DEFAULT NULL,
            period_end     date DEFAULT NULL,
            title          varchar(255) DEFAULT NULL,
            report_data    longtext DEFAULT NULL COMMENT 'JSON report payload',
            summary_html   longtext DEFAULT NULL COMMENT 'AI-generated HTML summary',
            status         varchar(20) DEFAULT 'draft' COMMENT 'draft|ready|sent',
            generated_at   datetime DEFAULT CURRENT_TIMESTAMP,
            exported_at    datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY report_type (report_type),
            KEY period_start (period_start),
            KEY status (status)
        ) $c;");

        // --- Automation Log ---
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_seo_automation_log (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id   varchar(100) DEFAULT NULL,
            action_type varchar(100) NOT NULL,
            entity_type varchar(50) DEFAULT NULL COMMENT 'keyword|page|content_job|report|audit',
            entity_id   bigint(20) DEFAULT NULL,
            details     longtext DEFAULT NULL COMMENT 'JSON context',
            status      varchar(20) DEFAULT 'success' COMMENT 'success|failed|skipped',
            triggered_by varchar(50) DEFAULT 'cron' COMMENT 'cron|manual|api',
            created_at  datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY action_type (action_type),
            KEY created_at (created_at)
        ) $c;");

        self::ensureProfileSchema();
        self::ensureReportSchema();
    }

    public static function ensureProfileSchema(): void
    {
        if (self::$profile_schema_checked) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_profiles';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            self::createTables();
            self::$profile_schema_checked = true;
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table", 0) ?: [];
        $column_set = array_fill_keys($columns, true);

        $defs = [
            'phone' => "ALTER TABLE $table ADD COLUMN phone varchar(50) DEFAULT NULL AFTER brand_notes",
            'email' => "ALTER TABLE $table ADD COLUMN email varchar(255) DEFAULT NULL AFTER phone",
        ];

        foreach ($defs as $column => $sql) {
            if (empty($column_set[$column])) {
                $wpdb->query($sql);
            }
        }

        self::$profile_schema_checked = true;
    }

    public static function ensureReportSchema(): void
    {
        if (self::$report_schema_checked) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_reports';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            self::createTables();
            self::$report_schema_checked = true;
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table", 0) ?: [];
        $column_set = array_fill_keys($columns, true);

        $defs = [
            'report_type'  => "ALTER TABLE $table ADD COLUMN report_type varchar(50) DEFAULT 'monthly' AFTER client_id",
            'period_start' => "ALTER TABLE $table ADD COLUMN period_start date DEFAULT NULL AFTER report_type",
            'period_end'   => "ALTER TABLE $table ADD COLUMN period_end date DEFAULT NULL AFTER period_start",
            'title'        => "ALTER TABLE $table ADD COLUMN title varchar(255) DEFAULT NULL AFTER period_end",
            'report_data'  => "ALTER TABLE $table ADD COLUMN report_data longtext DEFAULT NULL AFTER title",
            'summary_html' => "ALTER TABLE $table ADD COLUMN summary_html longtext DEFAULT NULL AFTER report_data",
            'status'       => "ALTER TABLE $table ADD COLUMN status varchar(20) DEFAULT 'draft' AFTER summary_html",
            'generated_at' => "ALTER TABLE $table ADD COLUMN generated_at datetime DEFAULT NULL AFTER status",
            'exported_at'  => "ALTER TABLE $table ADD COLUMN exported_at datetime DEFAULT NULL AFTER generated_at",
        ];

        foreach ($defs as $column => $sql) {
            if (empty($column_set[$column])) {
                $wpdb->query($sql);
            }
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table", 0) ?: [];
        $column_set = array_fill_keys($columns, true);

        if (!empty($column_set['month_year']) && !empty($column_set['period_start'])) {
            $wpdb->query(
                "UPDATE $table
                 SET period_start = STR_TO_DATE(CONCAT(month_year, '-01'), '%Y-%m-%d')
                 WHERE (period_start IS NULL OR period_start = '0000-00-00')
                   AND month_year REGEXP '^[0-9]{4}-[0-9]{2}$'"
            );
        }

        if (!empty($column_set['period_start']) && !empty($column_set['period_end'])) {
            $wpdb->query(
                "UPDATE $table
                 SET period_end = LAST_DAY(period_start)
                 WHERE period_start IS NOT NULL
                   AND (period_end IS NULL OR period_end = '0000-00-00')"
            );
        }

        if (!empty($column_set['report_type'])) {
            $wpdb->query("UPDATE $table SET report_type = 'monthly' WHERE report_type IS NULL OR report_type = ''");
        }

        if (!empty($column_set['status'])) {
            $wpdb->query("UPDATE $table SET status = 'ready' WHERE status IS NULL OR status = ''");
        }

        if (!empty($column_set['title']) && !empty($column_set['period_start'])) {
            $wpdb->query(
                "UPDATE $table
                 SET title = CONCAT('Analytics Report: ', DATE_FORMAT(period_start, '%M %Y'))
                 WHERE period_start IS NOT NULL
                   AND (title IS NULL OR title = '')"
            );
        }

        if (!empty($column_set['generated_at']) && !empty($column_set['created_at'])) {
            $wpdb->query("UPDATE $table SET generated_at = created_at WHERE generated_at IS NULL AND created_at IS NOT NULL");
        }

        self::ensureReportIndexes($table);
        self::$report_schema_checked = true;
    }

    private static function ensureReportIndexes(string $table): void
    {
        global $wpdb;
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A) ?: [];
        $keys = [];
        foreach ($indexes as $index) {
            if (!empty($index['Key_name'])) {
                $keys[$index['Key_name']] = true;
            }
        }

        $columns = array_fill_keys($wpdb->get_col("SHOW COLUMNS FROM $table", 0) ?: [], true);
        $defs = [
            'report_type'  => ['column' => 'report_type', 'sql' => "ALTER TABLE $table ADD INDEX report_type (report_type)"],
            'period_start' => ['column' => 'period_start', 'sql' => "ALTER TABLE $table ADD INDEX period_start (period_start)"],
            'status'       => ['column' => 'status', 'sql' => "ALTER TABLE $table ADD INDEX status (status)"],
        ];

        foreach ($defs as $name => $def) {
            if (empty($keys[$name]) && !empty($columns[$def['column']])) {
                $wpdb->query($def['sql']);
            }
        }
    }

    /* ═══════════════════════════════════════════
     *  SEO PROFILE METHODS
     * ═══════════════════════════════════════════ */

    public static function getProfile(string $client_id): ?array
    {
        global $wpdb;
        self::ensureProfileSchema();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_seo_profiles WHERE client_id = %s", $client_id),
            ARRAY_A
        );
        if (!$row) return null;
        foreach (['primary_services', 'service_locations', 'keyword_clusters'] as $f) {
            if (!empty($row[$f])) {
                $row[$f] = json_decode($row[$f], true) ?? [];
            }
        }
        return $row;
    }

    public static function upsertProfile(string $client_id, array $data): bool
    {
        global $wpdb;
        self::ensureProfileSchema();
        $t = $wpdb->prefix . 'wnq_seo_profiles';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE client_id=%s", $client_id));

        $payload = ['client_id' => $client_id];
        $json_fields = ['primary_services', 'service_locations', 'keyword_clusters'];
        $string_fields = ['brand_notes', 'phone', 'content_tone', 'gsc_property', 'ga_property'];
        $email_fields = ['email'];
        $int_fields = [];

        foreach ($json_fields as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = is_array($data[$f]) ? wp_json_encode($data[$f]) : $data[$f];
            }
        }
        foreach ($string_fields as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = sanitize_textarea_field((string)$data[$f]);
            }
        }
        foreach ($email_fields as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = sanitize_email((string)$data[$f]);
            }
        }
        foreach ($int_fields as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = (int)$data[$f];
            }
        }

        if ($existing) {
            return $wpdb->update($t, $payload, ['client_id' => $client_id]) !== false;
        }
        return $wpdb->insert($t, $payload) !== false;
    }

    /* ═══════════════════════════════════════════
     *  AGENT KEY METHODS
     * ═══════════════════════════════════════════ */

    public static function generateAgentKey(string $client_id, string $site_url, string $site_name = ''): string|false
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_agent_keys';
        $key = 'wnq_' . bin2hex(random_bytes(24));

        $res = $wpdb->insert($t, [
            'client_id' => $client_id,
            'api_key'   => $key,
            'site_url'  => esc_url_raw($site_url),
            'site_name' => sanitize_text_field($site_name),
            'status'    => 'active',
        ]);
        return $res ? $key : false;
    }

    public static function validateAgentKey(string $api_key): ?array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_agent_keys';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $t WHERE api_key=%s AND status='active'", $api_key),
            ARRAY_A
        );
        if ($row) {
            $wpdb->update($t, ['last_ping' => current_time('mysql')], ['api_key' => $api_key]);
        }
        return $row ?: null;
    }

    public static function getAgentKeys(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_seo_agent_keys WHERE client_id=%s ORDER BY created_at DESC", $client_id),
            ARRAY_A
        ) ?: [];
    }

    public static function getAllAgentKeys(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wnq_seo_agent_keys ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    public static function revokeAgentKey(int $key_id): bool
    {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'wnq_seo_agent_keys',
            ['status' => 'revoked'],
            ['id' => $key_id]
        ) !== false;
    }

    public static function updateAgentSiteUrl(int $key_id, string $site_url): bool
    {
        if ($key_id <= 0 || $site_url === '') {
            return false;
        }

        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'wnq_seo_agent_keys',
            [
                'site_url'        => untrailingslashit(esc_url_raw($site_url, ['https'])),
                'site_url_locked' => 1,
            ],
            ['id' => $key_id],
            ['%s', '%d'],
            ['%d']
        ) !== false;
    }

    /* ═══════════════════════════════════════════
     *  SITE DATA METHODS
     * ═══════════════════════════════════════════ */

    public static function upsertSiteData(string $client_id, array $pages): int
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_site_data';
        $count = 0;

        foreach ($pages as $page) {
            if (empty($page['page_url'])) continue;

            $payload = [
                'client_id'           => $client_id,
                'page_url'            => esc_url_raw($page['page_url']),
                'page_type'           => sanitize_text_field($page['page_type'] ?? 'page'),
                'post_id'             => isset($page['post_id']) ? (int)$page['post_id'] : null,
                'post_status'         => sanitize_text_field($page['post_status'] ?? 'publish'),
                'title'               => sanitize_text_field($page['title'] ?? ''),
                'meta_description'    => sanitize_textarea_field($page['meta_description'] ?? ''),
                'h1'                  => sanitize_text_field($page['h1'] ?? ''),
                'focus_keyword'       => sanitize_text_field($page['focus_keyword'] ?? ''),
                'word_count'          => (int)($page['word_count'] ?? 0),
                'internal_links_count'=> (int)($page['internal_links_count'] ?? 0),
                'images_count'        => (int)($page['images_count'] ?? 0),
                'images_missing_alt'  => (int)($page['images_missing_alt'] ?? 0),
                'images_without_lazy' => (int)($page['images_without_lazy'] ?? 0),
                'has_og_tags'         => (int)($page['has_og_tags'] ?? 0),
                'title_length'        => (int)($page['title_length'] ?? 0),
                'has_schema'          => (int)(!empty($page['schema_types'])),
                'schema_types'        => is_array($page['schema_types'] ?? null) ? wp_json_encode($page['schema_types']) : ($page['schema_types'] ?? null),
                'has_h1'              => (int)(!empty($page['h1'])),
                'keyword_in_title'    => (int)($page['keyword_in_title'] ?? 0),
                'keyword_in_meta'     => (int)($page['keyword_in_meta'] ?? 0),
                'keyword_in_h1'       => (int)($page['keyword_in_h1'] ?? 0),
                'categories'          => is_array($page['categories'] ?? null) ? wp_json_encode($page['categories']) : null,
                'tags'                => is_array($page['tags'] ?? null) ? wp_json_encode($page['tags']) : null,
                'featured_image_url'  => esc_url_raw($page['featured_image_url'] ?? ''),
                'last_modified'       => $page['last_modified'] ?? null,
                'last_synced'         => current_time('mysql'),
            ];

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $t WHERE client_id=%s AND page_url=%s",
                $client_id, $payload['page_url']
            ));

            if ($existing) {
                $wpdb->update($t, $payload, ['id' => $existing]);
            } else {
                $wpdb->insert($t, $payload);
            }
            $count++;
        }
        return $count;
    }

    public static function getSiteData(string $client_id, array $args = []): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_site_data';
        $where = $wpdb->prepare("WHERE client_id=%s", $client_id);

        if (!empty($args['page_type'])) {
            $where .= $wpdb->prepare(" AND page_type=%s", $args['page_type']);
        }
        if (!empty($args['has_issue'])) {
            switch ($args['has_issue']) {
                case 'missing_h1':   $where .= " AND has_h1=0"; break;
                case 'missing_alt':  $where .= " AND images_missing_alt>0"; break;
                case 'thin_content': $where .= " AND word_count < 300"; break;
                case 'no_schema':    $where .= " AND has_schema=0"; break;
                case 'no_internal':  $where .= " AND internal_links_count=0"; break;
            }
        }
        $limit = isset($args['limit']) ? "LIMIT " . (int)$args['limit'] : "";
        return $wpdb->get_results("SELECT * FROM $t $where ORDER BY last_synced DESC $limit", ARRAY_A) ?: [];
    }

    public static function getSiteStats(string $client_id): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_site_data';
        $cid = $wpdb->prepare("%s", $client_id);

        return [
            'total_pages'        => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid"),
            'missing_h1'         => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND has_h1=0"),
            'missing_alt'        => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND images_missing_alt>0"),
            'thin_content'       => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND word_count>0 AND word_count<300"),
            'no_schema'          => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND has_schema=0"),
            'no_internal_links'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND internal_links_count=0 AND page_type='post'"),
            'missing_og'         => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND has_og_tags=0"),
            'no_image_lazy_load' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND images_count>0 AND images_without_lazy>0"),
            'title_too_long'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE client_id=$cid AND title_length>60"),
            'last_synced'        => $wpdb->get_var("SELECT MAX(last_synced) FROM $t WHERE client_id=$cid"),
        ];
    }

    /* ═══════════════════════════════════════════
     *  KEYWORD METHODS
     * ═══════════════════════════════════════════ */

    public static function upsertKeyword(string $client_id, array $kw): int|false
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_keywords';

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE client_id=%s AND keyword=%s",
            $client_id, strtolower(trim($kw['keyword']))
        ));

        $payload = [
            'client_id'        => $client_id,
            'keyword'          => strtolower(trim($kw['keyword'])),
            'cluster_name'     => sanitize_text_field($kw['cluster_name'] ?? ''),
            'service_category' => sanitize_text_field($kw['service_category'] ?? ''),
            'location'         => sanitize_text_field($kw['location'] ?? ''),
            'target_url'       => esc_url_raw($kw['target_url'] ?? ''),
            'notes'            => sanitize_textarea_field($kw['notes'] ?? ''),
        ];

        // GSC data fields
        $num_fields = ['current_position', 'prev_position', 'impressions', 'clicks', 'avg_position', 'ctr', 'search_volume', 'difficulty'];
        foreach ($num_fields as $f) {
            if (isset($kw[$f])) {
                $payload[$f] = is_numeric($kw[$f]) ? $kw[$f] : null;
            }
        }

        if ($existing_id) {
            // Before updating position, save current as prev
            if (isset($payload['current_position'])) {
                $old = $wpdb->get_var($wpdb->prepare("SELECT current_position FROM $t WHERE id=%d", $existing_id));
                if ($old !== null) {
                    $payload['prev_position'] = $old;
                }
                // Update position history
                $history = json_decode($wpdb->get_var($wpdb->prepare("SELECT position_history FROM $t WHERE id=%d", $existing_id)) ?? '[]', true) ?: [];
                $history[] = ['date' => date('Y-m-d'), 'position' => $payload['current_position']];
                if (count($history) > 90) $history = array_slice($history, -90);
                $payload['position_history'] = wp_json_encode($history);
            }
            $payload['updated_at'] = current_time('mysql');
            $wpdb->update($t, $payload, ['id' => $existing_id]);
            return $existing_id;
        }

        $payload['position_history'] = isset($payload['current_position'])
            ? wp_json_encode([['date' => date('Y-m-d'), 'position' => $payload['current_position']]])
            : '[]';
        $wpdb->insert($t, $payload);
        return $wpdb->insert_id ?: false;
    }

    public static function getKeywords(string $client_id, array $args = []): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_keywords';
        $where = $wpdb->prepare("WHERE client_id=%s", $client_id);

        if (!empty($args['cluster'])) {
            $where .= $wpdb->prepare(" AND cluster_name=%s", $args['cluster']);
        }
        if (!empty($args['service'])) {
            $where .= $wpdb->prepare(" AND service_category=%s", $args['service']);
        }
        if (isset($args['content_gap']) && $args['content_gap'] !== '') {
            $where .= " AND content_gap=" . (int)$args['content_gap'];
        }

        $order = "ORDER BY " . ($args['order'] ?? 'impressions DESC');
        $limit = isset($args['limit']) ? "LIMIT " . (int)$args['limit'] : "";

        return $wpdb->get_results("SELECT * FROM $t $where $order $limit", ARRAY_A) ?: [];
    }

    public static function getKeywordClusters(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cluster_name, COUNT(*) as keyword_count, AVG(avg_position) as avg_pos, SUM(impressions) as total_impressions, SUM(clicks) as total_clicks
                 FROM {$wpdb->prefix}wnq_seo_keywords
                 WHERE client_id=%s AND cluster_name!=''
                 GROUP BY cluster_name ORDER BY total_impressions DESC",
                $client_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function markContentGaps(string $client_id): int
    {
        global $wpdb;
        $kt = $wpdb->prefix . 'wnq_seo_keywords';
        $st = $wpdb->prefix . 'wnq_seo_site_data';

        $keywords = $wpdb->get_results(
            $wpdb->prepare("SELECT id, keyword FROM $kt WHERE client_id=%s", $client_id),
            ARRAY_A
        ) ?: [];

        $gaps = 0;
        foreach ($keywords as $kw) {
            $like = '%' . $wpdb->esc_like($kw['keyword']) . '%';
            $has_page = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $st WHERE client_id=%s AND (title LIKE %s OR focus_keyword=%s OR meta_description LIKE %s)",
                $client_id, $like, $kw['keyword'], $like
            ));
            $is_gap = $has_page ? 0 : 1;
            $wpdb->update($kt, ['content_gap' => $is_gap, 'has_content' => $has_page ? 1 : 0], ['id' => $kw['id']]);
            if ($is_gap) $gaps++;
        }
        return $gaps;
    }

    /* ═══════════════════════════════════════════
     *  AUDIT FINDING METHODS
     * ═══════════════════════════════════════════ */

    public static function insertAuditFinding(string $client_id, string $type, string $severity, string $url = '', array $data = []): int|false
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_audit_findings';

        // Deduplicate: don't create duplicate open findings
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE client_id=%s AND finding_type=%s AND page_url=%s AND status='open'",
            $client_id, $type, $url
        ));
        if ($existing) return (int)$existing;

        $wpdb->insert($t, [
            'client_id'    => $client_id,
            'page_url'     => $url,
            'finding_type' => $type,
            'severity'     => $severity,
            'finding_data' => wp_json_encode($data),
            'status'       => 'open',
            'detected_at'  => current_time('mysql'),
        ]);
        return $wpdb->insert_id ?: false;
    }

    public static function getAuditFindings(string $client_id, array $args = []): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_audit_findings';
        $where = $wpdb->prepare("WHERE client_id=%s", $client_id);

        if (!empty($args['status']))   $where .= $wpdb->prepare(" AND status=%s", $args['status']);
        if (!empty($args['severity'])) $where .= $wpdb->prepare(" AND severity=%s", $args['severity']);
        if (!empty($args['type']))     $where .= $wpdb->prepare(" AND finding_type=%s", $args['type']);

        $limit = isset($args['limit']) ? "LIMIT " . (int)$args['limit'] : "";
        return $wpdb->get_results("SELECT * FROM $t $where ORDER BY detected_at DESC $limit", ARRAY_A) ?: [];
    }

    public static function resolveAuditFinding(int $id): bool
    {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'wnq_seo_audit_findings',
            ['status' => 'resolved', 'resolved_at' => current_time('mysql')],
            ['id' => $id]
        ) !== false;
    }

    public static function getAuditSummary(string $client_id): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_audit_findings';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT finding_type, severity, COUNT(*) as count FROM {$t} WHERE client_id=%s AND status='open' GROUP BY finding_type, severity",
                $client_id
            ),
            ARRAY_A
        ) ?: [];

        $summary = [];
        foreach ($rows as $r) {
            $summary[$r['finding_type']] = ['count' => (int)$r['count'], 'severity' => $r['severity']];
        }
        return $summary;
    }

    /* ═══════════════════════════════════════════
     *  REPORT METHODS
     * ═══════════════════════════════════════════ */

    public static function createReport(string $client_id, string $type, string $start, string $end, array $data, string $summary_html = '', string $status = 'ready'): int|false
    {
        self::ensureReportSchema();

        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_reports';
        $period_label = (string)($data['period']['label'] ?? date('F Y', strtotime($start)));
        $title_prefix = $type === 'monthly' ? 'Monthly Analytics Report: ' : 'Analytics Report: ';
        $columns = array_fill_keys($wpdb->get_col("SHOW COLUMNS FROM $t", 0) ?: [], true);
        $month_year = date('Y-m', strtotime($start));
        $payload = [
            'client_id'    => $client_id,
            'month_year'   => $month_year,
            'report_type'  => $type,
            'period_start' => $start,
            'period_end'   => $end,
            'title'        => $title_prefix . $period_label,
            'report_data'  => wp_json_encode($data),
            'summary_html' => $summary_html,
            'status'       => $status,
            'generated_at' => current_time('mysql'),
        ];
        $payload = array_intersect_key($payload, $columns);

        $existing = self::findWritableReport($client_id, $type, $start, $end, $month_year, $columns);
        if ($existing) {
            $updated = $wpdb->update($t, $payload, ['id' => (int)$existing['id']]);
            return $updated !== false ? (int)$existing['id'] : false;
        }

        $wpdb->insert($t, $payload);
        return $wpdb->insert_id ?: false;
    }

    private static function findWritableReport(string $client_id, string $type, string $start, string $end, string $month_year, array $columns): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_reports';

        if (!empty($columns['report_type']) && !empty($columns['period_start']) && !empty($columns['period_end'])) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table
                     WHERE client_id=%s AND report_type=%s AND period_start=%s AND period_end=%s
                     ORDER BY id DESC LIMIT 1",
                    $client_id,
                    $type,
                    $start,
                    $end
                ),
                ARRAY_A
            );

            if ($row) {
                return $row;
            }
        }

        if (!empty($columns['month_year'])) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table
                     WHERE client_id=%s AND month_year=%s
                     ORDER BY id DESC LIMIT 1",
                    $client_id,
                    $month_year
                ),
                ARRAY_A
            );

            if ($row) {
                return $row;
            }
        }

        return null;
    }

    public static function getReports(string $client_id, int $limit = 12): array
    {
        self::ensureReportSchema();

        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_reports WHERE client_id=%s ORDER BY period_start DESC, generated_at DESC, id DESC LIMIT %d",
                $client_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function getReport(int $id): ?array
    {
        self::ensureReportSchema();

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_seo_reports WHERE id=%d", $id),
            ARRAY_A
        );
        if ($row) {
            $row['report_data'] = !empty($row['report_data'])
                ? json_decode($row['report_data'], true)
                : [];
        }
        return $row ?: null;
    }

    public static function deleteReport(int $id): bool
    {
        self::ensureReportSchema();

        if ($id <= 0) {
            return false;
        }

        global $wpdb;
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'wnq_seo_reports',
            ['id' => $id],
            ['%d']
        );

        return $deleted !== false && $deleted > 0;
    }

    public static function getReportForPeriod(string $client_id, string $type, string $start, string $end): ?array
    {
        self::ensureReportSchema();

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_reports
                 WHERE client_id=%s AND report_type=%s AND period_start=%s AND period_end=%s
                 ORDER BY id DESC LIMIT 1",
                $client_id,
                $type,
                $start,
                $end
            ),
            ARRAY_A
        );

        if ($row) {
            $row['report_data'] = !empty($row['report_data'])
                ? json_decode($row['report_data'], true)
                : [];
        }

        return $row ?: null;
    }

    public static function updateReportStatus(int $id, string $status): bool
    {
        self::ensureReportSchema();

        global $wpdb;
        $status = sanitize_key($status);
        if (!in_array($status, ['draft', 'ready', 'sent'], true)) {
            return false;
        }

        $data = ['status' => $status];
        if ($status === 'sent') {
            $data['exported_at'] = current_time('mysql');
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'wnq_seo_reports',
            $data,
            ['id' => $id],
            null,
            ['%d']
        );

        return $updated !== false;
    }

    /* ═══════════════════════════════════════════
     *  AUTOMATION LOG METHODS
     * ═══════════════════════════════════════════ */

    public static function log(string $action, array $context = [], string $status = 'success', string $triggered_by = 'cron'): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'wnq_seo_automation_log', [
            'client_id'   => $context['client_id'] ?? null,
            'action_type' => $action,
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id'   => isset($context['entity_id']) ? (int)$context['entity_id'] : null,
            'details'     => wp_json_encode($context),
            'status'      => $status,
            'triggered_by'=> $triggered_by,
            'created_at'  => current_time('mysql'),
        ]);
    }

    public static function getLog(array $args = []): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_automation_log';
        $where = "WHERE 1=1";

        if (!empty($args['client_id'])) {
            $where .= $wpdb->prepare(" AND client_id=%s", $args['client_id']);
        }
        if (!empty($args['action_type'])) {
            $where .= $wpdb->prepare(" AND action_type=%s", $args['action_type']);
        }

        $limit = isset($args['limit']) ? (int)$args['limit'] : 100;
        return $wpdb->get_results("SELECT * FROM $t $where ORDER BY created_at DESC LIMIT $limit", ARRAY_A) ?: [];
    }
}
