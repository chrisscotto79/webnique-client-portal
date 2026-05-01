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
            client_id bigint(20) UNSIGNED DEFAULT NULL,
            payment_method varchar(100) DEFAULT '',
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY entry_date (entry_date),
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

        $result = $wpdb->insert(
            $table_name,
            [
                'type' => $type,
                'category' => sanitize_text_field($data['category'] ?? ''),
                'amount' => $amount,
                'entry_date' => $entry_date,
                'client_id' => !empty($data['client_id']) ? intval($data['client_id']) : null,
                'payment_method' => sanitize_text_field($data['payment_method'] ?? ''),
                'description' => sanitize_textarea_field($data['description'] ?? ''),
            ],
            ['%s', '%s', '%f', '%s', '%d', '%s', '%s']
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
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;
        $month_start = gmdate('Y-m-01', current_time('timestamp'));
        $month_end = gmdate('Y-m-t', current_time('timestamp'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT type,
                    SUM(amount) AS total,
                    SUM(CASE WHEN entry_date BETWEEN %s AND %s THEN amount ELSE 0 END) AS month_total
                FROM $table_name
                GROUP BY type",
                $month_start,
                $month_end
            ),
            ARRAY_A
        );

        $summary = [
            'income' => 0.0,
            'expense' => 0.0,
            'net' => 0.0,
            'month_income' => 0.0,
            'month_expense' => 0.0,
            'month_net' => 0.0,
        ];

        foreach ($rows ?: [] as $row) {
            $type = $row['type'] === 'expense' ? 'expense' : 'income';
            $summary[$type] = floatval($row['total']);
            $summary['month_' . $type] = floatval($row['month_total']);
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

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(entry_date, '%%Y-%%m') AS month_key,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expenses
                FROM $table_name
                WHERE entry_date >= %s
                GROUP BY month_key
                ORDER BY month_key ASC",
                $start
            ),
            ARRAY_A
        );

        $by_month = [];
        foreach ($rows ?: [] as $row) {
            $by_month[$row['month_key']] = [
                'income' => floatval($row['income']),
                'expenses' => floatval($row['expenses']),
            ];
        }

        $labels = [];
        $income = [];
        $expenses = [];
        $net = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $timestamp = strtotime("-$i months", current_time('timestamp'));
            $key = gmdate('Y-m', $timestamp);
            $labels[] = gmdate('M Y', $timestamp);
            $month_income = $by_month[$key]['income'] ?? 0.0;
            $month_expenses = $by_month[$key]['expenses'] ?? 0.0;
            $income[] = $month_income;
            $expenses[] = $month_expenses;
            $net[] = $month_income - $month_expenses;
        }

        return [
            'labels' => $labels,
            'income' => $income,
            'expenses' => $expenses,
            'net' => $net,
        ];
    }
}
