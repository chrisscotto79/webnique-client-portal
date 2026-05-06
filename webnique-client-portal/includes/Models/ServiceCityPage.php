<?php
/**
 * Service + City Page Queue
 *
 * Stores CSV-imported service-area page rows before they are generated as
 * Elementor draft child pages on a connected client WordPress site.
 *
 * @package WebNique Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceCityPage
{
    public static function requiredColumns(): array
    {
        return [
            'primary_keyword',
            'service',
            'service_variations',
            'city',
            'state',
            'county',
            'slug',
            'page_title',
            'title_tag',
            'meta_description',
            'h1',
            'cta_title',
            'cta_text',
            'related_services',
            'navigation_menu_related_services',
            'nearby_cities',
            'nav_menu_nearby_areas',
            'internal_links',
            'geo_modifiers',
            'commercial_intent',
            'page_type',
            'parent_service_slug',
            'keyword_variants',
        ];
    }

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_service_city_pages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            agent_key_id bigint(20) DEFAULT NULL,
            source_hash varchar(40) DEFAULT NULL,
            primary_keyword varchar(255) DEFAULT NULL,
            service varchar(255) DEFAULT NULL,
            service_variations text DEFAULT NULL,
            city varchar(255) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            county varchar(255) DEFAULT NULL,
            slug varchar(500) NOT NULL,
            page_title varchar(500) DEFAULT NULL,
            title_tag varchar(500) DEFAULT NULL,
            meta_description text DEFAULT NULL,
            h1 varchar(500) DEFAULT NULL,
            cta_title varchar(500) DEFAULT NULL,
            cta_text text DEFAULT NULL,
            related_services text DEFAULT NULL,
            navigation_menu_related_services text DEFAULT NULL,
            nearby_cities text DEFAULT NULL,
            nav_menu_nearby_areas text DEFAULT NULL,
            internal_links text DEFAULT NULL,
            geo_modifiers text DEFAULT NULL,
            commercial_intent varchar(255) DEFAULT NULL,
            page_type varchar(100) DEFAULT NULL,
            parent_service_slug varchar(500) DEFAULT NULL,
            keyword_variants text DEFAULT NULL,
            status varchar(30) DEFAULT 'imported',
            generated_html longtext DEFAULT NULL,
            elementor_json longtext DEFAULT NULL,
            wp_page_id bigint(20) DEFAULT NULL,
            wp_page_url varchar(1000) DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY agent_key_id (agent_key_id),
            KEY slug (slug(191)),
            KEY status (status)
        ) $charset;");
    }

    public static function templateOptionKey(string $client_id): string
    {
        return 'wnq_service_city_template_' . md5($client_id);
    }

    public static function saveTemplate(string $client_id, string $template): void
    {
        update_option(self::templateOptionKey($client_id), $template, false);
    }

    public static function getTemplate(string $client_id): string
    {
        return (string)get_option(self::templateOptionKey($client_id), '');
    }

    public static function getRows(string $client_id, int $limit = 100): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_service_city_pages
                 WHERE client_id=%s
                 ORDER BY FIELD(status, 'failed', 'imported', 'generating', 'draft_created', 'skipped'), created_at DESC
                 LIMIT %d",
                $client_id,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function getRow(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_service_city_pages WHERE id=%d", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function updateRow(int $id, array $data): bool
    {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'wnq_service_city_pages', $data, ['id' => $id]) !== false;
    }

    public static function importCsvFile(string $client_id, int $agent_key_id, string $file_path): array
    {
        if (!is_readable($file_path)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV file could not be read.']];
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV file could not be opened.']];
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV header row is missing.']];
        }

        $normalized_header = array_map([self::class, 'normalizeHeader'], $header);
        $missing = array_values(array_diff(self::requiredColumns(), $normalized_header));
        if (!empty($missing)) {
            fclose($handle);
            return [
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => ['Missing required columns: ' . implode(', ', $missing)],
            ];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $seen = [];

        while (($raw = fgetcsv($handle)) !== false) {
            if (count(array_filter($raw, static fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($normalized_header as $i => $key) {
                $row[$key] = isset($raw[$i]) ? trim((string)$raw[$i]) : '';
            }

            $slug = self::normalizeSlug($row['slug'] ?? '');
            if ($slug === '') {
                $slug = self::normalizeSlug($row['page_title'] ?? $row['h1'] ?? '');
            }
            if ($slug === '') {
                $skipped++;
                $errors[] = 'Skipped a row with no usable slug or title.';
                continue;
            }

            if (isset($seen[$slug]) || self::slugExistsForClient($client_id, $slug)) {
                $skipped++;
                $seen[$slug] = true;
                continue;
            }
            $seen[$slug] = true;
            $row['slug'] = $slug;

            if (self::insertRow($client_id, $agent_key_id, $row)) {
                $imported++;
            } else {
                $skipped++;
                $errors[] = 'Could not import row for slug: ' . $slug;
            }
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    public static function slugExistsForClient(string $client_id, string $slug): bool
    {
        global $wpdb;
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return false;
        }

        $queued = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wnq_service_city_pages
                 WHERE client_id=%s AND slug=%s AND status <> 'failed'
                 LIMIT 1",
                $client_id,
                $slug
            )
        );
        if ($queued) {
            return true;
        }

        $urls = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT page_url FROM {$wpdb->prefix}wnq_seo_site_data
                 WHERE client_id=%s AND page_url LIKE %s
                 LIMIT 300",
                $client_id,
                '%' . $wpdb->esc_like($slug) . '%'
            )
        ) ?: [];

        foreach ($urls as $url) {
            $path = trim((string)wp_parse_url($url, PHP_URL_PATH), '/');
            if ($path === '') {
                continue;
            }
            $parts = array_values(array_filter(explode('/', $path)));
            if (end($parts) === $slug) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeSlug(string $slug): string
    {
        $path = trim($slug);
        if (preg_match('#^https?://#i', $path)) {
            $path = (string)wp_parse_url($path, PHP_URL_PATH);
        }
        $path = trim($path, " \t\n\r\0\x0B/");
        if (str_contains($path, '/')) {
            $parts = array_values(array_filter(explode('/', $path)));
            $path = (string)end($parts);
        }
        return sanitize_title($path);
    }

    private static function insertRow(string $client_id, int $agent_key_id, array $row): bool
    {
        global $wpdb;

        $payload = [
            'client_id'       => $client_id,
            'agent_key_id'    => $agent_key_id ?: null,
            'source_hash'     => sha1($client_id . '|' . ($row['slug'] ?? '') . '|' . ($row['primary_keyword'] ?? '')),
            'status'          => 'imported',
            'error_message'   => null,
        ];

        foreach (self::requiredColumns() as $column) {
            $value = (string)($row[$column] ?? '');
            if ($column === 'slug') {
                $value = self::normalizeSlug($value);
            } elseif ($column === 'parent_service_slug') {
                $value = self::normalizeSlug($value);
            } elseif (in_array($column, ['meta_description', 'cta_text', 'internal_links'], true)) {
                $value = sanitize_textarea_field($value);
            } else {
                $value = sanitize_text_field($value);
            }
            $payload[$column] = $value;
        }

        return $wpdb->insert($wpdb->prefix . 'wnq_service_city_pages', $payload) !== false;
    }

    private static function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9_]+/', '_', $header);
        return trim((string)$header, '_');
    }
}
