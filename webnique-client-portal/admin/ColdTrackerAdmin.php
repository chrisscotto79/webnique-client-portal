<?php
/**
 * Cold Tracker Admin
 *
 * Interactive cold call KPI tracker with monthly calendar, day modals,
 * weekly analytics, monthly analytics, and smart coaching suggestions.
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\ColdTracker;

final class ColdTrackerAdmin
{
    private static bool $registered = false;

    // ── Registration ──────────────────────────────────────────────────────

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('admin_menu',            [self::class, 'addMenuPage'], 24);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_wnq_cold_save_day',      [self::class, 'ajaxSaveDay']);
        add_action('wp_ajax_wnq_cold_get_month',     [self::class, 'ajaxGetMonth']);
        add_action('wp_ajax_wnq_cold_get_analytics', [self::class, 'ajaxGetAnalytics']);
    }

    public static function addMenuPage(): void
    {
        $cap = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page(
            'wnq-portal',
            'Website Sales',
            'Website Sales',
            $cap,
            'wnq-cold-tracker',
            [self::class, 'renderPage']
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'wnq-cold-tracker') === false) return;

        // Chart.js from CDN
        wp_enqueue_script(
            'wnq-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        // Pass bootstrap data to JS
        $year  = (int)current_time('Y');
        $month = (int)current_time('n');
        $monthRows = ColdTracker::getMonthData($year, $month);

        wp_localize_script('wnq-chartjs', 'WNQ_COLD', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('wnq_cold_tracker'),
            'today'        => current_time('Y-m-d'),
            'initialYear'  => $year,
            'initialMonth' => $month,
            'monthData'    => array_values($monthRows),
            'streak'       => ColdTracker::getCurrentStreak(),
        ]);

        wp_add_inline_script('wnq-chartjs', self::getInlineJS(), 'after');
        add_action('admin_head', [self::class, 'outputCSS']);
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────

    public static function ajaxSaveDay(): void
    {
        if (!check_ajax_referer('wnq_cold_tracker', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $ok = ColdTracker::saveDay([
            'call_date'    => sanitize_text_field($_POST['call_date']    ?? ''),
            'num_calls'    => (int)($_POST['num_calls']    ?? 0),
            'num_answers'  => (int)($_POST['num_answers']  ?? 0),
            'num_pitches'  => (int)($_POST['num_pitches']  ?? 0),
            'num_meetings' => (int)($_POST['num_meetings'] ?? 0),
            'num_website_sales' => (int)($_POST['num_website_sales'] ?? 0),
            'notes'        => sanitize_textarea_field($_POST['notes'] ?? ''),
        ]);

        $ok ? wp_send_json_success(['message' => 'Saved']) : wp_send_json_error(['message' => 'Save failed']);
    }

    public static function ajaxGetMonth(): void
    {
        if (!check_ajax_referer('wnq_cold_tracker', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $year  = (int)($_POST['year']  ?? current_time('Y'));
        $month = (int)($_POST['month'] ?? current_time('n'));

        if ($year < 2020 || $year > 2099 || $month < 1 || $month > 12) {
            wp_send_json_error(['message' => 'Invalid date']);
        }

        $rows   = ColdTracker::getMonthData($year, $month);
        $streak = ColdTracker::getCurrentStreak();

        wp_send_json_success([
            'rows'   => array_values($rows),
            'streak' => $streak,
        ]);
    }

    public static function ajaxGetAnalytics(): void
    {
        if (!check_ajax_referer('wnq_cold_tracker', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $type  = sanitize_text_field($_POST['type']  ?? 'weekly'); // 'weekly' | 'monthly'
        $year  = (int)($_POST['year']  ?? current_time('Y'));
        $month = (int)($_POST['month'] ?? current_time('n'));
        $week  = sanitize_text_field($_POST['week_start'] ?? ''); // YYYY-MM-DD (Monday)

        if ($type === 'weekly') {
            if (!$week) {
                // Default to current week Monday
                $dow  = (int)date('N', strtotime(current_time('Y-m-d')));
                $week = date('Y-m-d', strtotime(current_time('Y-m-d') . ' -' . ($dow - 1) . ' days'));
            }
            $weekEnd = date('Y-m-d', strtotime($week . ' +6 days'));
            $days    = ColdTracker::getRange($week, $weekEnd);
            $stats   = ColdTracker::aggregateStats($days);
            $suggestions = ColdTracker::generateSuggestions($stats, 'week');

            // Build day-by-day series (7 days)
            $series = [];
            for ($i = 0; $i < 7; $i++) {
                $d      = date('Y-m-d', strtotime($week . " +{$i} days"));
                $label  = date('D', strtotime($d)); // Mon, Tue...
                $series[] = [
                    'date'     => $d,
                    'label'    => $label,
                    'calls'    => isset($days[$d]) ? (int)$days[$d]['num_calls']    : 0,
                    'answers'  => isset($days[$d]) ? (int)$days[$d]['num_answers']  : 0,
                    'pitches'  => isset($days[$d]) ? (int)$days[$d]['num_pitches']  : 0,
                    'meetings' => isset($days[$d]) ? (int)$days[$d]['num_meetings'] : 0,
                    'website_sales' => isset($days[$d]) ? (int)($days[$d]['num_website_sales'] ?? 0) : 0,
                ];
            }

            wp_send_json_success([
                'type'        => 'weekly',
                'week_start'  => $week,
                'week_end'    => $weekEnd,
                'stats'       => $stats,
                'series'      => $series,
                'suggestions' => $suggestions,
            ]);

        } else {
            // Monthly
            $rows    = ColdTracker::getMonthData($year, $month);
            $stats   = ColdTracker::aggregateStats($rows);
            $weeks   = ColdTracker::getWeeklyBreakdown($year, $month);
            $suggestions = ColdTracker::generateSuggestions($stats, 'month');

            // Daily series for line chart
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            $series = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr  = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $series[] = [
                    'date'  => $dateStr,
                    'day'   => $d,
                    'calls' => isset($rows[$dateStr]) ? (int)$rows[$dateStr]['num_calls'] : 0,
                    'website_sales' => isset($rows[$dateStr]) ? (int)($rows[$dateStr]['num_website_sales'] ?? 0) : 0,
                ];
            }

            // Best day
            $bestDay  = null;
            $bestMeet = -1;
            foreach ($rows as $row) {
                if ((int)($row['num_website_sales'] ?? 0) > $bestMeet) {
                    $bestMeet = (int)($row['num_website_sales'] ?? 0);
                    $bestDay  = $row;
                }
            }

            wp_send_json_success([
                'type'        => 'monthly',
                'year'        => $year,
                'month'       => $month,
                'stats'       => $stats,
                'weeks'       => $weeks,
                'series'      => $series,
                'best_day'    => $bestDay,
                'suggestions' => $suggestions,
            ]);
        }
    }

    // ── Page Render ───────────────────────────────────────────────────────

    public static function renderPage(): void
    {
        ?>
        <div class="wrap cold-wrap">
            <div class="cold-header">
                <div class="cold-header-left">
                    <h1 class="cold-title">Website Sales Tracker</h1>
                    <p class="cold-subtitle">Track cold calls, website pitches, and website sales performance over time</p>
                </div>
                <div class="cold-header-right">
                    <div class="cold-streak-badge" id="cold-streak-badge" title="Consecutive days with calls logged">
                        <span class="cold-streak-icon">🔥</span>
                        <span id="cold-streak-num">0</span>
                        <span class="cold-streak-label">day streak</span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="cold-tabs">
                <button class="cold-tab-btn active" data-tab="calendar">📅 Calendar</button>
                <button class="cold-tab-btn" data-tab="weekly">📊 Weekly</button>
                <button class="cold-tab-btn" data-tab="monthly">📈 Monthly</button>
            </div>

            <!-- ── Calendar Tab ── -->
            <div id="tab-calendar" class="cold-tab-panel">
                <div class="cold-card">
                    <div class="cold-cal-nav">
                        <button class="cold-btn cold-btn-ghost" id="cold-prev-month">&#8592; Prev</button>
                        <h2 class="cold-cal-month-label" id="cold-cal-header">Loading...</h2>
                        <button class="cold-btn cold-btn-ghost" id="cold-next-month">Next &#8594;</button>
                    </div>
                    <div class="cold-cal-dow">
                        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div>
                        <div>Thu</div><div>Fri</div><div>Sat</div>
                    </div>
                    <div class="cold-cal-grid" id="cold-cal-grid">
                        <div class="cold-loading">Loading calendar...</div>
                    </div>
                </div>

                <!-- Month summary bar below calendar -->
                <div class="cold-card cold-month-bar" id="cold-month-bar" style="display:none;">
                    <div class="cold-month-stat">
                        <span class="cold-ms-val" id="mbar-calls">0</span>
                        <span class="cold-ms-label">Calls</span>
                    </div>
                    <div class="cold-month-stat">
                        <span class="cold-ms-val" id="mbar-answers">0</span>
                        <span class="cold-ms-label">Answers</span>
                    </div>
                    <div class="cold-month-stat">
                        <span class="cold-ms-val" id="mbar-pitches">0</span>
                        <span class="cold-ms-label">Website Pitches</span>
                    </div>
                    <div class="cold-month-stat">
                        <span class="cold-ms-val" id="mbar-meetings">0</span>
                        <span class="cold-ms-label">Meetings</span>
                    </div>
                    <div class="cold-month-stat">
                        <span class="cold-ms-val" id="mbar-sales">0</span>
                        <span class="cold-ms-label">Website Sales</span>
                    </div>
                    <div class="cold-month-stat">
                        <span class="cold-ms-val cold-rate" id="mbar-answer-rate">0%</span>
                        <span class="cold-ms-label">Answer Rate</span>
                    </div>
                    <div class="cold-month-stat">
                        <span class="cold-ms-val cold-rate" id="mbar-conv">0%</span>
                        <span class="cold-ms-label">Call→Sale</span>
                    </div>
                    <div class="cold-month-stat">
                        <span class="cold-ms-val cold-days" id="mbar-days">0</span>
                        <span class="cold-ms-label">Days Called</span>
                    </div>
                </div>
            </div>

            <!-- ── Weekly Tab ── -->
            <div id="tab-weekly" class="cold-tab-panel" style="display:none;">
                <div class="cold-card">
                    <div class="cold-cal-nav">
                        <button class="cold-btn cold-btn-ghost" id="cold-prev-week">&#8592; Prev Week</button>
                        <h2 class="cold-cal-month-label" id="cold-week-label">This Week</h2>
                        <button class="cold-btn cold-btn-ghost" id="cold-next-week">Next Week &#8594;</button>
                    </div>
                    <div id="cold-weekly-content">
                        <div class="cold-loading">Click the Weekly tab to load data...</div>
                    </div>
                </div>
            </div>

            <!-- ── Monthly Tab ── -->
            <div id="tab-monthly" class="cold-tab-panel" style="display:none;">
                <div class="cold-card">
                    <div class="cold-cal-nav">
                        <button class="cold-btn cold-btn-ghost" id="cold-prev-mmonth">&#8592; Prev Month</button>
                        <h2 class="cold-cal-month-label" id="cold-month-label">This Month</h2>
                        <button class="cold-btn cold-btn-ghost" id="cold-next-mmonth">Next Month &#8594;</button>
                    </div>
                    <div id="cold-monthly-content">
                        <div class="cold-loading">Click the Monthly tab to load data...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Day Modal ── -->
        <div id="cold-modal" class="cold-modal-overlay" style="display:none;">
            <div class="cold-modal">
                <div class="cold-modal-header">
                    <h3 id="modal-date-title">Monday, January 1</h3>
                    <button class="cold-modal-close" id="modal-close">&times;</button>
                </div>
                <div class="cold-modal-body">
                    <input type="hidden" id="modal-date">

                    <!-- KPI Inputs -->
                    <div class="cold-kpi-grid">
                        <div class="cold-kpi-field cold-kpi-calls">
                            <label>Calls Made</label>
                            <input type="number" id="modal-calls" min="0" max="9999" placeholder="0">
                        </div>
                        <div class="cold-kpi-field cold-kpi-answers">
                            <label>Answers</label>
                            <input type="number" id="modal-answers" min="0" max="9999" placeholder="0">
                        </div>
                        <div class="cold-kpi-field cold-kpi-pitches">
                            <label>Website Pitches</label>
                            <input type="number" id="modal-pitches" min="0" max="9999" placeholder="0">
                        </div>
                        <div class="cold-kpi-field cold-kpi-meetings">
                            <label>Meetings Booked</label>
                            <input type="number" id="modal-meetings" min="0" max="9999" placeholder="0">
                        </div>
                        <div class="cold-kpi-field cold-kpi-sales">
                            <label>Website Sales</label>
                            <input type="number" id="modal-sales" min="0" max="9999" placeholder="0">
                        </div>
                    </div>

                    <!-- Live Rates -->
                    <div class="cold-rate-row">
                        <div class="cold-rate-item">
                            <span class="cold-rate-val" id="modal-rate-answer">0%</span>
                            <span class="cold-rate-key">Answer Rate</span>
                        </div>
                        <div class="cold-rate-item">
                            <span class="cold-rate-val" id="modal-rate-pitch">0%</span>
                            <span class="cold-rate-key">Pitch Rate</span>
                        </div>
                        <div class="cold-rate-item">
                            <span class="cold-rate-val" id="modal-rate-meeting">0%</span>
                            <span class="cold-rate-key">Meeting Rate</span>
                        </div>
                        <div class="cold-rate-item cold-rate-highlight">
                            <span class="cold-rate-val" id="modal-rate-conv">0%</span>
                            <span class="cold-rate-key">Call→Sale</span>
                        </div>
                    </div>

                    <!-- Bar Chart -->
                    <div class="cold-chart-wrap">
                        <canvas id="modal-chart" height="120"></canvas>
                    </div>

                    <!-- Notes -->
                    <div class="cold-notes-wrap">
                        <label for="modal-notes">Notes / Observations</label>
                        <textarea id="modal-notes" rows="3" placeholder="What worked today? What to improve? Any notable calls?"></textarea>
                    </div>
                </div>
                <div class="cold-modal-footer">
                    <button class="cold-btn cold-btn-ghost" id="modal-cancel">Cancel</button>
                    <button class="cold-btn cold-btn-primary" id="modal-save-btn">Save Day</button>
                </div>
            </div>
        </div>

        <!-- Toast -->
        <div id="cold-toast" class="cold-toast" style="display:none;"></div>
        <?php
    }

    // ── CSS ───────────────────────────────────────────────────────────────

    public static function outputCSS(): void
    {
        echo '<style>' . self::getCSS() . '</style>';
    }

    private static function getCSS(): string
    {
        return <<<'CSS'
/* ── Cold Tracker Styles ─────────────────────────────────────────── */
.cold-wrap { max-width: 1200px; }

