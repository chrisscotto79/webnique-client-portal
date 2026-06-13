<?php
/**
 * Client portal records and summaries.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class ClientPortal
{
    private const SCHEMA_VERSION = '1';
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
            name varchar(255) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            address varchar(500) DEFAULT NULL,
            service varchar(255) DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'new',
            follow_up_date date DEFAULT NULL,
            job_count int(11) NOT NULL DEFAULT 0,
            estimated_value decimal(12,2) NOT NULL DEFAULT 0.00,
            final_value decimal(12,2) NOT NULL DEFAULT 0.00,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY status (status),
            KEY follow_up_date (follow_up_date)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_portal_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            sender_user_id bigint(20) UNSIGNED DEFAULT NULL,
            sender_role varchar(20) NOT NULL DEFAULT 'client',
            subject varchar(255) DEFAULT NULL,
            message text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'unread',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;");

        update_option('wnq_client_portal_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function getCustomers(string $client_id, int $limit = 100): array
    {
        self::ensureSchema();
        global $wpdb;
        $limit = max(1, min(500, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_customers WHERE client_id=%s ORDER BY updated_at DESC, id DESC LIMIT %d",
            $client_id,
            $limit
        ), ARRAY_A) ?: [];
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
        return $row ?: null;
    }

    public static function saveCustomer(string $client_id, array $data): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $status = sanitize_key($data['status'] ?? 'new');
        if (!in_array($status, ['new', 'contacted', 'scheduled', 'completed', 'closed'], true)) {
            $status = 'new';
        }
        $follow_up = sanitize_text_field((string)($data['follow_up_date'] ?? ''));
        if ($follow_up !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow_up)) {
            $follow_up = '';
        }
        $record = [
            'client_id'       => sanitize_text_field($client_id),
            'name'            => sanitize_text_field((string)($data['name'] ?? '')),
            'phone'           => sanitize_text_field((string)($data['phone'] ?? '')),
            'email'           => sanitize_email((string)($data['email'] ?? '')),
            'address'         => sanitize_text_field((string)($data['address'] ?? '')),
            'service'         => sanitize_text_field((string)($data['service'] ?? '')),
            'status'          => $status,
            'follow_up_date'  => $follow_up ?: null,
            'job_count'       => max(0, absint($data['job_count'] ?? 0)),
            'estimated_value' => max(0, round((float)($data['estimated_value'] ?? 0), 2)),
            'final_value'     => max(0, round((float)($data['final_value'] ?? 0), 2)),
            'notes'           => sanitize_textarea_field((string)($data['notes'] ?? '')),
        ];
        if ($record['name'] === '') {
            return false;
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
                SUM(status='new') new_count,
                SUM(status='scheduled') scheduled_count,
                SUM(status='completed') completed_count,
                COALESCE(SUM(job_count),0) job_count,
                COALESCE(SUM(final_value),0) revenue
             FROM {$wpdb->prefix}wnq_portal_customers WHERE client_id=%s",
            $client_id
        ), ARRAY_A) ?: [];
        return [
            'total'           => absint($row['total'] ?? 0),
            'new_count'       => absint($row['new_count'] ?? 0),
            'scheduled_count' => absint($row['scheduled_count'] ?? 0),
            'completed_count' => absint($row['completed_count'] ?? 0),
            'job_count'       => absint($row['job_count'] ?? 0),
            'revenue'         => (float)($row['revenue'] ?? 0),
        ];
    }

    public static function getMessages(string $client_id, int $limit = 50): array
    {
        self::ensureSchema();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_portal_messages WHERE client_id=%s ORDER BY created_at DESC, id DESC LIMIT %d",
            $client_id,
            max(1, min(200, $limit))
        ), ARRAY_A) ?: [];
    }

    public static function createMessage(string $client_id, array $data, string $sender_role = 'client'): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $message = sanitize_textarea_field((string)($data['message'] ?? ''));
        if ($message === '') {
            return false;
        }
        $result = $wpdb->insert($wpdb->prefix . 'wnq_portal_messages', [
            'client_id'      => sanitize_text_field($client_id),
            'sender_user_id' => get_current_user_id() ?: null,
            'sender_role'    => $sender_role === 'admin' ? 'admin' : 'client',
            'subject'        => sanitize_text_field((string)($data['subject'] ?? '')),
            'message'        => $message,
            'status'         => 'unread',
        ]);
        return $result === false ? false : (int)$wpdb->insert_id;
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
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,report_type,period_start,period_end,status,generated_at FROM {$wpdb->prefix}wnq_seo_reports
             WHERE client_id=%s ORDER BY period_start DESC, generated_at DESC LIMIT %d",
            $client_id,
            max(1, min(50, $limit))
        ), ARRAY_A) ?: [];
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
            $actions[] = ['type' => 'work', 'label' => $open_tasks . ' work item' . ($open_tasks === 1 ? '' : 's') . ' in progress'];
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
            'unread_messages' => $unread_messages,
            'latest_report'   => $reports[0] ?? null,
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
}
