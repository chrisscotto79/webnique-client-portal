<?php
/**
 * Blog Scheduler Model
 *
 * Database operations for the blog auto-publishing system.
 *
 * Tables:
 *  wnq_blog_schedule      - Post queue (titles, status, scheduled dates, generated content)
 *  wnq_hub_notifications  - In-hub notification log
 *
 * @package WebNique Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogScheduler
{
    /* ═══════════════════════════════════════════
     *  TABLE CREATION
     * ═══════════════════════════════════════════ */

    public static function createTables(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_blog_schedule (
            id               bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id        varchar(100) NOT NULL,
            agent_key_id     bigint(20) DEFAULT NULL COMMENT 'FK to wnq_seo_agent_keys.id — target site',
            title            varchar(500) NOT NULL,
            category_type    varchar(50) DEFAULT 'Informational' COMMENT 'Informational',
            focus_keyword    varchar(255) DEFAULT NULL,
            featured_image_url varchar(1000) DEFAULT NULL,
            scheduled_date   date DEFAULT NULL,
            status           varchar(30) DEFAULT 'pending' COMMENT 'pending|generating|publishing|published|failed',
            generated_title  varchar(500) DEFAULT NULL,
            generated_meta   text DEFAULT NULL,
            generated_body   longtext DEFAULT NULL,
            generated_toc    text DEFAULT NULL,
            elementor_json   longtext DEFAULT NULL,
            wp_post_id       bigint(20) DEFAULT NULL,
            wp_post_url      varchar(1000) DEFAULT NULL,
            error_message    text DEFAULT NULL,
            internal_links   longtext DEFAULT NULL COMMENT 'Legacy unused link data',
            external_citation varchar(1000) DEFAULT NULL COMMENT 'Legacy unused citation data',
            tokens_used      int(11) DEFAULT 0,
            created_at       datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at       datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY agent_key_id (agent_key_id),
            KEY status (status),
            KEY scheduled_date (scheduled_date)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_hub_notifications (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id   varchar(100) DEFAULT NULL,
            type        varchar(50) NOT NULL COMMENT 'blog_published|sync_complete|error',
            title       varchar(500) NOT NULL,
            message     text DEFAULT NULL,
            url         varchar(1000) DEFAULT NULL,
            is_read     tinyint(1) DEFAULT 0,
            created_at  datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY is_read (is_read),
            KEY created_at (created_at)
        ) $c;");
    }

    /* ═══════════════════════════════════════════
     *  BLOG SCHEDULE CRUD
     * ═══════════════════════════════════════════ */

    public static function addPost(string $client_id, array $data): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wnq_blog_schedule',
            [
                'client_id'      => $client_id,
                'agent_key_id'   => !empty($data['agent_key_id']) ? (int)$data['agent_key_id'] : null,
                'title'          => sanitize_text_field($data['title'] ?? ''),
                'category_type'  => 'Informational',
                'focus_keyword'  => sanitize_text_field($data['focus_keyword'] ?? ''),
                'featured_image_url' => esc_url_raw($data['featured_image_url'] ?? ''),
                'scheduled_date' => !empty($data['scheduled_date']) ? sanitize_text_field($data['scheduled_date']) : null,
                'status'         => 'pending',
            ]
        );
        return (int)$wpdb->insert_id;
    }

    /**
     * Return all active agent sites for a client (from wnq_seo_agent_keys).
     * Used to populate the site selector in the blog scheduler UI.
     */
    public static function getClientAgents(string $client_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, site_url, site_name FROM {$wpdb->prefix}wnq_seo_agent_keys
                 WHERE client_id = %s AND status = 'active'
                 ORDER BY created_at ASC",
                $client_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function updatePost(int $id, array $data): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wnq_blog_schedule',
            $data,
            ['id' => $id]
        );
    }

    public static function deletePost(int $id): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wnq_blog_schedule', ['id' => $id]);
    }

    public static function deletePosts(array $ids, string $client_id = ''): int
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = $ids;
        $where_client = '';
        if ($client_id !== '') {
            $where_client = ' AND client_id = %s';
            $params[] = $client_id;
        }

        return (int)$wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}wnq_blog_schedule WHERE id IN ($placeholders)$where_client",
                $params
            )
        );
    }

    public static function deletePostsByClient(string $client_id): int
    {
        global $wpdb;
        if ($client_id === '') {
            return 0;
        }
        return (int)$wpdb->delete($wpdb->prefix . 'wnq_blog_schedule', ['client_id' => $client_id]);
    }

    public static function getPost(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_blog_schedule WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function getPostsByClient(string $client_id, int $limit = 50): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_blog_schedule
                 WHERE client_id = %s
                 ORDER BY scheduled_date ASC, created_at DESC
                 LIMIT %d",
                $client_id,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function getAllPosts(int $limit = 100): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_blog_schedule ORDER BY scheduled_date ASC, created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get posts that are due today or overdue and still pending.
     */
    public static function getDuePosts(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_blog_schedule
                 WHERE status = 'pending' AND scheduled_date <= %s
                 ORDER BY scheduled_date ASC
                 LIMIT 10",
                date('Y-m-d')
            ),
            ARRAY_A
        ) ?: [];
    }

    /* ═══════════════════════════════════════════
     *  NOTIFICATIONS
     * ═══════════════════════════════════════════ */

    public static function addNotification(string $type, string $title, string $message = '', string $url = '', string $client_id = ''): void
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wnq_hub_notifications',
            [
                'client_id' => $client_id,
                'type'      => $type,
                'title'     => $title,
                'message'   => $message,
                'url'       => $url,
                'is_read'   => 0,
            ]
        );
    }

    public static function getNotifications(int $limit = 50, bool $unread_only = false): array
    {
        global $wpdb;
        $where = $unread_only ? 'WHERE is_read = 0' : '';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_hub_notifications {$where} ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function markRead(int $id): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'wnq_hub_notifications', ['is_read' => 1], ['id' => $id]);
    }

    public static function markAllRead(): void
    {
        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->prefix}wnq_hub_notifications SET is_read = 1");
    }

    public static function getUnreadCount(): int
    {
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wnq_hub_notifications WHERE is_read = 0");
    }

}