.cold-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 20px;
}
.cold-title { font-size: 1.8rem; font-weight: 700; color: #e2e8f0; margin: 0 0 4px; }
.cold-subtitle { color: #94a3b8; margin: 0; font-size: 0.9rem; }

.cold-streak-badge {
    display: flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #f97316, #ef4444);
    color: #fff; padding: 8px 16px; border-radius: 24px;
    font-weight: 600; font-size: 0.9rem; box-shadow: 0 4px 12px rgba(249,115,22,.3);
}
.cold-streak-icon { font-size: 1.1rem; }
.cold-streak-num { font-size: 1.4rem; line-height: 1; }
.cold-streak-label { opacity: .85; font-size: 0.8rem; }

/* Tabs */
.cold-tabs {
    display: flex; gap: 4px; margin-bottom: 20px;
    background: #1e293b; padding: 4px; border-radius: 10px;
    width: fit-content;
}
.cold-tab-btn {
    background: transparent; border: none; color: #94a3b8;
    padding: 8px 20px; border-radius: 8px; cursor: pointer;
    font-size: 0.9rem; font-weight: 500; transition: all .2s;
}
.cold-tab-btn:hover { background: #334155; color: #e2e8f0; }
.cold-tab-btn.active { background: #6366f1; color: #fff; box-shadow: 0 2px 8px rgba(99,102,241,.4); }

/* Card */
.cold-card {
    background: #1e293b; border: 1px solid #334155;
    border-radius: 12px; padding: 24px; margin-bottom: 16px;
}
.cold-loading { color: #64748b; text-align: center; padding: 40px; font-size: 0.9rem; }

/* Calendar Nav */
.cold-cal-nav {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px;
}
.cold-cal-month-label { margin: 0; font-size: 1.2rem; font-weight: 700; color: #e2e8f0; }

/* Buttons */
.cold-btn {
    display: inline-flex; align-items: center; gap: 6px;
    border: none; cursor: pointer; border-radius: 8px;
    font-size: 0.85rem; font-weight: 500; padding: 8px 16px; transition: all .2s;
}
.cold-btn-primary { background: #6366f1; color: #fff; }
.cold-btn-primary:hover { background: #4f46e5; }
.cold-btn-ghost { background: #334155; color: #94a3b8; }
.cold-btn-ghost:hover { background: #475569; color: #e2e8f0; }
.cold-btn:disabled { opacity: .6; cursor: not-allowed; }

/* Day of Week Header */
.cold-cal-dow {
    display: grid; grid-template-columns: repeat(7, 1fr);
    gap: 4px; margin-bottom: 4px;
}
.cold-cal-dow > div {
    text-align: center; font-size: 0.72rem; font-weight: 600;
    color: #64748b; text-transform: uppercase; padding: 4px 0;
}

/* Calendar Grid */
.cold-cal-grid {
    display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;
}
.cold-day {
    min-height: 82px; background: #0f172a; border: 1px solid #1e293b;
    border-radius: 8px; padding: 6px; cursor: pointer;
    transition: all .15s; position: relative; overflow: hidden;
}
.cold-day:hover { border-color: #6366f1; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.3); }
.cold-day-empty { background: transparent; border-color: transparent; cursor: default; }
.cold-day-empty:hover { transform: none; box-shadow: none; }
.cold-day-weekend .cold-day-num { color: #64748b; }
.cold-day-today { border-color: #6366f1 !important; box-shadow: 0 0 0 1px #6366f1; }
.cold-day-today .cold-day-num { color: #6366f1; font-weight: 800; }
.cold-day-active { background: #0f1f3d; border-color: #1d4ed8; }
.cold-day-great { background: #052e16; border-color: #15803d; }
.cold-day-great .cold-day-num { color: #4ade80; }

.cold-day-num {
    font-size: 0.8rem; font-weight: 600; color: #94a3b8;
    margin-bottom: 4px; display: block;
}
.cold-day-stats {
    display: flex; flex-wrap: wrap; gap: 2px; margin-bottom: 3px;
}
.cold-day-stats span {
    font-size: 0.62rem; padding: 1px 4px; border-radius: 3px; font-weight: 600;
}
.cold-stat-calls    { background: rgba(59,130,246,.2);  color: #60a5fa; }
.cold-stat-answers  { background: rgba(16,185,129,.2);  color: #34d399; }
.cold-stat-pitches  { background: rgba(245,158,11,.2);  color: #fbbf24; }
.cold-stat-meetings { background: rgba(139,92,246,.2);  color: #a78bfa; }
.cold-stat-sales    { background: rgba(20,184,166,.2);  color: #2dd4bf; }
.cold-day-rate { font-size: 0.62rem; color: #64748b; }
.cold-day-note-dot {
    position: absolute; top: 5px; right: 5px;
    width: 5px; height: 5px; border-radius: 50%;
    background: #f59e0b;
}

/* Month Summary Bar */
.cold-month-bar {
    display: flex; flex-wrap: wrap; gap: 12px;
    padding: 16px 24px; align-items: center;
}
.cold-month-stat { text-align: center; flex: 1; min-width: 80px; }
.cold-ms-val {
    display: block; font-size: 1.5rem; font-weight: 700;
    color: #e2e8f0; line-height: 1.1;
}
.cold-ms-val.cold-rate { color: #6366f1; }
.cold-ms-val.cold-days { color: #10b981; }
.cold-ms-label { font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }

/* Weekly / Monthly content */
.cold-analytics-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px; margin-bottom: 24px;
}
.cold-stat-card {
    background: #0f172a; border: 1px solid #334155;
    border-radius: 10px; padding: 16px; text-align: center;
}
.cold-sc-val {
    font-size: 1.8rem; font-weight: 700; color: #e2e8f0;
    line-height: 1; margin-bottom: 4px;
}
.cold-sc-val.rate  { color: #6366f1; font-size: 1.5rem; }
.cold-sc-val.green { color: #10b981; }
.cold-sc-val.amber { color: #f59e0b; }
.cold-sc-val.purple{ color: #a78bfa; }
.cold-sc-key { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }

.cold-chart-section { margin-bottom: 24px; }
.cold-chart-section h4 { color: #94a3b8; font-size: 0.85rem; margin: 0 0 12px; text-transform: uppercase; letter-spacing: .06em; }
.cold-full-chart { position: relative; height: 240px; }

/* Suggestions */
.cold-suggestions h4 {
    color: #94a3b8; font-size: 0.85rem; margin: 0 0 12px;
    text-transform: uppercase; letter-spacing: .06em;
}
.cold-suggestion {
    display: flex; gap: 10px; align-items: flex-start;
    padding: 12px 14px; border-radius: 8px; margin-bottom: 8px;
    font-size: 0.85rem; line-height: 1.5;
}
.cold-suggestion.success { background: rgba(16,185,129,.1);  border-left: 3px solid #10b981; color: #a7f3d0; }
.cold-suggestion.warning { background: rgba(245,158,11,.1);  border-left: 3px solid #f59e0b; color: #fde68a; }
.cold-suggestion.danger  { background: rgba(239,68,68,.1);   border-left: 3px solid #ef4444; color: #fca5a5; }
.cold-suggestion.info    { background: rgba(99,102,241,.1);  border-left: 3px solid #6366f1; color: #c7d2fe; }
.cold-sugg-icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }

/* Weekly table */
.cold-week-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.cold-week-table th {
    text-align: left; padding: 8px 12px; color: #64748b;
    font-size: 0.72rem; text-transform: uppercase; letter-spacing: .05em;
    border-bottom: 1px solid #334155;
}
.cold-week-table td { padding: 10px 12px; border-bottom: 1px solid #1e293b; color: #94a3b8; }
.cold-week-table td:first-child { color: #e2e8f0; font-weight: 600; }
.cold-week-table tr:last-child td { border-bottom: none; }
.cold-week-table .best-week td { background: rgba(16,185,129,.07); }

/* Modal */
.cold-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.7);
    z-index: 99999; display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
}
.cold-modal {
    background: #1e293b; border: 1px solid #334155;
    border-radius: 16px; width: 520px; max-width: 95vw;
    max-height: 90vh; overflow-y: auto;
    box-shadow: 0 24px 60px rgba(0,0,0,.6);
}
.cold-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px 16px; border-bottom: 1px solid #334155;
}
.cold-modal-header h3 { margin: 0; font-size: 1.05rem; color: #e2e8f0; font-weight: 700; }
.cold-modal-close {
    background: none; border: none; color: #64748b;
    font-size: 1.4rem; cursor: pointer; padding: 0 4px; line-height: 1;
    transition: color .2s;
}
.cold-modal-close:hover { color: #e2e8f0; }
.cold-modal-body { padding: 20px 24px; }
.cold-modal-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 16px 24px; border-top: 1px solid #334155;
}

/* KPI inputs */
.cold-kpi-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;
}
.cold-kpi-field label {
    display: block; font-size: 0.75rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .05em;
    margin-bottom: 6px;
}
.cold-kpi-calls   label { color: #60a5fa; }
.cold-kpi-answers label { color: #34d399; }
.cold-kpi-pitches label { color: #fbbf24; }
.cold-kpi-meetings label { color: #a78bfa; }
.cold-kpi-sales label { color: #2dd4bf; }
.cold-kpi-field input {
    width: 100%; background: #0f172a; border: 1px solid #334155;
    border-radius: 8px; padding: 10px 12px; color: #e2e8f0;
    font-size: 1.1rem; font-weight: 600; box-sizing: border-box;
    transition: border-color .2s;
}
.cold-kpi-field input:focus { outline: none; border-color: #6366f1; }
.cold-kpi-calls   input:focus { border-color: #3b82f6; }
.cold-kpi-answers input:focus { border-color: #10b981; }
.cold-kpi-pitches input:focus { border-color: #f59e0b; }
.cold-kpi-meetings input:focus { border-color: #8b5cf6; }
.cold-kpi-sales input:focus { border-color: #14b8a6; }

/* Live rate pills */
.cold-rate-row {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 8px; margin-bottom: 16px;
}
.cold-rate-item {
    background: #0f172a; border: 1px solid #1e293b;
    border-radius: 8px; padding: 8px; text-align: center;
}
.cold-rate-highlight { border-color: #6366f1; background: rgba(99,102,241,.08); }
.cold-rate-val { display: block; font-size: 1.1rem; font-weight: 700; color: #e2e8f0; }
.cold-rate-key { font-size: 0.65rem; color: #64748b; }

/* Chart wrap in modal */
.cold-chart-wrap { margin-bottom: 16px; }

/* Notes */
.cold-notes-wrap label {
    display: block; font-size: 0.78rem; font-weight: 600;
    color: #64748b; text-transform: uppercase; letter-spacing: .05em;
    margin-bottom: 6px;
}
.cold-notes-wrap textarea {
    width: 100%; background: #0f172a; border: 1px solid #334155;
    border-radius: 8px; padding: 10px 12px; color: #e2e8f0;
    font-size: 0.88rem; resize: vertical; box-sizing: border-box;
    transition: border-color .2s; font-family: inherit;
}
.cold-notes-wrap textarea:focus { outline: none; border-color: #6366f1; }

/* Toast */
.cold-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 999999;
    padding: 12px 20px; border-radius: 10px; font-size: 0.88rem;
    font-weight: 600; box-shadow: 0 8px 24px rgba(0,0,0,.4);
    animation: cold-slide-up .3s ease;
}
.cold-toast-success { background: #10b981; color: #fff; }
.cold-toast-error   { background: #ef4444; color: #fff; }
@keyframes cold-slide-up {
    from { transform: translateY(16px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

/* Section divider */
.cold-section-title {
    font-size: 0.78rem; font-weight: 600; color: #64748b;
    text-transform: uppercase; letter-spacing: .08em;
    margin: 0 0 12px; padding-top: 20px;
    border-top: 1px solid #334155;
}
.cold-section-title:first-child { padding-top: 0; border-top: none; }

/* Responsive */
@media (max-width: 640px) {
    .cold-kpi-grid { grid-template-columns: 1fr 1fr; }
    .cold-rate-row { grid-template-columns: repeat(2, 1fr); }
    .cold-day { min-height: 60px; }
    .cold-day-stats { display: none; }
}
CSS;
    }

    // ── JavaScript ────────────────────────────────────────────────────────

    private static function getInlineJS(): string
    {
        return <<<'JS'
(function() {
    'use strict';

    const COLD = window.WNQ_COLD;
    if (!COLD) return;

    /* ── State ─────────────────────────────────────────────────────── */
    let calYear  = Number.parseInt(COLD.initialYear, 10) || new Date().getFullYear();
    let calMonth = Number.parseInt(COLD.initialMonth, 10) || (new Date().getMonth() + 1);
    let monthData = {};   // date -> row
    let charts = {};      // chart instances

    // Weekly state
    function currentWeekMonday() {
        const today = new Date(COLD.today + 'T12:00:00');
        const dow   = today.getDay(); // 0=Sun
        const diff  = dow === 0 ? -6 : 1 - dow;
        const mon   = new Date(today);
        mon.setDate(today.getDate() + diff);
        return dateStr(mon);
    }
    let weekStart = currentWeekMonday();

    // Monthly analytics state
    let analyticsYear  = Number.parseInt(COLD.initialYear, 10) || new Date().getFullYear();
    let analyticsMonth = Number.parseInt(COLD.initialMonth, 10) || (new Date().getMonth() + 1);

    /* ── Helpers ────────────────────────────────────────────────────── */
    function dateStr(d) {
        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }
    function addDays(ymd, n) {
        const d = new Date(ymd + 'T12:00:00');
        d.setDate(d.getDate() + n);
        return dateStr(d);
    }
    function addMonths(y, m, delta) {
        const year = Number.parseInt(y, 10) || new Date().getFullYear();
        const month = Number.parseInt(m, 10) || 1;
        const date = new Date(year, month - 1 + delta, 1);
        return { year: date.getFullYear(), month: date.getMonth() + 1 };
    }
    const MONTH_NAMES = ['January','February','March','April','May','June',
                         'July','August','September','October','November','December'];
    const DAY_NAMES   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const DAY_SHORT   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    function rate(a, b) { return b > 0 ? (a / b * 100).toFixed(1) : '0.0'; }

    function destroyChart(key) {
        if (charts[key]) { try { charts[key].destroy(); } catch(e){} charts[key] = null; }
    }

    function ajax(action, extra, cb) {
        const body = new URLSearchParams(Object.assign({ action, nonce: COLD.nonce }, extra));
        fetch(COLD.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        })
        .then(r => r.json())
        .then(res => cb(res))
        .catch(() => cb({ success: false, data: { message: 'Network error' } }));
    }

    function showToast(msg, type) {
        const t = document.getElementById('cold-toast');
        if (!t) return;
        t.textContent = msg;
        t.className = 'cold-toast cold-toast-' + type;
        t.style.display = 'block';
        clearTimeout(t._timer);
        t._timer = setTimeout(() => { t.style.display = 'none'; }, 3500);
    }

    function updateStreak(n) {
        const el = document.getElementById('cold-streak-num');
        if (el) el.textContent = n;
        const badge = document.getElementById('cold-streak-badge');
        if (badge) badge.style.display = n > 0 ? 'flex' : 'none';
    }

    /* ── Calendar ───────────────────────────────────────────────────── */
    function buildMonthData(rows) {
        monthData = {};
        (rows || []).forEach(r => { monthData[r.call_date] = r; });
    }

    function renderCalendar(year, month) {
        const header = document.getElementById('cold-cal-header');
        const grid   = document.getElementById('cold-cal-grid');
        if (!header || !grid) return;

        header.textContent = MONTH_NAMES[month - 1] + ' ' + year;

        const firstDow    = new Date(year, month - 1, 1).getDay(); // 0=Sun
        const daysInMonth = new Date(year, month, 0).getDate();
        const today       = COLD.today;

        let html = '';

        for (let i = 0; i < firstDow; i++) {
            html += '<div class="cold-day cold-day-empty"></div>';
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const ds  = year + '-' + String(month).padStart(2,'0') + '-' + String(d).padStart(2,'0');
            const row = monthData[ds];
            const isToday   = ds === today;
            const isWeekend = [0, 6].includes(new Date(year, month - 1, d).getDay());
            const hasNote   = row && row.notes && row.notes.trim().length > 0;

            let cls = 'cold-day';
            if (isToday)   cls += ' cold-day-today';
            if (isWeekend) cls += ' cold-day-weekend';
            if (row && (row.num_calls > 0)) {
                cls += parseInt(row.num_calls) >= 200 ? ' cold-day-great' : ' cold-day-active';
            }

            let inner = `<span class="cold-day-num">${d}</span>`;
            if (hasNote) inner += `<div class="cold-day-note-dot" title="Has notes"></div>`;

            if (row && parseInt(row.num_calls) > 0) {
                inner += `<div class="cold-day-stats">
                    <span class="cold-stat-calls">${row.num_calls}c</span>
                    <span class="cold-stat-answers">${row.num_answers}a</span>
                    <span class="cold-stat-pitches">${row.num_pitches}p</span>
                    <span class="cold-stat-meetings">${row.num_meetings}m</span>
                    <span class="cold-stat-sales">${row.num_website_sales || 0}s</span>
                </div>`;
                const conv = row.num_calls > 0
                    ? Math.round((parseInt(row.num_website_sales) || 0) / parseInt(row.num_calls) * 100)
                    : 0;
                inner += `<div class="cold-day-rate">${conv}% sale</div>`;
            }

            html += `<div class="${cls}" data-date="${ds}">${inner}</div>`;
        }

        grid.innerHTML = html;

        // Click handlers
        grid.querySelectorAll('.cold-day:not(.cold-day-empty)').forEach(cell => {
            cell.addEventListener('click', () => openDayModal(cell.dataset.date));
        });

        // Update month summary bar
        updateMonthBar();
    }

    function updateMonthBar() {
        const rows = Object.values(monthData);
        if (rows.length === 0) {
            document.getElementById('cold-month-bar').style.display = 'none';
            return;
        }

        let calls = 0, answers = 0, pitches = 0, meetings = 0, sales = 0, days = 0;
        rows.forEach(r => {
            calls    += parseInt(r.num_calls)    || 0;
            answers  += parseInt(r.num_answers)  || 0;
            pitches  += parseInt(r.num_pitches)  || 0;
            meetings += parseInt(r.num_meetings) || 0;
            sales    += parseInt(r.num_website_sales) || 0;
            if (parseInt(r.num_calls) > 0) days++;
        });

        document.getElementById('mbar-calls').textContent    = calls.toLocaleString();
        document.getElementById('mbar-answers').textContent  = answers.toLocaleString();
        document.getElementById('mbar-pitches').textContent  = pitches.toLocaleString();
        document.getElementById('mbar-meetings').textContent = meetings.toLocaleString();
        document.getElementById('mbar-sales').textContent    = sales.toLocaleString();
        document.getElementById('mbar-answer-rate').textContent = rate(answers, calls) + '%';
        document.getElementById('mbar-conv').textContent    = rate(sales, calls) + '%';
        document.getElementById('mbar-days').textContent    = days;
        document.getElementById('cold-month-bar').style.display = 'flex';
    }

    /* ── Day Modal ──────────────────────────────────────────────────── */
    function openDayModal(ds) {
        const row = monthData[ds] || { call_date: ds, num_calls: 0, num_answers: 0, num_pitches: 0, num_meetings: 0, num_website_sales: 0, notes: '' };
        const d   = new Date(ds + 'T12:00:00');

        document.getElementById('modal-date-title').textContent =
            DAY_NAMES[d.getDay()] + ', ' + MONTH_NAMES[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        document.getElementById('modal-date').value    = ds;
        document.getElementById('modal-calls').value   = row.num_calls    || 0;
        document.getElementById('modal-answers').value = row.num_answers  || 0;
        document.getElementById('modal-pitches').value = row.num_pitches  || 0;
        document.getElementById('modal-meetings').value= row.num_meetings || 0;
        document.getElementById('modal-sales').value   = row.num_website_sales || 0;
        document.getElementById('modal-notes').value   = row.notes        || '';

        updateModalRates();
        renderDayChart(row);
        document.getElementById('cold-modal').style.display = 'flex';
    }

    function updateModalRates() {
        const calls    = parseInt(document.getElementById('modal-calls').value)    || 0;
        const answers  = parseInt(document.getElementById('modal-answers').value)  || 0;
        const pitches  = parseInt(document.getElementById('modal-pitches').value)  || 0;
        const meetings = parseInt(document.getElementById('modal-meetings').value) || 0;
        const sales    = parseInt(document.getElementById('modal-sales').value) || 0;

        document.getElementById('modal-rate-answer').textContent  = rate(answers,  calls)   + '%';
        document.getElementById('modal-rate-pitch').textContent   = rate(pitches,  answers) + '%';
        document.getElementById('modal-rate-meeting').textContent = rate(meetings, pitches) + '%';
        document.getElementById('modal-rate-conv').textContent    = rate(sales, calls)   + '%';

        // Update live chart
        if (charts.day) {
            charts.day.data.datasets[0].data = [calls, answers, pitches, meetings, sales];
            charts.day.update('none');
        }
    }

    function renderDayChart(row) {
        destroyChart('day');
        const ctx = document.getElementById('modal-chart');
        if (!ctx) return;
        charts.day = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Calls Made', 'Answers', 'Website Pitches', 'Meetings', 'Website Sales'],
                datasets: [{
                    data: [
                        parseInt(row.num_calls)    || 0,
                        parseInt(row.num_answers)  || 0,
                        parseInt(row.num_pitches)  || 0,
                        parseInt(row.num_meetings) || 0,
                        parseInt(row.num_website_sales) || 0,
                    ],
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#14b8a6'],
                    borderRadius: 6,
                    borderSkipped: false,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.parsed.y,
                        },
                    },
                },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } },
                    y: { beginAtZero: true, ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: '#1e293b' } },
                },
            },
        });
    }

    function saveDay() {
        const btn = document.getElementById('modal-save-btn');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const ds = document.getElementById('modal-date').value;
        ajax('wnq_cold_save_day', {
            call_date:   ds,
            num_calls:   document.getElementById('modal-calls').value,
            num_answers: document.getElementById('modal-answers').value,
            num_pitches: document.getElementById('modal-pitches').value,
            num_meetings:document.getElementById('modal-meetings').value,
            num_website_sales: document.getElementById('modal-sales').value,
            notes:       document.getElementById('modal-notes').value,
        }, res => {
            btn.disabled = false;
            btn.textContent = 'Save Day';
            if (res.success) {
                // Update local data
                monthData[ds] = {
                    call_date:    ds,
                    num_calls:    parseInt(document.getElementById('modal-calls').value)   || 0,
                    num_answers:  parseInt(document.getElementById('modal-answers').value) || 0,
                    num_pitches:  parseInt(document.getElementById('modal-pitches').value) || 0,
                    num_meetings: parseInt(document.getElementById('modal-meetings').value)|| 0,
                    num_website_sales: parseInt(document.getElementById('modal-sales').value) || 0,
                    notes:        document.getElementById('modal-notes').value,
                };
                renderCalendar(calYear, calMonth);
                closeModal();
                showToast('Day saved!', 'success');
            } else {
                showToast(res.data?.message || 'Save failed', 'error');
            }
        });
    }

    function closeModal() {
        document.getElementById('cold-modal').style.display = 'none';
        destroyChart('day');
    }

    /* ── Weekly Analytics ───────────────────────────────────────────── */
    function loadWeeklyAnalytics() {
        document.getElementById('cold-weekly-content').innerHTML = '<div class="cold-loading">Loading...</div>';

        const d   = new Date(weekStart + 'T12:00:00');
        const end = addDays(weekStart, 6);
        document.getElementById('cold-week-label').textContent =
            MONTH_NAMES[d.getMonth()].slice(0,3) + ' ' + d.getDate() +
            ' – ' +
            MONTH_NAMES[new Date(end + 'T12:00:00').getMonth()].slice(0,3) +
            ' ' + new Date(end + 'T12:00:00').getDate() + ', ' + new Date(end + 'T12:00:00').getFullYear();

        ajax('wnq_cold_get_analytics', { type: 'weekly', week_start: weekStart }, res => {
            if (!res.success) {
                document.getElementById('cold-weekly-content').innerHTML =
                    '<div class="cold-loading">Failed to load data.</div>';
                return;
            }
            renderWeeklyContent(res.data);
        });
    }

    function renderWeeklyContent(data) {
        const s = data.stats;
        destroyChart('weekly');

        const html = `
            <div class="cold-analytics-grid">
                <div class="cold-stat-card">
                    <div class="cold-sc-val">${(s.calls || 0).toLocaleString()}</div>
                    <div class="cold-sc-key">Total Calls</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val green">${s.answers || 0}</div>
                    <div class="cold-sc-key">Answers</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val amber">${s.pitches || 0}</div>
                    <div class="cold-sc-key">Website Pitches</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val purple">${s.meetings || 0}</div>
                    <div class="cold-sc-key">Meetings</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val green">${s.website_sales || 0}</div>
                    <div class="cold-sc-key">Website Sales</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.answer_rate || 0}%</div>
                    <div class="cold-sc-key">Answer Rate</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.pitch_rate || 0}%</div>
                    <div class="cold-sc-key">Pitch Rate</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.meeting_rate || 0}%</div>
                    <div class="cold-sc-key">Meeting Rate</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.call_to_sale || 0}%</div>
                    <div class="cold-sc-key">Call → Sale</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val green">${s.days_called || 0} / 7</div>
                    <div class="cold-sc-key">Days Active</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val">${s.avg_calls || 0}</div>
                    <div class="cold-sc-key">Avg Calls/Day</div>
                </div>
            </div>

            <div class="cold-chart-section">
                <h4>Daily Breakdown</h4>
                <div class="cold-full-chart">
                    <canvas id="weekly-chart"></canvas>
                </div>
            </div>

            <div class="cold-suggestions">
                <h4>Coaching Insights</h4>
                ${renderSuggestions(data.suggestions)}
            </div>
        `;

        document.getElementById('cold-weekly-content').innerHTML = html;

        // Render grouped bar chart
        const labels  = data.series.map(d => d.label);
        const ctx     = document.getElementById('weekly-chart');
        if (ctx) {
            charts.weekly = new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Calls',    data: data.series.map(d => d.calls),    backgroundColor: '#3b82f6', borderRadius: 4 },
                        { label: 'Answers',  data: data.series.map(d => d.answers),  backgroundColor: '#10b981', borderRadius: 4 },
                        { label: 'Website Pitches', data: data.series.map(d => d.pitches), backgroundColor: '#f59e0b', borderRadius: 4 },
                        { label: 'Meetings', data: data.series.map(d => d.meetings), backgroundColor: '#8b5cf6', borderRadius: 4 },
                        { label: 'Website Sales', data: data.series.map(d => d.website_sales), backgroundColor: '#14b8a6', borderRadius: 4 },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#94a3b8', font: { size: 12 } } },
                    },
                    scales: {
                        x: { ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } },
                        y: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } },
                    },
                },
            });
        }
    }

    /* ── Monthly Analytics ──────────────────────────────────────────── */
    function loadMonthlyAnalytics() {
        document.getElementById('cold-monthly-content').innerHTML = '<div class="cold-loading">Loading...</div>';
        document.getElementById('cold-month-label').textContent =
            MONTH_NAMES[analyticsMonth - 1] + ' ' + analyticsYear;

        ajax('wnq_cold_get_analytics', { type: 'monthly', year: analyticsYear, month: analyticsMonth }, res => {
            if (!res.success) {
                document.getElementById('cold-monthly-content').innerHTML =
                    '<div class="cold-loading">Failed to load data.</div>';
                return;
            }
            renderMonthlyContent(res.data);
        });
    }

    function renderMonthlyContent(data) {
        const s = data.stats;
        destroyChart('monthly');
        destroyChart('monthlyPie');

        const bestDay = data.best_day;
        const bestDayStr = bestDay
            ? new Date(bestDay.call_date + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
            : '—';

        // Weekly breakdown table rows
        let weekRows = '';
        let bestWeekIdx = -1, bestWeekSales = -1;
        data.weeks.forEach((w, i) => {
            if (w.stats.website_sales > bestWeekSales) { bestWeekSales = w.stats.website_sales; bestWeekIdx = i; }
        });
        data.weeks.forEach((w, i) => {
            const isBest = i === bestWeekIdx && w.stats.calls > 0;
            const startLabel = new Date(w.start + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            const endLabel   = new Date(w.end   + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            weekRows += `<tr${isBest ? ' class="best-week"' : ''}>
                <td>Week ${w.week}${isBest ? ' ⭐' : ''}</td>
                <td>${startLabel} – ${endLabel}</td>
                <td>${w.stats.calls || 0}</td>
                <td>${w.stats.answers || 0}</td>
                <td>${w.stats.pitches || 0}</td>
                <td>${w.stats.meetings || 0}</td>
                <td>${w.stats.website_sales || 0}</td>
                <td>${w.stats.answer_rate || 0}%</td>
                <td>${w.stats.call_to_sale || 0}%</td>
            </tr>`;
        });

        const html = `
            <div class="cold-analytics-grid">
                <div class="cold-stat-card">
                    <div class="cold-sc-val">${(s.calls || 0).toLocaleString()}</div>
                    <div class="cold-sc-key">Total Calls</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val green">${(s.answers || 0).toLocaleString()}</div>
                    <div class="cold-sc-key">Total Answers</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val amber">${(s.pitches || 0).toLocaleString()}</div>
                    <div class="cold-sc-key">Website Pitches</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val purple">${(s.meetings || 0).toLocaleString()}</div>
                    <div class="cold-sc-key">Meetings</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val green">${(s.website_sales || 0).toLocaleString()}</div>
                    <div class="cold-sc-key">Website Sales</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.answer_rate || 0}%</div>
                    <div class="cold-sc-key">Answer Rate</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.pitch_rate || 0}%</div>
                    <div class="cold-sc-key">Pitch Rate</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.meeting_rate || 0}%</div>
                    <div class="cold-sc-key">Meeting Rate</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val rate">${s.call_to_sale || 0}%</div>
                    <div class="cold-sc-key">Call → Sale</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val green">${s.days_called || 0}</div>
                    <div class="cold-sc-key">Days Called</div>
                </div>
                <div class="cold-stat-card">
                    <div class="cold-sc-val">${bestDayStr}</div>
                    <div class="cold-sc-key">Best Day</div>
                </div>
            </div>

            <div class="cold-chart-section">
                <h4>Daily Call Volume</h4>
                <div class="cold-full-chart">
                    <canvas id="monthly-chart"></canvas>
                </div>
            </div>

            <div class="cold-chart-section">
                <h4>Weekly Breakdown</h4>
                <table class="cold-week-table">
                    <thead>
                        <tr>
                            <th>Week</th><th>Dates</th>
                            <th>Calls</th><th>Answers</th><th>Website Pitches</th><th>Meetings</th><th>Website Sales</th>
                            <th>Ans%</th><th>Conv%</th>
                        </tr>
                    </thead>
                    <tbody>${weekRows}</tbody>
                </table>
            </div>

            <div class="cold-suggestions">
                <h4>Monthly Coaching Insights</h4>
                ${renderSuggestions(data.suggestions)}
            </div>
        `;

        document.getElementById('cold-monthly-content').innerHTML = html;

        // Line chart — daily calls
        const ctx = document.getElementById('monthly-chart');
        if (ctx) {
            const labels = data.series.map(d => d.day);
            const callData = data.series.map(d => d.calls);
            const target200 = new Array(data.series.length).fill(200);

            charts.monthly = new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Calls Made',
                            data: callData,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59,130,246,.15)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3,
                            pointBackgroundColor: '#3b82f6',
                        },
                        {
                            label: '200-Call Target',
                            data: target200,
                            borderColor: 'rgba(100,116,139,.4)',
                            borderDash: [6, 4],
                            pointRadius: 0,
                            fill: false,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#94a3b8', font: { size: 12 } } },
                    },
                    scales: {
                        x: {
                            ticks: { color: '#94a3b8', maxTicksLimit: 15 },
                            grid: { color: '#1e293b' },
                            title: { display: true, text: 'Day of Month', color: '#64748b', font: { size: 11 } },
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#94a3b8' },
                            grid: { color: '#1e293b' },
                        },
                    },
                },
            });
        }
    }

    /* ── Suggestions Renderer ───────────────────────────────────────── */
    function renderSuggestions(suggestions) {
        if (!suggestions || suggestions.length === 0) return '<p style="color:#64748b;font-size:.85rem;">No suggestions yet — keep logging your daily KPIs!</p>';
        const icons = { success: '✅', warning: '⚠️', danger: '🚨', info: 'ℹ️' };
        return suggestions.map(s =>
            `<div class="cold-suggestion ${s.type}">
                <span class="cold-sugg-icon">${icons[s.type] || 'ℹ️'}</span>
                <span>${s.msg}</span>
            </div>`
        ).join('');
    }

    /* ── Tab Switching ──────────────────────────────────────────────── */
    function bindTabs() {
        document.querySelectorAll('.cold-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.cold-tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.cold-tab-panel').forEach(p => p.style.display = 'none');
                btn.classList.add('active');
                document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';

                if (btn.dataset.tab === 'weekly') loadWeeklyAnalytics();
                if (btn.dataset.tab === 'monthly') loadMonthlyAnalytics();
            });
        });
    }

    /* ── Calendar Navigation ────────────────────────────────────────── */
    function bindCalendarNav() {
        document.getElementById('cold-prev-month')?.addEventListener('click', () => {
            const r = addMonths(calYear, calMonth, -1);
            calYear = r.year; calMonth = r.month;
            loadCalendarMonth();
        });
        document.getElementById('cold-next-month')?.addEventListener('click', () => {
            const r = addMonths(calYear, calMonth, 1);
            calYear = r.year; calMonth = r.month;
            loadCalendarMonth();
        });
    }

    function loadCalendarMonth() {
        ajax('wnq_cold_get_month', { year: calYear, month: calMonth }, res => {
            if (res.success) {
                buildMonthData(res.data.rows);
                renderCalendar(calYear, calMonth);
                updateStreak(res.data.streak);
            }
        });
    }

    /* ── Week Navigation ────────────────────────────────────────────── */
    function bindWeekNav() {
        document.getElementById('cold-prev-week')?.addEventListener('click', () => {
            weekStart = addDays(weekStart, -7);
            loadWeeklyAnalytics();
        });
        document.getElementById('cold-next-week')?.addEventListener('click', () => {
            weekStart = addDays(weekStart, 7);
            loadWeeklyAnalytics();
        });
    }

    /* ── Monthly Nav ────────────────────────────────────────────────── */
    function bindMonthNav() {
        document.getElementById('cold-prev-mmonth')?.addEventListener('click', () => {
            const r = addMonths(analyticsYear, analyticsMonth, -1);
            analyticsYear = r.year; analyticsMonth = r.month;
            loadMonthlyAnalytics();
        });
        document.getElementById('cold-next-mmonth')?.addEventListener('click', () => {
            const r = addMonths(analyticsYear, analyticsMonth, 1);
            analyticsYear = r.year; analyticsMonth = r.month;
            loadMonthlyAnalytics();
        });
    }

    /* ── Modal Bindings ─────────────────────────────────────────────── */
    function bindModal() {
        document.getElementById('modal-close')?.addEventListener('click', closeModal);
        document.getElementById('modal-cancel')?.addEventListener('click', closeModal);
        document.getElementById('modal-save-btn')?.addEventListener('click', saveDay);

        // Close on overlay click
        document.getElementById('cold-modal')?.addEventListener('click', e => {
            if (e.target === document.getElementById('cold-modal')) closeModal();
        });

        // Close on Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && document.getElementById('cold-modal').style.display !== 'none') {
                closeModal();
            }
        });

        // Live rate updates
        ['modal-calls','modal-answers','modal-pitches','modal-meetings','modal-sales'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', updateModalRates);
        });
    }

    /* ── Init ───────────────────────────────────────────────────────── */
    function init() {
        buildMonthData(COLD.monthData);
        renderCalendar(calYear, calMonth);
        updateStreak(COLD.streak);
        bindTabs();
        bindCalendarNav();
        bindWeekNav();
        bindMonthNav();
        bindModal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
JS;
    }
}
