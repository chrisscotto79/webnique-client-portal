<?php
namespace WNQ\Services;
use WNQ\Models\Client;
use WNQ\Models\FinanceEntry;
if (!defined('ABSPATH')) exit;

/** Owner-authorized assumed payments, not a bank or Stripe integration. */
final class MonthlyBookkeeping
{
    public static function register(): void
    {
        add_action('wnq_monthly_bookkeeping', [self::class, 'run']);
        add_action('init', static function () {
            if (!wp_next_scheduled('wnq_monthly_bookkeeping')) wp_schedule_event(time() + 60, 'hourly', 'wnq_monthly_bookkeeping');
        });
    }

    public static function enabled(int $id): bool { return (bool)get_option('wnq_auto_books_' . $id, true); }

    public static function dueDay(array $client): int
    {
        $day = (int)($client['payment_due_day'] ?? 0);
        if ($day >= 1 && $day <= 31) return $day;
        foreach (['last_payment_date', 'created_at'] as $field) {
            if (preg_match('/^\d{4}-\d{2}-(\d{2})/', (string)($client[$field] ?? ''), $m)) return max(1, min(31, (int)$m[1]));
        }
        return 1;
    }

    public static function eligible(array $client, \DateTimeImmutable $today): bool
    {
        if (($client['status'] ?? '') !== 'active' || ($client['billing_cycle'] ?? 'monthly') !== 'monthly' || (float)($client['monthly_rate'] ?? 0) <= 0) return false;
        // Never fabricate historical payments on installation. Only the current month is processed.
        if (substr((string)($client['last_payment_date'] ?? ''), 0, 7) >= $today->format('Y-m')) return false;
        $next = (string)($client['next_payment_due_date'] ?? '');
        if ($next !== '' && $next > $today->format('Y-m-d')) return false;
        return (int)$today->format('j') >= min(self::dueDay($client), (int)$today->format('t'));
    }

    public static function ensure(): bool
    {
        global $wpdb;
        if (get_option('wnq_bookkeeping_schema') !== '1') {
            FinanceEntry::createTable();
            $columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}wnq_finance_entries", 0);
            if (!in_array('bookkeeping_data', $columns ?: [], true)) return false;
            update_option('wnq_bookkeeping_schema', '1', false);
        }
        return true;
    }

    public static function current(int $id): ?array
    {
        global $wpdb;
        if (!self::ensure()) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_finance_entries WHERE client_id = %d AND bookkeeping_period = %s", $id, current_datetime()->format('Y-m')), ARRAY_A) ?: null;
    }

    public static function run(): void
    {
        if (!self::ensure()) return;
        update_option('wnq_bookkeeping_error', '', false);
        foreach (Client::getAll() as $client) {
            if (self::enabled((int)$client['id']) && self::eligible($client, current_datetime())) {
                try { self::record((int)$client['id'], 'assumed'); }
                catch (\Throwable $e) { update_option('wnq_bookkeeping_error', $e->getMessage(), false); }
            }
        }
    }

    public static function record(int $id, string $status): void
    {
        global $wpdb;
        if (!in_array($status, ['assumed', 'manual', 'unpaid'], true) || !self::ensure()) throw new \RuntimeException('Bookkeeping storage is not ready.');
        $clients = $wpdb->prefix . 'wnq_clients'; $entries = $wpdb->prefix . 'wnq_finance_entries';
        // Fail closed on old non-transactional tables: partial financial updates are not safe.
        foreach ([$clients, $entries] as $table) {
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
            if (strtolower((string)$engine) !== 'innodb') throw new \RuntimeException('Automatic bookkeeping requires InnoDB client and finance tables. No payment was changed.');
        }
        $today = current_datetime(); $period = $today->format('Y-m');
        if ($wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Could not begin bookkeeping transaction.');
        try {
            $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM $clients WHERE id = %d FOR UPDATE", $id), ARRAY_A);
            if (!$client) throw new \RuntimeException('Client not found.');
            if (($client['billing_cycle'] ?? 'monthly') !== 'monthly') throw new \RuntimeException('This action is only for monthly bookkeeping.');
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $entries WHERE client_id = %d AND bookkeeping_period = %s FOR UPDATE", $id, $period), ARRAY_A);
            if ($status === 'assumed' && ($old || !self::enabled($id) || !self::eligible($client, $today))) { $wpdb->query('COMMIT'); return; }
            $oldPaid = $old && in_array($old['bookkeeping_status'], ['assumed', 'manual'], true);
            if ($status === 'manual' && $oldPaid && $old['bookkeeping_status'] === 'assumed') {
                if ($wpdb->update($entries, ['bookkeeping_status' => 'manual', 'payment_method' => 'Marked Paid'], ['id' => $old['id']]) === false) throw new \RuntimeException('Could not confirm the bookkeeping entry.');
                $wpdb->query('COMMIT'); return;
            }
            if ($status !== 'unpaid' && ($oldPaid || (!$old && substr((string)($client['last_payment_date'] ?? ''), 0, 7) === $period))) { $wpdb->query('COMMIT'); return; }
            if ($status === 'unpaid' && $old && $old['bookkeeping_status'] === 'unpaid') { $wpdb->query('COMMIT'); return; }
            $before = $old ? json_decode($old['bookkeeping_data'] ?? '{}', true) : ['last_payment_date' => $client['last_payment_date'], 'next_payment_due_date' => $client['next_payment_due_date']];
            $amount = $status === 'unpaid' ? 0 : max(0, round((float)($client['after_fees'] ?? $client['monthly_rate']), 2));
            $day = self::dueDay($client);
            $due = $today->format('Y-m-') . str_pad((string)min($day, (int)$today->format('t')), 2, '0', STR_PAD_LEFT);
            $row = ['client_id' => $id, 'type' => 'income', 'category' => 'Client Payment', 'amount' => $amount,
                'entry_date' => $status === 'assumed' ? $due : $today->format('Y-m-d'), 'recurrence' => 'one_time',
                'payment_method' => $status === 'assumed' ? 'Assumed paid (unverified)' : ($status === 'manual' ? 'Marked Paid' : 'Marked Unpaid'),
                'description' => 'Monthly bookkeeping for ' . $period . '. No payment processor confirmation.',
                'bookkeeping_period' => $period, 'bookkeeping_status' => $status, 'bookkeeping_data' => wp_json_encode($before)];
            $ok = $old ? $wpdb->update($entries, $row, ['id' => $old['id']]) : $wpdb->insert($entries, $row);
            if ($ok === false) throw new \RuntimeException('Payment entry could not be saved.');
            $updates = ['payment_count' => max(0, (int)$client['payment_count'] + ($status !== 'unpaid' ? 1 : 0) - ($oldPaid ? 1 : 0)),
                'total_collected' => max(0, round((float)$client['total_collected'] + $amount - ($oldPaid ? (float)$old['amount'] : 0), 2))];
            if ($status !== 'unpaid') {
                $updates['payment_due_day'] = $day; // Preserve day 31 across a February clamp, including inferred schedules.
                $updates['last_payment_date'] = $row['entry_date'];
                $updates['next_payment_due_date'] = Client::calculateFollowingPaymentDate($day, $due, 1, $row['entry_date']);
            } elseif ($oldPaid && $client['last_payment_date'] === $old['entry_date']) {
                $updates['last_payment_date'] = $before['last_payment_date'] ?? null;
                $updates['next_payment_due_date'] = $before['next_payment_due_date'] ?? $due;
            }
            if ($wpdb->update($clients, $updates, ['id' => $id]) === false) throw new \RuntimeException('Client totals could not be saved.');
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Bookkeeping commit failed.');
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }
}
