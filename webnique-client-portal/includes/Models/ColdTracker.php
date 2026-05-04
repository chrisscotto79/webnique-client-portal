<?php
/**
 * Cold Tracker Model
 *
 * Handles database operations for cold call KPI tracking.
 * Table: wp_wnq_cold_calls
 *
 * @package WebNique Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class ColdTracker
{
    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wnq_cold_calls';
    }

    public static function createTable(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::table() . " (
            id            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            call_date     date NOT NULL,
            num_calls     int(11) NOT NULL DEFAULT 0,
            num_answers   int(11) NOT NULL DEFAULT 0,
            num_pitches   int(11) NOT NULL DEFAULT 0,
            num_interested int(11) NOT NULL DEFAULT 0,
            num_meetings  int(11) NOT NULL DEFAULT 0,
            num_website_sales int(11) NOT NULL DEFAULT 0,
            offer_type    varchar(32) NOT NULL DEFAULT 'website_sales',
            notes         text,
            created_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY call_date (call_date),
            KEY idx_date (call_date)
        ) $c;");

        self::ensureSchema();
    }

    public static function ensureSchema(): void
    {
        global $wpdb;
        $columns = $wpdb->get_col("DESCRIBE " . self::table(), 0);

        if (empty($columns)) {
            return;
        }

        if (!in_array('num_website_sales', $columns, true)) {
            $wpdb->query("ALTER TABLE " . self::table() . " ADD COLUMN num_website_sales int(11) NOT NULL DEFAULT 0 AFTER num_meetings");
        }

        if (!in_array('num_interested', $columns, true)) {
            $wpdb->query("ALTER TABLE " . self::table() . " ADD COLUMN num_interested int(11) NOT NULL DEFAULT 0 AFTER num_pitches");
        }

        if (!in_array('offer_type', $columns, true)) {
            $wpdb->query("ALTER TABLE " . self::table() . " ADD COLUMN offer_type varchar(32) NOT NULL DEFAULT 'website_sales' AFTER num_website_sales");
        }
    }

    /**
     * Save or update a day's data. Returns true on success.
     */
    public static function saveDay(array $data): bool
    {
        global $wpdb;

        self::createTable();

        $date = sanitize_text_field($data['call_date'] ?? '');
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $row = [
            'call_date'    => $date,
            'num_calls'    => max(0, (int)($data['num_calls']    ?? 0)),
            'num_answers'  => max(0, (int)($data['num_answers']  ?? 0)),
            'num_pitches'  => max(0, (int)($data['num_pitches']  ?? 0)),
            'num_interested' => max(0, (int)($data['num_interested'] ?? 0)),
            'num_meetings' => max(0, (int)($data['num_meetings'] ?? 0)),
            'num_website_sales' => max(0, (int)($data['num_website_sales'] ?? 0)),
            'offer_type'   => self::normalizeOfferType($data['offer_type'] ?? 'website_sales'),
            'notes'        => sanitize_textarea_field($data['notes'] ?? ''),
            'updated_at'   => current_time('mysql'),
        ];

        $existing = self::getDay($date);
        if ($existing) {
            return false !== $wpdb->update(self::table(), $row, ['call_date' => $date]);
        }

        $row['created_at'] = current_time('mysql');
        return false !== $wpdb->insert(self::table(), $row);
    }

    /**
     * Get a single day's data, or null if not logged.
     */
    public static function getDay(string $date): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE call_date = %s", $date),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Get all logged days in a date range, keyed by date string.
     */
    public static function getRange(string $from, string $to): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE call_date BETWEEN %s AND %s ORDER BY call_date ASC",
                $from,
                $to
            ),
            ARRAY_A
        ) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $result[$row['call_date']] = $row;
        }
        return $result;
    }

    /**
     * Get all logged days in a calendar month, keyed by date string.
     */
    public static function getMonthData(int $year, int $month): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = date('Y-m-t', strtotime($from));
        return self::getRange($from, $to);
    }

    /**
     * Aggregate an array of day rows into totals + conversion rates.
     */
    public static function aggregateStats(array $days): array
    {
        $totals = [
            'calls'       => 0,
            'answers'     => 0,
            'pitches'     => 0,
            'interested'   => 0,
            'meetings'    => 0,
            'website_sales' => 0,
            'website_sales_offer' => 0,
            'ninety_offer' => 0,
            'days_called' => 0,
        ];

        foreach ($days as $day) {
            $totals['calls']    += (int)$day['num_calls'];
            $totals['answers']  += (int)$day['num_answers'];
            $totals['pitches']  += (int)$day['num_pitches'];
            $totals['interested'] += (int)($day['num_interested'] ?? 0);
            $totals['meetings'] += (int)$day['num_meetings'];
            $website_sales = (int)($day['num_website_sales'] ?? 0);
            $totals['website_sales'] += $website_sales;
            if (($day['offer_type'] ?? 'website_sales') === '90_offer') {
                $totals['ninety_offer'] += $website_sales;
            } else {
                $totals['website_sales_offer'] += $website_sales;
            }
            if ((int)$day['num_calls'] > 0) {
                $totals['days_called']++;
            }
        }

        $totals['avg_calls']     = $totals['days_called'] > 0
            ? round($totals['calls'] / $totals['days_called'], 1)
            : 0;
        $totals['answer_rate']   = $totals['calls']   > 0 ? round($totals['answers']  / $totals['calls']   * 100, 1) : 0;
        $totals['pitch_rate']    = $totals['answers'] > 0 ? round($totals['pitches']  / $totals['answers'] * 100, 1) : 0;
        $totals['interest_rate'] = $totals['pitches'] > 0 ? round($totals['interested'] / $totals['pitches'] * 100, 1) : 0;
        $totals['meeting_rate']  = $totals['pitches'] > 0 ? round($totals['meetings'] / $totals['pitches'] * 100, 1) : 0;
        $totals['call_to_meet']  = $totals['calls']   > 0 ? round($totals['meetings'] / $totals['calls']   * 100, 2) : 0;
        $totals['website_sale_rate'] = $totals['pitches'] > 0 ? round($totals['website_sales'] / $totals['pitches'] * 100, 1) : 0;
        $totals['call_to_sale'] = $totals['calls'] > 0 ? round($totals['website_sales'] / $totals['calls'] * 100, 2) : 0;

        return $totals;
    }

    private static function normalizeOfferType($offerType): string
    {
        $offerType = sanitize_key((string)$offerType);
        return in_array($offerType, ['website_sales', '90_offer'], true) ? $offerType : 'website_sales';
    }

    /**
     * Generate smart coaching suggestions based on KPI stats.
     * Returns array of ['type' => 'success|warning|danger|info', 'msg' => '...']
     */
    public static function generateSuggestions(array $stats, string $period = 'week'): array
    {
        $suggestions = [];

        $calls   = $stats['calls']        ?? 0;
        $days    = $stats['days_called']  ?? 0;
        $ar      = $stats['answer_rate']  ?? 0;
        $pr      = $stats['pitch_rate']   ?? 0;
        $mr      = $stats['meeting_rate'] ?? 0;
        $wsr     = $stats['website_sale_rate'] ?? 0;
        $avg     = $stats['avg_calls']    ?? 0;
        $c2s     = $stats['call_to_sale'] ?? 0;

        if ($calls === 0) {
            $suggestions[] = [
                'type' => 'info',
                'msg'  => 'No calls logged this ' . $period . ' yet. Enter your daily KPIs to start seeing insights.',
            ];
            return $suggestions;
        }

        // ── Call Volume ──────────────────────────────────────────────────
        if ($avg > 0 && $avg < 150) {
            $suggestions[] = [
                'type' => 'warning',
                'msg'  => "Averaging {$avg} calls/day — below the 200 target. Time-block 2-hour call sessions to hit your daily number consistently.",
            ];
        } elseif ($avg >= 200) {
            $suggestions[] = [
                'type' => 'success',
                'msg'  => "Solid volume — you're averaging {$avg} calls/day and hitting your target. Keep that momentum.",
            ];
        }

        // Missed days
        if ($period === 'week' && $days < 4) {
            $suggestions[] = [
                'type' => 'warning',
                'msg'  => "You called on {$days} day(s) this week. Daily consistency compounds over time — even 100 calls on a slow day keeps the pipeline moving.",
            ];
        }

        // ── Answer Rate ──────────────────────────────────────────────────
        if ($ar < 10) {
            $suggestions[] = [
                'type' => 'danger',
                'msg'  => "Answer rate is {$ar}% — very low. Shift call windows to 8–9am or 4–6pm when decision-makers are more reachable. Avoid Mondays and Fridays.",
            ];
        } elseif ($ar < 18) {
            $suggestions[] = [
                'type' => 'warning',
                'msg'  => "Answer rate of {$ar}% is below the 18–25% benchmark. Experiment with local area codes or call from a mobile number — caller ID matters.",
            ];
        } elseif ($ar >= 25) {
            $suggestions[] = [
                'type' => 'success',
                'msg'  => "Strong answer rate at {$ar}%! Your timing and approach are working. Document what's working and protect those call windows.",
            ];
        }

        // ── Pitch Rate (of answers) ──────────────────────────────────────
        if ($pr < 30) {
            $suggestions[] = [
                'type' => 'danger',
                'msg'  => "Only {$pr}% of answers become pitches. You have 7 seconds — lead with a specific, relevant pain point, not a company intro. Test a new opener this week.",
            ];
        } elseif ($pr < 50) {
            $suggestions[] = [
                'type' => 'warning',
                'msg'  => "Pitch rate is {$pr}%. Work on a stronger pattern interrupt or curiosity hook to earn 60 more seconds before they hang up.",
            ];
        } elseif ($pr >= 60) {
            $suggestions[] = [
                'type' => 'success',
                'msg'  => "Excellent pitch rate at {$pr}%! Your opener is resonating. Record what you're saying so you can refine and scale it.",
            ];
        }

        // ── Meeting Rate (of pitches) ────────────────────────────────────
        if ($mr < 10) {
            $suggestions[] = [
                'type' => 'danger',
                'msg'  => "Meeting conversion is {$mr}%. Make the ask feel low-commitment and offer a specific time.",
            ];
        } elseif ($mr < 20) {
            $suggestions[] = [
                'type' => 'warning',
                'msg'  => "Meeting rate is {$mr}%. Try asking permission to send a quick website teardown before the meeting ask.",
            ];
        } elseif ($mr >= 25) {
            $suggestions[] = [
                'type' => 'success',
                'msg'  => "Great meeting conversion at {$mr}%. Your discovery ask is working.",
            ];
        }

        // ── Website sales rate (of pitches) ──────────────────────────────
        if ($wsr > 0 && $wsr < 5) {
            $suggestions[] = [
                'type' => 'warning',
                'msg'  => "Website sales conversion is {$wsr}%. Make the offer concrete: focus on one website problem, one outcome, and one clear next step.",
            ];
        } elseif ($wsr >= 10) {
            $suggestions[] = [
                'type' => 'success',
                'msg'  => "Great website sales conversion at {$wsr}%. Your website offer and close are working.",
            ];
        }

        // ── Overall Efficiency ───────────────────────────────────────────
        if ($calls >= 100 && $c2s > 0) {
            $calls_per_sale = round(1 / ($c2s / 100));
            $suggestions[] = [
                'type' => 'info',
                'msg'  => "You're closing 1 website sale every ~{$calls_per_sale} dials. Increase call volume or improve any one stage to bring that number down.",
            ];
        }

        return $suggestions;
    }

    /**
     * Get the current call streak (consecutive days with > 0 calls logged up to today).
     */
    public static function getCurrentStreak(): int
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT call_date FROM " . self::table() . " WHERE num_calls > 0 ORDER BY call_date DESC LIMIT 60",
            ARRAY_A
        ) ?: [];

        if (empty($rows)) return 0;

        $streak = 0;
        $check  = current_time('Y-m-d');

        // Allow today to not be logged yet (check from yesterday if today isn't there)
        if ($rows[0]['call_date'] !== $check) {
            $check = date('Y-m-d', strtotime($check . ' -1 day'));
            if ($rows[0]['call_date'] !== $check) return 0;
        }

        foreach ($rows as $row) {
            if ($row['call_date'] === $check) {
                $streak++;
                $check = date('Y-m-d', strtotime($check . ' -1 day'));
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Get weekly breakdown rows for a given month (array of weeks with stats).
     */
    public static function getWeeklyBreakdown(int $year, int $month): array
    {
        $monthData = self::getMonthData($year, $month);
        $firstDay  = sprintf('%04d-%02d-01', $year, $month);
        $lastDay   = date('Y-m-t', strtotime($firstDay));

        $current = $firstDay;
        $weeks   = [];
        $weekNum = 1;

        while ($current <= $lastDay) {
            // Week starts on Monday
            $dow       = (int)date('N', strtotime($current)); // 1=Mon, 7=Sun
            $weekStart = date('Y-m-d', strtotime($current . ' -' . ($dow - 1) . ' days'));
            $weekEnd   = date('Y-m-d', strtotime($weekStart . ' +6 days'));

            // Clamp to month boundaries
            $rangeStart = max($current, $weekStart);
            $rangeEnd   = min($lastDay, $weekEnd);

            $weekDays = [];
            $d = $rangeStart;
            while ($d <= $rangeEnd) {
                if (isset($monthData[$d])) {
                    $weekDays[] = $monthData[$d];
                }
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }

            $stats = self::aggregateStats($weekDays);
            $weeks[] = [
                'week'  => $weekNum,
                'start' => $rangeStart,
                'end'   => $rangeEnd,
                'stats' => $stats,
            ];

            $weekNum++;
            $current = date('Y-m-d', strtotime($weekEnd . ' +1 day'));
        }

        return $weeks;
    }
}
