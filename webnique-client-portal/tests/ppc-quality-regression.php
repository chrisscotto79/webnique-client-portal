<?php
/** Offline QA regressions: php tests/ppc-quality-regression.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/ppc-phase1-regression.php';
require_once dirname(__DIR__) . '/includes/Models/PpcProposal.php';
require_once dirname(__DIR__) . '/includes/Models/PpcAccount.php';
require_once dirname(__DIR__) . '/includes/Services/PpcInvestigationService.php';
set_error_handler(static function ($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
define('ARRAY_A','ARRAY_A');
define('MINUTE_IN_SECONDS',60);
function absint($value): int { return abs((int)$value); }

use WNQ\Services\PpcAdvancedSearchService as Advanced;
use WNQ\Services\GoogleAdsClient;
use WNQ\Models\PpcMemory;
use WNQ\Models\PpcProposal;

function current_datetime(): DateTimeImmutable { return new DateTimeImmutable('2026-09-06 12:00:00', wp_timezone()); }
function current_time($type): string { return current_datetime()->format('Y-m-d H:i:s'); }
function get_current_user_id(): int { return 7; }
function get_transient($key) { return str_starts_with($key,'wnq_google_ads_access_token_') ? 'fixture-access-token' : false; }
function set_transient($key,$value,$ttl): bool { return true; }
function is_wp_error($value): bool { return false; }
function wp_remote_post($url,$args): array {
    global $responses,$requests;
    $requests[]=['url'=>$url,'args'=>$args];
    if (!$responses) throw new RuntimeException('Unexpected HTTP request');
    return array_shift($responses);
}
function wp_remote_retrieve_response_code($response): int { return $response['code']; }
function wp_remote_retrieve_body($response): string { return $response['body']; }
function response(array $body,int $code=200): array { return ['code'=>$code,'body'=>json_encode($body)]; }

$settings=['developer_token'=>'fixture-dev-token','manager_customer_id'=>'1234567890','oauth_client_id'=>'fixture-oauth-id','oauth_client_secret'=>'fixture-secret','refresh_token'=>'fixture-refresh'];
$requests=[];$responses=[response(['results'=>[['campaign'=>['id'=>'1']]],'nextPageToken'=>'next']),response(['results'=>[['campaign'=>['id'=>'2']]]])];
$api=new GoogleAdsClient($settings);
assertPpc(count($api->query('1234567890','SELECT campaign.id FROM campaign'))===2,'All API pages must be returned.');
assertPpc(json_decode($requests[1]['args']['body'],true)['pageToken']==='next','Second request must carry the page token.');
assertPpc($requests[0]['url']===$requests[1]['url'],'Pagination must preserve the exact account.');
$responses=[response(['results'=>[['campaign'=>['id'=>'1']]],'nextPageToken'=>'next']),response(['error'=>['message'=>'fixture-secret fixture-refresh Bearer fixture-access-token']],403)];
$api=new GoogleAdsClient($settings);
assertPpc($api->query('1234567890','SELECT campaign.id FROM campaign')===[],'A later page failure must discard partial results.');
assertPpc(!str_contains(implode(' ',$api->errors()),'fixture-'),'Error messages must redact configured credentials and bearer tokens.');
$responses=[response(['nextPageToken'=>'repeat']),response(['nextPageToken'=>'repeat'])];
$api=new GoogleAdsClient($settings);
assertPpc($api->query('1234567890','SELECT campaign.id FROM campaign')===[] && count($api->errors())>0,'Repeated tokens must fail safely.');
$responses=[['code'=>200,'body'=>'not json']];
$api=new GoogleAdsClient($settings);
assertPpc($api->query('1234567890','SELECT campaign.id FROM campaign')===[] && count($api->errors())>0,'Invalid JSON must not become a healthy empty report.');

$term=['query'=>'junk removal','campaign_id'=>'1','ad_group_id'=>'10','recommended_action'=>'human_review'];
$other=$term;$other['ad_group_id']='20';
assertPpc(PpcProposal::key('client','1234567890',$term)!==PpcProposal::key('client','1234567890',$other),'Proposal identity must include ad group.');
$context=['feedback'=>[['subject_key'=>'junk removal','human_decision'=>'relevant','context'=>['campaign_id'=>'1','ad_group_id'=>'10']]]];
assertPpc(PpcMemory::feedbackForQuery('junk removal',$context,'1','10')!==null,'Exact route should retrieve feedback.');
assertPpc(PpcMemory::feedbackForQuery('junk removal',$context,'1','20')===null,'Feedback must not leak across ad groups.');
assertPpc(PpcMemory::feedbackForQuery('junk removal',$context)===null,'Unscoped feedback must not be applied.');
$ruleTerm=['query'=>'junk removal','classification'=>'human_relevant','recommended_action'=>'keep','confidence'=>.99,'reason'=>'Prior review.'];
$guarded=WNQ\Services\PpcSearchTermService::respectClientRules($ruleTerm,['excluded_terms'=>['junk removal']],[]);
assertPpc($guarded['recommended_action']==='human_review' && $guarded['confidence']<=.5,'Explicit client exclusions must take precedence over historic feedback.');
assertPpc(WNQ\Services\PpcSearchTermService::respectClientRules($ruleTerm,[],['unavailable'=>true])['recommended_action']==='human_review','Missing memory must preserve human review.');
$incomplete=WNQ\Services\PpcInvestigationService::build('client','1234567890',[],[],[],[]);
assertPpc(!$incomplete['available'] && $incomplete['priority']['label']==='Evidence incomplete','Failed sources must not yield a healthy account.');
$positives=[['keyword'=>'junk removal','criterion_id'=>'1','campaign_id'=>'1','ad_group_id'=>'10'],['keyword'=>'junk removal','criterion_id'=>'1','campaign_id'=>'1','ad_group_id'=>'20']];
foreach($positives as &$positive) $positive+=['match_type'=>'phrase','campaign'=>'Search','ad_group'=>'Removal'];
unset($positive);
$conflicts=WNQ\Services\PpcNegativeInventoryService::conflicts($positives,[['negative'=>'junk','match_type'=>'broad','scope'=>'campaign','campaign_id'=>'1','resource_name'=>'fixture-negative']]);
assertPpc(count($conflicts)===2,'Criterion IDs shared across ad groups must not collapse separate negative conflicts.');

$duplicates=[];
for($i=0;$i<4;$i++) $duplicates[]=['query'=>'cheap junk removal','campaign_id'=>(string)$i,'ad_group_id'=>(string)$i,'clicks'=>8,'impressions'=>50,'cost'=>20,'conversions'=>0];
$patterns=Advanced::ngrams($duplicates);
assertPpc($patterns['items']===[],'A single query repeated across routes must not qualify as recurring intent.');
$duplicates[]=['query'=>'local junk removal','clicks'=>1,'impressions'=>10,'cost'=>1,'conversions'=>0];
$patterns=Advanced::ngrams($duplicates);
$pattern=array_values(array_filter($patterns['items'],static fn($r)=>$r['ngram']==='junk removal'))[0];
assertPpc($pattern['queries']===2 && $pattern['clicks']===33.0,'Repeated routes must add metrics without inflating unique-query support.');
assertPpc($pattern['classification']==='monitor' && $pattern['cpa']===null,'Two distinct queries do not meet the waste safeguard; undefined CPA is not zero.');

$zero=$anomaly_rows;
foreach($zero as &$row) if($row['segments']['date']>='2026-01-29') $row['metrics']=['impressions'=>0,'clicks'=>0,'costMicros'=>0,'conversions'=>0];
unset($row);
$drop=Advanced::analyzeAnomalies($zero,'2026-01-01','2026-02-04');
$metrics=array_column($drop['campaigns'][0]['anomalies'],'metric');
assertPpc(in_array('clicks',$metrics,true)&&in_array('conversions',$metrics,true),'Large count drops to zero must not be suppressed by current-volume guards.');
assertPpc(!in_array('cpc',$metrics,true),'Zero-click CPC is undefined and must not be flagged.');
$new=Advanced::analyzeAnomalies(array_slice($anomaly_rows,28),'2026-01-01','2026-02-04');
assertPpc($new['findings']===[] && $new['status']==='partial','New campaigns need a supported historical baseline.');
assertPpc(!Advanced::analyzeAnomalies([],'2026-02-30','2026-04-05')['available'],'Invalid calendar dates must be rejected.');
$week=new ReflectionMethod(Advanced::class,'week');
$day=['impressions'=>100,'clicks'=>10,'cost'=>20,'conversions'=>1,'search_is'=>.5,'lost_budget'=>.2,'lost_rank'=>.3];
$day2=$day;$day2['search_is']=.25;
$weighted=$week->invoke(null,[$day,$day2]);
assertPpc(abs($weighted['search_is']-1/3)<.000001,'Share aggregation must use estimated eligible impressions.');
$day2['search_is']=.0999;
assertPpc($week->invoke(null,[$day,$day2])['search_is']===null,'Censored shares must not masquerade as exact measurements.');
$quality=new ReflectionMethod(Advanced::class,'qualityScore');
$qualityRow=['campaign'=>['id'=>'1','name'=>'Search'],'adGroup'=>['id'=>'10','name'=>'Removal'],'adGroupCriterion'=>['criterionId'=>'1','keyword'=>['text'=>'junk removal'],'qualityInfo'=>['qualityScore'=>2,'searchPredictedCtr'=>'AVERAGE','creativeQualityScore'=>'AVERAGE','postClickQualityScore'=>'AVERAGE']],'metrics'=>['clicks'=>30,'impressions'=>300,'costMicros'=>50000000,'conversions'=>0]];
$responses=[response(['results'=>[$qualityRow]])];
assertPpc($quality->invoke(new Advanced(),'1234567890',true)['items']===[],'Low Quality Score alone must not trigger an issue.');
$qualityRow['adGroupCriterion']['qualityInfo']['creativeQualityScore']='BELOW_AVERAGE';
$responses=[response(['results'=>[$qualityRow]])];
$qs=$quality->invoke(new Advanced(),'1234567890',true);
assertPpc(count($qs['items'])===1 && $qs['items'][0]['cpa']===null,'Economic quality issue should retain undefined CPA.');
$responses=[response(['error'=>['message'=>'Unavailable']],503)];
assertPpc(!$quality->invoke(new Advanced(),'1234567890',true)['available'],'Quality API failure must remain provider-local.');

$active=['status'=>'enabled','campaign_status'=>'enabled','ad_group_status'=>'enabled','campaign_id'=>'1','ad_group_id'=>'10','headlines'=>[['text'=>'Junk'],['text'=>'Removal']],'descriptions'=>[]];
$gaps=Advanced::messaging($ngram_terms,[$active],[],['available'=>true,'pages'=>[]]);
assertPpc(count(array_filter($gaps['gaps'],static fn($g)=>$g['theme']==='junk removal'))===1,'Separate RSA assets must not create fictional contiguous messaging.');
$active['status']='paused';
assertPpc(Advanced::messaging($ngram_terms,[$active],[],['available'=>true])['gaps']===[],'Paused ads are not active messaging coverage.');

// A transactional database double exercises model behavior, not a live MySQL integration.
class QualityDatabase {
    public $prefix='wp_',$last_error='',$failInsert=false,$saved=[],$feedback=[],$snapshot=[],$queries=[];
    public function prepare($sql,...$args) { foreach($args as $arg) $sql=preg_replace('/%[sd]/',is_int($arg)?(string)$arg:"'".addslashes((string)$arg)."'",$sql,1); return $sql; }
    public function get_row($sql,$mode) {
        $this->queries[]=$sql;
        if(str_contains($sql,'wnq_ppc_accounts')) return ['client_id'=>'client','customer_id'=>'1234567890'];
        if(str_contains($sql,'wnq_ppc_proposals')) {
            preg_match('/id = (\d+)/',$sql,$match);
            $id=(int)($match[1]??0);
            $row=$this->saved[$id]??null;
            return $row && str_contains($sql,"customer_id = '".$row['customer_id']."'") ? $row : null;
        }
        return null;
    }
    public function get_results($sql,$mode) {
        $this->queries[]=$sql;
        if(str_contains($sql,'wnq_ppc_mutation_plans')) return [['id'=>1,'status'=>'approved','expires_at'=>'2026-09-01 00:00:00','current_state_json'=>'{"budget":10}','proposed_state_json'=>'{"budget":20}','evidence_json'=>'{}','idempotency_key'=>'private']];
        return [];
    }
    public function insert($table,$data) { if($this->failInsert)return false; $this->feedback[]=$data; return 1; }
    public function update($table,$data,$where) { $this->saved[$where['id']]=array_merge($this->saved[$where['id']],$data); return 1; }
    public function query($sql) {
        if($sql==='START TRANSACTION') $this->snapshot=[$this->saved,$this->feedback];
        if($sql==='ROLLBACK') [$this->saved,$this->feedback]=$this->snapshot;
        return 1;
    }
}
$wpdb=new QualityDatabase();
$wpdb->saved=[1=>['id'=>1,'client_id'=>'client','customer_id'=>'1234567890','query_text'=>'junk removal','campaign_id'=>'1','ad_group_id'=>'10','classification'=>'human_relevant','evidence_json'=>'{"original_ai_classification":"commercial"}','status'=>'pending']];
assertPpc(PpcProposal::review('client',[1],'relevant','Appropriate service')===1,'Review and feedback should succeed together.');
assertPpc($wpdb->feedback[0]['original_classification']==='commercial','Feedback must preserve the original classification.');
$wpdb->failInsert=true;
assertPpc(PpcProposal::review('client',[1],'ignored','Test failure')===0 && $wpdb->saved[1]['status']==='relevant','Feedback failure must not change review status.');
$wpdb->failInsert=false;$wpdb->saved[2]=$wpdb->saved[1];$wpdb->saved[2]['id']=2;$wpdb->saved[2]['customer_id']='9999999999';
assertPpc(PpcProposal::review('client',[1,2],'ignored','Wrong account')===0 && count($wpdb->feedback)===1 && $wpdb->saved[1]['status']==='relevant','Mixed-account bulk review must roll back the entire review.');
$plans=WNQ\Models\PpcMutationPlan::forClient('client');
assertPpc($plans[0]['status']==='expired' && $plans[0]['current_state']['budget']===10,'Plan expiry and decoded state must persist in returned rows.');
assertPpc(!isset($plans[0]['idempotency_key']),'Private plan key must not be returned.');
$wpdb->last_error='Database unavailable';
try { PpcMemory::context('client','1234567890'); assertPpc(false,'Database errors must not look like empty healthy memory.'); } catch (RuntimeException $expected) {}
echo "PPC quality regression checks passed.\n";
