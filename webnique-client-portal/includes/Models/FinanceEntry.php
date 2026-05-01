<?php
/**
 * Finance Entry Model
 *
 * Tracks portal income and expenses for client revenue reporting.
 *
 * @package WebNique Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class FinanceEntry
{
    private static string $table = 'wnq_finance_entries';

    public static function createTable(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'income',
            category varchar(100) NOT NULL DEFAULT '',
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            entry_date date NOT NULL,
            recurrence varchar(20) NOT NULL DEFAULT 'one_time',
            client_id bigint(20) UNSIGNED DEFAULT NULL,
            payment_method varchar(100) DEFAULT '',
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY entry_date (entry_date),
            KEY recurrence (recurrence),
            KEY client_id (client_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function create(array $data): int|false
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $type = sanitize_key($data['type'] ?? 'income');
        if (!in_array($type, ['income', 'expense'], true)) {
            $type = 'income';
        }

        $amount = round(abs(floatval($data['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            return false;
        }

        $entry_date = sanitize_text_field($data['entry_date'] ?? current_time('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)) {
            $entry_date = current_time('Y-m-d');
        }

        $recurrence = sanitize_key($data['recurrence'] ?? 'one_time');
        if (!in_array($recurrence, ['one_time', 'monthly'], true)) {
            $recurrence = 'one_time';
        }

        $result = $wpdb->insert(
            $table_name,
            [
                'type' => $type,
                'category' => sanitize_text_field($data['category'] ?? ''),
                'amount' => $amount,
                'entry_date' => $entry_date,
                'recurrence' => $recurrence,
                'client_id' => !empty($data['client_id']) ? intval($data['client_id']) : null,
                'payment_method' => sanitize_text_field($data['payment_method'] ?? ''),
                'description' => sanitize_textarea_field($data['description'] ?? ''),
            ],
            ['%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s']
        );

        return $result === false ? false : (int) $wpdb->insert_id;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $result = $wpdb->delete($table_name, ['id' => $id], ['%d']);

        return $result !== false;
    }

    public static function getAll(int $limit = 100): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;
        $clients_table = $wpdb->prefix . 'wnq_clients';

        $limit = max(1, min(500, $limit));

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*, c.name AS client_name, c.company AS client_company
                FROM $table_name e
                LEFT JOIN $clients_table c ON c.id = e.client_id
                ORDER BY e.entry_date DESC, e.id DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    public static function getSummary(): array
    {
        $summary = [
            'income' => 0.0,
            'expense' => 0.0,
            'net' => 0.0,
            'month_income' => 0.0,
            'month_expense' => 0.0,
            'month_net' => 0.0,
        ];

        $month_start = gmdate('Y-m-01', current_time('timestamp'));
        $month_end = gmdate('Y-m-t', current_time('timestamp'));

        foreach (self::getAll(500) as $entry) {
            $type = $entry['type'] === 'expense' ? 'expense' : 'income';
            $amount = floatval($entry['amount']);
            $is_monthly = ($entry['recurrence'] ?? 'one_time') === 'monthly';
            $is_this_month = $entry['entry_date'] >= $month_start && $entry['entry_date'] <= $month_end;
            $is_active_monthly = $is_monthly && $entry['entry_date'] <= $month_end;

            if (!$is_monthly || $is_active_monthly) {
                $summary[$type] += $amount;
            }

            if ($is_this_month || $is_active_monthly) {
                $summary['month_' . $type] += $amount;
            }
        }

        $summary['net'] = $summary['income'] - $summary['expense'];
        $summary['month_net'] = $summary['month_income'] - $summary['month_expense'];

        return $summary;
    }

    public static function getMonthlyTotals(int $months = 12): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;
        $months = max(1, min(36, $months));
        $start = gmdate('Y-m-01', strtotime('-' . ($months - 1) . ' months', current_time('timestamp')));
        $end = gmdate('Y-m-t', current_time('timestamp'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT type, amount, entry_date, recurrence
                FROM $table_name
                WHERE entry_date <= %s
                    AND (entry_date >= %s OR recurrence = 'monthly')
                ORDER BY entry_date ASC",
                $end,
                $start
            ),
            ARRAY_A
        );

        $labels = [];
        $income = [];
        $expenses = [];
        $net = [];
        $month_ranges = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $timestamp = strtotime("-$i months", current_time('timestamp'));
            $key = gmdate('Y-m', $timestamp);
            $labels[] = gmdate('M Y', $timestamp);
            $month_ranges[$key] = [
                'start' => gmdate('Y-m-01', $timestamp),
                'end' => gmdate('Y-m-t', $timestamp),
                'income' => 0.0,
                'expenses' => 0.0,
            ];
        }

        foreach ($rows ?: [] as $row) {
            $amount = floatval($row['amount']);
            $field = $row['type'] === 'expense' ? 'expenses' : 'income';
            $is_monthly = ($row['recurrence'] ?? 'one_time') === 'monthly';

            foreach ($month_ranges as $key => $range) {
                if ($is_monthly && $row['entry_date'] <= $range['end']) {
                    $month_ranges[$key][$field] += $amount;
                    continue;
                }

                if (!$is_monthly && $row['entry_date'] >= $range['start'] && $row['entry_date'] <= $range['end']) {
                    $month_ranges[$key][$field] += $amount;
                }
            }
        }

        foreach ($month_ranges as $range) {
            $income[] = $range['income'];
            $expenses[] = $range['expenses'];
            $net[] = $range['income'] - $range['expenses'];
        }

        return [
            'labels' => $labels,
            'income' => $income,
            'expenses' => $expenses,
            'net' => $net,
        ];
    }
}
