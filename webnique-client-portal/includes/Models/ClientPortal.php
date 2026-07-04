<?php
/**
 * Client portal records and summaries.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

use WNQ\Services\GoogleAdsClient;

if (!defined('ABSPATH')) {
    exit;
}

final class ClientPortal
{
    private const SCHEMA_VERSION = '12';
    private static bool $schema_ready = false;
    private static string $last_error = '';

    public static function ensureSchema(): void
    {
        if (self::$schema_ready) {
            return;
        }
        self::$schema_ready = true;
        global $wpdb;
        $customers_table = $wpdb->prefix . 'wnq_portal_customers';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $customers_table)) === $customers_table;
        if ((string)get_option('wnq_client_portal_schema_version', '') !== self::SCHEMA_VERSION || !$table_exists || self::customerColumnsMissing($customers_table)) {
            self::createTables();
        }
    }

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}wnq_portal_customers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            record_type varchar(30) NOT NULL DEFAULT 'lead',
            pipeline_stage varchar(100) NOT NULL DEFAULT 'new',
            name varchar(255) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            address varchar(500) DEFAULT NULL,
            job_address varchar(500) DEFAULT NULL,
            service varchar(255) DEFAULT NULL,
            crew varchar(255) DEFAULT NULL,
            lead_source varchar(100) DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'new',
            follow_up_date date DEFAULT NULL,
            reminder_date date DEFAULT NULL,
            job_date date DEFAULT NULL,
            completion_date date DEFAULT NULL,
            job_count int(11) NOT NULL DEFAULT 0,
            estimated_value decimal(12,2) NOT NULL DEFAULT 0.00,
            final_value decimal(12,2) NOT NULL DEFAULT 0.00,
            job_cost decimal(12,2) NOT NULL DEFAULT 0.00,
            notes text DEFAULT NULL,
            internal_notes text DEFAULT NULL,
            lost_reason text DEFAULT NULL,
            files longtext DEFAULT NULL,
            before_photos longtext DEFAULT NULL,
            after_photos longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY record_type (record_type),
            KEY pipeline_stage (pipeline_stage),
            KEY status (status),
            KEY follow_up_date (follow_up_date),
            KEY reminder_date (reminder_date),
            KEY job_date (job_date)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_portal_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            sender_user_id bigint(20) UNSIGNED DEFAULT NULL,
            sender_role varchar(20) NOT NULL DEFAULT 'client',
            ticket_key varchar(40) DEFAULT NULL,
            category varchar(50) DEFAULT 'general',
            priority varchar(20) DEFAULT 'normal',
            ticket_status varchar(20) DEFAULT 'open',
            subject varchar(255) DEFAULT NULL,
            message text NOT NULL,
            attachments longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'unread',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY ticket_key (ticket_key),
            KEY ticket_status (ticket_status),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_portal_learning_requests (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            request_type varchar(50) NOT NULL DEFAULT 'topic',
            title varchar(255) NOT NULL,
            details text DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'submitted',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_portal_requests (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            request_key varchar(40) NOT NULL,
            request_type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            details text DEFAULT NULL,
            priority varchar(20) NOT NULL DEFAULT 'normal',
            status varchar(20) NOT NULL DEFAULT 'submitted',
            request_data longtext DEFAULT NULL,
            attachments longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY request_key (request_key),
            KEY client_id (client_id),
            KEY request_type (request_type),
            KEY status (status)
        ) $charset;");

        self::ensureCustomerColumns();
        self::ensureCustomerIndexes();
        self::migrateCustomerRecordTypes();
        self::migrateLegacyAttachments();
        update_option('wnq_client_portal_schema_version', self::SCHEMA_VERSION, false);
    }

    private static function ensureCustomerColumns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_portal_customers';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $safe_table = self::sqlIdentifier($table);
        $existing = self::tableColumns($table);
        foreach (self::customerColumnDefinitions() as $column => $definition) {
            if (in_array($column, $existing, true)) {
                continue;
            }
            $result = $wpdb->query("ALTER TABLE {$safe_table} ADD COLUMN {$definition}");
            if (false === $result && $wpdb->last_error) {
                self::$last_error = $wpdb->last_error;
            }
        }
    }

    private static function ensureCustomerIndexes(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_portal_customers';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $safe_table = self::sqlIdentifier($table);
        $indexes = $wpdb->get_col("SHOW INDEX FROM {$safe_table}", 2) ?: [];
        $columns = self::tableColumns($table);
        foreach (['record_type', 'pipeline_stage', 'status', 'follow_up_date', 'reminder_date', 'job_date'] as $index) {
            if (!in_array($index, $columns, true) || in_array($index, $indexes, true)) {
                continue;
            }
            $safe_index = self::sqlIdentifier($index);
            $result = $wpdb->query("ALTER TABLE {$safe_table} ADD INDEX {$safe_index} ({$safe_index})");
            if (false === $result && $wpdb->last_error) {
                self::$last_error = $wpdb->last_error;
            }
        }
    }

    private static function migrateCustomerRecordTypes(): void
    {
        global $wpdb;
        $table = self::sqlIdentifier($wpdb->prefix . 'wnq_portal_customers');
        $wpdb->query(
            "UPDATE {$table} SET record_type='job', job_count=1
             WHERE record_type IN ('customer','lead')
             AND (status IN ('scheduled','in_progress','completed','canceled','won','closed')
                  OR job_date IS NOT NULL OR final_value > 0)"
        );
        $wpdb->query("UPDATE {$table} SET record_type='lead', job_count=0 WHERE record_type='customer'");
        $wpdb->query("UPDATE {$table} SET job_count=1 WHERE record_type='job'");
        $wpdb->query("UPDATE {$table} SET job_count=0 WHERE record_type='lead'");
        if ((string)get_option('wnq_pipeline_stage_migrated', '') !== '1') {
            $wpdb->query("UPDATE {$table} SET pipeline_stage='contacted' WHERE record_type='lead' AND status='contacted' AND pipeline_stage IN ('','new')");
            $wpdb->query("UPDATE {$table} SET pipeline_stage='quote-sent' WHERE record_type='lead' AND status IN ('quoted','estimate_sent') AND pipeline_stage IN ('','new')");
            update_option('wnq_pipeline_stage_migrated', '1', false);
        }
    }

    private static function customerColumnsMissing(string $table): bool
    {
        $existing = self::tableColumns($table);
        if (!$existing) {
            return true;
        }
        foreach (array_keys(self::customerColumnDefinitions()) as $column) {
            if (!in_array($column, $existing, true)) {
                return true;
            }
        }
        return false;
    }

    private static function tableColumns(string $table): array
    {
        global $wpdb;
        return array_map('strval', $wpdb->get_col('SHOW COLUMNS FROM ' . self::sqlIdentifier($table), 0) ?: []);
    }

    private static function sqlIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function customerColumnDefinitions(): array
    {
        return [
            'client_id'       => "`client_id` varchar(100) NOT NULL DEFAULT ''",
            'record_type'     => "`record_type` varchar(30) NOT NULL DEFAULT 'lead'",
            'pipeline_stage'  => "`pipeline_stage` varchar(100) NOT NULL DEFAULT 'new'",
            'name'            => "`name` varchar(255) NOT NULL DEFAULT ''",
            'phone'           => "`phone` varchar(50) DEFAULT NULL",
            'email'           => "`email` varchar(255) DEFAULT NULL",
            'address'         => "`address` varchar(500) DEFAULT NULL",
            'job_address'     => "`job_address` varchar(500) DEFAULT NULL",
            'service'         => "`service` varchar(255) DEFAULT NULL",
            'crew'            => "`crew` varchar(255) DEFAULT NULL",
            'lead_source'     => "`lead_source` varchar(100) DEFAULT NULL",
            'status'          => "`status` varchar(30) NOT NULL DEFAULT 'new'",
            'follow_up_date'  => "`follow_up_date` date DEFAULT NULL",
            'reminder_date'   => "`reminder_date` date DEFAULT NULL",
            'job_date'        => "`job_date` date DEFAULT NULL",
            'completion_date' => "`completion_date` date DEFAULT NULL",
            'job_count'       => "`job_count` int(11) NOT NULL DEFAULT 0",
            'estimated_value' => "`estimated_value` decimal(12,2) NOT NULL DEFAULT 0.00",
            'final_value'     => "`final_value` decimal(12,2) NOT NULL DEFAULT 0.00",
            'job_cost'        => "`job_cost` decimal(12,2) NOT NULL DEFAULT 0.00",
            'notes'           => "`notes` text DEFAULT NULL",
            'internal_notes'  => "`internal_notes` text DEFAULT NULL",
            'lost_reason'     => "`lost_reason` text DEFAULT NULL",
            'files'           => "`files` longtext DEFAULT NULL",
            'before_photos'   => "`before_photos` longtext DEFAULT NULL",
            'after_photos'    => "`after_photos` longtext DEFAULT NULL",
            'created_at'      => "`created_at` datetime DEFAULT CURRENT_TIMESTAMP",
            'updated_at'      => "`updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
    }

    public static function getCustomers(string $client_id, int $limit = 100, bool $include_private = true): array
    {
        self::ensureSchema();
        self::ensureCustomerColumns();
        global $wpdb;
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_customers WHERE client_id=%s ORDER BY updated_at DESC, id DESC LIMIT %d",
            $client_id,
            $limit
        ), ARRAY_A) ?: [];
        $records = array_map([self::class, 'hydrateCustomerRecord'], $rows);
        return $include_private ? $records : array_map([self::class, 'clientSafeCustomerRecord'], $records);
    }

    public static function getCustomer(int $id, string $client_id): ?array
    {
        self::ensureSchema();
        self::ensureCustomerColumns();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_customers WHERE id=%d AND client_id=%s",
            $id,
            $client_id
        ), ARRAY_A);
        return $row ? self::hydrateCustomerRecord($row) : null;
    }

    public static function publicCustomerRecord(array $row): array
    {
        return self::clientSafeCustomerRecord($row);
    }

    public static function saveCustomer(string $client_id, array $data): int|false
    {
        self::ensureSchema();
        self::ensureCustomerColumns();
        self::ensureCustomerIndexes();
        global $wpdb;
        self::$last_error = '';
        $id = absint($data['id'] ?? 0);
        $existing_record = $id > 0 ? self::getCustomer($id, $client_id) : null;
        if ($existing_record) {
            $data = array_merge($existing_record, $data);
        }
        $name = (string)($data['name'] ?? $data['customer_name'] ?? $data['customerName'] ?? '');
        $record_type = sanitize_key($data['record_type'] ?? 'lead');
        if (!in_array($record_type, ['lead', 'job'], true)) {
            $record_type = 'lead';
        }
        $status = sanitize_key($data['status'] ?? 'new');
        if (!in_array($status, self::customerStatuses(), true)) {
            $status = 'new';
        }
        $status = self::normalizeCustomerStatus($status);
        if (in_array($status, ['scheduled', 'in_progress', 'completed', 'canceled'], true)) {
            $record_type = 'job';
        }
        $follow_up = self::dateField($data['follow_up_date'] ?? '');
        $reminder_date = self::dateField($data['reminder_date'] ?? '');
        $job_date = self::dateField($data['job_date'] ?? '');
        $completion_date = self::dateField($data['completion_date'] ?? '');
        $job_count = $record_type === 'job' ? 1 : 0;
        $final_value = self::moneyAmount($data['final_value'] ?? 0);
        $pipeline_stages = self::getPortalSettings($client_id)['crm']['pipeline_stages'] ?? self::defaultPipelineStages();
        $pipeline_keys = array_column($pipeline_stages, 'key');
        $pipeline_stage = sanitize_key((string)($data['pipeline_stage'] ?? $existing_record['pipeline_stage'] ?? $pipeline_keys[0] ?? 'new'));
        if (!in_array($pipeline_stage, $pipeline_keys, true)) {
            $pipeline_stage = (string)($pipeline_keys[0] ?? 'new');
        }
        $record = [
            'client_id'       => sanitize_text_field($client_id),
            'record_type'     => $record_type,
            'pipeline_stage'  => $pipeline_stage,
            'name'            => sanitize_text_field($name),
            'phone'           => sanitize_text_field((string)($data['phone'] ?? '')),
            'email'           => sanitize_email((string)($data['email'] ?? '')),
            'address'         => sanitize_text_field((string)($data['address'] ?? '')),
            'job_address'     => sanitize_text_field((string)($data['job_address'] ?? '')),
            'service'         => sanitize_text_field((string)($data['service'] ?? '')),
            'crew'            => sanitize_text_field((string)($data['crew'] ?? '')),
            'lead_source'     => sanitize_text_field((string)($data['lead_source'] ?? '')),
            'status'          => $status,
            'follow_up_date'  => $follow_up ?: null,
            'reminder_date'   => $reminder_date ?: null,
            'job_date'        => $job_date ?: null,
            'completion_date' => $completion_date ?: null,
            'job_count'       => $job_count,
            'estimated_value' => self::moneyAmount($data['estimated_value'] ?? 0),
            'final_value'     => $final_value,
            'job_cost'        => self::moneyAmount($data['job_cost'] ?? 0),
            'notes'           => sanitize_textarea_field((string)($data['notes'] ?? '')),
            'internal_notes'  => sanitize_textarea_field((string)($data['internal_notes'] ?? '')),
            'lost_reason'     => sanitize_textarea_field((string)($data['lost_reason'] ?? '')),
        ];
        if ($record['name'] === '') {
            return false;
        }
        foreach (['files', 'before_photos', 'after_photos'] as $attachment_key) {
            if (!empty($data[$attachment_key]) && is_array($data[$attachment_key])) {
                $existing = [];
                if ($existing_record) {
                    $existing = is_array($existing_record[$attachment_key] ?? null) ? $existing_record[$attachment_key] : [];
                }
                $record[$attachment_key] = wp_json_encode(array_values(array_merge($existing, $data[$attachment_key])));
            }
        }
        $table = $wpdb->prefix . 'wnq_portal_customers';
        $available_columns = array_flip(self::tableColumns($table));
        if ($available_columns) {
            $record = array_intersect_key($record, $available_columns);
        }
        if (!isset($record['name']) || $record['name'] === '') {
            return false;
        }
        if ($id > 0 && $existing_record) {
            $result = $wpdb->update($table, $record, ['id' => $id, 'client_id' => $client_id]);
            if ($result === false) {
                self::$last_error = (string)$wpdb->last_error;
                return false;
            }
            $saved_record = self::getCustomer($id, $client_id) ?: $record;
            do_action('wnq_portal_crm_record_saved', $id, $client_id, $saved_record, true);
            return $id;
        }
        $result = $wpdb->insert($table, $record);
        if ($result === false) {
            self::$last_error = (string)$wpdb->last_error;
            return false;
        }
        $insert_id = (int)$wpdb->insert_id;
        $saved_record = self::getCustomer($insert_id, $client_id) ?: $record;
        do_action('wnq_portal_crm_record_saved', $insert_id, $client_id, $saved_record, false);
        return $insert_id;
    }

    public static function convertLeadToJob(string $client_id, int $id): int|false
    {
        self::ensureSchema();
        self::ensureCustomerColumns();
        global $wpdb;
        self::$last_error = '';
        $record = self::getCustomer($id, $client_id);
        if (!$record || ($record['record_type'] ?? '') === 'job') {
            return $record ? $id : false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'wnq_portal_customers',
            [
                'record_type' => 'job',
                'status'      => 'scheduled',
                'job_count'   => 1,
                'follow_up_date' => null,
                'reminder_date'  => null,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $id, 'client_id' => $client_id],
            ['%s', '%s', '%d', '%s', '%s', '%s'],
            ['%d', '%s']
        );
        if ($result === false) {
            self::$last_error = (string)$wpdb->last_error;
            return false;
        }
        do_action('wnq_portal_lead_converted', $id, $client_id, self::getCustomer($id, $client_id) ?: $record);
        return $id;
    }

    public static function updateOpportunityStage(string $client_id, int $id, string $stage): int|false
    {
        self::ensureSchema();
        self::ensureCustomerColumns();
        global $wpdb;
        self::$last_error = '';
        $record = self::getCustomer($id, $client_id);
        if (!$record || ($record['record_type'] ?? '') !== 'lead') {
            return false;
        }
        $stages = self::getPortalSettings($client_id)['crm']['pipeline_stages'] ?? self::defaultPipelineStages();
        $keys = array_column($stages, 'key');
        $stage = sanitize_key($stage);
        if ($stage === '' || !in_array($stage, $keys, true)) {
            return false;
        }
        $result = $wpdb->update(
            $wpdb->prefix . 'wnq_portal_customers',
            ['pipeline_stage' => $stage, 'updated_at' => current_time('mysql')],
            ['id' => $id, 'client_id' => $client_id],
            ['%s', '%s'],
            ['%d', '%s']
        );
        if ($result === false) {
            self::$last_error = (string)$wpdb->last_error;
            return false;
        }
        return $id;
    }

    public static function lastError(): string
    {
        return self::$last_error;
    }

    public static function getCustomerSummary(string $client_id, bool $include_private = true): array
    {
        self::ensureSchema();
        self::ensureCustomerColumns();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total,
                SUM(status IN ('new','contacted','quoted','estimate_sent')) new_count,
                SUM(status IN ('scheduled','in_progress')) scheduled_count,
                SUM(status IN ('completed','won','closed')) completed_count,
                SUM(status IN ('lost','canceled')) lost_count,
                COALESCE(SUM(job_count),0) job_count,
                COALESCE(SUM(final_value),0) revenue,
                COALESCE(SUM(job_cost),0) cost
             FROM {$wpdb->prefix}wnq_portal_customers WHERE client_id=%s",
            $client_id
        ), ARRAY_A) ?: [];
        return [
            'total'           => absint($row['total'] ?? 0),
            'new_count'       => absint($row['new_count'] ?? 0),
            'scheduled_count' => absint($row['scheduled_count'] ?? 0),
            'completed_count' => absint($row['completed_count'] ?? 0),
            'lost_count'      => absint($row['lost_count'] ?? 0),
            'job_count'       => absint($row['job_count'] ?? 0),
            'revenue'         => (float)($row['revenue'] ?? 0),
            'cost'            => $include_private ? (float)($row['cost'] ?? 0) : null,
            'profit'          => $include_private ? (float)($row['revenue'] ?? 0) - (float)($row['cost'] ?? 0) : null,
        ];
    }

    public static function customerStatuses(): array
    {
        return ['new', 'contacted', 'quoted', 'scheduled', 'in_progress', 'completed', 'lost', 'canceled', 'estimate_sent', 'won', 'closed'];
    }

    private static function hydrateCustomerRecord(array $row): array
    {
        $row['status'] = self::normalizeCustomerStatus((string)($row['status'] ?? 'new'));
        foreach (['files', 'before_photos', 'after_photos'] as $key) {
            $row[$key] = self::hydrateAttachments(self::jsonArray($row[$key] ?? ''), (string)($row['client_id'] ?? ''));
        }
        $row['profit'] = (float)($row['final_value'] ?? 0) - (float)($row['job_cost'] ?? 0);
        return $row;
    }

    private static function clientSafeCustomerRecord(array $row): array
    {
        unset($row['internal_notes'], $row['job_cost'], $row['profit']);
        return $row;
    }

    private static function normalizeCustomerStatus(string $status): string
    {
        return match ($status) {
            'estimate_sent' => 'quoted',
            'won', 'closed' => 'completed',
            default => in_array($status, ['new', 'contacted', 'quoted', 'scheduled', 'in_progress', 'completed', 'lost', 'canceled'], true) ? $status : 'new',
        };
    }

    public static function getAdsSpendSnapshot(string $client_id, bool $refresh = false): array
    {
        $settings = self::adsSettings($client_id, false);
        $customer_id = (string)($settings['customer_id'] ?? '');
        $snapshot = [
            'has_linked_account' => $customer_id !== '',
            'configured' => false,
            'customer_id' => $customer_id,
            'account_name' => (string)($settings['matched_account_name'] ?? ''),
            'spend' => 0.0,
            'threshold' => max(0.0, (float)($settings['spend_alert_threshold'] ?? 0)),
            'period' => current_time('Y-m'),
            'errors' => [],
        ];
        if ($customer_id === '' || !class_exists(GoogleAdsClient::class)) {
            return $snapshot;
        }

        $ads = new GoogleAdsClient($settings);
        if (!$ads->isConfigured()) {
            $snapshot['errors'] = $ads->errors() ?: ['Google Ads credentials are incomplete.'];
            return $snapshot;
        }

        $snapshot['spend'] = $ads->monthlySpend($customer_id, $refresh);
        $snapshot['errors'] = $ads->errors();
        $snapshot['configured'] = $snapshot['errors'] === [];
        return $snapshot;
    }

    public static function getAdsResource(string $client_id, bool $include_financial = true, bool $refresh = false): array
    {
        $settings = self::adsSettings($client_id);
        $raw_settings = self::adsSettings($client_id, false);
        $has_developer_token = !empty($raw_settings['developer_token']);
        $has_manager_customer_id = !empty($raw_settings['manager_customer_id']);
        $has_oauth_client_id = !empty($raw_settings['oauth_client_id']);
        $has_oauth_client_secret = !empty($raw_settings['oauth_client_secret']);
        $has_refresh_token = !empty($raw_settings['refresh_token']);
        $has_oauth = $has_oauth_client_id && $has_oauth_client_secret && $has_refresh_token;
        $client = Client::getByClientId($client_id) ?: [];
        $match = null;
        $ads_errors = [];
        $diagnostics = [];
        $summary = [
            'spend' => 0,
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'conversions' => 0,
            'cost_per_conversion' => 0,
        ];
        $campaigns = [];
        $search_terms = [];
        $keywords = [];
        $landing_pages = [];
        $devices = [];
        $billing = [];
        $account = [];
        $available_accounts = [];
        $access_level = (string)get_option('wnq_google_ads_access_level', 'test');
        $api_connection_verified = false;

        if (class_exists(GoogleAdsClient::class)) {
            $ads = new GoogleAdsClient($raw_settings);
            if ($ads->isConfigured()) {
                if ((string)($raw_settings['customer_id'] ?? '') === '') {
                    $match = $ads->matchClient($client, $refresh);
                    if ($match && (int)($match['match_score'] ?? 0) >= 70) {
                        $raw_settings['customer_id'] = (string)$match['customer_id'];
                        $settings['customer_id'] = (string)$match['customer_id'];
                        $settings['matched_account_name'] = (string)$match['name'];
                        self::saveAdsSettings($client_id, [
                            'customer_id' => (string)$match['customer_id'],
                            'matched_account_name' => (string)$match['name'],
                        ]);
                    }
                    $available_accounts = $ads->listManagerAccounts(false);
                } else {
                    $available_accounts = $ads->listManagerAccounts($refresh);
                }
                if ((string)($raw_settings['customer_id'] ?? '') !== '') {
                    foreach ($available_accounts as $listed_account) {
                        if (preg_replace('/\D+/', '', (string)($listed_account['customer_id'] ?? '')) === preg_replace('/\D+/', '', (string)$raw_settings['customer_id'])) {
                            $account = $listed_account;
                            break;
                        }
                    }
                    $performance = $ads->accountPerformance((string)$raw_settings['customer_id'], $refresh);
                    $summary = $performance['summary'] ?? $summary;
                    $campaigns = $performance['campaigns'] ?? [];
                    $search_terms = $performance['search_terms'] ?? [];
                    $keywords = $performance['keywords'] ?? [];
                    $landing_pages = $performance['landing_pages'] ?? [];
                    $devices = $performance['devices'] ?? [];
                    $billing = $ads->billingSummary((string)$raw_settings['customer_id'], $refresh);
                }
                $ads_errors = $ads->errors();
                $api_connection_verified = !empty($available_accounts) && empty($ads_errors);
                if ((string)($raw_settings['customer_id'] ?? '') === '' && !$match && empty($ads_errors)) {
                    $diagnostics[] = 'No linked client Google Ads account was returned from the manager account. Confirm the client account accepted the manager invitation and that the OAuth user can view it.';
                } elseif ((string)($raw_settings['customer_id'] ?? '') === '' && $match) {
                    $diagnostics[] = 'A possible Google Ads account match was found, but the name match was below the automatic connection threshold. Enter the customer ID manually or update the Ads account name to match the client more closely.';
                }
            } else {
                if (!$has_developer_token) {
                    $diagnostics[] = 'Google Ads developer token is missing.';
                }
                if (!$has_manager_customer_id) {
                    $diagnostics[] = 'Google Ads manager customer ID is missing.';
                }
                if (!$has_oauth) {
                    $diagnostics[] = 'OAuth client ID, client secret, and refresh token are required. A Google Ads API key alone cannot fetch account or campaign data.';
                }
            }
        } else {
            $diagnostics[] = 'Google Ads API client class is not loaded in the plugin.';
        }

        if ($access_level === 'test' && !$api_connection_verified) {
            $diagnostics[] = 'Your developer token is labeled Test Account Access. Production client accounts will not return live data until Google approves Basic Access, or unless you connect Google Ads test accounts.';
        } elseif ($access_level === 'test' && $api_connection_verified) {
            $diagnostics[] = 'Google Ads is responding successfully. The portal Access Level label is still set to Test Account Access; update it to Basic Access in WordPress settings so the displayed label matches your Google approval.';
        }

        $has_production_access = $access_level !== 'test' || $api_connection_verified;
        $ready = $has_developer_token && $has_manager_customer_id && !empty($raw_settings['customer_id']) && $has_oauth && $has_production_access;
        $has_report_data = !empty($campaigns)
            || !empty($search_terms)
            || !empty($keywords)
            || !empty($landing_pages)
            || !empty($devices)
            || (int)($summary['clicks'] ?? 0) > 0
            || (int)($summary['impressions'] ?? 0) > 0
            || (float)($summary['conversions'] ?? 0) > 0;
        $data_status = 'setup_needed';
        if ($ready && !empty($ads_errors)) {
            $data_status = 'api_attention';
        } elseif ($ready && $has_report_data) {
            $data_status = 'report_ready';
        } elseif ($ready) {
            $data_status = 'connected_empty';
        } elseif ($has_developer_token && $has_manager_customer_id && $has_oauth && !empty($available_accounts)) {
            $data_status = 'account_link_needed';
        }
        if (!$include_financial) {
            unset($summary['spend'], $summary['cost_per_conversion'], $summary['average_cpc'], $summary['cost'], $summary['cost_micros'], $summary['budget']);
            $campaigns = array_map(static function (array $campaign): array {
                unset($campaign['spend'], $campaign['cost_per_conversion'], $campaign['average_cpc'], $campaign['cost'], $campaign['cost_micros'], $campaign['budget']);
                return $campaign;
            }, $campaigns);
        }

        return [
            'configured' => $ready,
            'data_status' => $data_status,
            'mode' => 'read_only',
            'reporting_window' => 'This month',
            'last_checked' => current_time('mysql'),
            'access_level' => $access_level,
            'access_status_label' => $api_connection_verified ? 'Verified connection' : ucfirst($access_level) . ' access',
            'api_connection_verified' => $api_connection_verified,
            'access_label_needs_update' => $access_level === 'test' && $api_connection_verified,
            'customer_id' => (string)($settings['customer_id'] ?? ''),
            'manager_customer_id' => (string)($settings['manager_customer_id'] ?? ''),
            'matched_account_name' => (string)($account['name'] ?? $settings['matched_account_name'] ?? ($match['name'] ?? '')),
            'spend_alert_threshold' => (float)($raw_settings['spend_alert_threshold'] ?? 0),
            'currency_code' => (string)($account['currency_code'] ?? 'USD'),
            'time_zone' => (string)($account['time_zone'] ?? ''),
            'match_score' => (int)($match['match_score'] ?? 0),
            'has_developer_token' => $has_developer_token,
            'has_manager_customer_id' => $has_manager_customer_id,
            'has_oauth_client_id' => $has_oauth_client_id,
            'has_oauth_client_secret' => $has_oauth_client_secret,
            'has_refresh_token' => $has_refresh_token,
            'has_oauth' => $has_oauth,
            'summary' => $summary,
            'campaigns' => $campaigns,
            'search_terms' => $search_terms,
            'keywords' => $keywords,
            'landing_pages' => $landing_pages,
            'devices' => $devices,
            'billing' => $billing,
            'available_accounts' => $available_accounts,
            'available_accounts_count' => count($available_accounts),
            'has_report_data' => $has_report_data,
            'errors' => $ads_errors,
            'diagnostics' => $diagnostics,
            'setup_checks' => [
                ['label' => 'Manager customer ID', 'ok' => $has_manager_customer_id],
                ['label' => 'Developer token', 'ok' => $has_developer_token],
                ['label' => 'OAuth client ID', 'ok' => $has_oauth_client_id],
                ['label' => 'OAuth client secret', 'ok' => $has_oauth_client_secret],
                ['label' => 'OAuth refresh token', 'ok' => $has_refresh_token],
                ['label' => 'Matched client account', 'ok' => !empty($raw_settings['customer_id'])],
                ['label' => 'Google Ads API connection', 'ok' => $has_production_access],
            ],
            'requirements' => array_values(array_filter([
                $has_manager_customer_id ? '' : 'Google Ads manager customer ID',
                $has_developer_token ? '' : 'Google Ads developer token',
                $has_oauth ? '' : 'OAuth client ID, client secret, and refresh token',
                !empty($raw_settings['customer_id']) ? '' : 'Client Ads account linked under the manager account',
                $has_production_access ? '' : 'Google Ads API Basic Access for production accounts',
            ])),
        ];
    }

    public static function getAdsPublicStatus(string $client_id): array
    {
        $stored = get_option(self::adsOptionKey($client_id), []);
        $has_linked_account = is_array($stored) && trim((string)($stored['customer_id'] ?? '')) !== '';
        return [
            'configured' => false,
            'has_linked_account' => $has_linked_account,
            'mode' => 'internal_reporting_only',
            'message' => $has_linked_account
                ? 'Google Ads reporting is being prepared for this account.'
                : 'No Google Ads account is currently connected to this client.',
        ];
    }

    public static function saveAdsSettings(string $client_id, array $data): bool
    {
        $stored = get_option(self::adsOptionKey($client_id), []);
        $settings = is_array($stored) ? $stored : [];
        $allowed = ['customer_id', 'matched_account_name', 'spend_alert_threshold'];
        $inherited_keys = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string)$data[$key]);
            if ($value === 'Saved' || str_starts_with($value, 'Saved -')) {
                continue;
            }
            if ($value === '' && in_array($key, $inherited_keys, true)) {
                unset($settings[$key]);
                continue;
            }
            if ($key === 'customer_id') {
                $settings[$key] = preg_replace('/[^0-9-]/', '', $value);
                if ($settings[$key] === '') {
                    unset($settings['matched_account_name']);
                }
            } elseif ($key === 'spend_alert_threshold') {
                $settings[$key] = max(0, round((float)preg_replace('/[^0-9.\-]/', '', $value), 2));
            } else {
                $settings[$key] = sanitize_text_field($value);
            }
        }
        update_option(self::adsOptionKey($client_id), $settings, false);
        delete_transient('wnq_google_ads_accounts_' . md5((string)($settings['manager_customer_id'] ?? get_option('wnq_google_ads_manager_customer_id', ''))));
        delete_transient('wnq_google_ads_accounts_' . md5((string)get_option('wnq_google_ads_manager_customer_id', '')));
        return true;
    }

    private static function adsSettings(string $client_id, bool $masked = true): array
    {
        $settings = get_option(self::adsOptionKey($client_id), []);
        $settings = is_array($settings) ? $settings : [];
        $settings = array_intersect_key($settings, array_flip(['customer_id', 'matched_account_name', 'spend_alert_threshold']));
        $defaults = [
            'developer_token' => (string)get_option('wnq_google_ads_developer_token', ''),
            'customer_id' => '',
            'manager_customer_id' => (string)get_option('wnq_google_ads_manager_customer_id', ''),
            'oauth_client_id' => (string)get_option('wnq_google_ads_oauth_client_id', ''),
            'oauth_client_secret' => (string)get_option('wnq_google_ads_oauth_client_secret', ''),
            'refresh_token' => (string)get_option('wnq_google_ads_refresh_token', ''),
            'matched_account_name' => '',
            'spend_alert_threshold' => 0,
        ];
        $settings = array_merge($defaults, $settings);
        if ($masked) {
            foreach (['developer_token', 'oauth_client_id', 'oauth_client_secret', 'refresh_token'] as $secret) {
                if (!empty($settings[$secret])) {
                    $settings[$secret] = 'Saved';
                }
            }
        }
        return $settings;
    }

    private static function adsOptionKey(string $client_id): string
    {
        return 'wnq_google_ads_settings_' . md5($client_id);
    }

    public static function getMonthlyPerformance(string $client_id, int $months = 6, bool $include_private = true): array
    {
        self::ensureSchema();
        self::ensureCustomerColumns();
        global $wpdb;
        $months = max(3, min(12, $months));
        $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(COALESCE(job_date, created_at), '%%Y-%%m') month_key,
                COALESCE(SUM(job_count),0) jobs,
                COALESCE(SUM(final_value),0) revenue,
                COALESCE(SUM(job_cost),0) cost
             FROM {$wpdb->prefix}wnq_portal_customers
             WHERE client_id=%s AND COALESCE(job_date, DATE(created_at)) >= %s
             GROUP BY month_key ORDER BY month_key ASC",
            $client_id,
            $start
        ), ARRAY_A) ?: [];
        $indexed = array_column($rows, null, 'month_key');
        $result = [];
        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $timestamp = strtotime('-' . $offset . ' months');
            $key = date('Y-m', $timestamp);
            $row = $indexed[$key] ?? [];
            $revenue = (float)($row['revenue'] ?? 0);
            $cost = (float)($row['cost'] ?? 0);
            $result[] = [
                'month'   => $key,
                'label'   => date('M', $timestamp),
                'jobs'    => absint($row['jobs'] ?? 0),
                'revenue' => $revenue,
                'cost'    => $include_private ? $cost : null,
                'profit'  => $include_private ? $revenue - $cost : null,
            ];
        }
        return $result;
    }

    private static function moneyAmount($value): float
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', (string)$value);
        return max(0, round((float)$normalized, 2));
    }

    public static function getMessages(string $client_id, int $limit = 50): array
    {
        self::ensureSchema();
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_messages WHERE client_id=%s ORDER BY created_at DESC, id DESC LIMIT %d",
            $client_id,
            max(1, min(200, $limit))
        ), ARRAY_A) ?: [];
        return array_map(static function (array $row): array {
            $row['attachments'] = self::hydrateAttachments(self::jsonArray($row['attachments'] ?? ''), (string)$row['client_id']);
            return $row;
        }, $rows);
    }

    public static function getTickets(string $client_id, int $limit = 50): array
    {
        self::ensureSchema();
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $table = $wpdb->prefix . 'wnq_portal_messages';
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET ticket_key=CONCAT('legacy-', id) WHERE client_id=%s AND (ticket_key IS NULL OR ticket_key='')",
            $client_id
        ));
        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT ticket_key FROM $table WHERE client_id=%s GROUP BY ticket_key ORDER BY MAX(created_at) DESC, MAX(id) DESC LIMIT %d",
            $client_id,
            $limit
        )) ?: [];
        return array_values(array_filter(array_map(
            static fn($key) => self::getTicket($client_id, sanitize_key((string)$key)),
            $keys
        )));
    }

    public static function getTicket(string $client_id, string $ticket_key): ?array
    {
        self::ensureSchema();
        global $wpdb;
        if ($client_id === '' || $ticket_key === '') return null;
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_messages WHERE client_id=%s AND ticket_key=%s ORDER BY created_at ASC, id ASC",
            $client_id,
            $ticket_key
        ), ARRAY_A) ?: [];
        if (!$messages) return null;
        $messages = array_map(static function (array $row): array {
            $row['attachments'] = self::hydrateAttachments(self::jsonArray($row['attachments'] ?? ''), (string)$row['client_id']);
            return $row;
        }, $messages);
        $first = $messages[0];
        $latest = $messages[count($messages) - 1];
        return [
            'ticket_key'    => $ticket_key,
            'subject'       => (string)($latest['subject'] ?: $first['subject'] ?: 'Support request'),
            'category'      => (string)($latest['category'] ?: $first['category'] ?: 'general'),
            'priority'      => (string)($latest['priority'] ?: $first['priority'] ?: 'normal'),
            'ticket_status' => (string)($latest['ticket_status'] ?: 'open'),
            'created_at'    => (string)$first['created_at'],
            'updated_at'    => (string)$latest['created_at'],
            'unread'        => count(array_filter($messages, static fn($message) => ($message['status'] ?? '') === 'unread' && ($message['sender_role'] ?? '') === 'admin')) > 0,
            'response_time' => self::responseTime((string)($latest['priority'] ?: 'normal')),
            'messages'      => $messages,
        ];
    }

    public static function getUnreadMessageCount(?string $client_id = null, string $sender_role = 'client'): int
    {
        self::ensureSchema();
        global $wpdb;
        $role = $sender_role === 'admin' ? 'admin' : 'client';
        if ($client_id !== null && $client_id !== '') {
            return (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wnq_portal_messages WHERE client_id=%s AND sender_role=%s AND status='unread'",
                $client_id,
                $role
            ));
        }
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wnq_portal_messages WHERE sender_role=%s AND status='unread'",
            $role
        ));
    }

    public static function getUnreadClientMessages(int $limit = 20): array
    {
        self::ensureSchema();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, COALESCE(c.company,c.name,m.client_id) client_name
             FROM {$wpdb->prefix}wnq_portal_messages m
             LEFT JOIN {$wpdb->prefix}wnq_clients c ON c.client_id=m.client_id
             WHERE m.sender_role='client' AND m.status='unread'
             ORDER BY m.created_at DESC, m.id DESC LIMIT %d",
            max(1, min(100, $limit))
        ), ARRAY_A) ?: [];
    }

    public static function createMessage(string $client_id, array $data, string $sender_role = 'client'): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $sender_role = $sender_role === 'admin' ? 'admin' : 'client';
        $message = sanitize_textarea_field((string)($data['message'] ?? ''));
        if ($message === '') {
            return false;
        }
        $ticket_key = sanitize_key((string)($data['ticket_key'] ?? ''));
        $existing = $ticket_key !== '' ? self::getTicket($client_id, $ticket_key) : null;
        if ($ticket_key !== '' && !$existing) {
            return false;
        }
        if ($sender_role === 'client' && $existing && in_array((string)$existing['ticket_status'], ['resolved', 'closed'], true) && sanitize_key((string)($data['ticket_status'] ?? '')) !== 'open') {
            return false;
        }
        if ($ticket_key === '') {
            $ticket_key = 'tkt-' . strtolower(wp_generate_password(8, false, false));
        }
        $subject = $existing ? (string)$existing['subject'] : sanitize_text_field((string)($data['subject'] ?? ''));
        if ($subject === '') return false;
        $category = $existing ? (string)$existing['category'] : sanitize_key((string)($data['category'] ?? 'general'));
        if (!in_array($category, ['general', 'website', 'seo', 'billing', 'training'], true)) $category = 'general';
        $priority = $existing ? (string)$existing['priority'] : sanitize_key((string)($data['priority'] ?? 'normal'));
        $ticket_status = $existing ? (string)$existing['ticket_status'] : 'open';
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $priority = 'normal';
        if ($sender_role === 'admin') {
            $requested_status = sanitize_key((string)($data['ticket_status'] ?? $ticket_status));
            if (in_array($requested_status, ['open', 'in_progress', 'waiting', 'resolved', 'closed'], true)) {
                $ticket_status = $requested_status;
            }
        } elseif ($existing && in_array($ticket_status, ['resolved', 'closed'], true) && sanitize_key((string)($data['ticket_status'] ?? '')) === 'open') {
            $ticket_status = 'open';
        }
        $result = $wpdb->insert($wpdb->prefix . 'wnq_portal_messages', [
            'client_id'      => sanitize_text_field($client_id),
            'sender_user_id' => get_current_user_id() ?: null,
            'sender_role'    => $sender_role,
            'ticket_key'     => $ticket_key,
            'category'       => $category,
            'priority'       => $priority,
            'ticket_status'  => $ticket_status,
            'subject'        => $subject,
            'message'        => $message,
            'attachments'    => wp_json_encode(self::sanitizeAttachments($data['attachments'] ?? [])),
            'status'         => 'unread',
        ]);
        if ($result === false) {
            return false;
        }
        $id = (int)$wpdb->insert_id;
        $client = Client::getByClientId($client_id) ?: [];
        $recipient = $sender_role === 'admin'
            ? sanitize_email((string)($client['email'] ?? ''))
            : sanitize_email((string)get_option('wnq_support_email', get_option('admin_email')));
        if ($recipient !== '') {
            $company = sanitize_text_field((string)($client['company'] ?: $client['name'] ?: $client_id));
            wp_mail(
                $recipient,
                ($sender_role === 'admin' ? 'Reply from Golden Web Marketing: ' : 'New client portal ticket from ' . $company . ': ') . $subject,
                $message . "\n\nView the full ticket in the client portal."
            );
        }
        do_action('wnq_portal_message_created', $id, $client_id, $sender_role, [
            'subject' => $subject,
            'category' => $category,
            'priority' => $priority,
            'message' => $message,
        ], $ticket_key, !$existing);
        return $id;
    }

    public static function messageValidationError(string $client_id, array $data, string $sender_role = 'client'): string
    {
        if ($client_id === '') return 'No client is linked to this account.';
        if (sanitize_textarea_field((string)($data['message'] ?? '')) === '') return 'Message is required.';
        $ticket_key = sanitize_key((string)($data['ticket_key'] ?? ''));
        if ($ticket_key === '' && sanitize_text_field((string)($data['subject'] ?? '')) === '') {
            return 'A subject is required when creating a support ticket.';
        }
        if ($ticket_key !== '') {
            $ticket = self::getTicket($client_id, $ticket_key);
            if (!$ticket) return 'The selected support ticket could not be found.';
            if ($sender_role !== 'admin' && in_array((string)$ticket['ticket_status'], ['resolved', 'closed'], true) && sanitize_key((string)($data['ticket_status'] ?? '')) !== 'open') {
                return 'Reopen this ticket before adding another reply.';
            }
        }
        return '';
    }

    public static function getRequests(string $client_id, int $limit = 100): array
    {
        self::ensureSchema();
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_requests WHERE client_id=%s ORDER BY updated_at DESC, id DESC LIMIT %d",
            $client_id,
            max(1, min(300, $limit))
        ), ARRAY_A) ?: [];
        return array_map(static function (array $row): array {
            $row['request_data'] = self::jsonArray($row['request_data'] ?? '');
            $row['attachments'] = self::hydrateAttachments(self::jsonArray($row['attachments'] ?? ''), (string)$row['client_id']);
            return $row;
        }, $rows);
    }

    public static function isValidRequestInput(array $data): bool
    {
        return in_array(sanitize_key((string)($data['request_type'] ?? '')), array_keys(self::requestTypes()), true)
            && sanitize_text_field((string)($data['title'] ?? '')) !== '';
    }

    public static function createRequest(string $client_id, array $data): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $type = sanitize_key((string)($data['request_type'] ?? ''));
        $types = array_keys(self::requestTypes());
        if (!in_array($type, $types, true)) return false;
        $title = sanitize_text_field((string)($data['title'] ?? ''));
        if ($title === '') return false;
        $priority = sanitize_key((string)($data['priority'] ?? 'normal'));
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $priority = 'normal';
        $request_data = [];
        foreach ((array)($data['request_data'] ?? []) as $key => $value) {
            $request_data[sanitize_key((string)$key)] = sanitize_textarea_field((string)$value);
        }
        $request_key = 'req-' . strtolower(wp_generate_password(8, false, false));
        $result = $wpdb->insert($wpdb->prefix . 'wnq_portal_requests', [
            'client_id'    => sanitize_text_field($client_id),
            'request_key'  => $request_key,
            'request_type' => $type,
            'title'        => $title,
            'details'      => sanitize_textarea_field((string)($data['details'] ?? '')),
            'priority'     => $priority,
            'status'       => 'submitted',
            'request_data' => wp_json_encode($request_data),
            'attachments'  => wp_json_encode(self::sanitizeAttachments($data['attachments'] ?? [])),
        ]);
        if ($result === false) return false;
        $client = Client::getByClientId($client_id) ?: [];
        $recipient = sanitize_email((string)get_option('wnq_support_email', get_option('admin_email')));
        if ($recipient !== '') {
            $client_name = sanitize_text_field((string)($client['company'] ?: $client['name'] ?: $client_id));
            $admin_url = admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode($client_id));
            $message = "A new client request was submitted.\n\n"
                . "Client: {$client_name}\n"
                . "Request: {$title}\n"
                . "Type: " . str_replace('_', ' ', $type) . "\n"
                . "Priority: {$priority}\n"
                . "Reference: {$request_key}\n\n"
                . sanitize_textarea_field((string)($data['details'] ?? '')) . "\n\n"
                . "Review in WordPress: {$admin_url}";
            wp_mail($recipient, 'New portal request from ' . $client_name, $message);
        }
        do_action('wnq_portal_request_created', (int)$wpdb->insert_id, $client_id, $request_key);
        return (int)$wpdb->insert_id;
    }

    public static function updateRequestStatus(int $id, string $client_id, string $status): bool
    {
        self::ensureSchema();
        global $wpdb;
        $status = sanitize_key($status);
        if (!in_array($status, ['submitted', 'reviewing', 'scheduled', 'in_progress', 'completed', 'declined'], true)) return false;
        $request = $wpdb->get_row($wpdb->prepare("SELECT title,status FROM {$wpdb->prefix}wnq_portal_requests WHERE id=%d AND client_id=%s", $id, $client_id), ARRAY_A);
        if (!$request) return false;
        $updated = $wpdb->update($wpdb->prefix . 'wnq_portal_requests', ['status' => $status], ['id' => $id, 'client_id' => $client_id]) !== false;
        if ($updated && ($request['status'] ?? '') !== $status) {
            $client = Client::getByClientId($client_id) ?: [];
            $recipient = sanitize_email((string)($client['email'] ?? ''));
            if ($recipient !== '') {
                wp_mail($recipient, 'Request update from Golden Web Marketing', 'Your request "' . sanitize_text_field((string)$request['title']) . '" is now ' . str_replace('_', ' ', $status) . ".\n\nView the request in your client portal.");
            }
        }
        return $updated;
    }

    public static function getOpenRequestCount(?string $client_id = null): int
    {
        self::ensureSchema();
        global $wpdb;
        $open = ['submitted', 'reviewing', 'scheduled', 'in_progress'];
        $placeholders = implode(',', array_fill(0, count($open), '%s'));
        if ($client_id !== null && $client_id !== '') {
            $args = array_merge([$client_id], $open);
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wnq_portal_requests WHERE client_id=%s AND status IN ($placeholders)", $args));
        }
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wnq_portal_requests WHERE status IN ($placeholders)", $open));
    }

    public static function requestTypes(): array
    {
        return [
            'website_update' => ['label' => 'Website Edit', 'description' => 'Request a change to an existing page.'],
            'new_page'       => ['label' => 'New Page', 'description' => 'Request a new service, city, or landing page.'],
            'blog'           => ['label' => 'Blog Content', 'description' => 'Request a blog topic or content update.'],
            'report_question'=> ['label' => 'Report Question', 'description' => 'Ask about rankings, traffic, leads, or results.'],
            'strategy_call'  => ['label' => 'Strategy Call', 'description' => 'Request time to discuss priorities and next steps.'],
        ];
    }

    public static function getLearningRequests(string $client_id): array
    {
        self::ensureSchema();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_learning_requests WHERE client_id=%s ORDER BY created_at DESC, id DESC LIMIT 50",
            $client_id
        ), ARRAY_A) ?: [];
    }

    public static function createLearningRequest(string $client_id, array $data): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $title = sanitize_text_field((string)($data['title'] ?? ''));
        if ($title === '') return false;
        $request_type = sanitize_key((string)($data['request_type'] ?? 'topic'));
        if (!in_array($request_type, ['topic', 'course', 'help'], true)) {
            $request_type = 'topic';
        }
        $result = $wpdb->insert($wpdb->prefix . 'wnq_portal_learning_requests', [
            'client_id'    => sanitize_text_field($client_id),
            'request_type' => $request_type,
            'title'        => $title,
            'details'      => sanitize_textarea_field((string)($data['details'] ?? '')),
            'status'       => 'submitted',
        ]);
        if ($result === false) {
            return false;
        }
        $id = (int)$wpdb->insert_id;
        do_action('wnq_portal_learning_request_created', $id, $client_id, [
            'request_type' => $request_type,
            'title' => $title,
            'details' => sanitize_textarea_field((string)($data['details'] ?? '')),
        ]);
        return $id;
    }

    public static function courses(): array
    {
        return [
            ['id' => 'reviews', 'title' => 'Build a Reliable Review System', 'category' => 'Reputation', 'duration' => '18 min', 'description' => 'Create a repeatable process for earning more quality Google reviews.'],
            ['id' => 'photos', 'title' => 'Project Photos That Build Trust', 'category' => 'Content', 'duration' => '14 min', 'description' => 'Capture before, during, and after photos that support sales and SEO.'],
            ['id' => 'leads', 'title' => 'Follow Up and Convert More Leads', 'category' => 'Sales', 'duration' => '22 min', 'description' => 'Use a simple follow-up rhythm that keeps opportunities from going cold.'],
            ['id' => 'reports', 'title' => 'Understand Your Monthly Report', 'category' => 'Marketing', 'duration' => '16 min', 'description' => 'Know which rankings, traffic, and lead numbers deserve attention.'],
        ];
    }

    public static function updatePublicProfile(string $client_id, array $data): bool
    {
        $client = Client::getByClientId($client_id);
        if (!$client) return false;
        $services = array_values(array_filter(array_map('sanitize_text_field', preg_split('/[\r\n,]+/', (string)($data['active_services'] ?? '')) ?: [])));
        return Client::update((int)$client['id'], [
            'company'          => sanitize_text_field((string)($data['company'] ?? '')),
            'email'            => sanitize_email((string)($data['email'] ?? '')),
            'phone'            => sanitize_text_field((string)($data['phone'] ?? '')),
            'website'          => esc_url_raw((string)($data['website'] ?? '')),
            'business_address' => sanitize_text_field((string)($data['business_address'] ?? '')),
            'city'             => sanitize_text_field((string)($data['city'] ?? '')),
            'state'            => sanitize_text_field((string)($data['state'] ?? '')),
            'active_services'  => $services,
        ]);
    }

    public static function getPortalSettings(string $client_id): array
    {
        $client = Client::getByClientId($client_id) ?: [];
        $stored = get_option(self::portalSettingsOptionKey($client_id), []);
        $stored = is_array($stored) ? $stored : [];
        $default_sources = [
            'Google Ads', 'Google Business Profile', 'Organic Search', 'Website Form',
            'Phone Call', 'Referral', 'Facebook', 'Instagram', 'Other',
        ];
        $services = self::services($client['active_services'] ?? '');

        return [
            'profile' => self::publicClient($client),
            'crm' => [
                'lead_sources' => self::sanitizeTextList($stored['lead_sources'] ?? $default_sources, $default_sources),
                'services' => self::sanitizeTextList($stored['services'] ?? $services, $services),
                'default_follow_up_days' => max(0, min(90, absint($stored['default_follow_up_days'] ?? 2))),
                'pipeline_stages' => self::sanitizePipelineStages($stored['pipeline_stages'] ?? self::defaultPipelineStages()),
            ],
            'notifications' => [
                'support_replies' => !array_key_exists('support_replies', $stored) || !empty($stored['support_replies']),
                'overdue_followups' => !array_key_exists('overdue_followups', $stored) || !empty($stored['overdue_followups']),
                'upcoming_jobs' => !array_key_exists('upcoming_jobs', $stored) || !empty($stored['upcoming_jobs']),
                'new_reports' => !array_key_exists('new_reports', $stored) || !empty($stored['new_reports']),
                'sound_enabled' => !array_key_exists('sound_enabled', $stored) || !empty($stored['sound_enabled']),
            ],
        ];
    }

    public static function savePortalSettings(string $client_id, array $data): bool
    {
        $client = Client::getByClientId($client_id);
        if (!$client) {
            return false;
        }
        $current = self::getPortalSettings($client_id);
        $crm = is_array($data['crm'] ?? null) ? $data['crm'] : [];
        $notifications = is_array($data['notifications'] ?? null) ? $data['notifications'] : [];
        $lead_sources = self::sanitizeTextList($crm['lead_sources'] ?? $current['crm']['lead_sources'], $current['crm']['lead_sources']);
        $services = self::sanitizeTextList($crm['services'] ?? $current['crm']['services'], $current['crm']['services']);
        $pipeline_stages = self::sanitizePipelineStages($crm['pipeline_stages'] ?? $current['crm']['pipeline_stages'], $current['crm']['pipeline_stages']);
        $preference = static function (string $key) use ($notifications, $current): bool {
            return array_key_exists($key, $notifications)
                ? !empty($notifications[$key])
                : !empty($current['notifications'][$key]);
        };
        $settings = [
            'lead_sources' => $lead_sources,
            'services' => $services,
            'default_follow_up_days' => max(0, min(90, absint($crm['default_follow_up_days'] ?? $current['crm']['default_follow_up_days']))),
            'pipeline_stages' => $pipeline_stages,
            'support_replies' => $preference('support_replies'),
            'overdue_followups' => $preference('overdue_followups'),
            'upcoming_jobs' => $preference('upcoming_jobs'),
            'new_reports' => $preference('new_reports'),
            'sound_enabled' => $preference('sound_enabled'),
        ];
        update_option(self::portalSettingsOptionKey($client_id), $settings, false);

        if ($services !== self::services($client['active_services'] ?? '')) {
            Client::update((int)$client['id'], ['active_services' => $services]);
        }
        self::reassignRemovedPipelineStages($client_id, $pipeline_stages);
        return true;
    }

    public static function getPortalNotifications(string $client_id): array
    {
        $settings = self::getPortalSettings($client_id);
        $preferences = $settings['notifications'];
        $records = self::getCustomers($client_id, 200, false);
        $today = current_time('Y-m-d');
        $week_end = wp_date('Y-m-d', current_time('timestamp') + (7 * DAY_IN_SECONDS));
        $items = [];

        if (!empty($preferences['support_replies'])) {
            foreach (self::getTickets($client_id, 25) as $ticket) {
                if (empty($ticket['unread'])) {
                    continue;
                }
                $items[] = [
                    'type' => 'support', 'tone' => 'gold', 'attention' => true,
                    'title' => 'New support reply',
                    'message' => (string)($ticket['subject'] ?? 'Support ticket updated'),
                    'date' => (string)($ticket['updated_at'] ?? ''),
                    'route' => 'messages', 'action' => 'View ticket',
                ];
            }
        }
        foreach ($records as $record) {
            $status = (string)($record['status'] ?? 'new');
            if (in_array($status, ['completed', 'lost', 'canceled'], true)) {
                continue;
            }
            if (!empty($preferences['overdue_followups']) && !empty($record['follow_up_date']) && $record['follow_up_date'] < $today) {
                $items[] = [
                    'type' => 'followup', 'tone' => 'red', 'attention' => true,
                    'title' => 'Follow-up overdue',
                    'message' => (string)($record['name'] ?? 'Lead') . ' - ' . (string)($record['service'] ?: 'Follow-up'),
                    'date' => (string)$record['follow_up_date'],
                    'route' => 'followups', 'action' => 'Review follow-up',
                ];
            }
            if (!empty($preferences['upcoming_jobs']) && ($record['record_type'] ?? '') === 'job' && !empty($record['job_date']) && $record['job_date'] >= $today && $record['job_date'] <= $week_end) {
                $items[] = [
                    'type' => 'job', 'tone' => 'green', 'attention' => $record['job_date'] === $today,
                    'title' => $record['job_date'] === $today ? 'Job scheduled today' : 'Upcoming job',
                    'message' => (string)($record['name'] ?? 'Job') . ' - ' . (string)($record['service'] ?: 'Service'),
                    'date' => (string)$record['job_date'],
                    'route' => 'calendar', 'action' => 'Open calendar',
                ];
            }
        }
        if (!empty($preferences['new_reports'])) {
            $reports = self::getReports($client_id, 1);
            if (!empty($reports[0])) {
                $items[] = [
                    'type' => 'report', 'tone' => 'blue', 'attention' => false,
                    'title' => 'Latest SEO report available',
                    'message' => (string)($reports[0]['title'] ?? 'Monthly Report'),
                    'date' => (string)($reports[0]['generated_at'] ?? $reports[0]['period_end'] ?? ''),
                    'route' => 'reports', 'action' => 'View report',
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            $attention = (int)!empty($right['attention']) <=> (int)!empty($left['attention']);
            return $attention !== 0 ? $attention : strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
        });
        return [
            'attention_count' => count(array_filter($items, static fn(array $item): bool => !empty($item['attention']))),
            'items' => array_slice($items, 0, 50),
            'settings' => $preferences,
        ];
    }

    public static function markMessagesRead(string $client_id, string $sender_role): void
    {
        self::ensureSchema();
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wnq_portal_messages',
            ['status' => 'read'],
            ['client_id' => $client_id, 'sender_role' => $sender_role, 'status' => 'unread']
        );
    }

    public static function markTicketMessagesRead(string $client_id, string $ticket_key, string $sender_role): void
    {
        self::ensureSchema();
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wnq_portal_messages',
            ['status' => 'read'],
            [
                'client_id' => $client_id,
                'ticket_key' => sanitize_key($ticket_key),
                'sender_role' => $sender_role === 'admin' ? 'admin' : 'client',
                'status' => 'unread',
            ]
        );
    }

    public static function getTasks(string $client_id, int $limit = 20, bool $include_private = true): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_tasks';
        if (!self::tableExists($table)) {
            if (class_exists(Task::class)) {
                Task::createTable();
            }
        }
        if (!self::tableExists($table)) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,title,description,status,task_type,priority,assigned_to,due_date,notes,completed_at,created_at FROM $table
             WHERE client_id=%s AND archived_at IS NULL ORDER BY status='done' ASC, due_date IS NULL, due_date ASC, created_at DESC LIMIT %d",
            $client_id,
            max(1, min(100, $limit))
        ), ARRAY_A) ?: [];
        return array_map(static function (array $row) use ($include_private): array {
            if (!$include_private) {
                unset($row['notes'], $row['assigned_to']);
            }
            $row['work_type_label'] = self::workTypeLabel((string)($row['task_type'] ?? 'other'));
            return $row;
        }, $rows);
    }

    public static function createMarketingWork(string $client_id, array $data): int|false
    {
        if (!class_exists(Task::class)) {
            return false;
        }
        Task::createTable();
        $title = sanitize_text_field((string)($data['title'] ?? ''));
        if ($client_id === '' || $title === '') {
            return false;
        }
        $type = sanitize_key((string)($data['work_type'] ?? $data['task_type'] ?? 'other'));
        $allowed_types = ['seo', 'google_ads', 'website_update', 'gbp', 'content', 'tracking_analytics', 'technical_fix', 'other'];
        if (!in_array($type, $allowed_types, true)) {
            $type = 'other';
        }
        $status = sanitize_key((string)($data['status'] ?? 'done'));
        if (!in_array($status, ['todo', 'in_progress', 'done'], true)) {
            $status = 'done';
        }
        $work_date = self::dateField($data['work_date'] ?? $data['due_date'] ?? '') ?: current_time('Y-m-d');
        $id = Task::create([
            'client_id'    => $client_id,
            'title'        => $title,
            'description'  => sanitize_textarea_field((string)($data['description'] ?? '')),
            'task_type'    => $type,
            'priority'     => 'medium',
            'status'       => $status,
            'due_date'     => $work_date,
            'assigned_to'  => sanitize_text_field((string)($data['assigned_to'] ?? '')),
            'notes'        => sanitize_textarea_field((string)($data['notes'] ?? '')),
        ]);
        if ($id && $status === 'done') {
            Task::update((int)$id, ['status' => 'done']);
        }
        return $id ? (int)$id : false;
    }

    private static function workTypeLabel(string $type): string
    {
        return [
            'seo' => 'SEO',
            'google_ads' => 'Google Ads',
            'website_update' => 'Website Update',
            'gbp' => 'Google Business Profile',
            'content' => 'Content',
            'tracking_analytics' => 'Tracking / Analytics',
            'technical_fix' => 'Technical Fix',
            'other' => 'Other',
        ][$type] ?? 'Other';
    }

    public static function getReports(string $client_id, int $limit = 12): array
    {
        global $wpdb;
        if (!self::tableExists($wpdb->prefix . 'wnq_seo_reports')) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,report_type,period_start,period_end,status,generated_at FROM {$wpdb->prefix}wnq_seo_reports
             WHERE client_id=%s ORDER BY period_start DESC, generated_at DESC LIMIT %d",
            $client_id,
            max(1, min(50, $limit))
        ), ARRAY_A) ?: [];
        return array_map(static function (array $row): array {
            $row['view_url'] = self::reportExportUrl((int)$row['id']);
            $row['pdf_url'] = self::reportExportUrl((int)$row['id'], 'pdf');
            return $row;
        }, $rows);
    }

    public static function getReport(int $id, string $client_id): ?array
    {
        global $wpdb;
        if (!self::tableExists($wpdb->prefix . 'wnq_seo_reports')) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,report_type,period_start,period_end,title,summary_html,status,generated_at
             FROM {$wpdb->prefix}wnq_seo_reports WHERE id=%d AND client_id=%s",
            $id,
            $client_id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['summary_html'] = wp_kses_post((string)($row['summary_html'] ?? ''));
        $row['view_url'] = self::reportExportUrl($id);
        $row['pdf_url'] = self::reportExportUrl($id, 'pdf');
        return $row;
    }

    public static function overview(string $client_id, bool $include_private = true): array
    {
        $client = Client::getByClientId($client_id) ?: [];
        $customers = self::getCustomerSummary($client_id, $include_private);
        $tasks = self::getTasks($client_id, 50, $include_private);
        $messages = self::getMessages($client_id, 50);
        $reports = self::getReports($client_id, 12);
        $open_tasks = count(array_filter($tasks, static fn($task) => ($task['status'] ?? '') !== 'done'));
        $open_requests = self::getOpenRequestCount($client_id);
        $unread_messages = count(array_filter($messages, static fn($message) => ($message['status'] ?? '') === 'unread' && ($message['sender_role'] ?? '') === 'admin'));
        $billing = self::billingHealth($client);
        $actions = [];
        if ($billing['tone'] !== 'green') {
            $actions[] = ['type' => 'billing', 'label' => $billing['message']];
        }
        if ($unread_messages > 0) {
            $actions[] = ['type' => 'messages', 'label' => $unread_messages . ' unread message' . ($unread_messages === 1 ? '' : 's')];
        }
        if ($open_tasks > 0) {
            $actions[] = ['type' => 'customers', 'label' => $open_tasks . ' marketing work item' . ($open_tasks === 1 ? '' : 's') . ' in progress'];
        }
        if ($open_requests > 0) {
            $actions[] = ['type' => 'requests', 'label' => $open_requests . ' request' . ($open_requests === 1 ? '' : 's') . ' being handled'];
        }

        return [
            'client'          => self::publicClient($client),
            'health'          => [
                'overall' => $billing['tone'] === 'red' ? 'red' : ($actions ? 'yellow' : 'green'),
                'billing' => $billing,
                'work'    => ['tone' => $open_tasks > 0 ? 'yellow' : 'green', 'message' => $open_tasks > 0 ? 'Work is in progress' : 'No open work items'],
            ],
            'actions'         => $actions,
            'customers'       => $customers,
            'open_tasks'      => $open_tasks,
            'open_requests'   => $open_requests,
            'unread_messages' => $unread_messages,
            'latest_report'   => $reports[0] ?? null,
            'performance'     => self::getMonthlyPerformance($client_id, 6, $include_private),
        ];
    }

    public static function publicClient(array $client): array
    {
        return [
            'client_id'         => (string)($client['client_id'] ?? ''),
            'name'              => (string)($client['name'] ?? ''),
            'company'           => (string)($client['company'] ?? ''),
            'email'             => (string)($client['email'] ?? ''),
            'phone'             => (string)($client['phone'] ?? ''),
            'website'           => (string)($client['website'] ?? ''),
            'business_address'  => (string)($client['business_address'] ?? ''),
            'city'              => (string)($client['city'] ?? ''),
            'state'             => (string)($client['state'] ?? ''),
            'tier'              => (string)($client['tier'] ?? ''),
            'status'            => (string)($client['status'] ?? ''),
            'billing_cycle'     => (string)($client['billing_cycle'] ?? ''),
            'monthly_rate'      => (float)($client['monthly_rate'] ?? 0),
            'last_payment_date' => (string)($client['last_payment_date'] ?? ''),
            'next_payment_due_date' => (string)($client['next_payment_due_date'] ?? ''),
            'payment_reminder_days' => (int)($client['payment_reminder_days'] ?? 3),
            'active_services'   => self::services($client['active_services'] ?? ''),
        ];
    }

    private static function services($services): array
    {
        if (is_array($services)) {
            return array_values(array_filter(array_map('sanitize_text_field', $services)));
        }
        $decoded = json_decode((string)$services, true);
        return is_array($decoded) ? array_values(array_filter(array_map('sanitize_text_field', $decoded))) : [];
    }

    private static function portalSettingsOptionKey(string $client_id): string
    {
        return 'wnq_portal_settings_' . md5($client_id);
    }

    private static function sanitizeTextList($value, array $fallback = []): array
    {
        $items = is_array($value) ? $value : (preg_split('/[\r\n,]+/', (string)$value) ?: []);
        $items = array_values(array_unique(array_filter(array_map(
            static fn($item): string => sanitize_text_field((string)$item),
            $items
        ))));
        return $items ?: array_values(array_filter(array_map('sanitize_text_field', $fallback)));
    }

    private static function defaultPipelineStages(): array
    {
        return [
            ['key' => 'new', 'label' => 'New Lead', 'color' => '#D7B846'],
            ['key' => 'contacted', 'label' => 'Contacted', 'color' => '#4B7BEC'],
            ['key' => 'quote-sent', 'label' => 'Quote Sent', 'color' => '#8E63CE'],
            ['key' => 'follow-up', 'label' => 'Follow-Up', 'color' => '#E58A2B'],
        ];
    }

    private static function sanitizePipelineStages($value, array $fallback = []): array
    {
        $rows = is_array($value) ? $value : [];
        $stages = [];
        $used = [];
        foreach (array_slice($rows, 0, 12) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = sanitize_text_field((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $base_key = sanitize_key((string)($row['key'] ?? '')) ?: sanitize_title($label);
            $key = $base_key !== '' ? $base_key : 'stage-' . ($index + 1);
            $suffix = 2;
            while (isset($used[$key])) {
                $key = $base_key . '-' . $suffix++;
            }
            $used[$key] = true;
            $stages[] = [
                'key' => $key,
                'label' => $label,
                'color' => sanitize_hex_color((string)($row['color'] ?? '')) ?: '#D7B846',
            ];
        }
        if (!$stages && $fallback) {
            return self::sanitizePipelineStages($fallback);
        }
        return $stages ?: self::defaultPipelineStages();
    }

    private static function reassignRemovedPipelineStages(string $client_id, array $stages): void
    {
        self::ensureSchema();
        global $wpdb;
        $keys = array_values(array_filter(array_column($stages, 'key')));
        if (!$keys) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $args = array_merge([$keys[0], $client_id], $keys);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}wnq_portal_customers SET pipeline_stage=%s WHERE client_id=%s AND record_type='lead' AND pipeline_stage NOT IN ({$placeholders})",
            ...$args
        ));
    }

    private static function responseTime(string $priority): string
    {
        return match ($priority) {
            'urgent' => 'Expected response within 4 business hours',
            'high'   => 'Expected response within 1 business day',
            'low'    => 'Expected response within 3 business days',
            default  => 'Expected response within 2 business days',
        };
    }

    public static function storePrivateUpload(array $file): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return null;
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 10 * MB_IN_BYTES) return null;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $allowed_mimes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
        ];
        $checked = wp_check_filetype_and_ext((string)$file['tmp_name'], sanitize_file_name((string)($file['name'] ?? 'attachment')), $allowed_mimes);
        if (empty($checked['type']) || empty($checked['ext'])) return null;
        $directory = self::privateUploadDirectory();
        if ($directory === '' || (!is_dir($directory) && !wp_mkdir_p($directory))) return null;
        self::protectPrivateUploadDirectory($directory);
        $token = str_replace('-', '', wp_generate_uuid4());
        $filename = $token . '.' . sanitize_key((string)$checked['ext']);
        $destination = trailingslashit($directory) . $filename;
        if (!move_uploaded_file((string)$file['tmp_name'], $destination)) return null;
        @chmod($destination, 0640);
        return [
            'name'  => sanitize_file_name((string)(($checked['proper_filename'] ?? '') ?: ($file['name'] ?? '') ?: 'attachment')),
            'token' => $token,
            'path'  => $filename,
            'type'  => sanitize_mime_type((string)$checked['type']),
            'size'  => $size,
        ];
    }

    public static function deletePrivateAttachments($attachments): void
    {
        $directory = self::privateUploadDirectory();
        if ($directory === '') return;
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            $path = sanitize_file_name((string)($attachment['path'] ?? ''));
            if ($path === '') continue;
            $file = trailingslashit($directory) . $path;
            if (is_file($file)) wp_delete_file($file);
        }
    }

    public static function findPrivateAttachment(string $client_id, string $token): ?array
    {
        self::ensureSchema();
        global $wpdb;
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
        if ($client_id === '' || strlen($token) !== 32) return null;
        $locations = [
            'wnq_portal_messages' => ['attachments'],
            'wnq_portal_requests' => ['attachments'],
            'wnq_portal_customers' => ['files', 'before_photos', 'after_photos'],
        ];
        foreach ($locations as $suffix => $columns) {
            foreach ($columns as $column) {
                $table = $wpdb->prefix . $suffix;
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT {$column} FROM {$table} WHERE client_id=%s AND {$column} LIKE %s",
                    $client_id,
                    '%' . $wpdb->esc_like($token) . '%'
                )) ?: [];
                foreach ($rows as $json) {
                    foreach (self::jsonArray($json) as $attachment) {
                        if (is_array($attachment) && hash_equals($token, (string)($attachment['token'] ?? ''))) {
                            return self::sanitizeAttachments([$attachment])[0] ?? null;
                        }
                    }
                }
            }
        }
        return null;
    }

    public static function attachmentDownloadUrl(string $client_id, string $token, bool $preview = false): string
    {
        $args = [
            'action'    => 'wnq_portal_download_attachment',
            'client_id' => $client_id,
            'token'     => $token,
            '_wpnonce'  => wp_create_nonce('wnq_portal_attachment_' . $client_id . '_' . $token),
        ];
        if ($preview) {
            $args['preview'] = '1';
        }
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    public static function privateAttachmentPath(array $attachment): string
    {
        $path = sanitize_file_name((string)($attachment['path'] ?? ''));
        return $path === '' ? '' : trailingslashit(self::privateUploadDirectory()) . $path;
    }

    private static function sanitizeAttachments($attachments): array
    {
        $clean = [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            if (!is_array($attachment)) continue;
            $item = [
                'name' => sanitize_file_name((string)($attachment['name'] ?? 'attachment')),
                'type' => sanitize_mime_type((string)($attachment['type'] ?? '')),
                'size' => absint($attachment['size'] ?? 0),
            ];
            $token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($attachment['token'] ?? '')));
            $path = sanitize_file_name((string)($attachment['path'] ?? ''));
            if (strlen($token) === 32 && $path !== '') {
                $item['token'] = $token;
                $item['path'] = $path;
            } elseif (!empty($attachment['url'])) {
                // Preserve access to legacy uploads while all new files use protected references.
                $item['url'] = esc_url_raw((string)$attachment['url']);
            } else {
                continue;
            }
            $clean[] = $item;
        }
        return $clean;
    }

    private static function hydrateAttachments(array $attachments, string $client_id): array
    {
        return array_map(static function (array $attachment) use ($client_id): array {
            if (!empty($attachment['token'])) {
                $attachment['url'] = self::attachmentDownloadUrl($client_id, (string)$attachment['token']);
                if (str_starts_with((string)($attachment['type'] ?? ''), 'image/')) {
                    $attachment['preview_url'] = self::attachmentDownloadUrl($client_id, (string)$attachment['token'], true);
                }
                unset($attachment['path'], $attachment['token']);
            }
            return $attachment;
        }, self::sanitizeAttachments($attachments));
    }

    private static function privateUploadDirectory(): string
    {
        $uploads = wp_upload_dir();
        return empty($uploads['error']) ? trailingslashit((string)$uploads['basedir']) . 'wnq-private' : '';
    }

    private static function protectPrivateUploadDirectory(string $directory): void
    {
        $files = [
            '.htaccess' => "Deny from all\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>",
            'index.php' => "<?php\nhttp_response_code(404);\nexit;\n",
        ];
        foreach ($files as $name => $contents) {
            $path = trailingslashit($directory) . $name;
            if (!file_exists($path)) @file_put_contents($path, $contents);
        }
    }

    private static function migrateLegacyAttachments(): void
    {
        global $wpdb;
        $uploads = wp_upload_dir();
        $directory = self::privateUploadDirectory();
        if (!empty($uploads['error']) || $directory === '' || (!is_dir($directory) && !wp_mkdir_p($directory))) return;
        self::protectPrivateUploadDirectory($directory);
        $base_url = trailingslashit((string)$uploads['baseurl']);
        $base_dir = trailingslashit((string)$uploads['basedir']);
        foreach (['wnq_portal_messages', 'wnq_portal_requests'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $rows = $wpdb->get_results("SELECT id,attachments FROM $table WHERE attachments LIKE '%\"url\"%'", ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $changed = false;
                $attachments = self::jsonArray($row['attachments'] ?? '');
                foreach ($attachments as &$attachment) {
                    $url = esc_url_raw((string)($attachment['url'] ?? ''));
                    if ($url === '' || !str_starts_with($url, $base_url)) continue;
                    $source = $base_dir . ltrim(substr($url, strlen($base_url)), '/');
                    if (!is_file($source) || !is_readable($source)) continue;
                    $token = str_replace('-', '', wp_generate_uuid4());
                    $extension = sanitize_key((string)pathinfo($source, PATHINFO_EXTENSION));
                    $filename = $token . ($extension !== '' ? '.' . $extension : '');
                    $destination = trailingslashit($directory) . $filename;
                    if (!@rename($source, $destination) && (!@copy($source, $destination) || !@unlink($source))) continue;
                    @chmod($destination, 0640);
                    $attachment = [
                        'name' => sanitize_file_name((string)($attachment['name'] ?? basename($source))),
                        'token' => $token,
                        'path' => $filename,
                        'type' => sanitize_mime_type((string)($attachment['type'] ?? '')),
                        'size' => (int)filesize($destination),
                    ];
                    $changed = true;
                }
                unset($attachment);
                if ($changed) {
                    $wpdb->update($table, ['attachments' => wp_json_encode(self::sanitizeAttachments($attachments))], ['id' => absint($row['id'])]);
                }
            }
        }
    }

    private static function jsonArray($value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function billingHealth(array $client): array
    {
        if (($client['status'] ?? 'active') !== 'active') {
            return ['tone' => 'red', 'label' => 'Blocked', 'message' => 'Account is not active'];
        }
        $rate = (float)($client['monthly_rate'] ?? 0);
        $last = strtotime((string)($client['last_payment_date'] ?? ''));
        if ($rate > 0 && (!$last || $last < strtotime('-45 days'))) {
            return ['tone' => 'yellow', 'label' => 'Action needed', 'message' => 'Billing status needs review'];
        }
        return ['tone' => 'green', 'label' => 'Current', 'message' => 'Billing is current'];
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private static function dateField($value): string
    {
        $date = sanitize_text_field((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private static function reportExportUrl(int $report_id, string $format = 'html'): string
    {
        return add_query_arg([
            'action'    => 'wnq_portal_export_report',
            'report_id' => $report_id,
            'format'    => $format,
            '_wpnonce'  => wp_create_nonce('wnq_portal_export_report_' . $report_id),
        ], admin_url('admin-post.php'));
    }
}
