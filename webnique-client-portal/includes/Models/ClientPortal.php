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
    private const SCHEMA_VERSION = '6';
    private static bool $schema_ready = false;

    public static function ensureSchema(): void
    {
        if (self::$schema_ready) {
            return;
        }
        self::$schema_ready = true;
        if ((string)get_option('wnq_client_portal_schema_version', '') !== self::SCHEMA_VERSION) {
            self::createTables();
        }
    }

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_portal_customers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            record_type varchar(30) NOT NULL DEFAULT 'customer',
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

        self::migrateLegacyAttachments();
        update_option('wnq_client_portal_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function getCustomers(string $client_id, int $limit = 100): array
    {
        self::ensureSchema();
        global $wpdb;
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_customers WHERE client_id=%s ORDER BY updated_at DESC, id DESC LIMIT %d",
            $client_id,
            $limit
        ), ARRAY_A) ?: [];
        return array_map([self::class, 'hydrateCustomerRecord'], $rows);
    }

    public static function getCustomer(int $id, string $client_id): ?array
    {
        self::ensureSchema();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_customers WHERE id=%d AND client_id=%s",
            $id,
            $client_id
        ), ARRAY_A);
        return $row ? self::hydrateCustomerRecord($row) : null;
    }

    public static function saveCustomer(string $client_id, array $data): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $record_type = sanitize_key($data['record_type'] ?? 'customer');
        if (!in_array($record_type, ['lead', 'customer', 'job'], true)) {
            $record_type = 'customer';
        }
        $status = sanitize_key($data['status'] ?? 'new');
        if (!in_array($status, self::customerStatuses(), true)) {
            $status = 'new';
        }
        $follow_up = self::dateField($data['follow_up_date'] ?? '');
        $reminder_date = self::dateField($data['reminder_date'] ?? '');
        $job_date = self::dateField($data['job_date'] ?? '');
        $completion_date = self::dateField($data['completion_date'] ?? '');
        $job_count = max(0, absint($data['job_count'] ?? 0));
        $final_value = max(0, round((float)($data['final_value'] ?? 0), 2));
        if ($job_count === 0 && ($final_value > 0 || in_array($status, ['completed', 'won', 'closed'], true))) {
            $job_count = 1;
        }
        $record = [
            'client_id'       => sanitize_text_field($client_id),
            'record_type'     => $record_type,
            'name'            => sanitize_text_field((string)($data['name'] ?? '')),
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
            'estimated_value' => max(0, round((float)($data['estimated_value'] ?? 0), 2)),
            'final_value'     => $final_value,
            'job_cost'        => max(0, round((float)($data['job_cost'] ?? 0), 2)),
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
                if ($id > 0) {
                    $existing_record = self::getCustomer($id, $client_id);
                    $existing = is_array($existing_record[$attachment_key] ?? null) ? $existing_record[$attachment_key] : [];
                }
                $record[$attachment_key] = wp_json_encode(array_values(array_merge($existing, $data[$attachment_key])));
            }
        }
        if ($id > 0 && self::getCustomer($id, $client_id)) {
            $result = $wpdb->update($wpdb->prefix . 'wnq_portal_customers', $record, ['id' => $id, 'client_id' => $client_id]);
            return $result === false ? false : $id;
        }
        $result = $wpdb->insert($wpdb->prefix . 'wnq_portal_customers', $record);
        return $result === false ? false : (int)$wpdb->insert_id;
    }

    public static function getCustomerSummary(string $client_id): array
    {
        self::ensureSchema();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total,
                SUM(status IN ('new','contacted','estimate_sent')) new_count,
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
            'cost'            => (float)($row['cost'] ?? 0),
            'profit'          => (float)($row['revenue'] ?? 0) - (float)($row['cost'] ?? 0),
        ];
    }

    public static function customerStatuses(): array
    {
        return ['new', 'contacted', 'estimate_sent', 'scheduled', 'in_progress', 'completed', 'won', 'lost', 'closed', 'canceled'];
    }

    private static function hydrateCustomerRecord(array $row): array
    {
        foreach (['files', 'before_photos', 'after_photos'] as $key) {
            $row[$key] = self::hydrateAttachments(self::jsonArray($row[$key] ?? ''), (string)($row['client_id'] ?? ''));
        }
        $row['profit'] = (float)($row['final_value'] ?? 0) - (float)($row['job_cost'] ?? 0);
        return $row;
    }

    public static function getAdsResource(string $client_id): array
    {
        $settings = self::adsSettings($client_id);
        $raw_settings = self::adsSettings($client_id, false);
        $has_oauth = !empty($raw_settings['oauth_client_id']) && !empty($raw_settings['oauth_client_secret']) && !empty($raw_settings['refresh_token']);
        $client = Client::getByClientId($client_id) ?: [];
        $match = null;
        $ads_errors = [];
        $summary = [
            'spend' => 0,
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'conversions' => 0,
            'cost_per_conversion' => 0,
        ];
        $campaigns = [];

        if (class_exists(GoogleAdsClient::class)) {
            $ads = new GoogleAdsClient($raw_settings);
            if ($ads->isConfigured()) {
                if ((string)($raw_settings['customer_id'] ?? '') === '') {
                    $match = $ads->matchClient($client);
                    if ($match && (int)($match['match_score'] ?? 0) >= 70) {
                        $raw_settings['customer_id'] = (string)$match['customer_id'];
                        $settings['customer_id'] = (string)$match['customer_id'];
                        $settings['matched_account_name'] = (string)$match['name'];
                        self::saveAdsSettings($client_id, [
                            'customer_id' => (string)$match['customer_id'],
                            'matched_account_name' => (string)$match['name'],
                        ]);
                    }
                }
                if ((string)($raw_settings['customer_id'] ?? '') !== '') {
                    $performance = $ads->accountPerformance((string)$raw_settings['customer_id']);
                    $summary = $performance['summary'] ?? $summary;
                    $campaigns = $performance['campaigns'] ?? [];
                }
                $ads_errors = $ads->errors();
            }
        }

        $ready = !empty($raw_settings['developer_token']) && !empty($raw_settings['manager_customer_id']) && !empty($raw_settings['customer_id']) && $has_oauth;
        return [
            'configured' => $ready,
            'mode' => 'read_only',
            'access_level' => (string)get_option('wnq_google_ads_access_level', 'test'),
            'service_account_email' => (string)($settings['service_account_email'] ?: 'webnique-portal@webnique-client-portal-486204.iam.gserviceaccount.com'),
            'customer_id' => (string)($settings['customer_id'] ?? ''),
            'manager_customer_id' => (string)($settings['manager_customer_id'] ?? ''),
            'matched_account_name' => (string)($settings['matched_account_name'] ?? ($match['name'] ?? '')),
            'match_score' => (int)($match['match_score'] ?? 0),
            'has_api_key' => !empty($settings['api_key']),
            'has_developer_token' => !empty($settings['developer_token']),
            'has_oauth' => $has_oauth,
            'summary' => $summary,
            'campaigns' => $campaigns,
            'errors' => $ads_errors,
            'requirements' => [
                'Google Ads manager customer ID',
                'Google Ads developer token',
                'OAuth client ID, client secret, and refresh token',
                'Client Ads account linked under the manager account',
            ],
        ];
    }

    public static function saveAdsSettings(string $client_id, array $data): bool
    {
        $stored = get_option(self::adsOptionKey($client_id), []);
        $settings = is_array($stored) ? $stored : [];
        $allowed = ['api_key', 'developer_token', 'customer_id', 'manager_customer_id', 'service_account_email', 'oauth_client_id', 'oauth_client_secret', 'refresh_token', 'matched_account_name'];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string)$data[$key]);
            if ($value === 'Saved') {
                continue;
            }
            if ($key === 'service_account_email') {
                $settings[$key] = sanitize_email($value);
            } elseif ($key === 'customer_id' || $key === 'manager_customer_id') {
                $settings[$key] = preg_replace('/[^0-9-]/', '', $value);
            } else {
                $settings[$key] = sanitize_text_field($value);
            }
        }
        update_option(self::adsOptionKey($client_id), $settings, false);
        delete_transient('wnq_google_ads_accounts_' . md5((string)($settings['manager_customer_id'] ?? get_option('wnq_google_ads_manager_customer_id', ''))));
        return true;
    }

    private static function adsSettings(string $client_id, bool $masked = true): array
    {
        $settings = get_option(self::adsOptionKey($client_id), []);
        $settings = is_array($settings) ? $settings : [];
        $global_service_account = sanitize_email((string)get_option('wnq_google_ads_service_account_email', 'webnique-portal@webnique-client-portal-486204.iam.gserviceaccount.com'));
        $defaults = [
            'api_key' => '',
            'developer_token' => (string)get_option('wnq_google_ads_developer_token', ''),
            'customer_id' => '',
            'manager_customer_id' => (string)get_option('wnq_google_ads_manager_customer_id', ''),
            'service_account_email' => $global_service_account ?: 'webnique-portal@webnique-client-portal-486204.iam.gserviceaccount.com',
            'oauth_client_id' => (string)get_option('wnq_google_ads_oauth_client_id', ''),
            'oauth_client_secret' => (string)get_option('wnq_google_ads_oauth_client_secret', ''),
            'refresh_token' => (string)get_option('wnq_google_ads_refresh_token', ''),
            'matched_account_name' => '',
        ];
        $settings = array_merge($defaults, $settings);
        if ($masked) {
            foreach (['api_key', 'developer_token', 'oauth_client_secret', 'refresh_token'] as $secret) {
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

    public static function getMonthlyPerformance(string $client_id, int $months = 6): array
    {
        self::ensureSchema();
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
                'cost'    => $cost,
                'profit'  => $revenue - $cost,
            ];
        }
        return $result;
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
        $result = $wpdb->insert($wpdb->prefix . 'wnq_portal_requests', [
            'client_id'    => sanitize_text_field($client_id),
            'request_key'  => 'req-' . strtolower(wp_generate_password(8, false, false)),
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
            wp_mail($recipient, 'New portal request from ' . sanitize_text_field((string)($client['company'] ?: $client['name'] ?: $client_id)), $title . "\n\n" . sanitize_textarea_field((string)($data['details'] ?? '')));
        }
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
        return $result === false ? false : (int)$wpdb->insert_id;
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

    public static function getTasks(string $client_id, int $limit = 20): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_tasks';
        if (!self::tableExists($table)) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,title,status,priority,due_date,completed_at,created_at FROM $table
             WHERE client_id=%s AND archived_at IS NULL ORDER BY status='done' ASC, due_date IS NULL, due_date ASC, created_at DESC LIMIT %d",
            $client_id,
            max(1, min(100, $limit))
        ), ARRAY_A) ?: [];
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

    public static function overview(string $client_id): array
    {
        $client = Client::getByClientId($client_id) ?: [];
        $customers = self::getCustomerSummary($client_id);
        $tasks = self::getTasks($client_id, 50);
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
            'performance'     => self::getMonthlyPerformance($client_id),
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
        $checked = wp_check_filetype_and_ext((string)$file['tmp_name'], sanitize_file_name((string)($file['name'] ?? 'attachment')), get_allowed_mime_types());
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
        foreach (['wnq_portal_messages', 'wnq_portal_requests'] as $suffix) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT attachments FROM {$wpdb->prefix}{$suffix} WHERE client_id=%s AND attachments LIKE %s",
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
        return null;
    }

    public static function attachmentDownloadUrl(string $client_id, string $token): string
    {
        return add_query_arg([
            'action'    => 'wnq_portal_download_attachment',
            'client_id' => $client_id,
            'token'     => $token,
            '_wpnonce'  => wp_create_nonce('wnq_portal_attachment_' . $client_id . '_' . $token),
        ], admin_url('admin-post.php'));
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
