<?php
/**
 * Cron Scheduler
 *
 * Registers and manages WP-Cron jobs for the SEO OS:
 *  - wnq_seo_nightly_audit    (daily at ~2am)
 *  - wnq_seo_monthly_reports  (monthly, 1st of month)
 *
 * @package WebNique Portal
 */

namespace WNQ\Core;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Services\AuditEngine;
use WNQ\Services\BacklinkVerifier;
use WNQ\Services\BlogPublisher;
use WNQ\Services\CrawlEngine;
use WNQ\Services\ReportGenerator;

final class CronScheduler
{
    public static function register(): void
    {
        // Register custom intervals
        add_filter('cron_schedules', [self::class, 'addIntervals']);

        // Hook cron actions to handlers
        add_action('wnq_seo_nightly_audit',       [self::class, 'runNightlyAudit']);
        add_action('wnq_seo_monthly_reports',     [self::class, 'generateMonthlyReports']);
        add_action('wnq_blog_publisher',          [self::class, 'runBlogPublisher']);
        add_action('wnq_spider_auto_crawl',       [self::class, 'runSpiderCrawls']);
        add_action('wnq_spider_run_batch',        [self::class, 'runCronBatch'], 10, 1);
        add_action('wnq_backlink_verify',         [self::class, 'runBacklinkVerify']);

        // Schedule jobs if not already scheduled
        self::scheduleJobs();
        self::unscheduleDeprecatedAutomationJobs();
    }

    public static function addIntervals(array $schedules): array
    {
        $schedules['every_fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 Minutes',
        ];
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => 'Monthly',
        ];
        $schedules['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => 'Weekly',
        ];
        return $schedules;
    }

    private static function scheduleJobs(): void
    {
        // Nightly audit at 2am
        if (!wp_next_scheduled('wnq_seo_nightly_audit')) {
            $next_2am = strtotime('tomorrow 2:00am');
            wp_schedule_event($next_2am, 'daily', 'wnq_seo_nightly_audit');
        }

        // Monthly reports on 1st of next month at 6am
        if (!wp_next_scheduled('wnq_seo_monthly_reports')) {
            $next_month_1st = strtotime('first day of next month 6:00am');
            wp_schedule_event($next_month_1st, 'monthly', 'wnq_seo_monthly_reports');
        }

        // Blog publisher daily at 8am — processes posts due today
        if (!wp_next_scheduled('wnq_blog_publisher')) {
            $next_8am = strtotime('tomorrow 8:00am');
            wp_schedule_event($next_8am, 'daily', 'wnq_blog_publisher');
        }

        // Spider auto-crawl check — runs hourly, starts any due schedules
        if (!wp_next_scheduled('wnq_spider_auto_crawl')) {
            wp_schedule_event(time() + 300, 'hourly', 'wnq_spider_auto_crawl');
        }

        // Backlink verifier — weekly on Sunday at 4am
        if (!wp_next_scheduled('wnq_backlink_verify')) {
            $next_sunday_4am = strtotime('next Sunday 4:00am');
            wp_schedule_event($next_sunday_4am, 'weekly', 'wnq_backlink_verify');
        }
    }

    public static function unscheduleAll(): void
    {
        $hooks = ['wnq_seo_nightly_audit', 'wnq_seo_nightly_automation', 'wnq_seo_process_queue', 'wnq_seo_monthly_reports', 'wnq_blog_publisher', 'wnq_spider_auto_crawl', 'wnq_backlink_verify'];
        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }

    private static function unscheduleDeprecatedAutomationJobs(): void
    {
        foreach (['wnq_seo_nightly_automation', 'wnq_seo_process_queue'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            while ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
    }

    // ── Cron Handlers ──────────────────────────────────────────────────────

    public static function runNightlyAudit(): void
    {
        if (!self::canRun()) return;
        AuditEngine::runNightlyAudit();
    }

    public static function generateMonthlyReports(): void
    {
        if (!self::canRun()) return;
        ReportGenerator::generateAllMonthlyReports();
    }

    public static function runBlogPublisher(): void
    {
        if (!self::canRun()) return;
        BlogPublisher::processDuePosts();
    }

    public static function runBacklinkVerify(): void
    {
        if (!self::canRun()) return;
        BacklinkVerifier::verifyAllClients();
    }

    /**
     * Hourly: start crawls for any clients whose schedule is due.
     */
    public static function runSpiderCrawls(): void
    {
        if (!self::canRun()) return;

        global $wpdb;
        $like_prefix = $wpdb->esc_like('wnq_spider_sched_');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like_prefix . '%'
            ),
            ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
            $sched = maybe_unserialize($row['option_value']);
            if (empty($sched['enabled']) || empty($sched['start_url'])) continue;
            if (empty($sched['next_run']) || (int)$sched['next_run'] > time()) continue;

            $client_id = substr($row['option_name'], strlen('wnq_spider_sched_'));

            // Skip if a crawl is already running for this client
            $sessions = CrawlEngine::getSessions($client_id, 1);
            if (!empty($sessions) && $sessions[0]['status'] === 'running') continue;

            $opts       = ['max_depth' => (int)($sched['max_depth'] ?? 3)];
            $session_id = CrawlEngine::startCrawl($client_id, $sched['start_url'], $opts);

            // Chain first cron batch in 5 seconds
            wp_schedule_single_event(time() + 5, 'wnq_spider_run_batch', [$session_id]);

            // Advance next_run by the chosen interval
            $interval        = self::scheduleInterval($sched['frequency'] ?? 'weekly');
            $sched['last_run'] = time();
            $sched['next_run'] = time() + $interval;
            update_option($row['option_name'], $sched, false);
        }
    }

    /**
     * Cron batch step: process one batch then re-schedule itself until done.
     */
    public static function runCronBatch(int $session_id): void
    {
        $result = CrawlEngine::crawlBatch($session_id);
        if (empty($result['done'])) {
            wp_schedule_single_event(time() + 3, 'wnq_spider_run_batch', [$session_id]);
        }
    }

    private static function scheduleInterval(string $frequency): int
    {
        return match($frequency) {
            'daily'   => DAY_IN_SECONDS,
            'monthly' => 30 * DAY_IN_SECONDS,
            default   => 7 * DAY_IN_SECONDS,   // weekly
        };
    }

    private static function canRun(): bool
    {
        // Only run if SEO OS is enabled
        $settings = get_option('wnq_seo_os_settings', []);
        return !empty($settings['enabled']) || empty($settings); // default enabled
    }

    /**
     * Get cron status for settings page
     */
    public static function getCronStatus(): array
    {
        $jobs = [
            'wnq_seo_nightly_audit'      => 'Nightly Audit (2am daily)',
            'wnq_seo_monthly_reports'    => 'Monthly Report Generator',
            'wnq_blog_publisher'         => 'Blog Auto-Publisher (8am daily)',
            'wnq_spider_auto_crawl'      => 'Spider Auto-Crawl Scheduler (hourly check)',
            'wnq_backlink_verify'        => 'Backlink Verifier (Sunday 4am weekly)',
        ];

        $status = [];
        foreach ($jobs as $hook => $label) {
            $next = wp_next_scheduled($hook);
            $status[$hook] = [
                'label'      => $label,
                'scheduled'  => (bool)$next,
                'next_run'   => $next ? date('Y-m-d H:i:s', $next) : 'Not scheduled',
                'next_human' => $next ? human_time_diff($next) . ' from now' : 'Not scheduled',
            ];
        }
        return $status;
    }
}
