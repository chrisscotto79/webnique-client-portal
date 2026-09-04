<?php
/**
 * Persistent, auditable PPC recommendation lifecycle and validation evidence.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcRecommendation
{
    private const TABLE = 'wnq_ppc_recommendations';
    private const EVENT_TABLE = 'wnq_ppc_recommendation_events';
    private const SCHEMA_VERSION = '1';

    private const STATUSES = [
        'open' => 'Open',
        'investigating' => 'Investigating',
        'ready_for_review' => 'Ready for review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'implemented_externally' => 'Implemented externally',
        'implemented_through_system' => 'Implemented through system (future)',
        'monitoring' => 'Monitoring',
        'successful' => 'Successful',
        'neutral' => 'Neutral / inconclusive',
        'unsuccessful' => 'Unsuccessful',
        'superseded' => 'Superseded',
        'cancelled' => 'Cancelled',
    ];

    private const CATEGORIES = [
        'conversion_tracking' => 'Conversion tracking',
        'search_term_waste' => 'Search-term waste',
        'negative_conflict' => 'Negative conflict',
        'keyword_opportunity' => 'Keyword opportunity',
        'non_serving_keyword' => 'Non-serving keyword',
        'budget_pacing' => 'Budget pacing',
        'impression_share' => 'Impression share',
        'ad_rank' => 'Ad Rank',
        'rsa_quality' => 'RSA quality',
        'policy_issue' => 'Policy issue',
        'claim_verification' => 'Claim verification',
        'landing_page_issue' => 'Landing-page issue',
        'lead_quality' => 'Lead quality',
        'device_performance' => 'Device performance',
        'schedule_performance' => 'Schedule/hour performance',
        'geographic_issue' => 'Geographic issue',
        'change_history' => 'Change-history investigation',
        'other' => 'Other',
    ];

    public static function createTables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        $events = $wpdb->prefix . self::EVENT_TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            recommendation_uuid char(36) NOT NULL,
            recommendation_key char(64) NOT NULL,
            client_id varchar(100) NOT NULL,
            customer_id varchar(20) NOT NULL,
            campaign_id varchar(30) NOT NULL DEFAULT '',
            campaign_name varchar(255) NOT NULL DEFAULT '',
            ad_group_id varchar(30) NOT NULL DEFAULT '',
            entity_id varchar(255) NOT NULL DEFAULT '',
            category varchar(50) NOT NULL,
            recommendation_text text NOT NULL,
            severity varchar(20) NOT NULL,
            data_confidence decimal(5,4) NOT NULL DEFAULT 0,
            recommendation_confidence decimal(5,4) NOT NULL DEFAULT 0,
            estimated_impact text DEFAULT NULL,
            supporting_evidence_json longtext DEFAULT NULL,
            reporting_period varchar(100) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'open',
            implemented_at datetime DEFAULT NULL,
            implementation_source varchar(30) NOT NULL DEFAULT '',
            outcome_label varchar(30) NOT NULL DEFAULT '',
            validation_json longtext DEFAULT NULL,
            validated_at datetime DEFAULT NULL,
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY recommendation_uuid (recommendation_uuid),
            UNIQUE KEY recommendation_key (recommendation_key),
            KEY client_status (client_id,status),
            KEY customer_category (customer_id,category),
            KEY outcome_label (outcome_label),
            KEY created_at (created_at)
        ) {$charset};");
        dbDelta("CREATE TABLE {$events} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            recommendation_id bigint(20) UNSIGNED NOT NULL,
            event_type varchar(50) NOT NULL,
            from_status varchar(40) NOT NULL DEFAULT '',
            to_status varchar(40) NOT NULL DEFAULT '',
            actor_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            note text DEFAULT NULL,
            metadata_json longtext DEFAULT NULL,
            previous_event_hash char(64) NOT NULL DEFAULT '',
            event_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY recommendation_id (recommendation_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};");
        update_option('wnq_ppc_recommendation_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybeUpgrade(): void
    {
        if ((string)get_option('wnq_ppc_recommendation_schema_version', '') !== self::SCHEMA_VERSION) {
            self::createTables();
        }
    }

    public static function sync(string $client_id, string $customer_id, array $recommendations): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $client_id = sanitize_text_field($client_id);
        $customer_id = self::customerId($customer_id);
        if ($client_id === '' || strlen($customer_id) !== 10) {
            return;
        }
        foreach ($recommendations as $recommendation) {
            $key = (string)($recommendation['recommendation_key'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
                continue;
            }
            $data = [
                'client_id' => $client_id,
                'customer_id' => $customer_id,
                'campaign_id' => self::customerId((string)($recommendation['campaign_id'] ?? '')),
                'campaign_name' => sanitize_text_field((string)($recommendation['campaign_name'] ?? '')),
                'ad_group_id' => self::customerId((string)($recommendation['ad_group_id'] ?? '')),
                'entity_id' => sanitize_text_field((string)($recommendation['entity_id'] ?? '')),
                'category' => self::category((string)($recommendation['category'] ?? 'other')),
                'recommendation_text' => sanitize_textarea_field((string)($recommendation['recommendation_text'] ?? '')),
                'severity' => self::severity((string)($recommendation['severity'] ?? 'opportunity')),
                'data_confidence' => self::confidence($recommendation['data_confidence'] ?? 0),
                'recommendation_confidence' => self::confidence($recommendation['recommendation_confidence'] ?? 0),
                'estimated_impact' => sanitize_textarea_field((string)($recommendation['estimated_impact'] ?? 'Requires measured validation; no impact is assumed.')),
                'supporting_evidence_json' => wp_json_encode((array)($recommendation['evidence'] ?? [])),
                'reporting_period' => sanitize_text_field((string)($recommendation['reporting_period'] ?? '')),
            ];
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE recommendation_key = %s", $key));
            if ($existing) {
                $wpdb->update($table, $data, ['id' => (int)$existing]);
                continue;
            }
            $wpdb->query('START TRANSACTION');
            $saved = $wpdb->insert($table, $data + [
                'recommendation_uuid' => wp_generate_uuid4(),
                'recommendation_key' => $key,
                'status' => 'open',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ]);
            $id = (int)$wpdb->insert_id;
            $audited = $saved !== false && self::appendEvent($id, 'recommendation_created', '', 'open', 'Generated from current PPC evidence.', ['key' => $key]);
            $wpdb->query($audited ? 'COMMIT' : 'ROLLBACK');
        }
    }

    public static function forClient(string $client_id, int $limit = 100): array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE client_id = %s ORDER BY CASE status WHEN 'open' THEN 1 WHEN 'investigating' THEN 2 WHEN 'ready_for_review' THEN 3 WHEN 'approved' THEN 4 WHEN 'implemented_externally' THEN 5 WHEN 'monitoring' THEN 6 ELSE 7 END, severity = 'critical' DESC, updated_at DESC LIMIT %d", sanitize_text_field($client_id), $limit), ARRAY_A);
        return array_map([self::class, 'hydrate'], (array)$rows);
    }

    public static function getByIdForClient(int $id, string $client_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND client_id = %s LIMIT 1", $id, sanitize_text_field($client_id)), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function changeStatus(string $client_id, int $id, string $status, string $implemented_at, string $note): array
    {
        global $wpdb;
        $status = sanitize_key($status);
        if (!isset(self::STATUSES[$status]) || $status === 'implemented_through_system') {
            return ['ok' => false, 'message' => 'Choose an available lifecycle status. System execution remains disabled.'];
        }
        $recommendation = self::getByIdForClient($id, $client_id);
        $connection = PpcAccount::getByClientId($client_id);
        if (!$recommendation || !$connection || !hash_equals(self::customerId((string)$recommendation['customer_id']), self::customerId((string)$connection['customer_id'] ?? ''))) {
            return ['ok' => false, 'message' => 'The recommendation no longer matches this client’s exact Google Ads account.'];
        }
        $implementation_source = '';
        $date = null;
        if ($status === 'implemented_externally' || $status === 'monitoring') {
            $date = self::dateTime($implemented_at);
            if ($date === '') {
                return ['ok' => false, 'message' => 'Record the external implementation date and time before monitoring an outcome.'];
            }
            if ($date > current_time('mysql')) {
                return ['ok' => false, 'message' => 'The external implementation time cannot be in the future.'];
            }
            $implementation_source = 'external';
        }
        $outcome = in_array($status, ['successful', 'neutral', 'unsuccessful'], true) ? $status : '';
        if ($outcome !== '' && empty($recommendation['implemented_at'])) {
            return ['ok' => false, 'message' => 'An outcome cannot be recorded until implementation is separately documented.'];
        }
        $table = $wpdb->prefix . self::TABLE;
        $update = [
            'status' => $status,
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
        ];
        if ($date) {
            $update['implemented_at'] = $date;
            $update['implementation_source'] = $implementation_source;
        }
        $update['outcome_label'] = $outcome;
        $wpdb->query('START TRANSACTION');
        $saved = $wpdb->update($table, $update, ['id' => $id, 'client_id' => sanitize_text_field($client_id), 'customer_id' => (string)$recommendation['customer_id']]);
        $audited = $saved !== false && self::appendEvent($id, 'status_changed', (string)$recommendation['status'], $status, $note, ['implemented_at' => $date, 'outcome' => $outcome]);
        $wpdb->query($audited ? 'COMMIT' : 'ROLLBACK');
        return $audited
            ? ['ok' => true, 'message' => 'Recommendation lifecycle updated. No Google Ads change was made.']
            : ['ok' => false, 'message' => 'The lifecycle update could not be stored.'];
    }

    public static function recordValidation(string $client_id, int $id, array $validation): bool
    {
        global $wpdb;
        $recommendation = self::getByIdForClient($id, $client_id);
        if (!$recommendation) {
            return false;
        }
        $label = sanitize_key((string)($validation['overall'] ?? 'insufficient_data'));
        if (!in_array($label, ['positive_evidence', 'neutral_inconclusive', 'negative_evidence', 'insufficient_data'], true)) {
            $label = 'insufficient_data';
        }
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query('START TRANSACTION');
        $saved = $wpdb->update($table, [
            'validation_json' => wp_json_encode($validation),
            'validated_at' => current_time('mysql'),
        ], ['id' => $id, 'client_id' => sanitize_text_field($client_id)]);
        $audited = $saved !== false && self::appendEvent($id, 'validation_refreshed', (string)$recommendation['status'], (string)$recommendation['status'], 'Read-only before/after evidence refreshed.', ['evidence_label' => $label]);
        $wpdb->query($audited ? 'COMMIT' : 'ROLLBACK');
        return $audited;
    }

    public static function qualityReport(array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows = (array)$wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 5000", ARRAY_A);
        $client = sanitize_text_field((string)($filters['client_id'] ?? ''));
        $campaign = sanitize_text_field((string)($filters['campaign'] ?? ''));
        $category = sanitize_key((string)($filters['category'] ?? ''));
        $severity = sanitize_key((string)($filters['severity'] ?? ''));
        $confidence = sanitize_key((string)($filters['confidence'] ?? ''));
        $from = self::date((string)($filters['from'] ?? ''));
        $to = self::date((string)($filters['to'] ?? ''));
        $rows = array_values(array_filter($rows, static function (array $row) use ($client, $campaign, $category, $severity, $confidence, $from, $to): bool {
            $score = (float)$row['recommendation_confidence'];
            $campaign_id = preg_replace('/\D+/', '', $campaign) ?: '';
            return ($client === '' || hash_equals($client, (string)$row['client_id']))
                && ($campaign === '' || ($campaign_id !== '' && hash_equals($campaign_id, (string)$row['campaign_id'])) || stripos((string)$row['campaign_name'], $campaign) !== false)
                && ($category === '' || $category === (string)$row['category'])
                && ($severity === '' || $severity === (string)$row['severity'])
                && ($confidence === '' || ($confidence === 'low' && $score < .5) || ($confidence === 'medium' && $score >= .5 && $score < .8) || ($confidence === 'high' && $score >= .8))
                && ($from === '' || substr((string)$row['created_at'], 0, 10) >= $from)
                && ($to === '' || substr((string)$row['created_at'], 0, 10) <= $to);
        }));
        $statuses = array_count_values(array_column($rows, 'status'));
        $outcomes = array_count_values(array_filter(array_column($rows, 'outcome_label')));
        $reviewed = count(array_filter($rows, static fn(array $row): bool => !in_array((string)$row['status'], ['open', 'investigating'], true)));
        $generated = count($rows);
        $approved_states = ['approved', 'implemented_externally', 'implemented_through_system', 'monitoring', 'successful', 'neutral', 'unsuccessful'];
        $approved = count(array_filter($rows, static fn(array $row): bool => in_array((string)$row['status'], $approved_states, true)));
        $average = $generated ? array_sum(array_map(static fn(array $row): float => (float)$row['recommendation_confidence'], $rows)) / $generated : 0;
        $by_category = [];
        foreach ($rows as $row) {
            $key = (string)$row['category'];
            $by_category[$key]['generated'] = ($by_category[$key]['generated'] ?? 0) + 1;
            if ((string)$row['outcome_label'] !== '') {
                $by_category[$key][(string)$row['outcome_label']] = ($by_category[$key][(string)$row['outcome_label']] ?? 0) + 1;
            }
        }
        foreach ($by_category as &$counts) {
            $resolved = (int)($counts['successful'] ?? 0) + (int)($counts['neutral'] ?? 0) + (int)($counts['unsuccessful'] ?? 0);
            $counts['outcome_rate'] = !empty($counts['generated']) ? $resolved / (int)$counts['generated'] : 0;
        }
        unset($counts);
        return [
            'generated' => $generated,
            'reviewed' => $reviewed,
            'approval_rate' => $reviewed ? ($approved / $reviewed) : 0,
            'rejection_rate' => $reviewed ? (($statuses['rejected'] ?? 0) / $reviewed) : 0,
            'implemented' => (int)($statuses['implemented_externally'] ?? 0) + (int)($statuses['implemented_through_system'] ?? 0) + (int)($statuses['monitoring'] ?? 0) + array_sum($outcomes),
            'awaiting_outcome' => (int)($statuses['implemented_externally'] ?? 0) + (int)($statuses['monitoring'] ?? 0),
            'outcomes' => $outcomes,
            'average_confidence' => $average,
            'by_category' => $by_category,
            'rows' => array_map([self::class, 'hydrate'], array_slice($rows, 0, 250)),
        ];
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    public static function verifyEventChain(array $events): bool
    {
        $previous = '';
        foreach ($events as $event) {
            if (!hash_equals($previous, (string)($event['previous_event_hash'] ?? ''))) return false;
            $expected = hash('sha256', implode('|', [
                $previous,
                (int)($event['recommendation_id'] ?? 0),
                (string)($event['event_type'] ?? ''),
                (string)($event['from_status'] ?? ''),
                (string)($event['to_status'] ?? ''),
                (int)($event['actor_id'] ?? 0),
                (string)($event['created_at'] ?? ''),
                (string)($event['note'] ?? ''),
                (string)($event['metadata_json'] ?? ''),
            ]));
            if (!hash_equals($expected, (string)($event['event_hash'] ?? ''))) return false;
            $previous = $expected;
        }
        return true;
    }

    private static function appendEvent(int $id, string $type, string $from, string $to, string $note, array $metadata): bool
    {
        global $wpdb;
        $type = sanitize_key($type);
        $from = sanitize_key($from);
        $to = sanitize_key($to);
        $note = sanitize_textarea_field($note);
        $events = $wpdb->prefix . self::EVENT_TABLE;
        $last = $wpdb->get_row($wpdb->prepare("SELECT event_hash FROM {$events} WHERE recommendation_id = %d ORDER BY id DESC LIMIT 1", $id), ARRAY_A);
        $previous = (string)($last['event_hash'] ?? '');
        $created = current_time('mysql');
        $actor = get_current_user_id();
        $metadata_json = wp_json_encode($metadata);
        $hash = hash('sha256', implode('|', [$previous, $id, $type, $from, $to, $actor, $created, $note, $metadata_json]));
        return $wpdb->insert($events, [
            'recommendation_id' => $id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor,
            'note' => $note,
            'metadata_json' => $metadata_json,
            'previous_event_hash' => $previous,
            'event_hash' => $hash,
            'created_at' => $created,
        ]) !== false;
    }

    private static function hydrate(array $row): array
    {
        $row['evidence'] = self::decode((string)($row['supporting_evidence_json'] ?? ''));
        $row['validation'] = self::decode((string)($row['validation_json'] ?? ''));
        unset($row['supporting_evidence_json'], $row['validation_json']);
        return $row;
    }

    private static function category(string $value): string
    {
        $value = sanitize_key($value);
        return isset(self::CATEGORIES[$value]) ? $value : 'other';
    }

    private static function severity(string $value): string
    {
        $value = sanitize_key($value);
        return in_array($value, ['critical', 'warning', 'opportunity', 'healthy'], true) ? $value : 'opportunity';
    }

    private static function confidence($value): float
    {
        return max(0, min(1, (float)$value));
    }

    private static function customerId(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private static function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date ? $date->format('Y-m-d') : '';
    }

    private static function dateTime(string $value): string
    {
        $value = trim($value);
        foreach (['!Y-m-d\\TH:i', '!Y-m-d\\TH:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, wp_timezone());
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        return '';
    }

    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
