<?php
/**
 * Cron Scheduler
 *
 * Registers and manages WP-Cron jobs for the SEO OS:
 *  - wnq_seo_nightly_audit    (daily at ~2am)
 *  - wnq_seo_nightly_automation (daily at ~3am)
 *  - wnq_seo_process_queue    (every 15 minutes)
 *  - wnq_seo_monthly_reports  (monthly, 1st of month)
 *
 * @package WebNique Portal
 */

namespace WNQ\Core;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Services\AuditEngine;
use WNQ\Services\AutomationEngine;
use WNQ\Services\BlogPublisher;
use WNQ\Services\ReportGenerator;

final class CronScheduler
{
    public static function register(): void
    {
        // Register custom intervals
        add_filter('cron_schedules', [self::class, 'addIntervals']);

        // Hook cron actions to handlers
        add_action('wnq_seo_nightly_audit',       [self::class, 'runNightlyAudit']);
        add_action('wnq_seo_nightly_automation',  [self::class, 'runNightlyAutomation']);
        add_action('wnq_seo_process_queue',       [self::class, 'processContentQueue']);
        add_action('wnq_seo_monthly_reports',     [self::class, 'generateMonthlyReports']);
        add_action('wnq_blog_publisher',          [self::class, 'runBlogPublisher']);

        // Schedule jobs if not already scheduled
        self::scheduleJobs();
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
        return $schedules;
    }

    private static function scheduleJobs(): void
    {
        // Nightly audit at 2am
        if (!wp_next_scheduled('wnq_seo_nightly_audit')) {
            $next_2am = strtotime('tomorrow 2:00am');
            wp_schedule_event($next_2am, 'daily', 'wnq_seo_nightly_audit');
        }

        // Nightly automation at 3am
        if (!wp_next_scheduled('wnq_seo_nightly_automation')) {
            $next_3am = strtotime('tomorrow 3:00am');
            wp_schedule_event($next_3am, 'daily', 'wnq_seo_nightly_automation');
        }

        // Queue processor every 15 min
        if (!wp_next_scheduled('wnq_seo_process_queue')) {
            wp_schedule_event(time() + 60, 'every_fifteen_minutes', 'wnq_seo_process_queue');
        }

        // Monthly reports on 1st of month at 6am
        if (!wp_next_scheduled('wnq_seo_monthly_reports')) {
            $next_month_1st = mktime(6, 0, 0, (int)date('n') + 1, 1, (int)date('Y'));
            wp_schedule_event($next_month_1st, 'monthly', 'wnq_seo_monthly_reports');
        }

        // Blog publisher daily at 8am — processes posts due today
        if (!wp_next_scheduled('wnq_blog_publisher')) {
            $next_8am = strtotime('tomorrow 8:00am');
            wp_schedule_event($next_8am, 'daily', 'wnq_blog_publisher');
        }
    }

    public static function unscheduleAll(): void
    {
        $hooks = ['wnq_seo_nightly_audit', 'wnq_seo_nightly_automation', 'wnq_seo_process_queue', 'wnq_seo_monthly_reports', 'wnq_blog_publisher'];
        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }

    // ── Cron Handlers ──────────────────────────────────────────────────────

    public static function runNightlyAudit(): void
    {
        if (!self::canRun()) return;
        AuditEngine::runNightlyAudit();
    }

    public static function runNightlyAutomation(): void
    {
        if (!self::canRun()) return;
        AutomationEngine::runNightlyAutomation();
    }

    public static function processContentQueue(): void
    {
        if (!self::canRun()) return;
        AutomationEngine::processContentQueue(5);
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
            'wnq_seo_nightly_automation' => 'Nightly Automation (3am daily)',
            'wnq_seo_process_queue'      => 'AI Queue Processor (every 15 min)',
            'wnq_seo_monthly_reports'    => 'Monthly Report Generator',
            'wnq_blog_publisher'         => 'Blog Auto-Publisher (8am daily)',
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
