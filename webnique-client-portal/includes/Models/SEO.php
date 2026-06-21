<?php
/**
 * SEO Model - FULLY OPTIMIZED
 * 
 * Structure:
 * - On-Page SEO: Full operations manual (Section 1-9) - One-time per client
 * - Technical SEO: Full checklist - One-time per client
 * - Local SEO: Full setup - One-time per client
 * - Off-Page SEO: Full foundation - One-time per client
 * - Monthly Tasks: Recurring tasks to do every month
 * 
 * Features:
 * - Full CRUD for tasks and reports
 * - Bulk import from templates
 * - Bulk import from CSV
 * - Transaction-safe batch operations
 * - Progress tracking per service type and overall
 * - Duplicate prevention on bulk imports
 * - Export tasks to CSV
 * - Delete all tasks by service type (for re-import)
 * 
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class SEO
{
    public const MONTHLY_CHECKLIST_VERSION = '2026-06-08-v2';

    /* ───────────────────────────────────────────
     *  TABLE CREATION
     * ─────────────────────────────────────────── */

    public static function createTables(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_seo = $wpdb->prefix . 'wnq_seo_tasks';
        $sql_seo = "CREATE TABLE IF NOT EXISTS $table_seo (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            service_type varchar(50) NOT NULL,
            task_group varchar(255) DEFAULT '',
            task_name varchar(255) NOT NULL,
            task_description text,
            status varchar(20) DEFAULT 'pending',
            completed_date datetime DEFAULT NULL,
            notes text,
            is_recurring tinyint(1) DEFAULT 0,
            month_year varchar(7) DEFAULT NULL,
            sort_order int UNSIGNED DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_service (client_id, service_type),
            KEY client_service_month (client_id, service_type, month_year),
            KEY status (status),
            KEY month_year (month_year)
        ) $charset_collate;";

        $table_reports = $wpdb->prefix . 'wnq_seo_reports';
        $sql_reports = "CREATE TABLE IF NOT EXISTS $table_reports (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            month_year varchar(7) NOT NULL,
            report_url varchar(500) DEFAULT '',
            keywords_tracked int DEFAULT 0,
            avg_position decimal(5,2) DEFAULT 0.00,
            organic_traffic int DEFAULT 0,
            backlinks_added int DEFAULT 0,
            summary text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_month (client_id, month_year)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_seo);
        dbDelta($sql_reports);
    }

    /* ───────────────────────────────────────────
     *  TASK CRUD
     * ─────────────────────────────────────────── */

    public static function createTask(array $data): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        $inserted = $wpdb->insert($table, [
            'client_id'        => sanitize_text_field($data['client_id'] ?? ''),
            'service_type'     => sanitize_text_field($data['service_type'] ?? ''),
            'task_group'       => sanitize_text_field($data['task_group'] ?? ''),
            'task_name'        => sanitize_text_field($data['task_name'] ?? ''),
            'task_description' => sanitize_textarea_field($data['task_description'] ?? ''),
            'status'           => sanitize_text_field($data['status'] ?? 'pending'),
            'notes'            => sanitize_textarea_field($data['notes'] ?? ''),
            'is_recurring'     => absint($data['is_recurring'] ?? 0),
            'month_year'       => !empty($data['month_year']) ? sanitize_text_field($data['month_year']) : null,
            'sort_order'       => absint($data['sort_order'] ?? 0),
        ]);

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function getTask(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );

        return $result ?: null;
    }

    public static function updateTask(int $id, array $data): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        $allowed = ['task_name', 'task_description', 'task_group', 'status', 'notes', 'completed_date', 'sort_order'];
        $update_data = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update_data[$field] = $data[$field];
            }
        }

        if (empty($update_data)) {
            return false;
        }

        return (bool) $wpdb->update($table, $update_data, ['id' => $id]);
    }

    public static function completeTask(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        return (bool) $wpdb->update($table, [
            'status'         => 'completed',
            'completed_date' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public static function uncompleteTask(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        return (bool) $wpdb->update($table, [
            'status'         => 'pending',
            'completed_date' => null,
        ], ['id' => $id]);
    }

    public static function updateTaskStatus(int $id, string $status): bool
    {
        $status = sanitize_key($status);
        if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) {
            return false;
        }
        $task = self::getTask($id);
        if (!$task) {
            return false;
        }
        if ((string)($task['status'] ?? '') === $status) {
            return true;
        }

        return $status === 'completed'
            ? self::completeTask($id)
            : self::updateTask($id, [
                'status' => $status,
                'completed_date' => null,
            ]);
    }

    public static function bulkUpdateTaskStatus(array $task_ids, string $status): array
    {
        $updated = 0;
        $failed = 0;

        foreach (array_unique(array_map('absint', $task_ids)) as $task_id) {
            if ($task_id < 1) {
                continue;
            }
            self::updateTaskStatus($task_id, $status) ? $updated++ : $failed++;
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    public static function deleteTask(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        return (bool) $wpdb->delete($table, ['id' => $id]);
    }

    /* ───────────────────────────────────────────
     *  TASK QUERIES
     * ─────────────────────────────────────────── */

    public static function getTasksByClient(string $client_id, ?string $service_type = null, ?string $month_year = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        $where  = ['client_id = %s'];
        $params = [$client_id];

        if ($service_type !== null) {
            $where[]  = 'service_type = %s';
            $params[] = $service_type;
        }

        if ($month_year !== null) {
            $where[]  = 'month_year = %s';
            $params[] = $month_year;
        }

        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY task_group ASC, sort_order ASC, id ASC";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Check if tasks already exist for a given client + service type (+ optional month).
     */
    public static function hasExistingTasks(string $client_id, string $service_type, ?string $month_year = null): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        if ($month_year) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE client_id = %s AND service_type = %s AND month_year = %s",
                $client_id, $service_type, $month_year
            ));
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE client_id = %s AND service_type = %s AND month_year IS NULL",
                $client_id, $service_type
            ));
        }

        return (int) $count > 0;
    }

    /**
     * Delete all tasks for a client + service type (+ optional month). Useful for re-import.
     */
    public static function deleteAllTasks(string $client_id, string $service_type, ?string $month_year = null): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        if ($month_year) {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE client_id = %s AND service_type = %s AND month_year = %s",
                $client_id, $service_type, $month_year
            ));
        } else {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE client_id = %s AND service_type = %s AND month_year IS NULL",
                $client_id, $service_type
            ));
        }

        return (int) $deleted;
    }

    /**
     * Delete ALL tasks for a client (full reset).
     */
    public static function deleteAllClientTasks(string $client_id): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE client_id = %s",
            $client_id
        ));
    }

    /* ───────────────────────────────────────────
     *  REPORTS
     * ─────────────────────────────────────────── */

    public static function saveReport(array $data): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_reports';

        $client_id  = sanitize_text_field($data['client_id'] ?? '');
        $month_year = sanitize_text_field($data['month_year'] ?? '');

        if (empty($client_id) || empty($month_year)) {
            return false;
        }

        $row = [
            'report_url'       => esc_url_raw($data['report_url'] ?? ''),
            'keywords_tracked' => absint($data['keywords_tracked'] ?? 0),
            'avg_position'     => floatval($data['avg_position'] ?? 0),
            'organic_traffic'  => absint($data['organic_traffic'] ?? 0),
            'backlinks_added'  => absint($data['backlinks_added'] ?? 0),
            'summary'          => sanitize_textarea_field($data['summary'] ?? ''),
        ];

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE client_id = %s AND month_year = %s",
            $client_id, $month_year
        ));

        if ($existing) {
            return (bool) $wpdb->update($table, $row, [
                'client_id'  => $client_id,
                'month_year' => $month_year,
            ]);
        }

        $row['client_id']  = $client_id;
        $row['month_year'] = $month_year;

        return (bool) $wpdb->insert($table, $row);
    }

    public static function getReport(string $client_id, string $month_year): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_reports';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE client_id = %s AND month_year = %s",
            $client_id, $month_year
        ), ARRAY_A);

        return $result ?: null;
    }

    public static function getClientReports(string $client_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_reports';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE client_id = %s ORDER BY month_year DESC",
            $client_id
        ), ARRAY_A);

        return $results ?: [];
    }

    /* ───────────────────────────────────────────
     *  PROGRESS / STATS
     * ─────────────────────────────────────────── */

    public static function getServiceTypeProgress(string $client_id, ?string $month_year = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        if ($month_year) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT service_type,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
                 FROM $table
                 WHERE client_id = %s AND (month_year = %s OR month_year IS NULL)
                 GROUP BY service_type",
                $client_id, $month_year
            ), ARRAY_A);
        } else {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT service_type,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
                 FROM $table
                 WHERE client_id = %s AND month_year IS NULL
                 GROUP BY service_type",
                $client_id
            ), ARRAY_A);
        }

        return $results ?: [];
    }

    public static function getOverallProgress(string $client_id, ?string $month_year = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';

        if ($month_year) {
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS total_tasks,
                        SUM(CASE WHEN status = 'completed'   THEN 1 ELSE 0 END) AS completed_tasks,
                        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                        SUM(CASE WHEN status = 'pending'     THEN 1 ELSE 0 END) AS pending_tasks
                 FROM $table
                 WHERE client_id = %s AND (month_year = %s OR month_year IS NULL)",
                $client_id, $month_year
            ), ARRAY_A);
        } else {
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS total_tasks,
                        SUM(CASE WHEN status = 'completed'   THEN 1 ELSE 0 END) AS completed_tasks,
                        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                        SUM(CASE WHEN status = 'pending'     THEN 1 ELSE 0 END) AS pending_tasks
                 FROM $table
                 WHERE client_id = %s AND month_year IS NULL",
                $client_id
            ), ARRAY_A);
        }

        $defaults = [
            'total_tasks'       => 0,
            'completed_tasks'   => 0,
            'in_progress_tasks' => 0,
            'pending_tasks'     => 0,
        ];

        $stats = $stats ? array_map('intval', $stats) : $defaults;

        $stats['completion_percentage'] = $stats['total_tasks'] > 0
            ? round(($stats['completed_tasks'] / $stats['total_tasks']) * 100, 1)
            : 0;

        return $stats;
    }

    /* ───────────────────────────────────────────
     *  BULK IMPORT — TEMPLATES
     * ─────────────────────────────────────────── */

    public static function getTaskTemplates(): array
    {
        return [
            'launch'    => self::getLaunchTasks(),
            'onpage'    => self::getOnPageTasks(),
            'technical' => self::getTechnicalTasks(),
            'local'     => self::getLocalTasks(),
            'offpage'   => self::getOffPageTasks(),
            'monthly'   => self::getMonthlyTasks(),
        ];
    }

    public static function getValidServiceTypes(): array
    {
        return ['launch', 'onpage', 'technical', 'local', 'offpage', 'monthly'];
    }

    /**
     * Initialize a client with ALL one-time tasks.
     */
    public static function initializeClientTasks(string $client_id): array
    {
        $templates = self::getTaskTemplates();
        $imported  = 0;
        $failed    = 0;

        foreach (['launch', 'onpage', 'technical', 'local', 'offpage'] as $service_type) {
            if (self::hasExistingTasks($client_id, $service_type)) {
                continue; // skip if already initialized
            }
            $result   = self::importTasksFromTemplate($client_id, $service_type, $templates[$service_type]);
            $imported += $result['imported'];
            $failed   += $result['failed'];
        }

        return ['imported' => $imported, 'failed' => $failed];
    }

    /**
     * Initialize monthly tasks for a specific month.
     */
    public static function initializeMonthlyTasks(string $client_id, string $month_year): array
    {
        if (self::hasExistingTasks($client_id, 'monthly', $month_year)) {
            return ['imported' => 0, 'failed' => 0, 'skipped' => true];
        }

        $templates = self::getTaskTemplates();

        return self::importTasksFromTemplate($client_id, 'monthly', $templates['monthly'], $month_year);
    }

    /**
     * Apply the latest managed monthly checklist to all clients and months.
     *
     * Previous built-in checklist items are replaced, matching task progress
     * is preserved, and manually-added monthly tasks are left untouched.
     */
    public static function syncMonthlyChecklistForAllClients(): array
    {
        global $wpdb;

        $task_table = $wpdb->prefix . 'wnq_seo_tasks';
        $client_table = $wpdb->prefix . 'wnq_clients';
        $current_month = current_time('Y-m');
        $targets = [];

        $existing_targets = $wpdb->get_results(
            "SELECT DISTINCT client_id, month_year
             FROM $task_table
             WHERE service_type = 'monthly' AND month_year IS NOT NULL AND month_year <> ''",
            ARRAY_A
        ) ?: [];

        foreach ($existing_targets as $target) {
            $client_id = sanitize_text_field((string)($target['client_id'] ?? ''));
            $month_year = sanitize_text_field((string)($target['month_year'] ?? ''));
            if ($client_id !== '' && preg_match('/^\d{4}-\d{2}$/', $month_year)) {
                $targets[$client_id . '|' . $month_year] = [$client_id, $month_year];
            }
        }

        $active_clients = $wpdb->get_col(
            "SELECT client_id FROM $client_table WHERE status = 'active' OR status IS NULL OR status = ''"
        ) ?: [];
        foreach ($active_clients as $client_id) {
            $client_id = sanitize_text_field((string)$client_id);
            if ($client_id !== '') {
                $targets[$client_id . '|' . $current_month] = [$client_id, $current_month];
            }
        }

        $result = ['clients' => count($active_clients), 'months' => 0, 'imported' => 0, 'failed' => 0];
        foreach ($targets as [$client_id, $month_year]) {
            $sync = self::syncMonthlyChecklist($client_id, $month_year);
            $result['months']++;
            $result['imported'] += (int)($sync['imported'] ?? 0);
            $result['failed'] += (int)($sync['failed'] ?? 0);
        }

        return $result;
    }

    private static function syncMonthlyChecklist(string $client_id, string $month_year): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wnq_seo_tasks';
        $latest = self::getMonthlyTasks();
        $managed_names = array_values(array_unique(array_merge(
            self::flattenTaskGroups(self::getLegacyMonthlyTasks()),
            self::flattenTaskGroups(self::getPreviousMonthlyTasks()),
            self::flattenTaskGroups($latest)
        )));
        $existing = self::getTasksByClient($client_id, 'monthly', $month_year);
        $existing_by_name = [];
        foreach ($existing as $task) {
            $existing_by_name[(string)$task['task_name']] = $task;
        }

        $wpdb->query('START TRANSACTION');
        $imported = 0;
        $failed = 0;

        try {
            if ($managed_names) {
                $placeholders = implode(',', array_fill(0, count($managed_names), '%s'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table
                     WHERE client_id = %s AND service_type = 'monthly' AND month_year = %s
                     AND task_name IN ($placeholders)",
                    $client_id,
                    $month_year,
                    ...$managed_names
                ));
            }

            $sort = 0;
            foreach ($latest as $task_group => $tasks) {
                foreach ($tasks as $task_name) {
                    $sort++;
                    $previous = $existing_by_name[$task_name] ?? [];
                    $inserted = $wpdb->insert($table, [
                        'client_id'        => $client_id,
                        'service_type'     => 'monthly',
                        'task_group'       => $task_group,
                        'task_name'        => $task_name,
                        'task_description' => (string)($previous['task_description'] ?? ''),
                        'status'           => (string)($previous['status'] ?? 'pending'),
                        'completed_date'   => $previous['completed_date'] ?? null,
                        'notes'            => (string)($previous['notes'] ?? ''),
                        'is_recurring'     => 1,
                        'month_year'       => $month_year,
                        'sort_order'       => $sort,
                    ]);
                    $inserted ? $imported++ : $failed++;
                }
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('SEO monthly checklist sync error: ' . $e->getMessage());
            return ['imported' => 0, 'failed' => count(self::flattenTaskGroups($latest)), 'error' => $e->getMessage()];
        }

        return ['imported' => $imported, 'failed' => $failed];
    }

    private static function flattenTaskGroups(array $groups): array
    {
        $tasks = [];
        foreach ($groups as $group_tasks) {
            foreach ($group_tasks as $task) {
                $tasks[] = (string)$task;
            }
        }
        return $tasks;
    }

    /**
     * Bulk import tasks for a single service type from built-in templates.
     */
    public static function bulkImportTasks(string $client_id, string $service_type, ?string $month_year = null): array
    {
        $templates = self::getTaskTemplates();

        if (!isset($templates[$service_type])) {
            return ['imported' => 0, 'failed' => 0, 'error' => 'Invalid service type'];
        }

        return self::importTasksFromTemplate($client_id, $service_type, $templates[$service_type], $month_year);
    }

    public static function syncLocalTasks(string $client_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_seo_tasks';
        $templates = self::getLocalTasks();
        $existing = self::getTasksByClient($client_id, 'local', null);
        $existing_names = array_fill_keys(array_map(static fn($task) => (string)$task['task_name'], $existing), true);
        $max_sort = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) FROM $table WHERE client_id=%s AND service_type='local' AND month_year IS NULL",
            $client_id
        ));
        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($templates as $task_group => $tasks) {
            foreach ($tasks as $task_name) {
                if (isset($existing_names[$task_name])) {
                    $skipped++;
                    continue;
                }
                $max_sort++;
                $inserted = $wpdb->insert($table, [
                    'client_id' => $client_id,
                    'service_type' => 'local',
                    'task_group' => $task_group,
                    'task_name' => $task_name,
                    'status' => 'pending',
                    'is_recurring' => 0,
                    'month_year' => null,
                    'sort_order' => $max_sort,
                ]);
                $inserted ? $imported++ : $failed++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Internal: batch-insert tasks from a template group array.
     */
    private static function importTasksFromTemplate(string $client_id, string $service_type, array $groups, ?string $month_year = null): array
    {
        global $wpdb;

        $is_recurring = ($service_type === 'monthly') ? 1 : 0;
        $imported     = 0;
        $failed       = 0;
        $sort         = 0;

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($groups as $task_group => $tasks) {
                foreach ($tasks as $task_name) {
                    $sort++;
                    $result = $wpdb->insert($wpdb->prefix . 'wnq_seo_tasks', [
                        'client_id'    => $client_id,
                        'service_type' => $service_type,
                        'task_group'   => $task_group,
                        'task_name'    => $task_name,
                        'status'       => 'pending',
                        'is_recurring' => $is_recurring,
                        'month_year'   => $month_year,
                        'sort_order'   => $sort,
                    ]);
                    $result ? $imported++ : $failed++;
                }
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('SEO bulk import error: ' . $e->getMessage());
            return ['imported' => 0, 'failed' => $imported + $failed, 'error' => $e->getMessage()];
        }

        return ['imported' => $imported, 'failed' => $failed];
    }

    /* ───────────────────────────────────────────
     *  BULK IMPORT — CSV
     * ─────────────────────────────────────────── */

    /**
     * Import tasks from a CSV file.
     *
     * Expected CSV columns (header row required):
     *   service_type, task_group, task_name, task_description (optional)
     *
     * Alternatively accepts:
     *   task_group, task_name, task_description
     *   (when $default_service_type is supplied)
     *
     * @param string      $client_id            Client ID
     * @param string      $file_path            Full path to uploaded CSV
     * @param string|null $default_service_type  Fallback service_type if column missing
     * @param string|null $month_year            Month for monthly tasks
     * @return array      ['imported' => int, 'failed' => int, 'errors' => string[], 'skipped' => int]
     */
    public static function importFromCsv(string $client_id, string $file_path, ?string $default_service_type = null, ?string $month_year = null): array
    {
        global $wpdb;

        $result = [
            'imported' => 0,
            'failed'   => 0,
            'skipped'  => 0,
            'errors'   => [],
        ];

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $result['errors'][] = 'CSV file not found or not readable.';
            return $result;
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            $result['errors'][] = 'Could not open CSV file.';
            return $result;
        }

        // Read header row
        $header = fgetcsv($handle);
        if ($header === false || empty($header)) {
            fclose($handle);
            $result['errors'][] = 'CSV file is empty or has no header row.';
            return $result;
        }

        // Normalize header names
        $header = array_map(function ($col) {
            return strtolower(trim(str_replace([' ', '-'], '_', $col)));
        }, $header);

        // Map columns
        $col_map = array_flip($header);
        $has_service_type = isset($col_map['service_type']);
        $has_task_group   = isset($col_map['task_group']);
        $has_task_name    = isset($col_map['task_name']);
        $has_description  = isset($col_map['task_description']) || isset($col_map['description']);
        $desc_key         = isset($col_map['task_description']) ? 'task_description' : (isset($col_map['description']) ? 'description' : null);

        if (!$has_task_name) {
            fclose($handle);
            $result['errors'][] = 'CSV must contain a "task_name" column.';
            return $result;
        }

        if (!$has_service_type && empty($default_service_type)) {
            fclose($handle);
            $result['errors'][] = 'CSV must contain a "service_type" column, or a default service type must be selected.';
            return $result;
        }

        $valid_types  = self::getValidServiceTypes();
        $row_num      = 1;
        $sort         = 0;

        $wpdb->query('START TRANSACTION');

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $row_num++;

                // Skip empty rows
                if (empty(array_filter($row))) {
                    $result['skipped']++;
                    continue;
                }

                // Build associative row
                $assoc = [];
                foreach ($header as $i => $col_name) {
                    $assoc[$col_name] = isset($row[$i]) ? trim($row[$i]) : '';
                }

                // Determine service type
                $service_type = $has_service_type && !empty($assoc['service_type'])
                    ? strtolower(trim($assoc['service_type']))
                    : $default_service_type;

                if (!in_array($service_type, $valid_types, true)) {
                    $result['errors'][] = "Row $row_num: Invalid service_type '$service_type'.";
                    $result['failed']++;
                    continue;
                }

                $task_name = $assoc['task_name'] ?? '';
                if (empty($task_name)) {
                    $result['errors'][] = "Row $row_num: Empty task_name.";
                    $result['failed']++;
                    continue;
                }

                $task_group  = $has_task_group ? ($assoc['task_group'] ?? '') : '';
                $description = ($desc_key && isset($assoc[$desc_key])) ? $assoc[$desc_key] : '';

                $is_recurring = ($service_type === 'monthly') ? 1 : 0;
                $task_month   = ($service_type === 'monthly' && !empty($month_year)) ? $month_year : null;

                $sort++;
                $inserted = $wpdb->insert($wpdb->prefix . 'wnq_seo_tasks', [
                    'client_id'        => $client_id,
                    'service_type'     => $service_type,
                    'task_group'       => sanitize_text_field($task_group),
                    'task_name'        => sanitize_text_field($task_name),
                    'task_description' => sanitize_textarea_field($description),
                    'status'           => 'pending',
                    'is_recurring'     => $is_recurring,
                    'month_year'       => $task_month,
                    'sort_order'       => $sort,
                ]);

                $inserted ? $result['imported']++ : $result['failed']++;
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            $result['errors'][] = 'Database error: ' . $e->getMessage();
            error_log('SEO CSV import error: ' . $e->getMessage());
        }

        fclose($handle);

        return $result;
    }

    /**
     * Generate a sample CSV template for download.
     */
    public static function generateCsvTemplate(): string
    {
        $lines = [
            ['service_type', 'task_group', 'task_name', 'task_description'],
            ['launch', 'Go Live', 'Set up SSL certificate', 'One-time client site launch task'],
            ['onpage', 'Section 1: Standards Alignment', 'Run SOP kickoff meeting', 'Initial meeting to align on SEO standards'],
            ['technical', 'Site Speed & Core Web Vitals', 'Run PageSpeed Insights analysis (desktop)', 'Analyze desktop performance scores'],
            ['local', 'Google Business Profile Setup', 'Complete business name and category', ''],
            ['offpage', 'Backlink Audit & Foundation', 'Audit existing backlinks using backlink tool', ''],
            ['monthly', 'Technical SEO', 'Check Google Search Console errors', 'Monthly recurring task'],
        ];

        $output = '';
        foreach ($lines as $line) {
            $output .= '"' . implode('","', array_map(function ($v) {
                return str_replace('"', '""', $v);
            }, $line)) . '"' . "\n";
        }

        return $output;
    }

    /**
     * Export client tasks to CSV format string.
     */
    public static function exportTasksCsv(string $client_id, ?string $service_type = null): string
    {
        $tasks = self::getTasksByClient($client_id, $service_type);

        $output = '"service_type","task_group","task_name","task_description","status","notes","month_year"' . "\n";

        foreach ($tasks as $task) {
            $row = [
                $task['service_type'],
                $task['task_group'],
                $task['task_name'],
                $task['task_description'] ?? '',
                $task['status'],
                $task['notes'] ?? '',
                $task['month_year'] ?? '',
            ];
            $output .= '"' . implode('","', array_map(function ($v) {
                return str_replace('"', '""', $v);
            }, $row)) . '"' . "\n";
        }

        return $output;
    }

    /* ───────────────────────────────────────────
     *  TASK TEMPLATES (built-in)
     * ─────────────────────────────────────────── */

    private static function getLaunchTasks(): array
    {
        return [
            'Design Source & Build Plan' => [
                'If the site uses a bought template, import the template',
                'If the site uses a ChatGPT design, generate all page layouts',
                'Map out all pages and focus keywords',
            ],
            'Site Structure & Global Layout' => [
                'Fix header',
                'Fix footer',
                'Fix pages, add pages, and remove pages as needed',
                'Fix primary menu',
                'Add service menu',
                'Add service area menu',
                'Finish site customization',
                'Finish popups',
            ],
            'Core Pages' => [
                'Edit Home page',
                'Edit About page',
                'Edit FAQ page',
                'Edit Projects / Gallery page',
                'Edit Services page',
                'Edit Service Areas page',
            ],
            'Content Buildout' => [
                'Make 3 blogs',
                'Create all individual service pages',
                'Create all service area pages',
            ],
            'Go Live' => [
                'Get site live',
                'Change DNS config and point domain to the right application',
                'Set up SSL certificate',
            ],
            'Post-Launch Tracking Setup' => [
                'Set up Google Site Kit',
                'Install GTM',
                'Import GTM tag files',
                'Set up Google Analytics',
                'Get Measurement ID and correct all tags',
                'Go to GHL and set up form automation',
                'Go to Analytics and create API secret',
            ],
            'SEO Plugin & Metadata' => [
                'Download and activate Rank Math',
                'Set up titles and tag descriptions',
                'Push sitemap in Google Search Console',
            ],
            'Golden Web Marketing Client Setup' => [
                'Import site to Clients in Golden Web Marketing',
                'Set up payment for plugin',
                'Make a blog for client site on Golden Web Marketing',
            ],
        ];
    }

    private static function getOnPageTasks(): array
    {
        return [
            'Section 1: Standards Alignment (Setup)' => [
                'Run SOP kickoff meeting',
                'Confirm "On-Page Only" scope (exclude off-page, local, technical)',
                'Re-affirm non-negotiables: One primary keyword per page',
                'Re-affirm non-negotiables: Intent-first approach',
                'Re-affirm non-negotiables: Human-first writing',
                'Re-affirm non-negotiables: Clarity for AI + search',
                'Re-affirm non-negotiables: No thin/duplicate content',
                'Confirm keyword usage rules globally',
                'Confirm heading rules (one H1, logical H2/H3)',
                'Confirm URL rules',
                'Confirm image + alt text rules',
                'Confirm internal linking rules (descriptive anchors, no "click here")',
                'Assign SEO Strategist role',
                'Assign Writer/Editor role',
                'Assign Web Builder role',
                'Assign QA Reviewer role',
            ],
            'Section 2: Client Intake & Page Selection' => [
                'Answer: What is the site\'s purpose? (leads/services/educate)',
                'Answer: What actions should users take? (call/form/quote/consult)',
                'Answer: What services matter most? (primary/secondary)',
                'Answer: Who is the audience? (homeowners/businesses/niche)',
                'Build full page inventory (indexable pages only)',
                'Record each page: URL + current purpose',
                'Flag unclear-purpose pages',
                'Classify pages: Primary Conversion pages',
                'Classify pages: Supporting Conversion pages',
                'Classify pages: Informational pages',
                'Classify pages: Utility pages',
                'Assign Priority 1 (must): homepage + primary services',
                'Assign Priority 2 (should): secondary services + supporting info',
                'Assign Priority 3 (later): low-traffic blogs + legacy content',
                'Map intent: Learn/Compare/Take action for each page',
                'Write "why would someone search for this page?" statement per page',
                'Apply "one page, one purpose" test (one-sentence summary)',
                'Identify duplicate pages for consolidation',
                'Identify thin placeholder pages',
                'Identify unclear role pages',
                'Decide: consolidate/rewrite/exclude problematic pages',
                'Produce: Page inventory list',
                'Produce: Page role assignments',
                'Produce: Priority assignments',
                'Produce: Intent mappings',
                'Produce: Exclusion/revision list',
            ],
            'Section 3: Keyword Selection & Mapping' => [
                'Define one primary keyword for each page',
                'Define 3-8 supporting keywords (same intent only) per page',
                'Map intent type: informational/commercial/transactional',
                'Validate keyword: Relevance to page topic',
                'Validate keyword: Intent match to page role',
                'Validate keyword: Clarity (specific, obvious meaning)',
                'Validate keyword: Realistic natural usage in title/H1/body',
                'Check for keyword cannibalization across pages',
                'Resolve cannibalization: consolidate overlapping pages',
                'Resolve cannibalization: refocus page topics',
                'Resolve cannibalization: reassign keywords',
                'Identify utility pages (contact/privacy/terms/thank-you)',
                'Note: Utility pages do NOT target competitive keywords',
                'Lock keyword assignments (only change if purpose changes)',
                'Produce: Page-to-keyword mapping sheet',
                'Produce: Supporting keyword list per page',
                'Produce: Cannibalization resolution log',
                'Produce: Keyword approval record',
            ],
            'Section 4: Page Structure & Content Framework' => [
                'Confirm "one page, one topic" for each page',
                'Create page structure outline: SEO title',
                'Create page structure outline: H1',
                'Create page structure outline: Intro (above fold)',
                'Create page structure outline: H2 sections',
                'Create page structure outline: H3 subsections (when needed)',
                'Create page structure outline: Internal link opportunities',
                'Create page structure outline: Primary CTA',
                'Write H1: Exactly one H1 per page',
                'Write H1: Include primary keyword naturally',
                'Write H1: Confirm "I\'m in the right place" message',
                'Rewrite introduction: Place immediately under H1',
                'Rewrite introduction: Match search intent',
                'Rewrite introduction: Use primary keyword naturally',
                'Rewrite introduction: Fast clarity (answer-first for questions)',
                'Build H2/H3 map: Descriptive headings (no vague "Overview")',
                'Build H2/H3 map: Logical hierarchy',
                'Build H2/H3 map: Scan-friendly structure',
                'Enforce: Plain language throughout',
                'Enforce: Short paragraphs',
                'Enforce: Bullets where helpful',
                'Enforce: Remove fluff/filler',
                'Enforce: Answer-first structure for question-based sections',
                'Identify content to remove: Repetition',
                'Identify content to remove: Off-topic content',
                'Identify content to remove: Outdated content',
                'Identify content to remove: "SEO-only" stuffing',
                'Define CTA: Clear next step',
                'Define CTA: Relevant to page',
                'Define CTA: Non-aggressive tone',
                'Produce: Page outline (H1 + H2/H3 map)',
                'Produce: Approved introduction',
                'Produce: Confirmed CTA',
                'Produce: Structure approval to proceed',
            ],
            'Section 5: On-Page Optimization Checklist (Per Page)' => [
                'Pre-check: Primary keyword assigned',
                'Pre-check: Structure finalized',
                'Pre-check: Role + intent defined',
                'Optimize Title Tag: Unique and descriptive',
                'Optimize Title Tag: Primary keyword included naturally',
                'Optimize Title Tag: Not stuffed',
                'Optimize Title Tag: Keyword near start when possible',
                'Optimize H1: One H1 only',
                'Optimize H1: Aligns with title intent',
                'Optimize H1: Natural keyword use',
                'Optimize Meta Description: Unique',
                'Optimize Meta Description: Accurate',
                'Optimize Meta Description: Intent-matching',
                'Optimize Meta Description: Natural keyword inclusion',
                'Optimize Meta Description: Benefit-focused, not spammy',
                'Optimize URL Slug: Short',
                'Optimize URL Slug: Readable',
                'Optimize URL Slug: Hyphenated',
                'Optimize URL Slug: Topic-focused',
                'Optimize URL Slug: No unnecessary dates/words',
                'Optimize Body Content: Primary keyword early',
                'Optimize Body Content: Variations natural',
                'Optimize Body Content: Stays on-topic',
                'Optimize Body Content: Satisfies intent',
                'Optimize Headings: Logical hierarchy (H2/H3)',
                'Optimize Headings: Descriptive',
                'Optimize Headings: No skipped levels',
                'Add Internal Links: At least one relevant link where appropriate',
                'Add Internal Links: Descriptive anchors (no "click here")',
                'Add Internal Links: Correct destinations',
                'Add External Links: Only if needed',
                'Add External Links: Relevant + trustworthy',
                'Add External Links: Used sparingly',
                'Add External Links: Supports claims',
                'Image Optimization: Images support content',
                'Image Optimization: Placed near relevant text',
                'Image Optimization: Alt text for every image (descriptive, not stuffed)',
                'Image Optimization: Descriptive file naming when possible',
                'Readability Pass: Short paragraphs',
                'Readability Pass: Bullets where helpful',
                'Readability Pass: Spacing',
                'Readability Pass: Scanability',
                'Optional: Add page-level schema (only if it supports content)',
                'Run Final QA Checklist (must pass all items)',
                'Produce: Final title tag',
                'Produce: Final meta description',
                'Produce: Final URL slug',
                'Produce: Updated/optimized content',
                'Produce: Internal link confirmation',
                'Produce: QA checklist completed',
            ],
            'Section 6: Internal Linking Strategy (On-Page Only)' => [
                'Apply principle: User-first',
                'Apply principle: Relevance-only',
                'Apply principle: Natural placement',
                'Apply principle: Descriptive anchor text',
                'Place links: Early content (if helpful)',
                'Place links: Within explanations',
                'Place links: Near CTAs',
                'Enforce anchor text: Descriptive',
                'Enforce anchor text: Concise',
                'Enforce anchor text: Varied (avoid repetitive exact-match)',
                'Service pages: Link to related services',
                'Service pages: Link to relevant blogs',
                'Blog pages: Link to relevant blogs',
                'Blog pages: Link to relevant service pages',
                'Utility pages: Minimal links, only if helpful',
                'Avoid: Duplicate anchors to same destination repeatedly',
                'Avoid: Linking to thin/low-value pages',
                'Avoid: Link clusters with no context',
                'QA: Correct destination',
                'QA: Relevance',
                'QA: Helpfulness',
                'QA: Clean anchor text',
                'Produce: At least one relevant internal link per page',
                'Produce: Anchor text + placement confirmed',
                'Produce: QA approval logged',
            ],
            'Section 7: Visual Content & Image Optimization (On-Page Only)' => [
                'Confirm every visual serves a purpose (no decorative filler)',
                'Service pages: Process/outcomes/trust visuals',
                'Informational pages: Diagrams/examples/steps',
                'Apply placement: Near supporting text',
                'Apply placement: Improves flow',
                'Apply placement: Avoids clutter',
                'Write alt text: Describe what it shows in plain language',
                'Write alt text: Keywords only if natural',
                'Write alt text: No repeats across different images',
                'Check file names: Descriptive',
                'Check file names: Hyphenated',
                'Check file names: Not generic',
                'Video embeds: Relevant',
                'Video embeds: Introduced with context',
                'Video embeds: Captions when possible',
                'Video embeds: Not replacing key text',
                'Prevent visual overload: Remove or consolidate if cluttered',
                'QA visuals: Relevance',
                'QA visuals: Placement',
                'QA visuals: Alt accuracy',
                'QA visuals: No distraction',
                'Produce: Visuals added/approved (if appropriate)',
                'Produce: Alt text complete',
                'Produce: Visual QA passed',
            ],
            'Section 8: QA & Completion Standards' => [
                'Assign QA reviewer (preferably different from optimizer)',
                'QA order 1: Purpose/intent check',
                'QA order 2: Keyword focus',
                'QA order 3: Structure/headings',
                'QA order 4: Metadata',
                'QA order 5: URL',
                'QA order 6: Content quality',
                'QA order 7: Internal links',
                'QA order 8: Visuals',
                'QA order 9: UX/CTA',
                'Fail if: Keyword stuffing',
                'Fail if: Mixed intent',
                'Fail if: Vague headings',
                'Fail if: Duplicate metadata',
                'Fail if: Thin content',
                'Fail if: Broken links',
                'Fail if: Missing alt text',
                'Fail if: Confusing flow',
                'Approval criteria: All checklist items pass',
                'Approval criteria: No critical issues remain',
                'Document: Page URL',
                'Document: Primary keyword',
                'Document: Completion date',
                'Document: QA reviewer name',
                'Document: Notes',
                'Rework loop: Log issues → fix → re-review if failed',
            ],
            'Section 9: Post-Launch Monitoring (On-Page Only)' => [
                'Verify: Loads correctly',
                'Verify: No missing sections/images',
                'Verify: Formatting intact',
                'Verify live: Title/meta present',
                'Verify live: H1 visible',
                'Verify live: Headings correct',
                'Verify live: Images + alt correct',
                'Review search snippet: Title/meta clarity',
                'Review search snippet: Accurate representation',
                'Review search snippet: Intent match',
                'Reality vs intent reread: Clear in seconds',
                'Reality vs intent reread: Promises matched',
                'Reality vs intent reread: No gaps',
                'Light refinements: Headings clarity',
                'Light refinements: Intro tweaks',
                'Light refinements: Meta refinements',
                'Light refinements: Internal link placement',
                'Light refinements: Alt improvements',
                'Log anything outside scope for correct SOP',
                'Document post-launch: Date',
                'Document post-launch: Changes made (or "no changes")',
                'Document post-launch: Confirmation of on-page standards',
                'Mark "On-Page SEO Fully Complete"',
            ],
        ];
    }

    private static function getTechnicalTasks(): array
    {
        return [
            'Site Speed & Core Web Vitals' => [
                'Run PageSpeed Insights analysis (desktop)',
                'Run PageSpeed Insights analysis (mobile)',
                'Check Largest Contentful Paint (LCP) score',
                'Check First Input Delay (FID) score',
                'Check Cumulative Layout Shift (CLS) score',
                'Identify render-blocking CSS',
                'Identify render-blocking JavaScript',
                'Review JavaScript execution time',
                'Check Time to Interactive (TTI)',
                'Analyze server response time (TTFB)',
                'Check image optimization opportunities',
                'Review lazy loading implementation',
            ],
            'Mobile Optimization' => [
                'Test mobile responsiveness on multiple devices',
                'Check mobile usability in Google Search Console',
                'Verify touch elements are properly sized (48x48px minimum)',
                'Test mobile page speed separately',
                'Check viewport meta tag is present',
                'Verify mobile navigation is accessible',
                'Test forms on mobile devices',
                'Check font sizes are readable on mobile',
            ],
            'XML Sitemap & Indexing' => [
                'Generate/update XML sitemap',
                'Submit sitemap to Google Search Console',
                'Submit sitemap to Bing Webmaster Tools',
                'Verify all important pages are in sitemap',
                'Check sitemap is accessible at /sitemap.xml',
                'Verify sitemap is referenced in robots.txt',
                'Check for sitemap errors in GSC',
                'Remove excluded pages from sitemap',
            ],
            'Robots.txt Configuration' => [
                'Review current robots.txt configuration',
                'Verify critical pages are not blocked',
                'Block admin and system pages appropriately',
                'Add sitemap reference to robots.txt',
                'Test robots.txt using GSC testing tool',
                'Check for crawl budget optimization',
            ],
            'Schema Markup Implementation' => [
                'Implement Organization schema',
                'Implement LocalBusiness schema (if applicable)',
                'Add WebSite schema with site search',
                'Implement BreadcrumbList schema',
                'Add Product schema (if e-commerce)',
                'Add Review/Rating schema (if applicable)',
                'Validate all schema with Rich Results Test',
                'Check schema appears in Google Search Console',
            ],
            'Site Architecture & URL Structure' => [
                'Review URL structure site-wide',
                'Check internal linking depth (max 3 clicks from homepage)',
                'Verify canonical tags on all pages',
                'Check for URL parameter handling',
                'Review site navigation structure',
                'Verify breadcrumb implementation',
                'Check for orphaned pages',
                'Optimize URL structure where needed',
            ],
            'HTTPS & Security' => [
                'Verify SSL certificate is active',
                'Check all pages load via HTTPS',
                'Update internal links to HTTPS',
                'Verify HSTS header is enabled',
                'Check for mixed content warnings',
                'Test SSL certificate validity',
            ],
            'Technical SEO Cleanup' => [
                'Fix broken links site-wide',
                'Set up 301 redirects for moved pages',
                'Remove duplicate content',
                'Fix redirect chains',
                'Check for soft 404 errors',
                'Verify pagination is properly implemented',
                'Check hreflang tags (if multi-language)',
            ],
        ];
    }

    private static function getLocalTasks(): array
    {
        return [
            'Google Business Profile Setup' => [
                'Complete business name and category',
                'Add accurate business address',
                'Add phone number',
                'Add website URL',
                'Set business hours',
                'Add service areas',
                'Write business description (750 characters)',
                'Add attributes (e.g., wheelchair accessible, WiFi)',
                'Upload logo',
                'Upload cover photo',
                'Add 10-15 business photos',
                'Add products/services with descriptions',
                'Enable messaging',
                'Set up Google Posts schedule',
            ],
            'NAP Consistency Foundation' => [
                'Standardize business name format',
                'Standardize address format',
                'Standardize phone number format',
                'Update NAP on website footer',
                'Update NAP on contact page',
                'Add NAP to schema markup (Organization/LocalBusiness)',
                'Document official NAP format',
                'Create NAP consistency checklist',
            ],
            'Core Citation Building (15-20 Citations)' => [
                'Tier 1 citation: Google Business Profile',
                'Tier 1 citation: Apple Business Connect / Apple Maps',
                'Tier 1 citation: Bing Places / Bing Maps',
                'Tier 1 citation: Facebook Business Page',
                'Tier 1 citation: Yelp',
                'Tier 1 citation: BBB',
                'Tier 1 citation: Yellow Pages',
                'Tier 1 citation: Nextdoor',
                'Tier 1 citation: MapQuest',
                'Tier 1 citation: Foursquare',
                'Tier 1 citation: Data Axle',
                'Tier 1 citation: Dun & Bradstreet',
                'Tier 1 citation: ChamberofCommerce.com',
                'Verify Tier 1 citations are accurate and live',
                'Document Tier 1 citation URLs in the client tracker',
            ],
            'Strong General Citations (Tier 2)' => [
                'Tier 2 citation: Manta',
                'Tier 2 citation: Hotfrog',
                'Tier 2 citation: EZlocal',
                'Tier 2 citation: MerchantCircle',
                'Tier 2 citation: Brownbook',
                'Tier 2 citation: Cylex',
                'Tier 2 citation: ShowMeLocal',
                'Tier 2 citation: Local.com',
                'Tier 2 citation: Alignable',
                'Tier 2 citation: Tupalo',
                'Tier 2 citation: CitySquares',
                'Tier 2 citation: eLocal',
                'Tier 2 citation: Superpages / DexKnows',
                'Tier 2 citation: Kompass',
                'Tier 2 citation: Opendi',
                'Tier 2 citation: 2FindLocal',
                'Tier 2 citation: BizHwy',
                'Tier 2 citation: USCity.net',
                'Tier 2 citation: FindUsLocal',
                'Tier 2 citation: YellowBot',
                'Tier 2 citation: 411.info',
                'Tier 2 citation: GoLocal247',
                'Tier 2 citation: Hub.biz',
                'Verify Tier 2 citation consistency for NAP, website, and categories',
                'Document Tier 2 citation URLs in the client tracker',
            ],
            'Navigation & Map Citations' => [
                'Submit or verify TomTom listing',
                'Submit or verify HERE WeGo listing',
                'Submit or verify Waze listing',
                'Submit or verify Garmin listing',
                'Submit or verify Uber map listing',
                'Submit or verify Lyft map listing',
                'Review OpenStreetMap business/map data',
                'Confirm map citation NAP matches official business profile',
            ],
            'Review & Trust Profiles' => [
                'Review Google Business Profile review settings',
                'Review Facebook recommendations setup',
                'Review Yelp profile accuracy',
                'Review BBB profile accuracy',
                'Create or verify Trustpilot profile when relevant',
                'Create or verify Birdeye profile when relevant',
                'Create or verify Reputation.com profile when relevant',
                'Confirm review profile links are documented',
            ],
            'Home-Service Citation Opportunities' => [
                'Create or verify Angi profile for home-service clients',
                'Create or verify HomeAdvisor profile for home-service clients',
                'Create or verify Porch profile for home-service clients',
                'Create or verify Thumbtack profile for service-lead clients',
                'Create or verify Houzz profile for home, remodeling, or design clients',
                'Create or verify BuildZoom profile for contractor clients',
                'Create or verify Networx profile for contractor clients',
                'Create or verify Bark profile when relevant',
                'Create or verify Fixr profile when relevant',
                'Create or verify Modernize profile when relevant',
                'Create or verify The Blue Book profile for commercial contractors',
                'Create or verify Thomasnet profile for industrial or B2B trade clients',
                'Document skipped home-service citations that are not relevant',
            ],
            'Niche Citation Opportunities (Tier 3)' => [
                'Identify industry-specific directories for this client',
                'Identify city or chamber directories in the client service area',
                'For auto detailing clients, check auto detailing directories',
                'For ceramic coating clients, check installer directories and coating brand profiles',
                'For contractor clients, check trade-specific directories',
                'Prioritize Tier 3 citations only when competing in tougher local searches',
                'Document selected Tier 3 citations and why they were chosen',
            ],
            'Review Foundation Setup' => [
                'Set up review monitoring system',
                'Create review request email template',
                'Create review request SMS template',
                'Set up review response templates',
                'Document review response process',
                'Create negative review response guide',
                'Set up review alerts',
            ],
            'Local Content Foundation' => [
                'Create location page for primary location',
                'Create service area pages for each area',
                'Add city/region names to key pages',
                'Include local landmarks in content',
                'Add map embed to contact page',
                'Create local resource content',
                'Add local business photos',
            ],
        ];
    }

    private static function getOffPageTasks(): array
    {
        return [
            'Backlink Audit & Foundation' => [
                'Audit existing backlinks using backlink tool',
                'Identify toxic/spam backlinks',
                'Disavow harmful backlinks if needed',
                'Identify top performing backlinks',
                'Analyze competitor backlinks (top 3-5 competitors)',
                'Create target link prospects list (50+ opportunities)',
                'Set up backlink monitoring system',
                'Document link building guidelines',
            ],
            'Content Partnership Strategy' => [
                'Identify 20+ guest post opportunities',
                'Create guest post pitch template',
                'Create author bio template',
                'Identify podcast interview opportunities',
                'Identify webinar/speaking opportunities',
                'Create partnership outreach email template',
                'Set up partnership tracking system',
            ],
            'Directory & Citation Strategy' => [
                'Identify 20-30 relevant directories',
                'Create directory submission checklist',
                'Submit to general directories (10-15)',
                'Submit to industry-specific directories (10-15)',
                'Verify all submissions',
                'Document all directory links',
            ],
            'Brand Monitoring Setup' => [
                'Set up Google Alerts for brand mentions',
                'Set up social media monitoring',
                'Create brand mention tracking spreadsheet',
                'Create unlinked mention outreach template',
                'Document mention response process',
            ],
            'Social Media Foundation' => [
                'Create/optimize Facebook business page',
                'Create/optimize LinkedIn company page',
                'Create/optimize Twitter/X profile',
                'Create/optimize Instagram business profile',
                'Link all social profiles to website',
                'Add social sharing buttons to website',
                'Create social media posting schedule',
                'Document social media strategy',
            ],
        ];
    }

    private static function getMonthlyTasks(): array
    {
        return [
            'Rankings & Search Visibility' => [
                'Compare main service and city keyword rankings month over month',
                'Review GSC clicks, impressions, CTR, and average position',
                'Identify page 2 keywords with realistic ranking potential',
                'Identify high-impression keywords with low CTR',
                'Choose priority keyword and page opportunities to act on',
            ],
            'Traffic, Leads & Conversion Tracking' => [
                'Compare organic traffic and landing page performance month over month',
                'Review calls, forms, quote requests, and booking conversions',
                'Confirm call, form, and conversion tracking is working',
                'Identify pages driving leads and pages losing conversions',
                'Test forms, phone buttons, quote buttons, and booking links',
            ],
            'Service & Location Page Improvements' => [
                'Improve 1-3 priority service or location pages',
                'Create or optimize the next priority service/city page when included',
                'Improve title tags and meta descriptions for priority pages',
                'Add internal links to priority service and city pages',
                'Add useful FAQs, local proof, reviews, photos, or project details',
                'Refresh thin or outdated content that can improve rankings or leads',
            ],
            'Google Business Profile Visibility' => [
                'Review GBP performance, calls, website clicks, and direction requests',
                'Verify GBP categories, services, hours, phone, website, and service areas',
                'Publish useful GBP updates or offers when relevant',
                'Add recent project or team photos when available',
                'Respond to new reviews and request reviews from recent customers',
                'Identify GBP visibility improvements for priority services and locations',
            ],
            'Technical & Lead-Blocking Issues' => [
                'Review Google Search Console indexing and coverage issues',
                'Fix broken links, 404s, redirect issues, or crawl blockers',
                'Check sitemap and important page indexing status',
                'Review Core Web Vitals and speed issues affecting priority pages',
                'Fix technical or usability issues blocking calls, forms, or rankings',
            ],
            'Competitive Gaps & Authority' => [
                'Review top local competitors for priority services and cities',
                'Identify competitor page, content, or local visibility gaps',
                'Find relevant local, niche, partnership, or citation opportunities',
                'Improve citation consistency or pursue authority opportunities when valuable',
                'Document authority improvements and newly earned links or citations',
            ],
            'Monthly Results & Next Priorities' => [
                'Document ranking, traffic, lead, and GBP wins or losses',
                'Document pages created, improved, or fixed this month',
                'Compile and send the monthly client update',
                'Set next month\'s priorities based on expected ranking and lead impact',
            ],
        ];
    }

    private static function getLegacyMonthlyTasks(): array
    {
        return [
            'Monthly Keyword & Traffic Analysis' => [
                'Update keyword ranking report',
                'Analyze traffic trends in Google Analytics',
                'Identify ranking opportunities',
                'Compare month-over-month changes',
                'Track keyword position changes',
                'Identify keywords moving up/down',
                'Document seasonal trends',
            ],
            'Monthly Competitor Analysis' => [
                'Review top 3 competitors',
                'Analyze competitor keyword rankings',
                'Identify new competitor content',
                'Track competitor backlinks',
                'Identify content gaps',
                'Document competitive changes',
            ],
            'Monthly Content Activities' => [
                'Create content calendar for next month',
                'Identify 5-10 blog topic ideas',
                'Plan service page updates',
                'Schedule content production',
                'Review existing content performance',
                'Update outdated content',
            ],
            'Monthly Link Building (5-10 New Links)' => [
                'Identify 10-15 new link opportunities',
                'Send outreach emails to prospects',
                'Follow up on previous outreach',
                'Secure 5-10 quality backlinks',
                'Document new backlinks acquired',
                'Update link tracking spreadsheet',
            ],
            'Monthly Local SEO Activities' => [
                'Update Google Business Profile info',
                'Add 5-10 new photos to GMB',
                'Create 2-3 Google Posts',
                'Respond to all new reviews',
                'Request reviews from recent clients',
                'Build 3-5 new local citations',
                'Update existing citations if needed',
            ],
            'Monthly Technical Checks' => [
                'Check for broken links',
                'Review Google Search Console errors',
                'Check site speed scores',
                'Verify sitemap is up to date',
                'Check for crawl errors',
                'Review Core Web Vitals',
            ],
            'Monthly Reporting & Strategy' => [
                'Compile monthly SEO report',
                'Document all work completed',
                'Prepare client presentation',
                'Review overall SEO strategy',
                'Identify next month priorities',
                'Provide improvement recommendations',
                'Set goals for next month',
            ],
        ];
    }

    private static function getPreviousMonthlyTasks(): array
    {
        return [
            'Technical SEO' => [
                'Check Google Search Console errors',
                'Check indexing status for important pages',
                'Check sitemap',
                'Check broken links and 404s',
                'Check site speed/Core Web Vitals',
                'Test forms, phone buttons, quote buttons, and booking links',
                'Confirm tracking is working for calls/forms',
            ],
            'Keyword & Traffic Review' => [
                'Review GSC clicks, impressions, CTR, and rankings',
                'Review GA4 traffic trends',
                'Compare month-over-month performance',
                'Identify keywords gaining impressions but low clicks',
                'Identify page 2 ranking opportunities',
                'Track main service and city keywords',
                'Document wins, drops, and opportunities',
            ],
            'On-Page SEO' => [
                'Update 1-3 priority pages',
                'Improve title tags/meta descriptions where needed',
                'Add internal links to priority pages',
                'Add or update FAQs',
                'Add local proof, photos, reviews, or project details',
                'Review thin or outdated content',
                'Plan next service/city page improvements',
            ],
            'Content / Page Creation' => [
                'Choose next month\'s priority content/pages',
                'Create or optimize new service/city page if included',
                'Create or update blog content if useful',
                'Review content performance',
                'Update content calendar',
            ],
            'Google Business Profile / Local SEO' => [
                'Check GBP info, hours, phone, website, services',
                'Add new photos if available',
                'Publish 2-4 GBP posts',
                'Respond to new reviews',
                'Request new reviews',
                'Check GBP categories and services',
                'Build or clean up citations as needed',
            ],
            'Competitor Review' => [
                'Review top 3 competitors',
                'Check competitor rankings for main services',
                'Identify new competitor pages/content',
                'Find content/service/city gaps',
                'Document competitor changes',
            ],
            'Links / Authority' => [
                'Find local/niche link opportunities',
                'Build 3-5 citations or local links when appropriate',
                'Check citation consistency',
                'Document new links/citations',
            ],
            'Reporting' => [
                'Compile monthly report',
                'Document completed work',
                'Report leads, calls, forms, traffic, rankings, and GBP updates',
                'Set next month\'s priorities',
                'Send client update',
            ],
        ];
    }
}
