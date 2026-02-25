<?php
/**
 * Task Model - ULTRA ENHANCED VERSION
 * 
 * Complete task management with:
 * - Task categories (client/webnique/general)
 * - Archive system
 * - Recurring tasks
 * - Completion tracking
 * - Advanced queries
 * 
 * @package WebNique Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class Task
{
    private static string $table = 'wnq_tasks';

    /**
     * Create tasks table with ALL enhanced fields
     */
    public static function createTable(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description longtext DEFAULT NULL,
            status varchar(50) DEFAULT 'todo',
            task_type varchar(50) DEFAULT 'general',
            priority varchar(50) DEFAULT 'medium',
            assigned_to varchar(255) DEFAULT NULL,
            due_date date DEFAULT NULL,
            client_id varchar(100) DEFAULT NULL,
            attachments longtext DEFAULT NULL,
            notes longtext DEFAULT NULL,
            is_recurring tinyint(1) DEFAULT 0,
            recurring_frequency varchar(50) DEFAULT NULL,
            recurring_data longtext DEFAULT NULL,
            position int(11) DEFAULT 0,
            created_by bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            archived_at datetime DEFAULT NULL,
            completion_count int(11) DEFAULT 0,
            last_completed_date date DEFAULT NULL,
            
            PRIMARY KEY (id),
            KEY status (status),
            KEY task_type (task_type),
            KEY assigned_to (assigned_to),
            KEY priority (priority),
            KEY due_date (due_date),
            KEY is_recurring (is_recurring),
            KEY archived_at (archived_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get all tasks with filters
     */
    public static function getAll(array $filters = []): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $where = ['1=1'];
        $params = [];

        // Exclude archived by default
        if (!isset($filters['include_archived'])) {
            $where[] = 'archived_at IS NULL';
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['task_type'])) {
            $where[] = 'task_type = %s';
            $params[] = $filters['task_type'];
        }

        if (!empty($filters['assigned_to'])) {
            $where[] = 'assigned_to = %s';
            $params[] = $filters['assigned_to'];
        }

        if (!empty($filters['priority'])) {
            $where[] = 'priority = %s';
            $params[] = $filters['priority'];
        }

        if (!empty($filters['client_id'])) {
            $where[] = 'client_id = %s';
            $params[] = $filters['client_id'];
        }

        if (isset($filters['is_recurring'])) {
            $where[] = 'is_recurring = %d';
            $params[] = $filters['is_recurring'] ? 1 : 0;
        }

        $where_sql = implode(' AND ', $where);
        $order_by = 'ORDER BY position ASC, created_at DESC';

        if (empty($params)) {
            $results = $wpdb->get_results(
                "SELECT * FROM $table_name WHERE $where_sql $order_by",
                ARRAY_A
            );
        } else {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE $where_sql $order_by",
                    ...$params
                ),
                ARRAY_A
            );
        }

        return $results ?: [];
    }

    /**
     * Get task by ID
     */
    public static function getById(int $id): ?array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Create new task
     */
    public static function create(array $data): int|false
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        if (empty($data['title'])) {
            return false;
        }

        $insert_data = [
            'title' => sanitize_text_field($data['title']),
            'description' => wp_kses_post($data['description'] ?? ''),
            'status' => sanitize_text_field($data['status'] ?? 'todo'),
            'task_type' => sanitize_text_field($data['task_type'] ?? 'general'),
            'priority' => sanitize_text_field($data['priority'] ?? 'medium'),
            'assigned_to' => sanitize_text_field($data['assigned_to'] ?? ''),
            'due_date' => !empty($data['due_date']) ? sanitize_text_field($data['due_date']) : null,
            'client_id' => !empty($data['client_id']) ? sanitize_text_field($data['client_id']) : null,
            'notes' => wp_kses_post($data['notes'] ?? ''),
            'position' => isset($data['position']) ? intval($data['position']) : 0,
            'created_by' => get_current_user_id(),
            'is_recurring' => !empty($data['is_recurring']) ? 1 : 0,
            'recurring_frequency' => !empty($data['recurring_frequency']) ? sanitize_text_field($data['recurring_frequency']) : null,
        ];

        if (!empty($data['recurring_data'])) {
            $insert_data['recurring_data'] = is_array($data['recurring_data']) 
                ? wp_json_encode($data['recurring_data'])
                : $data['recurring_data'];
        }

        $result = $wpdb->insert($table_name, $insert_data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update task
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $update_data = [];

        if (isset($data['title'])) {
            $update_data['title'] = sanitize_text_field($data['title']);
        }
        if (isset($data['description'])) {
            $update_data['description'] = wp_kses_post($data['description']);
        }
        if (isset($data['status'])) {
            $update_data['status'] = sanitize_text_field($data['status']);
            
            if ($data['status'] === 'done' && !isset($data['completed_at'])) {
                $update_data['completed_at'] = current_time('mysql');
            }
        }
        if (isset($data['task_type'])) {
            $update_data['task_type'] = sanitize_text_field($data['task_type']);
        }
        if (isset($data['priority'])) {
            $update_data['priority'] = sanitize_text_field($data['priority']);
        }
        if (isset($data['assigned_to'])) {
            $update_data['assigned_to'] = sanitize_text_field($data['assigned_to']);
        }
        if (isset($data['due_date'])) {
            $update_data['due_date'] = !empty($data['due_date']) 
                ? sanitize_text_field($data['due_date']) 
                : null;
        }
        if (isset($data['client_id'])) {
            $update_data['client_id'] = sanitize_text_field($data['client_id']);
        }
        if (isset($data['notes'])) {
            $update_data['notes'] = wp_kses_post($data['notes']);
        }
        if (isset($data['position'])) {
            $update_data['position'] = intval($data['position']);
        }
        if (isset($data['is_recurring'])) {
            $update_data['is_recurring'] = !empty($data['is_recurring']) ? 1 : 0;
        }
        if (isset($data['recurring_frequency'])) {
            $update_data['recurring_frequency'] = sanitize_text_field($data['recurring_frequency']);
        }
        if (isset($data['recurring_data'])) {
            $update_data['recurring_data'] = is_array($data['recurring_data'])
                ? wp_json_encode($data['recurring_data'])
                : $data['recurring_data'];
        }
        if (isset($data['archived_at'])) {
            $update_data['archived_at'] = $data['archived_at'];
        }
        if (isset($data['completion_count'])) {
            $update_data['completion_count'] = intval($data['completion_count']);
        }
        if (isset($data['last_completed_date'])) {
            $update_data['last_completed_date'] = $data['last_completed_date'];
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $id],
            null,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete task
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $result = $wpdb->delete(
            $table_name,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Archive task
     */
    public static function archive(int $id): bool
    {
        return self::update($id, ['archived_at' => current_time('mysql')]);
    }

    /**
     * Restore from archive
     */
    public static function restore(int $id): bool
    {
        return self::update($id, ['archived_at' => null]);
    }

    /**
     * Get archived tasks
     */
    public static function getArchived(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $results = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE archived_at IS NOT NULL ORDER BY archived_at DESC",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get tasks by status
     */
    public static function getByStatus(string $status): array
    {
        return self::getAll(['status' => $status]);
    }

    /**
     * Get tasks by type
     */
    public static function getByType(string $type): array
    {
        return self::getAll(['task_type' => $type]);
    }

    /**
     * Get recurring tasks
     */
    public static function getRecurring(): array
    {
        return self::getAll(['is_recurring' => 1]);
    }

    /**
     * Get task counts by status
     */
    public static function getCountsByStatus(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name WHERE archived_at IS NULL GROUP BY status",
            ARRAY_A
        );

        $counts = [
            'todo' => 0,
            'in_progress' => 0,
            'review' => 0,
            'done' => 0,
        ];

        foreach ($results as $row) {
            $counts[$row['status']] = intval($row['count']);
        }

        return $counts;
    }

    /**
     * Get counts by type
     */
    public static function getCountsByType(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $results = $wpdb->get_results(
            "SELECT task_type, COUNT(*) as count FROM $table_name WHERE archived_at IS NULL GROUP BY task_type",
            ARRAY_A
        );

        $counts = [
            'client' => 0,
            'webnique' => 0,
            'general' => 0,
        ];

        foreach ($results as $row) {
            $counts[$row['task_type']] = intval($row['count']);
        }

        return $counts;
    }

    /**
     * Get overdue tasks
     */
    public static function getOverdue(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE due_date < %s 
                AND status != 'done' 
                AND archived_at IS NULL
                ORDER BY due_date ASC",
                current_time('Y-m-d')
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get tasks due this week
     */
    public static function getDueThisWeek(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table;

        $today = current_time('Y-m-d');
        $week_end = date('Y-m-d', strtotime('+7 days'));

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE due_date BETWEEN %s AND %s
                AND archived_at IS NULL
                ORDER BY due_date ASC",
                $today,
                $week_end
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Mark recurring task as complete
     */
    public static function completeRecurring(int $id): bool
    {
        $task = self::getById($id);
        if (!$task || !$task['is_recurring']) {
            return false;
        }

        $update_data = [
            'completion_count' => intval($task['completion_count'] ?? 0) + 1,
            'last_completed_date' => current_time('Y-m-d'),
        ];

        return self::update($id, $update_data);
    }

    /**
     * Check if recurring task needs reset
     */
    public static function checkRecurringReset(int $id): bool
    {
        $task = self::getById($id);
        if (!$task || !$task['is_recurring']) {
            return false;
        }

        $last_completed = $task['last_completed_date'];
        if (!$last_completed) {
            return false;
        }

        $frequency = $task['recurring_frequency'];
        $today = current_time('Y-m-d');

        // Check if it's time to reset
        switch ($frequency) {
            case 'daily':
                return $last_completed < $today;
            case 'weekly':
                $days_diff = (strtotime($today) - strtotime($last_completed)) / (60 * 60 * 24);
                return $days_diff >= 7;
            case 'monthly':
                $month_diff = (date('Y', strtotime($today)) - date('Y', strtotime($last_completed))) * 12
                    + (date('m', strtotime($today)) - date('m', strtotime($last_completed)));
                return $month_diff >= 1;
            default:
                return false;
        }
    }
}