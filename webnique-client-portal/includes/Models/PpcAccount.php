<?php
/**
 * Canonical client-to-Google Ads account connections.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcAccount
{
    private const TABLE = 'wnq_ppc_accounts';
    private const SCHEMA_VERSION = '1';

    public static function createTable(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            customer_id varchar(20) NOT NULL DEFAULT '',
            manager_customer_id varchar(20) NOT NULL DEFAULT '',
            account_name varchar(255) NOT NULL DEFAULT '',
            connection_status varchar(30) NOT NULL DEFAULT 'disconnected',
            currency_code varchar(10) NOT NULL DEFAULT '',
            time_zone varchar(100) NOT NULL DEFAULT '',
            last_connected_at datetime DEFAULT NULL,
            last_sync_at datetime DEFAULT NULL,
            last_error text DEFAULT NULL,
            metadata_json longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id),
            KEY customer_id (customer_id),
            KEY connection_status (connection_status)
        ) {$charset};");
        update_option('wnq_ppc_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybeUpgrade(): void
    {
        if ((string)get_option('wnq_ppc_schema_version', '') !== self::SCHEMA_VERSION) {
            self::createTable();
        }
    }

    public static function getByClientId(string $client_id): ?array
    {
        global $wpdb;
        $client_id = sanitize_text_field($client_id);
        if ($client_id === '') {
            return null;
        }
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE client_id = %s LIMIT 1", $client_id), ARRAY_A);
        if (!$row) {
            return self::migrateLegacyMapping($client_id);
        }
        $row['metadata'] = self::decodeMetadata((string)($row['metadata_json'] ?? ''));
        unset($row['metadata_json']);
        return $row;
    }

    public static function saveConnection(string $client_id, array $account, string $manager_customer_id): bool
    {
        global $wpdb;
        $client_id = sanitize_text_field($client_id);
        $customer_id = self::customerId((string)($account['customer_id'] ?? ''));
        if ($client_id === '' || strlen($customer_id) !== 10) {
            return false;
        }
        $linked_client_id = self::clientIdForCustomerId($customer_id);
        if ($linked_client_id !== '' && !hash_equals($linked_client_id, $client_id)) {
            return false;
        }

        $now = current_time('mysql');
        $data = [
            'client_id'           => $client_id,
            'customer_id'         => $customer_id,
            'manager_customer_id' => self::customerId($manager_customer_id),
            'account_name'        => sanitize_text_field((string)($account['name'] ?? '')),
            'connection_status'   => 'connected',
            'currency_code'       => sanitize_text_field((string)($account['currency_code'] ?? '')),
            'time_zone'           => sanitize_text_field((string)($account['time_zone'] ?? '')),
            'last_connected_at'   => $now,
            'last_sync_at'        => $now,
            'last_error'          => '',
            'metadata_json'       => wp_json_encode([
                'account_status' => sanitize_key((string)($account['status'] ?? '')),
                'read_only'      => true,
            ]),
        ];

        $table = $wpdb->prefix . self::TABLE;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE client_id = %s", $client_id));
        $saved = $existing
            ? $wpdb->update($table, $data, ['client_id' => $client_id])
            : $wpdb->insert($table, $data);

        if ($saved === false) {
            return false;
        }

        ClientPortal::saveAdsSettings($client_id, [
            'customer_id'         => $customer_id,
            'matched_account_name'=> $data['account_name'],
        ]);
        return true;
    }

    public static function clientIdForCustomerId(string $customer_id): string
    {
        global $wpdb;
        $customer_id = self::customerId($customer_id);
        if (strlen($customer_id) !== 10) {
            return '';
        }
        $table = $wpdb->prefix . self::TABLE;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT client_id FROM {$table} WHERE customer_id = %s LIMIT 1", $customer_id));
    }

    public static function recordTest(string $client_id, bool $ok, array $metadata = [], string $error = ''): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $data = [
            'connection_status' => $ok ? 'connected' : 'error',
            'last_sync_at'      => current_time('mysql'),
            'last_error'        => $ok ? '' : sanitize_textarea_field($error),
        ];
        if ($ok) {
            $data['last_connected_at'] = current_time('mysql');
        }
        if ($metadata) {
            $data['account_name'] = sanitize_text_field((string)($metadata['name'] ?? ''));
            $data['currency_code'] = sanitize_text_field((string)($metadata['currency_code'] ?? ''));
            $data['time_zone'] = sanitize_text_field((string)($metadata['time_zone'] ?? ''));
            $data['metadata_json'] = wp_json_encode([
                'account_status' => sanitize_key((string)($metadata['status'] ?? '')),
                'read_only'      => true,
            ]);
        }
        return $wpdb->update($table, $data, ['client_id' => sanitize_text_field($client_id)]) !== false;
    }

    public static function disconnect(string $client_id): bool
    {
        global $wpdb;
        $client_id = sanitize_text_field($client_id);
        $table = $wpdb->prefix . self::TABLE;
        $deleted = $wpdb->delete($table, ['client_id' => $client_id]);
        ClientPortal::saveAdsSettings($client_id, [
            'customer_id'          => '',
            'matched_account_name' => '',
        ]);
        return $deleted !== false;
    }

    private static function migrateLegacyMapping(string $client_id): ?array
    {
        $legacy = get_option('wnq_google_ads_settings_' . md5($client_id), []);
        if (!is_array($legacy) || self::customerId((string)($legacy['customer_id'] ?? '')) === '') {
            return null;
        }
        $account = [
            'customer_id'  => (string)$legacy['customer_id'],
            'name'         => (string)($legacy['matched_account_name'] ?? ''),
            'status'       => 'unknown',
            'currency_code'=> '',
            'time_zone'    => '',
        ];
        if (!self::saveConnection($client_id, $account, '')) {
            return null;
        }
        return self::getByClientId($client_id);
    }

    private static function customerId(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private static function decodeMetadata(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
