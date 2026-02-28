<?php
/**
 * LocalSEOEngine
 *
 * Tracks local SEO health: service areas, NAP consistency checks,
 * local keyword tracking, and GMB status per client.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class LocalSEOEngine
{
    const TABLE = 'wnq_local_seo';

    // ── Table Creation ─────────────────────────────────────────────────────

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE . " (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            location_name varchar(200) NOT NULL,
            city varchar(100) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            zip varchar(20) DEFAULT NULL,
            address varchar(300) DEFAULT NULL,
            phone varchar(30) DEFAULT NULL,
            gmb_url varchar(2083) DEFAULT NULL,
            gmb_status varchar(20) DEFAULT 'unknown',
            nap_consistent tinyint DEFAULT NULL,
            local_keywords longtext DEFAULT NULL,
            schema_local_business tinyint DEFAULT 0,
            notes text DEFAULT NULL,
            checked_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client_id (client_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ── Location CRUD ──────────────────────────────────────────────────────

    public static function saveLocation(string $client_id, array $data): int
    {
        global $wpdb;
        $table = "{$wpdb->prefix}" . self::TABLE;

        $id = (int)($data['id'] ?? 0);
        $payload = [
            'client_id'            => $client_id,
            'location_name'        => sanitize_text_field($data['location_name'] ?? ''),
            'city'                 => sanitize_text_field($data['city'] ?? ''),
            'state'                => sanitize_text_field($data['state'] ?? ''),
            'zip'                  => sanitize_text_field($data['zip'] ?? ''),
            'address'              => sanitize_text_field($data['address'] ?? ''),
            'phone'                => sanitize_text_field($data['phone'] ?? ''),
            'gmb_url'              => esc_url_raw($data['gmb_url'] ?? ''),
            'local_keywords'       => wp_json_encode((array)($data['local_keywords'] ?? [])),
            'notes'                => sanitize_textarea_field($data['notes'] ?? ''),
        ];

        if ($id) {
            $wpdb->update($table, $payload, ['id' => $id, 'client_id' => $client_id]);
            return $id;
        } else {
            $wpdb->insert($table, $payload);
            return (int)$wpdb->insert_id;
        }
    }

    public static function deleteLocation(int $id, string $client_id): void
    {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}" . self::TABLE, ['id' => $id, 'client_id' => $client_id]);
    }

    public static function getLocations(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE client_id=%s ORDER BY location_name ASC",
            $client_id
        ), ARRAY_A) ?: [];
    }

    // ── Local Health Audit ─────────────────────────────────────────────────

    public static function auditLocalSEO(string $client_id): array
    {
        $locations = self::getLocations($client_id);
        $profile   = \WNQ\Models\SEOHub::getProfile($client_id) ?? [];
        $stats     = \WNQ\Models\SEOHub::getSiteStats($client_id);

        $issues = [];

        // Check: location pages in profile
        $service_locations = (array)($profile['service_locations'] ?? []);
        if (empty($service_locations)) {
            $issues[] = ['type' => 'no_service_locations', 'severity' => 'warning',
                         'message' => 'No service locations defined in the SEO profile.'];
        }

        // Check: local keywords coverage
        $local_keywords = \WNQ\Models\SEOHub::getKeywords($client_id);
        $with_location  = array_filter($local_keywords, fn($k) => !empty($k['location']));
        if (count($local_keywords) > 0 && count($with_location) < (count($local_keywords) * 0.3)) {
            $issues[] = ['type' => 'few_local_keywords', 'severity' => 'info',
                         'message' => 'Less than 30% of keywords have a location assigned. Add city/region modifiers.'];
        }

        // Check: LocalBusiness schema
        if (($stats['no_schema'] ?? 0) > 0) {
            $issues[] = ['type' => 'missing_local_schema', 'severity' => 'warning',
                         'message' => $stats['no_schema'] . ' pages missing schema. Add LocalBusiness JSON-LD.'];
        }

        // Check: GMB links in locations
        foreach ($locations as $loc) {
            if (empty($loc['gmb_url'])) {
                $issues[] = ['type' => 'missing_gmb', 'severity' => 'info',
                             'message' => "Location '{$loc['location_name']}' has no Google Business Profile URL."];
            }
        }

        // Local keyword coverage by city
        $city_coverage = [];
        foreach ($local_keywords as $kw) {
            if (!empty($kw['location'])) {
                $city_coverage[$kw['location']] = ($city_coverage[$kw['location']] ?? 0) + 1;
            }
        }

        return [
            'locations'      => $locations,
            'issues'         => $issues,
            'city_coverage'  => $city_coverage,
            'local_kw_count' => count($with_location),
            'total_kw_count' => count($local_keywords),
        ];
    }

    // ── Local Keyword Opportunities ────────────────────────────────────────

    public static function getLocalOpportunities(string $client_id): array
    {
        $profile  = \WNQ\Models\SEOHub::getProfile($client_id) ?? [];
        $services = (array)($profile['primary_services'] ?? []);
        $locations = (array)($profile['service_locations'] ?? []);
        $existing  = array_column(\WNQ\Models\SEOHub::getKeywords($client_id), 'keyword');
        $existing  = array_map('strtolower', $existing);

        $opportunities = [];
        foreach ($services as $svc) {
            foreach ($locations as $loc) {
                // Variants
                $variants = [
                    strtolower("$svc $loc"),
                    strtolower("$svc near me"),
                    strtolower("best $svc $loc"),
                    strtolower("$svc company $loc"),
                ];
                foreach ($variants as $kw) {
                    if (!in_array($kw, $existing)) {
                        $opportunities[] = $kw;
                    }
                }
            }
        }

        return array_slice(array_unique($opportunities), 0, 30);
    }
}
