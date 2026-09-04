<?php
/** Durable, client-scoped PPC learnings and human feedback. */
namespace WNQ\Models;

if (!defined('ABSPATH')) exit;

final class PpcMemory
{
    private const MEMORY_TABLE = 'wnq_ppc_memory';
    private const FEEDBACK_TABLE = 'wnq_ppc_feedback';
    private const SCHEMA_VERSION = '1';

    public static function createTables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $memory = $wpdb->prefix . self::MEMORY_TABLE;
        $feedback = $wpdb->prefix . self::FEEDBACK_TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$memory} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            customer_id varchar(20) NOT NULL,
            memory_type varchar(40) NOT NULL,
            subject_key varchar(255) NOT NULL DEFAULT '',
            content text NOT NULL,
            evidence_json longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            source_type varchar(40) NOT NULL DEFAULT 'human',
            source_id varchar(100) NOT NULL DEFAULT '',
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            last_confirmed_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY client_account (client_id,customer_id),
            KEY memory_type (memory_type),
            KEY status (status)
        ) {$charset};");
        dbDelta("CREATE TABLE {$feedback} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            customer_id varchar(20) NOT NULL,
            subject_type varchar(40) NOT NULL,
            subject_key varchar(255) NOT NULL,
            original_classification varchar(40) NOT NULL,
            human_decision varchar(40) NOT NULL,
            reason text DEFAULT NULL,
            eventual_result text DEFAULT NULL,
            context_json longtext DEFAULT NULL,
            actor_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            decided_at datetime NOT NULL,
            result_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY client_account (client_id,customer_id),
            KEY subject_lookup (subject_type,subject_key(100)),
            KEY human_decision (human_decision),
            KEY decided_at (decided_at)
        ) {$charset};");
        update_option('wnq_ppc_memory_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybeUpgrade(): void
    {
        if ((string)get_option('wnq_ppc_memory_schema_version', '') !== self::SCHEMA_VERSION) self::createTables();
    }

    public static function add(string $client_id, string $customer_id, string $type, string $subject, string $content, array $evidence = []): bool
    {
        global $wpdb;
        $type = sanitize_key($type);
        if (!in_array($type, ['client_rule','strategy','bid_strategy','search_pattern','test','outcome','learning'], true)) return false;
        $client_id = sanitize_text_field($client_id); $customer_id = self::customerId($customer_id);
        $content = sanitize_textarea_field($content); $subject = sanitize_text_field($subject);
        if ($client_id === '' || strlen($customer_id) !== 10 || $content === '') return false;
        $now = current_time('mysql');
        return $wpdb->insert($wpdb->prefix . self::MEMORY_TABLE, [
            'client_id'=>$client_id,'customer_id'=>$customer_id,'memory_type'=>$type,'subject_key'=>function_exists('mb_substr')?mb_substr(strtolower($subject),0,255):substr(strtolower($subject),0,255),
            'content'=>$content,'evidence_json'=>wp_json_encode($evidence),'status'=>'active','source_type'=>'human','source_id'=>'',
            'created_by'=>get_current_user_id(),'last_confirmed_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
        ]) !== false;
    }

    public static function recordFeedback(string $client_id, string $customer_id, array $proposal, string $decision, string $reason = '', string $result = ''): bool
    {
        global $wpdb;
        $allowed = ['approved_exact','approved_phrase','relevant','ignored','rejected'];
        if (!in_array($decision, $allowed, true)) return false;
        $query = sanitize_text_field((string)($proposal['query_text'] ?? $proposal['query'] ?? ''));
        $customer_id = self::customerId($customer_id);
        if ($query === '' || strlen($customer_id) !== 10) return false;
        $now = current_time('mysql');
        return $wpdb->insert($wpdb->prefix . self::FEEDBACK_TABLE, [
            'client_id'=>sanitize_text_field($client_id),'customer_id'=>$customer_id,'subject_type'=>'search_term','subject_key'=>self::normalize($query),
            'original_classification'=>sanitize_key((string)($proposal['classification'] ?? 'unknown')),'human_decision'=>$decision,
            'reason'=>sanitize_textarea_field($reason),'eventual_result'=>sanitize_textarea_field($result),
            'context_json'=>wp_json_encode(['proposal_id'=>(int)($proposal['id']??0),'campaign_id'=>(string)($proposal['campaign_id']??''),'ad_group_id'=>(string)($proposal['ad_group_id']??''),'evidence'=>(array)($proposal['evidence']??[])]),
            'actor_id'=>get_current_user_id(),'decided_at'=>$now,'result_at'=>$result!==''?$now:null,
        ]) !== false;
    }

    public static function context(string $client_id, string $customer_id): array
    {
        global $wpdb;
        $client_id = sanitize_text_field($client_id); $customer_id = self::customerId($customer_id);
        $memory = $wpdb->prefix . self::MEMORY_TABLE; $feedback = $wpdb->prefix . self::FEEDBACK_TABLE;
        $memories = (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$memory} WHERE client_id=%s AND customer_id=%s AND status='active' ORDER BY last_confirmed_at DESC LIMIT 250",$client_id,$customer_id),ARRAY_A);
        $decisions = (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$feedback} WHERE client_id=%s AND customer_id=%s ORDER BY decided_at DESC LIMIT 500",$client_id,$customer_id),ARRAY_A);
        foreach ($memories as &$row) { $row['evidence']=self::decode((string)($row['evidence_json']??'')); unset($row['evidence_json']); } unset($row);
        foreach ($decisions as &$row) { $row['context']=self::decode((string)($row['context_json']??''));$row['is_stale']=strtotime((string)($row['decided_at']??'')) < strtotime('-365 days'); unset($row['context_json']); } unset($row);
        return ['memories'=>$memories,'feedback'=>$decisions,'rules'=>array_values(array_filter($memories,static fn($r)=>(string)$r['memory_type']==='client_rule'))];
    }

    public static function updateFeedbackResult(string $client_id,string $customer_id,int $id,string $result):bool
    {
        global $wpdb;$result=sanitize_textarea_field($result);if($id<1||$result==='')return false;
        return (int)$wpdb->update($wpdb->prefix.self::FEEDBACK_TABLE,['eventual_result'=>$result,'result_at'=>current_time('mysql')],['id'=>$id,'client_id'=>sanitize_text_field($client_id),'customer_id'=>self::customerId($customer_id)])>0;
    }

    public static function feedbackForQuery(string $query, array $context): ?array
    {
        $key = self::normalize($query);
        foreach ((array)($context['feedback']??[]) as $row) if (hash_equals($key,(string)($row['subject_key']??''))) return $row;
        return null;
    }

    public static function rulesForText(string $text, array $context): array
    {
        $text = self::normalize($text); $matches=[];
        foreach ((array)($context['rules']??[]) as $rule) {
            $subject=self::normalize((string)($rule['subject_key']??''));
            if ($subject!=='' && str_contains(' '.$text.' ',' '.$subject.' ')) $matches[]=$rule;
        }
        return $matches;
    }

    private static function normalize(string $value): string { return trim(preg_replace('/\s+/',' ',preg_replace('/[^a-z0-9]+/i',' ',strtolower($value)))??''); }
    private static function customerId(string $value): string { return preg_replace('/\D+/','',$value)?:''; }
    private static function decode(string $json): array { $v=json_decode($json,true); return is_array($v)?$v:[]; }
}
