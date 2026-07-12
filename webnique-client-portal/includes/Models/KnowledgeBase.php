<?php
/**
 * Internal knowledge base and Telegram assistant audit log.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class KnowledgeBase
{
    private const SCHEMA_VERSION = '1';
    private const MAX_UPLOAD_BYTES = 5 * MB_IN_BYTES;
    private const MAX_DOCX_XML_BYTES = 2 * MB_IN_BYTES;
    private const MAX_CONTENT_LENGTH = 120000;
    private const MAX_AUDIT_ROWS = 500;
    private static bool $schema_ready = false;

    public static function ensureSchema(): void
    {
        if (self::$schema_ready) {
            return;
        }
        self::$schema_ready = true;
        if ((string)get_option('wnq_knowledge_schema_version', '') !== self::SCHEMA_VERSION) {
            self::createTables();
        }
    }

    public static function createTables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$wpdb->prefix}wnq_knowledge_items (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            category varchar(80) NOT NULL DEFAULT 'general',
            content longtext NOT NULL,
            source_name varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY category (category),
            KEY status (status),
            KEY updated_at (updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}wnq_telegram_ai_log (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question text NOT NULL,
            answer longtext DEFAULT NULL,
            matched_client_id varchar(100) DEFAULT NULL,
            sources longtext DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'answered',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY matched_client_id (matched_client_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;");

        update_option('wnq_knowledge_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function categories(): array
    {
        return [
            'onboarding' => 'Client Onboarding',
            'sop' => 'Standard Operating Procedure',
            'billing' => 'Billing',
            'money_management' => 'Money Management',
            'sales' => 'Sales',
            'seo' => 'SEO',
            'ads' => 'Google Ads',
            'website' => 'Website',
            'general' => 'General',
        ];
    }

    public static function getAll(string $search = ''): array
    {
        self::ensureSchema();
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_knowledge_items';
        $search = sanitize_text_field($search);
        if ($search === '') {
            return $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC", ARRAY_A) ?: [];
        }
        $like = '%' . $wpdb->esc_like($search) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE title LIKE %s OR category LIKE %s OR content LIKE %s ORDER BY updated_at DESC, id DESC",
            $like,
            $like,
            $like
        ), ARRAY_A) ?: [];
    }

    public static function getById(int $id): ?array
    {
        self::ensureSchema();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_knowledge_items WHERE id=%d",
            $id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function save(array $data, int $id = 0): int|false
    {
        self::ensureSchema();
        global $wpdb;
        $title = sanitize_text_field((string)($data['title'] ?? ''));
        $content = self::normalizeContent((string)($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            return false;
        }
        $category = sanitize_key((string)($data['category'] ?? 'general'));
        if (!isset(self::categories()[$category])) {
            $category = 'general';
        }
        $status = sanitize_key((string)($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'draft'], true)) {
            $status = 'active';
        }
        $record = [
            'title' => $title,
            'category' => $category,
            'content' => $content,
            'source_name' => sanitize_file_name((string)($data['source_name'] ?? '')),
            'status' => $status,
            'created_by' => get_current_user_id(),
        ];
        $table = $wpdb->prefix . 'wnq_knowledge_items';
        if ($id > 0) {
            unset($record['created_by']);
            $updated = $wpdb->update($table, $record, ['id' => $id]);
            return $updated === false ? false : $id;
        }
        $inserted = $wpdb->insert($table, $record);
        return $inserted === false ? false : (int)$wpdb->insert_id;
    }

    public static function delete(int $id): bool
    {
        self::ensureSchema();
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'wnq_knowledge_items', ['id' => $id], ['%d']) !== false;
    }

    public static function search(string $query, int $limit = 4): array
    {
        self::ensureSchema();
        global $wpdb;
        $limit = max(1, min(8, $limit));
        $terms = self::searchTerms($query);
        if ($terms === []) {
            return [];
        }
        $table = $wpdb->prefix . 'wnq_knowledge_items';
        $where = [];
        $args = [];
        foreach ($terms as $term) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where[] = '(title LIKE %s OR category LIKE %s OR content LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        $args[] = 40;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status='active' AND (" . implode(' OR ', $where) . ') ORDER BY updated_at DESC LIMIT %d',
            $args
        ), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $haystacks = [
                strtolower((string)($row['title'] ?? '')) => 8,
                strtolower((string)($row['category'] ?? '')) => 3,
                strtolower((string)($row['content'] ?? '')) => 1,
            ];
            $score = 0;
            foreach ($terms as $term) {
                foreach ($haystacks as $haystack => $weight) {
                    $score += substr_count($haystack, $term) * $weight;
                }
            }
            $row['_score'] = $score;
        }
        unset($row);
        usort($rows, static fn(array $left, array $right): int => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0));
        return array_slice($rows, 0, $limit);
    }

    public static function extractUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'content' => '', 'name' => ''];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return ['ok' => false, 'error' => 'The uploaded file could not be read.'];
        }
        if ((int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'error' => 'Knowledge files must be smaller than 5 MB.'];
        }

        $name = sanitize_file_name((string)($file['name'] ?? 'knowledge-file'));
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['txt', 'md', 'csv', 'json', 'html', 'htm', 'docx'];
        if (!in_array($extension, $allowed, true)) {
            return ['ok' => false, 'error' => 'Use a TXT, MD, CSV, JSON, HTML, or DOCX file.'];
        }

        $content = '';
        if ($extension === 'docx') {
            if (!class_exists('ZipArchive')) {
                return ['ok' => false, 'error' => 'DOCX extraction is unavailable on this server. Use TXT or paste the content instead.'];
            }
            $zip = new \ZipArchive();
            if ($zip->open((string)$file['tmp_name']) !== true) {
                return ['ok' => false, 'error' => 'The DOCX file is not valid.'];
            }
            $document_index = $zip->locateName('word/document.xml');
            $document_stat = $document_index === false ? false : $zip->statIndex($document_index);
            if (!is_array($document_stat) || (int)($document_stat['size'] ?? 0) > self::MAX_DOCX_XML_BYTES) {
                $zip->close();
                return ['ok' => false, 'error' => 'The DOCX document is too large to extract safely.'];
            }
            $xml = (string)$zip->getFromName('word/document.xml');
            $zip->close();
            $xml = str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $xml);
            $content = html_entity_decode(wp_strip_all_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } else {
            $raw = file_get_contents((string)$file['tmp_name']);
            if ($raw === false) {
                return ['ok' => false, 'error' => 'The uploaded file could not be opened.'];
            }
            $content = in_array($extension, ['html', 'htm'], true) ? wp_strip_all_tags($raw) : $raw;
        }

        $content = self::normalizeContent($content);
        if ($content === '') {
            return ['ok' => false, 'error' => 'No readable text was found in the uploaded file.'];
        }
        return ['ok' => true, 'content' => $content, 'name' => $name];
    }

    public static function logQuery(string $question, string $answer, string $client_id, array $sources, string $status): void
    {
        self::ensureSchema();
        global $wpdb;
        $table = $wpdb->prefix . 'wnq_telegram_ai_log';
        $wpdb->insert($table, [
            'question' => sanitize_textarea_field($question),
            'answer' => sanitize_textarea_field($answer),
            'matched_client_id' => sanitize_text_field($client_id),
            'sources' => wp_json_encode(array_values(array_map('sanitize_text_field', $sources))),
            'status' => sanitize_key($status),
        ]);
        if (wp_rand(1, 20) === 1) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY id DESC LIMIT %d) recent_rows)",
                self::MAX_AUDIT_ROWS
            ));
        }
    }

    public static function recentQueries(int $limit = 20): array
    {
        self::ensureSchema();
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_telegram_ai_log ORDER BY created_at DESC, id DESC LIMIT %d",
            max(1, min(100, $limit))
        ), ARRAY_A) ?: [];
    }

    private static function searchTerms(string $query): array
    {
        $query = strtolower(remove_accents(wp_strip_all_tags($query)));
        preg_match_all('/[a-z0-9]{3,}/', $query, $matches);
        $stop = ['the', 'and', 'for', 'that', 'this', 'with', 'from', 'what', 'when', 'where', 'who', 'how', 'hey', 'golden', 'please', 'someone', 'just', 'about'];
        return array_slice(array_values(array_unique(array_diff($matches[0] ?? [], $stop))), 0, 10);
    }

    private static function normalizeContent(string $content): string
    {
        $content = str_replace("\0", '', wp_check_invalid_utf8($content, true));
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/[ \t]+/', ' ', $content) ?? $content;
        $content = preg_replace('/\R{3,}/', "\n\n", $content) ?? $content;
        $content = trim($content);
        return function_exists('mb_substr') ? mb_substr($content, 0, self::MAX_CONTENT_LENGTH) : substr($content, 0, self::MAX_CONTENT_LENGTH);
    }
}
