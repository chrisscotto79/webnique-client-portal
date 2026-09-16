<?php
/**
 * Analytics Configuration Model - FIXED
 * 
 * FIX: Changed esc_url_raw to sanitize_text_field for search_console_url
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class AnalyticsConfig
{
    private static string $table = 'wnq_analytics_config';
    private static string $credentials_table = 'wnq_analytics_credentials';

    /**
     * Create database tables
     */
    public static function createTables(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Analytics config table (per client)
        $config_table = $wpdb->prefix . self::$table;
        $sql1 = "CREATE TABLE IF NOT EXISTS $config_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            client_name varchar(255) NOT NULL,
            ga4_property_id varchar(100) DEFAULT NULL,
            search_console_url varchar(255) DEFAULT NULL,
            website_url varchar(255) DEFAULT NULL,
            timezone varchar(100) DEFAULT 'America/New_York',
            phone_numbers longtext DEFAULT NULL,
            form_ids longtext DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) $charset_collate;";

        // Credentials table (single service account)
        $creds_table = $wpdb->prefix . self::$credentials_table;
        $sql2 = "CREATE TABLE IF NOT EXISTS $creds_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            service_account_email varchar(255) NOT NULL,
            credentials_json longtext NOT NULL,
            project_id varchar(255) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            last_tested datetime DEFAULT NULL,
            test_status varchar(50) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
    }

    /**
     * Store service account credentials
     */
    public static function saveCredentials(array $credentials_data): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::$credentials_table;

        // Parse JSON if string
        if (is_string($credentials_data)) {
            $credentials_data = json_decode($credentials_data, true);
        }

        if (!isset($credentials_data['client_email']) || !isset($credentials_data['private_key'])) {
            return false;
        }

        // Encrypt credentials before storing
        $encrypted_json = self::encryptData(wp_json_encode($credentials_data));

        $data = [
            'service_account_email' => sanitize_email($credentials_data['client_email']),
            'credentials_json' => $encrypted_json,
            'project_id' => sanitize_text_field($credentials_data['project_id'] ?? ''),
            'is_active' => 1,
        ];

        // Deactivate all existing credentials
        $wpdb->update($table, ['is_active' => 0], ['is_active' => 1]);

        // Insert new credentials
        $result = $wpdb->insert($table, $data);

        return $result !== false;
    }

    /**
     * Get service account credentials
     */
    public static function getCredentials(): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::$credentials_table;

        $row = $wpdb->get_row(
            "SELECT * FROM $table WHERE is_active = 1 ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        if (!$row || empty($row['credentials_json'])) {
            return null;
        }

        // Decrypt credentials
        $decrypted_json = self::decryptData($row['credentials_json']);
        $credentials = json_decode($decrypted_json, true);

        if (!$credentials || !is_array($credentials)) {
            return null;
        }

        return [
            'credentials' => $credentials,
            'email' => $row['service_account_email'] ?? '',
            'project_id' => $row['project_id'] ?? '',
            'last_tested' => $row['last_tested'] ?? null,
            'test_status' => $row['test_status'] ?? null,
        ];
    }

    /**
     * Save client configuration - FIXED
     */
    public static function saveClientConfig(array $config): int|false
    {
        global $wpdb;
        $table = $wpdb->prefix . self::$table;
        $property = trim((string)($config['ga4_property_id'] ?? ''));
        if (ctype_digit($property)) $property = 'properties/' . $property;
        if ($property !== '' && !preg_match('#^properties/[0-9]+$#', $property)) return false;

        // FIXED: Use sanitize_text_field instead of esc_url_raw for search_console_url
        $data = [
            'client_id' => sanitize_text_field($config['client_id']),
            'client_name' => sanitize_text_field($config['client_name']),
            'ga4_property_id' => $property,
            'search_console_url' => sanitize_text_field($config['search_console_url'] ?? ''),
            'website_url' => esc_url_raw($config['website_url'] ?? ''),
            'timezone' => sanitize_text_field($config['timezone'] ?? 'America/New_York'),
            'phone_numbers' => isset($config['phone_numbers']) ? wp_json_encode($config['phone_numbers']) : null,
            'form_ids' => isset($config['form_ids']) ? wp_json_encode($config['form_ids']) : null,
            'is_active' => 1,
        ];

        // Check if config exists
        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM $table WHERE client_id = %s", $config['client_id'])
        );

        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => $existing]);
            return $result !== false ? $existing : false;
        } else {
            $result = $wpdb->insert($table, $data);
            return $result !== false ? $wpdb->insert_id : false;
        }
    }

    /**
     * Get client configuration
     */
    public static function getClientConfig(string $client_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::$table;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE client_id = %s AND is_active = 1", $client_id),
            ARRAY_A
        );

        if (!$row) {
            $portal = class_exists(Client::class) ? Client::getByClientId($client_id) : null;
            if (!$portal) return null;
            $linked = self::idForPortal($portal);
            return $linked !== $client_id ? self::getClientConfig($linked) : self::portalDefaults($portal);
        }

        // Parse JSON fields
        if (!empty($row['phone_numbers'])) {
            $row['phone_numbers'] = json_decode($row['phone_numbers'], true);
        }
        if (!empty($row['form_ids'])) {
            $row['form_ids'] = json_decode($row['form_ids'], true);
        }

        return $row;
    }

    /**
     * Get all client configurations
     */
    public static function getAllClients(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::$table;

        $results = $wpdb->get_results(
            "SELECT * FROM $table WHERE is_active = 1 ORDER BY client_name ASC",
            ARRAY_A
        );

        $results = $results ?: [];
        $ids = array_column($results, 'client_id');
        foreach (class_exists(Client::class) ? Client::getAll() : [] as $portal) {
            if (!in_array(self::idForPortal($portal, $results), $ids, true)) $results[] = self::portalDefaults($portal);
        }

        // Parse JSON fields
        foreach ($results as &$row) {
            if (!empty($row['phone_numbers'])) {
                $row['phone_numbers'] = json_decode($row['phone_numbers'], true);
            }
            if (!empty($row['form_ids'])) {
                $row['form_ids'] = json_decode($row['form_ids'], true);
            }
        }

        return $results;
    }

    /** Unsaved setup state uses the shared client identity, never another account. */
    private static function portalDefaults(array $client): array
    {
        return [
            'client_id' => $client['client_id'], 'client_name' => $client['company'] ?: $client['name'],
            'website_url' => $client['website'] ?? '', 'ga4_property_id' => $client['google_analytics_property_id'] ?? '',
            'search_console_url' => $client['google_search_console_site_url'] ?? '',
            'timezone' => 'America/New_York', 'phone_numbers' => [], 'form_ids' => [], 'is_active' => 1,
        ];
    }

    /** Preserve legacy analytics IDs/settings; only reuse an unambiguous exact website match. */
    public static function idForPortal(array $portal, ?array $configs = null): string
    {
        global $wpdb;
        $configs = $configs ?? ($wpdb->get_results("SELECT * FROM {$wpdb->prefix}wnq_analytics_config WHERE is_active = 1", ARRAY_A) ?: []);
        foreach ($configs as $config) if ($config['client_id'] === $portal['client_id']) return $portal['client_id'];
        $normalize = static function ($url): string {
            $p = parse_url(trim((string)$url));
            if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['query'])) return '';
            return strtolower(preg_replace('/^www\./i', '', $p['host'])) . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($p['path'] ?? '', '/');
        };
        $site = $normalize($portal['website'] ?? ''); $matches = [];
        if (!$site) return $portal['client_id'];
        // Do not share one legacy config between two portal companies with the same website.
        $owners = array_filter(Client::getAll(), static fn($c) => $normalize($c['website'] ?? '') === $site);
        if (count($owners) !== 1) return $portal['client_id'];
        foreach ($configs as $config) {
            if ($normalize($config['website_url'] ?? '') !== $site) continue;
            if (!empty($portal['google_analytics_property_id']) && !empty($config['ga4_property_id']) && preg_replace('#^properties/#', '', $portal['google_analytics_property_id']) !== preg_replace('#^properties/#', '', $config['ga4_property_id'])) continue;
            $matches[] = $config['client_id'];
        }
        return count($matches) === 1 ? $matches[0] : $portal['client_id'];
    }

    /**
     * Test API connection
     */
    public static function testConnection(string $client_id): array
    {
        return \WNQ\Admin\AnalyticsAdmin::probeConnections($client_id);
    }


    /**
     * Delete client configuration
     */
    public static function deleteClient(string $client_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::$table;

        $result = $wpdb->update(
            $table,
            ['is_active' => 0],
            ['client_id' => $client_id],
            ['%d'],
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Get client count
     */
    public static function getClientCount(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . self::$table;
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_active = 1");
    }

    /**
     * Encrypt sensitive data
     */
    private static function encryptData(string $data): string
    {
        if (!function_exists('openssl_encrypt')) {
            // Fallback to base64 if OpenSSL not available
            return base64_encode($data);
        }

        $key = self::getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        
        return base64_encode($encrypted . '::' . $iv);
    }

    /**
     * Decrypt sensitive data
     */
    private static function decryptData(string $data): string
    {
        if (!function_exists('openssl_decrypt')) {
            // Fallback from base64
            return base64_decode($data);
        }

        $key = self::getEncryptionKey();
        $data = base64_decode($data);
        
        if (strpos($data, '::') === false) {
            // Old format without IV, just base64
            return $data;
        }

        list($encrypted, $iv) = explode('::', $data, 2);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    /**
     * Get encryption key
     */
    private static function getEncryptionKey(): string
    {
        // Use WordPress auth key as encryption key
        if (defined('AUTH_KEY') && AUTH_KEY) {
            return substr(hash('sha256', AUTH_KEY), 0, 32);
        }
        
        // Fallback key (should not happen in production)
        return substr(hash('sha256', 'webnique-portal-encryption'), 0, 32);
    }

    /**
     * Get credentials file path (for storing JSON file)
     */
    public static function getCredentialsPath(): string
    {
        $upload_dir = wp_upload_dir();
        $credentials_dir = $upload_dir['basedir'] . '/webnique-credentials';
        
        // Create directory if it doesn't exist
        if (!file_exists($credentials_dir)) {
            wp_mkdir_p($credentials_dir);
            
            // Create .htaccess to protect directory
            $htaccess = $credentials_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Order Deny,Allow\nDeny from all");
            }
        }
        
        return $credentials_dir . '/service-account.json';
    }
}
