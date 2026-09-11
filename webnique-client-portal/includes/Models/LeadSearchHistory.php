<?php
namespace WNQ\Models;
if (!defined('ABSPATH')) { exit; }

final class LeadSearchHistory
{
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'wnq_lead_search_history'; }
    public static function install(): void
    {
        if (get_option('wnq_lead_search_schema') === '1') { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_key varchar(64) NOT NULL,
            keyword varchar(100) NOT NULL,
            keyword_key varchar(64) NOT NULL,
            zip varchar(5) NOT NULL,
            user_id bigint unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'started',
            started_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            found int unsigned NOT NULL DEFAULT 0,
            saved int unsigned NOT NULL DEFAULT 0,
            emails int unsigned NOT NULL DEFAULT 0,
            duplicates int unsigned NOT NULL DEFAULT 0,
            coverage varchar(20) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY run_key (run_key),
            KEY search_key (keyword_key,zip)
        ) {$wpdb->get_charset_collate()};");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) { update_option('wnq_lead_search_schema', '1', false); }
    }
    public static function keyword(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim(sanitize_text_field($value)));
        if ($value === '' || strlen($value) > 100) { throw new \RuntimeException('Enter a keyword of 1–100 characters.'); }
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
    public static function zips(string $value): array
    {
        if (strlen($value) > 4000) { throw new \RuntimeException('Enter no more than 100 ZIP codes.'); }
        $items = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!$items) { throw new \RuntimeException('Enter at least one five-digit ZIP code.'); }
        foreach ($items as $zip) { if (!preg_match('/^\d{5}$/D', $zip)) { throw new \RuntimeException('Use five-digit ZIP codes separated by commas, spaces or new lines.'); } }
        $items = array_values(array_unique($items));
        if (count($items) > 100) { throw new \RuntimeException('Use up to 100 unique ZIP codes per batch.'); }
        return $items;
    }
    public static function check(string $keyword, array $zips): array
    {
        global $wpdb;
        $keyword = self::keyword($keyword);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT zip,status,started_at,finished_at,found,saved,emails,duplicates,coverage FROM ' . self::table() . ' WHERE id IN (SELECT MAX(id) FROM ' . self::table() . ' WHERE keyword_key=%s GROUP BY zip)', hash('sha256', $keyword)), ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('Search history could not be read. No search started; retry after checking the database.'); }
        $prior = [];
        foreach ($rows ?: [] as $row) { if (!isset($prior[$row['zip']])) { $prior[$row['zip']] = $row; } }
        // Before this feature, successful imports already saved keyword/ZIP in notes.
        // Treat that as evidence of a prior search, NOT proof the ZIP finished.
        $legacy = $wpdb->get_results("SELECT notes,scraped_at FROM {$wpdb->prefix}wnq_leads WHERE notes LIKE '%Search:%' ORDER BY id DESC LIMIT 10000", ARRAY_A) ?: [];
        if ($wpdb->last_error) { throw new \RuntimeException('Previous lead searches could not be checked. No search started.'); }
        foreach ($legacy as $lead) {
            if (preg_match('/(?:^|\n)Search: (.+) in (\d{5})(?:\r?\n|$)/', $lead['notes'] ?? '', $m)) {
                try { $same = self::keyword($m[1]) === $keyword; } catch (\RuntimeException $e) { $same = false; }
                if ($same && !isset($prior[$m[2]])) { $prior[$m[2]] = ['status' => 'legacy', 'started_at' => $lead['scraped_at'], 'coverage' => 'unknown']; }
            }
        }
        return array_map(static fn($zip) => ['zip' => $zip, 'previous' => $prior[$zip] ?? null], $zips);
    }
    public static function begin(string $run, string $keyword, string $zip): void
    {
        global $wpdb;
        $keyword = self::keyword($keyword);
        if (!preg_match('/^[a-f0-9-]{36}$/D', $run) || self::zips($zip) !== [$zip]) { throw new \RuntimeException('Invalid search identifier or ZIP.'); }
        $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE run_key=%s', $run), ARRAY_A);
        if ($old) {
            if ($old['keyword_key'] !== hash('sha256', $keyword) || $old['zip'] !== $zip || (int)$old['user_id'] !== get_current_user_id()) { throw new \RuntimeException('Search history does not match this run.'); }
            return;
        }
        if ($wpdb->insert(self::table(), ['run_key' => $run, 'keyword' => $keyword, 'keyword_key' => hash('sha256', $keyword), 'zip' => $zip, 'user_id' => get_current_user_id(), 'started_at' => gmdate('Y-m-d H:i:s')]) !== 1) { throw new \RuntimeException('Search history could not be saved. No search started.'); }
    }
    public static function finish(string $run, array $stats): void
    {
        global $wpdb;
        $data = ['status' => 'completed', 'finished_at' => gmdate('Y-m-d H:i:s'), 'coverage' => !empty($stats['limited']) ? 'limited' : 'list_end'];
        foreach (['found', 'saved', 'emails', 'duplicates'] as $field) { $data[$field] = max(0, min(100, (int)($stats[$field] ?? 0))); }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE run_key=%s', $run), ARRAY_A);
        if (!$row || (int)$row['user_id'] !== get_current_user_id()) { throw new \RuntimeException('Search history not found for this user.'); }
        if ($row['status'] === 'completed') { return; }
        if ($wpdb->update(self::table(), $data, ['run_key' => $run, 'user_id' => get_current_user_id()]) === false) { throw new \RuntimeException('Could not record completion. Resume to retry before the next ZIP.'); }
    }
}
