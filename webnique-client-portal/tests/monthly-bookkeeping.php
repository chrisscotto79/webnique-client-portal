<?php
namespace WNQ\Models {
    class Client {
        public static function getAll() { return array_values($GLOBALS['wpdb']->clients); }
        public static function calculateFollowingPaymentDate($day, $due, $months, $paid) {
            $date = (new \DateTimeImmutable($paid))->modify('first day of next month');
            return $date->format('Y-m-') . str_pad((string)min($day, (int)$date->format('t')), 2, '0', STR_PAD_LEFT);
        }
    }
    class FinanceEntry { public static function createTable() {} }
}
namespace {
define('ABSPATH', __DIR__);
$now = '2026-09-15'; $options = ['wnq_bookkeeping_schema' => '1'];
function current_datetime() { return new DateTimeImmutable($GLOBALS['now'], new DateTimeZone('America/New_York')); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function wp_json_encode($value) { return json_encode($value); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
class DB {
    public $prefix = 'wp_', $clients = [], $entries = [], $fail = false, $snapshot;
    function prepare($sql, ...$args) { return [$sql, $args]; }
    function get_var($sql) { return 'InnoDB'; }
    function get_row($q, $format) {
        [$sql, $args] = $q;
        if (str_contains($sql, 'wnq_clients')) return $this->clients[$args[0]] ?? null;
        foreach ($this->entries as $row) if ($row['client_id'] === $args[0] && $row['bookkeeping_period'] === $args[1]) return $row;
        return null;
    }
    function query($sql) {
        if ($sql === 'START TRANSACTION') $this->snapshot = [$this->clients, $this->entries];
        if ($sql === 'ROLLBACK') [$this->clients, $this->entries] = $this->snapshot;
        return 1;
    }
    function insert($table, $data) { $data['id'] = count($this->entries) + 1; $this->entries[$data['id']] = $data; return 1; }
    function update($table, $data, $where) {
        if (str_ends_with($table, 'wnq_clients')) {
            if ($this->fail) return false;
            $this->clients[$where['id']] = array_merge($this->clients[$where['id']], $data);
        } else $this->entries[$where['id']] = array_merge($this->entries[$where['id']], $data);
        return 1;
    }
}
define('ARRAY_A', 'ARRAY_A');
$wpdb = new DB();
$base = ['id' => 1, 'status' => 'active', 'billing_cycle' => 'monthly', 'monthly_rate' => 100, 'after_fees' => 97, 'payment_due_day' => 15, 'last_payment_date' => '2026-08-15', 'next_payment_due_date' => '2026-09-15', 'payment_count' => 2, 'total_collected' => 194];
$wpdb->clients[1] = $base;
require dirname(__DIR__) . '/includes/Services/MonthlyBookkeeping.php';
use WNQ\Services\MonthlyBookkeeping as Books;
Books::run(); Books::run();
check(count($wpdb->entries) === 1 && $wpdb->clients[1]['total_collected'] === 291.0, 'Repeated cron must not double count');
check($wpdb->clients[1]['next_payment_due_date'] === '2026-10-15', 'Advance next due month');
Books::record(1, 'manual');
check($wpdb->clients[1]['payment_count'] === 3, 'Manual paid after assumed paid must not double count');
Books::record(1, 'unpaid'); Books::run();
check($wpdb->clients[1]['total_collected'] === 194.0 && $wpdb->entries[1]['bookkeeping_status'] === 'unpaid', 'Manual unpaid reverses and survives cron');
check($wpdb->clients[1]['last_payment_date'] === '2026-08-15', 'Reversal restores prior payment');
Books::record(1, 'manual'); Books::record(1, 'manual');
check($wpdb->clients[1]['total_collected'] === 291.0, 'Unpaid to paid once');
$now = '2026-10-15'; Books::run();
check(count($wpdb->entries) === 2 && $wpdb->clients[1]['payment_count'] === 4, 'New month gets its own entry');
$wpdb->clients[2] = array_merge($base, ['id' => 2, 'payment_due_day' => 31]);
$now = '2026-02-28'; $wpdb->clients[2]['last_payment_date'] = '2026-01-31'; $wpdb->clients[2]['next_payment_due_date'] = '';
check(Books::eligible($wpdb->clients[2], current_datetime()), 'Short month clamps day 31');
$options['wnq_auto_books_2'] = false; Books::run();
check(count($wpdb->entries) === 2, 'Disabled client skipped');
$options['wnq_auto_books_2'] = true; $wpdb->fail = true; Books::run();
check(count($wpdb->entries) === 2 && $wpdb->clients[2]['payment_count'] === 2, 'Failed totals write rolls back ledger');
$wpdb->fail = false; Books::run();
check(count($wpdb->entries) === 3, 'Retry failed operation safely');
$legacy = array_merge($base, ['last_payment_date' => '2026-02-02']);
check(!Books::eligible($legacy, current_datetime()), 'Preserve existing monthly payment');
check(!Books::eligible(array_merge($base, ['status' => 'inactive']), current_datetime()), 'Inactive skipped');
check(!Books::eligible(array_merge($base, ['billing_cycle' => 'quarterly']), current_datetime()), 'Quarterly not treated as monthly');
echo "Monthly bookkeeping tests passed: duplicate, reversal, monthly rollover, short months, disable, failure rollback, legacy preservation.\n";
}
