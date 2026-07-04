<?php
/**
 * Client Model
 * 
 * Handles database operations for client records
 * 
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Client
 * Database model for client management
 */
final class Client
{
    private const SCHEMA_VERSION = '3';

    /**
     * Table name
     */
    private static string $table = 'wnq_clients';
    private static bool $schema_ready = false;

    private static function ensureSchema(): void
    {
        if (self::$schema_ready) {
            return;
        }
        self::$schema_ready = true;
        if ((string)get_option('wnq_clients_schema_version', '') !== self::SCHEMA_VERSION) {
            self::createTable();
        }
    }

    /**
     * Create clients table
     */
    public static function createTable(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            company varchar(255) DEFAULT NULL,
            website varchar(255) DEFAULT NULL,
            business_address varchar(500) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            primary_color varchar(20) DEFAULT NULL,
            secondary_color varchar(20) DEFAULT NULL,
            body_font varchar(100) DEFAULT NULL,
            heading_font varchar(100) DEFAULT NULL,
            
            -- Account Status
            status varchar(50) DEFAULT 'active',
            tier varchar(50) DEFAULT 'website',
            
            -- API Keys & Credentials
            google_analytics_property_id varchar(255) DEFAULT NULL,
            google_search_console_site_url varchar(255) DEFAULT NULL,
            google_api_credentials longtext DEFAULT NULL,
            facebook_access_token text DEFAULT NULL,
            other_api_keys longtext DEFAULT NULL,
            
            -- Billing
            billing_email varchar(255) DEFAULT NULL,
            billing_cycle varchar(50) DEFAULT 'monthly',
            monthly_rate decimal(10,2) DEFAULT 0.00,
            stripe_fee_percent decimal(5,2) DEFAULT 2.90,
            stripe_fee_flat decimal(10,2) DEFAULT 0.30,
            after_fees decimal(10,2) DEFAULT 0.00,
            last_payment_date date DEFAULT NULL,
            next_payment_due_date date DEFAULT NULL,
            payment_reminder_days int(11) DEFAULT 3,
            payment_notifications_enabled tinyint(1) DEFAULT 1,
            payment_count int(11) DEFAULT 0,
            total_collected decimal(10,2) DEFAULT 0.00,
            
            -- Services
            active_services longtext DEFAULT NULL,
            
            -- Metadata
            notes longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id),
            UNIQUE KEY email (email),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $wpdb->query(
            "UPDATE $table_name
             SET next_payment_due_date = CASE billing_cycle
                 WHEN 'quarterly' THEN DATE_ADD(last_payment_date, INTERVAL 3 MONTH)
                 WHEN 'annually' THEN DATE_ADD(last_payment_date, INTERVAL 12 MONTH)
                 ELSE DATE_ADD(last_payment_date, INTERVAL 1 MONTH)
             END
             WHERE next_payment_due_date IS NULL AND last_payment_date IS NOT NULL"
        );
        update_option('wnq_clients_schema_version', self::SCHEMA_VERSION, false);
    }

    /**
     * Get all clients
     */
    public static function getAll(): array
    {
        self::ensureSchema();
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $results = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get client by ID
     */
    public static function getById(int $id): ?array
    {
        self::ensureSchema();
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get client by client_id
     */
    public static function getByClientId(string $client_id): ?array
    {
        self::ensureSchema();
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE client_id = %s", $client_id),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Create new client
     */
    public static function create(array $data): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        error_log('WNQ Client::create called with data: ' . print_r($data, true));

        // Required fields
        if (empty($data['client_id']) || empty($data['name']) || empty($data['email'])) {
            error_log('WNQ Client::create - Missing required fields');
            error_log('client_id: ' . ($data['client_id'] ?? 'EMPTY'));
            error_log('name: ' . ($data['name'] ?? 'EMPTY'));
            error_log('email: ' . ($data['email'] ?? 'EMPTY'));
            return false;
        }

        // Prepare data
        $insert_data = [
            'client_id' => sanitize_text_field($data['client_id']),
            'name' => sanitize_text_field($data['name']),
            'email' => sanitize_email($data['email']),
            'phone' => isset($data['phone']) ? sanitize_text_field($data['phone']) : null,
            'company' => isset($data['company']) ? sanitize_text_field($data['company']) : null,
            'website' => isset($data['website']) ? esc_url_raw($data['website']) : null,
            'business_address' => isset($data['business_address']) ? sanitize_text_field($data['business_address']) : null,
            'city' => isset($data['city']) ? sanitize_text_field($data['city']) : null,
            'state' => isset($data['state']) ? sanitize_text_field($data['state']) : null,
            'primary_color' => isset($data['primary_color']) ? (sanitize_hex_color($data['primary_color']) ?: null) : null,
            'secondary_color' => isset($data['secondary_color']) ? (sanitize_hex_color($data['secondary_color']) ?: null) : null,
            'body_font' => isset($data['body_font']) ? sanitize_text_field($data['body_font']) : null,
            'heading_font' => isset($data['heading_font']) ? sanitize_text_field($data['heading_font']) : null,
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'active',
            'tier' => isset($data['tier']) ? sanitize_text_field($data['tier']) : 'website',
        ];

        error_log('WNQ Client::create - Prepared insert data: ' . print_r($insert_data, true));

        // API Keys
        if (isset($data['google_analytics_property_id'])) {
            $insert_data['google_analytics_property_id'] = sanitize_text_field($data['google_analytics_property_id']);
        }
        if (isset($data['google_search_console_site_url'])) {
            $insert_data['google_search_console_site_url'] = esc_url_raw($data['google_search_console_site_url']);
        }
        if (isset($data['google_api_credentials'])) {
            $insert_data['google_api_credentials'] = wp_json_encode($data['google_api_credentials']);
        }
        if (isset($data['facebook_access_token'])) {
            $insert_data['facebook_access_token'] = sanitize_text_field($data['facebook_access_token']);
        }
        if (isset($data['other_api_keys'])) {
            $insert_data['other_api_keys'] = wp_json_encode($data['other_api_keys']);
        }

        // Billing
        if (isset($data['billing_email'])) {
            $insert_data['billing_email'] = sanitize_email($data['billing_email']);
        }
        if (isset($data['billing_cycle'])) {
            $insert_data['billing_cycle'] = sanitize_text_field($data['billing_cycle']);
        }
        if (isset($data['monthly_rate'])) {
            $insert_data['monthly_rate'] = floatval($data['monthly_rate']);
        }
        if (isset($data['stripe_fee_percent'])) {
            $insert_data['stripe_fee_percent'] = floatval($data['stripe_fee_percent']);
        }
        if (isset($data['stripe_fee_flat'])) {
            $insert_data['stripe_fee_flat'] = floatval($data['stripe_fee_flat']);
        }
        if (isset($data['after_fees'])) {
            $insert_data['after_fees'] = floatval($data['after_fees']);
        }
        if (isset($data['last_payment_date'])) {
            $insert_data['last_payment_date'] = sanitize_text_field($data['last_payment_date']);
        }
        if (isset($data['next_payment_due_date'])) {
            $insert_data['next_payment_due_date'] = self::dateValue($data['next_payment_due_date']);
        }
        if (isset($data['payment_reminder_days'])) {
            $insert_data['payment_reminder_days'] = max(0, min(30, intval($data['payment_reminder_days'])));
        }
        if (isset($data['payment_notifications_enabled'])) {
            $insert_data['payment_notifications_enabled'] = !empty($data['payment_notifications_enabled']) ? 1 : 0;
        }
        if (isset($data['payment_count'])) {
            $insert_data['payment_count'] = intval($data['payment_count']);
        }
        if (isset($data['total_collected'])) {
            $insert_data['total_collected'] = floatval($data['total_collected']);
        }

        // Services
        if (isset($data['active_services'])) {
            $insert_data['active_services'] = is_array($data['active_services']) 
                ? wp_json_encode($data['active_services'])
                : $data['active_services'];
        }

        // Notes
        if (isset($data['notes'])) {
            $insert_data['notes'] = wp_kses_post($data['notes']);
        }

        $result = $wpdb->insert($table_name, $insert_data);

        if ($result === false) {
            error_log('WNQ Client::create - Database insert failed');
            error_log('WNQ Client::create - wpdb error: ' . $wpdb->last_error);
            return false;
        }

        error_log('WNQ Client::create - Success! Insert ID: ' . $wpdb->insert_id);
        return $wpdb->insert_id;
    }

    /**
     * Update client
     */
    public static function update(int $id, array $data): bool
    {
        self::ensureSchema();
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        // Prepare update data
        $update_data = [];

        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        if (isset($data['email'])) {
            $update_data['email'] = sanitize_email($data['email']);
        }
        if (isset($data['phone'])) {
            $update_data['phone'] = sanitize_text_field($data['phone']);
        }
        if (isset($data['company'])) {
            $update_data['company'] = sanitize_text_field($data['company']);
        }
        if (isset($data['website'])) {
            $update_data['website'] = esc_url_raw($data['website']);
        }
        foreach (['business_address', 'city', 'state', 'body_font', 'heading_font'] as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = sanitize_text_field($data[$field]);
            }
        }
        foreach (['primary_color', 'secondary_color'] as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = sanitize_hex_color($data[$field]) ?: '';
            }
        }
        if (isset($data['status'])) {
            $update_data['status'] = sanitize_text_field($data['status']);
        }
        if (isset($data['tier'])) {
            $update_data['tier'] = sanitize_text_field($data['tier']);
        }

        // API Keys
        if (isset($data['google_analytics_property_id'])) {
            $update_data['google_analytics_property_id'] = sanitize_text_field($data['google_analytics_property_id']);
        }
        if (isset($data['google_search_console_site_url'])) {
            $update_data['google_search_console_site_url'] = esc_url_raw($data['google_search_console_site_url']);
        }
        if (isset($data['google_api_credentials'])) {
            $update_data['google_api_credentials'] = is_array($data['google_api_credentials'])
                ? wp_json_encode($data['google_api_credentials'])
                : $data['google_api_credentials'];
        }
        if (isset($data['facebook_access_token'])) {
            $update_data['facebook_access_token'] = sanitize_text_field($data['facebook_access_token']);
        }
        if (isset($data['other_api_keys'])) {
            $update_data['other_api_keys'] = is_array($data['other_api_keys'])
                ? wp_json_encode($data['other_api_keys'])
                : $data['other_api_keys'];
        }

        // Billing
        if (isset($data['billing_email'])) {
            $update_data['billing_email'] = sanitize_email($data['billing_email']);
        }
        if (isset($data['billing_cycle'])) {
            $update_data['billing_cycle'] = sanitize_text_field($data['billing_cycle']);
        }
        if (isset($data['monthly_rate'])) {
            $update_data['monthly_rate'] = floatval($data['monthly_rate']);
        }
        if (isset($data['stripe_fee_percent'])) {
            $update_data['stripe_fee_percent'] = floatval($data['stripe_fee_percent']);
        }
        if (isset($data['stripe_fee_flat'])) {
            $update_data['stripe_fee_flat'] = floatval($data['stripe_fee_flat']);
        }
        if (isset($data['after_fees'])) {
            $update_data['after_fees'] = floatval($data['after_fees']);
        }
        if (isset($data['last_payment_date'])) {
            $update_data['last_payment_date'] = sanitize_text_field($data['last_payment_date']);
        }
        if (isset($data['next_payment_due_date'])) {
            $update_data['next_payment_due_date'] = self::dateValue($data['next_payment_due_date']);
        }
        if (isset($data['payment_reminder_days'])) {
            $update_data['payment_reminder_days'] = max(0, min(30, intval($data['payment_reminder_days'])));
        }
        if (isset($data['payment_notifications_enabled'])) {
            $update_data['payment_notifications_enabled'] = !empty($data['payment_notifications_enabled']) ? 1 : 0;
        }
        if (isset($data['payment_count'])) {
            $update_data['payment_count'] = intval($data['payment_count']);
        }
        if (isset($data['total_collected'])) {
            $update_data['total_collected'] = floatval($data['total_collected']);
        }

        // Services
        if (isset($data['active_services'])) {
            $update_data['active_services'] = is_array($data['active_services'])
                ? wp_json_encode($data['active_services'])
                : $data['active_services'];
        }

        // Notes
        if (isset($data['notes'])) {
            $update_data['notes'] = wp_kses_post($data['notes']);
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $id],
            null,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete client
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $result = $wpdb->delete(
            $table_name,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Search clients
     */
    public static function search(string $term): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $like = '%' . $wpdb->esc_like($term) . '%';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE name LIKE %s 
                OR email LIKE %s 
                OR client_id LIKE %s 
                OR company LIKE %s 
                ORDER BY created_at DESC",
                $like, $like, $like, $like
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get clients by status
     */
    public static function getByStatus(string $status): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE status = %s ORDER BY created_at DESC",
                $status
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get client count
     */
    public static function getCount(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }

    /**
     * Get count by status
     */
    public static function getCountByStatus(string $status): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", $status)
        );
    }

    private static function dateValue($value): ?string
    {
        $date = sanitize_text_field((string)$value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return null;
        }
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) ? $date : null;
    }
}
