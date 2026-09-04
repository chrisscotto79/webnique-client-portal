<?php
/**
 * Approval-gated PPC mutation previews and their append-only audit history.
 *
 * This model records authorization only. It deliberately contains no Google Ads
 * mutation client or execution method.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcMutationPlan
{
    private const TABLE = 'wnq_ppc_mutation_plans';
    private const EVENT_TABLE = 'wnq_ppc_mutation_events';
    private const SCHEMA_VERSION = '1';

    private const OPERATIONS = [
        'budget_update'            => 'Update campaign budget',
        'status_update'            => 'Change entity status',
        'negative_keyword_add'     => 'Add negative keyword',
        'negative_keyword_remove'  => 'Remove negative keyword',
        'ad_copy_update'           => 'Update ad copy',
        'conversion_action_update' => 'Update conversion action',
        'name_update'              => 'Rename entity',
        'other'                    => 'Other controlled change',
    ];

    private const ENTITY_TYPES = [
        'campaign'          => 'Campaign',
        'campaign_budget'   => 'Campaign budget',
        'ad_group'          => 'Ad group',
        'keyword'           => 'Keyword',
        'negative_keyword'  => 'Negative keyword',
        'responsive_search_ad' => 'Responsive Search Ad',
        'conversion_action' => 'Conversion action',
        'shared_set'        => 'Shared set',
        'other'             => 'Other exact entity',
    ];

    private const REVERSIBILITY = [
        'reversible'   => 'Reversible',
        'conditional'  => 'Conditionally reversible',
        'irreversible' => 'Irreversible',
    ];

    public static function createTables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $plans = $wpdb->prefix . self::TABLE;
        $events = $wpdb->prefix . self::EVENT_TABLE;
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$plans} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            proposal_uuid char(36) NOT NULL,
            client_id varchar(100) NOT NULL,
            customer_id varchar(20) NOT NULL,
            account_name varchar(255) NOT NULL DEFAULT '',
            operation varchar(50) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id varchar(255) NOT NULL,
            entity_name varchar(255) NOT NULL DEFAULT '',
            current_state_json longtext NOT NULL,
            proposed_state_json longtext NOT NULL,
            evidence_json longtext DEFAULT NULL,
            reversibility varchar(20) NOT NULL,
            rollback_plan text NOT NULL,
            idempotency_key char(64) NOT NULL,
            content_hash char(64) NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'awaiting_approval',
            created_by bigint(20) UNSIGNED NOT NULL,
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY proposal_uuid (proposal_uuid),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY client_status (client_id,status),
            KEY customer_id (customer_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) UNSIGNED NOT NULL,
            event_type varchar(40) NOT NULL,
            actor_id bigint(20) UNSIGNED NOT NULL,
            details_json longtext DEFAULT NULL,
            previous_event_hash char(64) NOT NULL DEFAULT '',
            event_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY plan_id (plan_id),
            KEY event_type (event_type)
        ) {$charset};");

        update_option('wnq_ppc_mutation_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybeUpgrade(): void
    {
        if ((string)get_option('wnq_ppc_mutation_schema_version', '') !== self::SCHEMA_VERSION) {
            self::createTables();
        }
    }

    public static function create(string $client_id, array $connection, array $input): array
    {
        global $wpdb;
        $client_id = sanitize_text_field($client_id);
        $customer_id = self::customerId((string)($connection['customer_id'] ?? ''));
        $canonical = PpcAccount::getByClientId($client_id);
        if ($client_id === '' || strlen($customer_id) !== 10 || !$canonical || !hash_equals($customer_id, self::customerId((string)$canonical['customer_id']))) {
            return ['ok' => false, 'message' => 'The exact saved client and Google Ads account mapping could not be verified.'];
        }

        $operation = sanitize_key((string)($input['operation'] ?? ''));
        $entity_type = sanitize_key((string)($input['entity_type'] ?? ''));
        $entity_id = sanitize_text_field((string)($input['entity_id'] ?? ''));
        $entity_name = sanitize_text_field((string)($input['entity_name'] ?? ''));
        $current_value = sanitize_textarea_field((string)($input['current_value'] ?? ''));
        $proposed_value = sanitize_textarea_field((string)($input['proposed_value'] ?? ''));
        $evidence = sanitize_textarea_field((string)($input['evidence'] ?? ''));
        $reversibility = sanitize_key((string)($input['reversibility'] ?? ''));
        $rollback_plan = sanitize_textarea_field((string)($input['rollback_plan'] ?? ''));
        $request_token = sanitize_text_field((string)($input['request_token'] ?? ''));

        if (!isset(self::OPERATIONS[$operation]) || !isset(self::ENTITY_TYPES[$entity_type]) || !isset(self::REVERSIBILITY[$reversibility])) {
            return ['ok' => false, 'message' => 'Choose a valid operation, entity type, and reversibility classification.'];
        }
        if ($entity_id === '' || $current_value === '' || $proposed_value === '' || hash_equals($current_value, $proposed_value)) {
            return ['ok' => false, 'message' => 'Exact entity ID and different current and proposed values are required.'];
        }
        if ($rollback_plan === '') {
            return ['ok' => false, 'message' => 'Document the rollback plan or explain why this change cannot be reversed.'];
        }
        if (!preg_match('/^[a-f0-9-]{36}$/i', $request_token)) {
            return ['ok' => false, 'message' => 'The preview request token is invalid. Refresh and try again.'];
        }
        if ($reversibility === 'irreversible' && empty($input['irreversible_ack'])) {
            return ['ok' => false, 'message' => 'Explicitly acknowledge an irreversible proposal before creating its preview.'];
        }

        $content = [
            'client_id'      => $client_id,
            'customer_id'    => $customer_id,
            'operation'      => $operation,
            'entity_type'    => $entity_type,
            'entity_id'      => $entity_id,
            'entity_name'    => $entity_name,
            'current_value'  => $current_value,
            'proposed_value' => $proposed_value,
            'reversibility'  => $reversibility,
            'rollback_plan'  => $rollback_plan,
        ];
        $content_hash = self::contentHash($content);
        $idempotency_key = hash('sha256', 'ppc-plan-v1|' . $content_hash . '|' . strtolower($request_token));
        $plans = $wpdb->prefix . self::TABLE;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$plans} WHERE idempotency_key = %s", $idempotency_key));
        if ($existing) {
            return ['ok' => false, 'message' => 'This preview request was already processed. Review the existing plan instead.', 'id' => (int)$existing];
        }
        $active_duplicate = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$plans} WHERE content_hash = %s AND status IN ('awaiting_approval','approved') AND expires_at >= %s ORDER BY id DESC LIMIT 1",
            $content_hash,
            current_time('mysql')
        ));
        if ($active_duplicate) {
            return ['ok' => false, 'message' => 'An identical active mutation preview already exists. Review that plan instead.', 'id' => (int)$active_duplicate];
        }

        $now = current_time('mysql');
        $expires_at = gmdate('Y-m-d H:i:s', (int)current_time('timestamp') + (7 * DAY_IN_SECONDS));
        $wpdb->query('START TRANSACTION');
        $saved = $wpdb->insert($plans, [
            'proposal_uuid'       => wp_generate_uuid4(),
            'client_id'           => $client_id,
            'customer_id'         => $customer_id,
            'account_name'        => sanitize_text_field((string)($canonical['account_name'] ?? '')),
            'operation'           => $operation,
            'entity_type'         => $entity_type,
            'entity_id'           => $entity_id,
            'entity_name'         => $entity_name,
            'current_state_json'  => wp_json_encode(['value' => $current_value]),
            'proposed_state_json' => wp_json_encode(['value' => $proposed_value]),
            'evidence_json'       => wp_json_encode(['reason' => $evidence]),
            'reversibility'       => $reversibility,
            'rollback_plan'       => $rollback_plan,
            'idempotency_key'     => $idempotency_key,
            'content_hash'        => $content_hash,
            'status'              => 'awaiting_approval',
            'created_by'          => get_current_user_id(),
            'expires_at'          => $expires_at,
            'created_at'          => $now,
        ]);
        if ($saved === false) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'message' => 'The mutation preview could not be stored.'];
        }

        $plan_id = (int)$wpdb->insert_id;
        if (!self::appendEvent($plan_id, 'preview_created', ['content_hash' => $content_hash, 'status' => 'awaiting_approval'])) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'message' => 'The mutation preview audit record could not be stored.'];
        }
        $wpdb->query('COMMIT');
        return ['ok' => true, 'message' => 'Mutation preview created. Nothing was sent to Google Ads.', 'id' => $plan_id];
    }

    public static function review(string $client_id, int $plan_id, string $decision, string $confirmation, string $expected_hash): array
    {
        global $wpdb;
        $decision = sanitize_key($decision);
        if (!self::confirmationMatches($decision, $confirmation)) {
            return ['ok' => false, 'message' => 'Type the exact confirmation word shown for this decision.'];
        }

        $plans = $wpdb->prefix . self::TABLE;
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$plans} WHERE id = %d AND client_id = %s LIMIT 1", $plan_id, sanitize_text_field($client_id)), ARRAY_A);
        if (!$plan || (string)$plan['status'] !== 'awaiting_approval') {
            return ['ok' => false, 'message' => 'This preview is missing or no longer awaiting approval.'];
        }
        if (!hash_equals((string)$plan['content_hash'], $expected_hash)) {
            return ['ok' => false, 'message' => 'The preview changed or is stale. Refresh and review it again.'];
        }
        if ((string)$plan['expires_at'] < current_time('mysql')) {
            $wpdb->query('START TRANSACTION');
            $expired = $wpdb->update($plans, ['status' => 'expired'], ['id' => $plan_id, 'status' => 'awaiting_approval']);
            $audited = (int)$expired === 1 && self::appendEvent($plan_id, 'preview_expired', ['content_hash' => (string)$plan['content_hash']]);
            $wpdb->query($audited ? 'COMMIT' : 'ROLLBACK');
            return ['ok' => false, 'message' => 'This preview expired. Create a new preview from current account data.'];
        }

        $connection = PpcAccount::getByClientId((string)$plan['client_id']);
        if (!$connection || !hash_equals(self::customerId((string)$plan['customer_id']), self::customerId((string)$connection['customer_id'] ?? ''))) {
            return ['ok' => false, 'message' => 'The client-to-account mapping changed. Approval was blocked.'];
        }

        $wpdb->query('START TRANSACTION');
        $updated = $wpdb->update($plans, [
            'status'      => $decision,
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
        ], [
            'id'           => $plan_id,
            'client_id'    => sanitize_text_field($client_id),
            'customer_id'  => (string)$plan['customer_id'],
            'content_hash' => (string)$plan['content_hash'],
            'status'       => 'awaiting_approval',
        ]);
        if ((int)$updated !== 1) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'message' => 'The preview was updated elsewhere. Refresh before reviewing it.'];
        }

        if (!self::appendEvent($plan_id, 'preview_' . $decision, ['content_hash' => (string)$plan['content_hash'], 'status' => $decision])) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'message' => 'The audit decision could not be stored, so the plan was not changed.'];
        }
        $wpdb->query('COMMIT');
        $message = $decision === 'approved'
            ? 'Plan approved locally. Execution remains disabled; no Google Ads change was made.'
            : 'Plan ' . $decision . '. No Google Ads change was made.';
        return ['ok' => true, 'message' => $message];
    }

    public static function forClient(string $client_id, int $limit = 25): array
    {
        global $wpdb;
        $plans = $wpdb->prefix . self::TABLE;
        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$plans} WHERE client_id = %s ORDER BY id DESC LIMIT %d", sanitize_text_field($client_id), $limit), ARRAY_A);
        foreach ((array)$rows as &$row) {
            if (in_array((string)$row['status'], ['awaiting_approval', 'approved'], true) && (string)$row['expires_at'] < current_time('mysql')) {
                $row['stored_status'] = (string)$row['status'];
                $row['status'] = 'expired';
            }
            $row['current_state'] = self::decode((string)$row['current_state_json']);
            $row['proposed_state'] = self::decode((string)$row['proposed_state_json']);
            $row['evidence'] = self::decode((string)($row['evidence_json'] ?? ''));
            $row['events'] = self::events((int)$row['id']);
            $row['audit_valid'] = self::verifyEventChain($row['events']);
            unset($row['current_state_json'], $row['proposed_state_json'], $row['evidence_json'], $row['idempotency_key']);
        }
        unset($row);
        return (array)$rows;
    }

    public static function operations(): array
    {
        return self::OPERATIONS;
    }

    public static function entityTypes(): array
    {
        return self::ENTITY_TYPES;
    }

    public static function reversibilityOptions(): array
    {
        return self::REVERSIBILITY;
    }

    public static function contentHash(array $content): string
    {
        ksort($content);
        return hash('sha256', wp_json_encode($content));
    }

    public static function confirmationMatches(string $decision, string $confirmation): bool
    {
        $required_words = ['approved' => 'APPROVE', 'rejected' => 'REJECT', 'cancelled' => 'CANCEL'];
        $decision = sanitize_key($decision);
        return isset($required_words[$decision]) && hash_equals($required_words[$decision], trim($confirmation));
    }

    public static function verifyEventChain(array $events): bool
    {
        $previous = '';
        foreach ($events as $event) {
            if (!hash_equals($previous, (string)($event['previous_event_hash'] ?? ''))) {
                return false;
            }
            $expected = self::eventHash(
                $previous,
                (int)($event['plan_id'] ?? 0),
                (string)($event['event_type'] ?? ''),
                (int)($event['actor_id'] ?? 0),
                (string)($event['created_at'] ?? ''),
                (string)($event['details_json'] ?? '')
            );
            if (!hash_equals($expected, (string)($event['event_hash'] ?? ''))) {
                return false;
            }
            $previous = (string)$event['event_hash'];
        }
        return true;
    }

    private static function appendEvent(int $plan_id, string $event_type, array $details): bool
    {
        global $wpdb;
        $events = $wpdb->prefix . self::EVENT_TABLE;
        $previous = (string)$wpdb->get_var($wpdb->prepare("SELECT event_hash FROM {$events} WHERE plan_id = %d ORDER BY id DESC LIMIT 1", $plan_id));
        $actor_id = get_current_user_id();
        $created_at = current_time('mysql');
        $details_json = wp_json_encode($details);
        $event_hash = self::eventHash($previous, $plan_id, $event_type, $actor_id, $created_at, $details_json);
        return $wpdb->insert($events, [
            'plan_id'            => $plan_id,
            'event_type'         => sanitize_key($event_type),
            'actor_id'           => $actor_id,
            'details_json'       => $details_json,
            'previous_event_hash'=> $previous,
            'event_hash'         => $event_hash,
            'created_at'         => $created_at,
        ]) !== false;
    }

    private static function events(int $plan_id): array
    {
        global $wpdb;
        $events = $wpdb->prefix . self::EVENT_TABLE;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$events} WHERE plan_id = %d ORDER BY id ASC", $plan_id), ARRAY_A);
    }

    private static function eventHash(string $previous, int $plan_id, string $event_type, int $actor_id, string $created_at, string $details_json): string
    {
        return hash('sha256', implode('|', [$previous, $plan_id, $event_type, $actor_id, $created_at, $details_json]));
    }

    private static function customerId(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
