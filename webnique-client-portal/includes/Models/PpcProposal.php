<?php
/**
 * Internal review records for read-only PPC recommendations.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) exit;

final class PpcProposal
{
    private const TABLE = 'wnq_ppc_proposals';
    private const SCHEMA_VERSION = '1';

    public static function createTable(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            customer_id varchar(20) NOT NULL,
            proposal_key char(64) NOT NULL,
            query_text text NOT NULL,
            campaign_id varchar(30) NOT NULL DEFAULT '',
            campaign_name varchar(255) NOT NULL DEFAULT '',
            ad_group_id varchar(30) NOT NULL DEFAULT '',
            classification varchar(40) NOT NULL,
            confidence decimal(5,4) NOT NULL DEFAULT 0,
            recommended_action varchar(40) NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            evidence_json longtext DEFAULT NULL,
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY proposal_key (proposal_key),
            KEY client_status (client_id,status),
            KEY customer_id (customer_id),
            KEY campaign_id (campaign_id)
        ) {$charset};");
        update_option('wnq_ppc_proposal_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybeUpgrade(): void
    {
        if ((string)get_option('wnq_ppc_proposal_schema_version', '') !== self::SCHEMA_VERSION) self::createTable();
    }

    public static function sync(string $client_id, string $customer_id, array $terms): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        foreach ($terms as $term) {
            $action = (string)($term['recommended_action'] ?? 'human_review');
            if (!in_array($action, ['negative_exact', 'negative_phrase', 'human_review', 'investigate', 'keep', 'watch', 'no_action', 'add_as_keyword'], true)) continue;
            $key = self::key($client_id, $customer_id, $term);
            $data = [
                'client_id' => sanitize_text_field($client_id),
                'customer_id' => preg_replace('/\D+/', '', $customer_id) ?: '',
                'proposal_key' => $key,
                'query_text' => sanitize_text_field((string)$term['query']),
                'campaign_id' => sanitize_text_field((string)$term['campaign_id']),
                'campaign_name' => sanitize_text_field((string)$term['campaign']),
                'ad_group_id' => sanitize_text_field((string)$term['ad_group_id']),
                'classification' => sanitize_key((string)$term['classification']),
                'confidence' => max(0, min(1, (float)$term['confidence'])),
                'recommended_action' => sanitize_key($action),
                'evidence_json' => wp_json_encode(['cost' => (float)$term['cost'], 'clicks' => (int)$term['clicks'], 'conversions' => (float)$term['conversions'], 'period' => 'last_30_days', 'original_ai_classification' => (string)($term['original_ai_classification'] ?? $term['classification'])]),
            ];
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE proposal_key = %s", $key));
            if ($existing) $wpdb->update($table, $data, ['id' => (int)$existing]);
            else $wpdb->insert($table, $data + ['status' => 'pending']);
        }
    }

    public static function key(string $client_id, string $customer_id, array $term): string
    {
        return hash('sha256', implode('|', [$client_id, $customer_id, strtolower((string)$term['query']), (string)$term['campaign_id'], (string)$term['ad_group_id'], (string)$term['recommended_action']]));
    }

    public static function statuses(string $client_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, proposal_key, status FROM {$table} WHERE client_id = %s", sanitize_text_field($client_id)), ARRAY_A);
        $result = [];
        foreach ((array)$rows as $row) $result[(string)$row['proposal_key']] = ['id' => (int)$row['id'], 'status' => (string)$row['status']];
        return $result;
    }

    public static function getByIdForClient(int $id, string $client_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND client_id = %s LIMIT 1",
            $id,
            sanitize_text_field($client_id)
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $evidence = json_decode((string)($row['evidence_json'] ?? ''), true);
        $row['evidence'] = is_array($evidence) ? $evidence : [];
        unset($row['evidence_json']);
        return $row;
    }

    public static function getByIdsForClient(array $ids, string $client_id): array
    {
        $result = [];
        foreach (array_values(array_unique(array_filter(array_map('absint', $ids)))) as $id) {
            $row = self::getByIdForClient($id, $client_id);
            if ($row) $result[] = $row;
        }
        return $result;
    }

    public static function review(string $client_id, array $ids, string $status, string $reason = ''): int
    {
        global $wpdb;
        $allowed = ['approved_exact', 'approved_phrase', 'ignored', 'rejected', 'relevant'];
        if (!in_array($status, $allowed, true) || trim($reason) === '') return 0;
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) return 0;
        $connection = PpcAccount::getByClientId($client_id);
        $customer_id = (string)($connection['customer_id'] ?? '');
        if (!preg_match('/^\d{10}$/', $customer_id) || count($ids) > 500) return 0;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->query('START TRANSACTION') === false) return 0;
        try {
            foreach ($ids as $id) {
                $proposal = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND client_id = %s AND customer_id = %s FOR UPDATE", $id, $client_id, $customer_id), ARRAY_A);
                if (!$proposal) throw new \RuntimeException('The proposal no longer matches the linked account.');
                $proposal['evidence'] = json_decode((string)($proposal['evidence_json'] ?? ''), true) ?: [];
                if (!PpcMemory::recordFeedback($client_id, $customer_id, $proposal, $status, $reason)) throw new \RuntimeException('Feedback could not be stored.');
                if ($wpdb->update($table, ['status'=>$status, 'reviewed_by'=>get_current_user_id(), 'reviewed_at'=>current_time('mysql')], ['id'=>$id, 'client_id'=>$client_id, 'customer_id'=>$customer_id]) === false) throw new \RuntimeException('Review could not be stored.');
            }
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Review could not be committed.');
            return count($ids);
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return 0;
        }
    }
}
