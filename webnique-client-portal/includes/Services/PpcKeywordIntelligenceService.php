<?php
/** Read-only keyword health and negative-conflict analysis. */
namespace WNQ\Services;
if (!defined('ABSPATH')) exit;

final class PpcKeywordIntelligenceService
{
    public function report(string $customer_id, bool $refresh = false): array
    {
        $key = 'wnq_ppc_keywords_' . md5($customer_id);
        if (!$refresh && is_array($cached = get_transient($key))) return $cached;
        $today = current_datetime()->format('Y-m-d');
        $start = current_datetime()->modify('-179 days')->format('Y-m-d');
        $query = new GoogleAdsQueryService();
        $rows = $query->select($customer_id, "SELECT campaign.id, campaign.name, campaign.status, ad_group.id, ad_group.name, ad_group.status, ad_group_criterion.criterion_id, ad_group_criterion.status, ad_group_criterion.primary_status, ad_group_criterion.primary_status_reasons, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions FROM keyword_view WHERE campaign.advertising_channel_type = 'SEARCH' AND campaign.status = 'ENABLED' AND ad_group.status = 'ENABLED' AND ad_group_criterion.status = 'ENABLED' AND segments.date BETWEEN '{$start}' AND '{$today}' LIMIT 10000");
        if ($query->errors()) return self::unavailable('Enabled keyword evidence is unavailable.');
        $keywords = [];
        foreach ($rows as $row) {
            $criterion = (array)($row['adGroupCriterion'] ?? []); $campaign = (array)($row['campaign'] ?? []); $group = (array)($row['adGroup'] ?? []); $metrics = (array)($row['metrics'] ?? []);
            $keywords[] = ['criterion_id'=>(string)($criterion['criterionId']??''),'keyword'=>sanitize_text_field((string)($criterion['keyword']['text']??'')),'match_type'=>strtolower(sanitize_key((string)($criterion['keyword']['matchType']??'unknown'))),'primary_status'=>strtolower(sanitize_key((string)($criterion['primaryStatus']??'unknown'))),'status_reasons'=>array_map('sanitize_key',(array)($criterion['primaryStatusReasons']??[])),'campaign_id'=>(string)($campaign['id']??''),'campaign'=>sanitize_text_field((string)($campaign['name']??'')),'ad_group_id'=>(string)($group['id']??''),'ad_group'=>sanitize_text_field((string)($group['name']??'')),'impressions'=>(int)($metrics['impressions']??0),'clicks'=>(int)($metrics['clicks']??0),'cost'=>round(((float)($metrics['costMicros']??0))/1000000,2),'conversions'=>round((float)($metrics['conversions']??0),2)];
        }
        $inventory = (new PpcNegativeInventoryService())->inventory($customer_id, $refresh);
        $negatives = (array)($inventory['items'] ?? []);
        $conflicts = self::conflicts($keywords, $negatives);
        $non_serving = array_values(array_filter($keywords, static fn(array $k): bool => $k['impressions'] === 0));
        foreach ($non_serving as &$keyword) {
            $low_volume = count(array_filter($keyword['status_reasons'], static fn(string $reason): bool => str_contains($reason, 'low_search_volume'))) > 0;
            $keyword['verdict'] = $low_volume ? 'keep_investigate' : 'investigate';
            $keyword['reason'] = $low_volume ? 'Google reports low search volume; do not pause without strategic review.' : 'Enabled keyword returned zero impressions in the full 180-day window; investigate structure and conflicts.';
        } unset($keyword);
        $result = ['available'=>true,'status'=>!empty($inventory['errors'])?'partial':'ready','message'=>(string)($inventory['message']??''),'keywords'=>$keywords,'negatives'=>$negatives,'shared_sets'=>(array)($inventory['shared_sets']??[]),'negative_inventory_status'=>(string)($inventory['status']??'unavailable'),'negative_inventory_errors'=>(array)($inventory['errors']??[]),'non_serving'=>$non_serving,'conflicts'=>$conflicts,'negative_scope'=>'Enabled ad-group, campaign, shared-list, and accessible account-level negatives','period'=>'Last 180 days','findings'=>self::findings($non_serving,$conflicts)];
        set_transient($key,$result,15*MINUTE_IN_SECONDS); return $result;
    }

    public static function conflicts(array $keywords,array $negatives): array
    {
        return PpcNegativeInventoryService::conflicts($keywords, $negatives);
    }
    private static function findings(array $dead,array $conflicts):array
    {
        $f=[];if($conflicts)$f[]=['severity'=>'warning','title'=>'Negative keyword conflicts','evidence'=>count($conflicts).' enabled positive keyword(s) may be blocked by enabled ad-group, campaign, shared-list, or account-level negatives.','period'=>'Current configuration','action'=>'Review exact positives, shared-list scope, and routing first; do not automatically remove negatives.','confidence'=>.98,'campaign_id'=>(string)$conflicts[0]['campaign_id'],'section'=>'ppc-keywords'];
        if($dead)$f[]=['severity'=>'opportunity','title'=>'Non-serving keyword hygiene','evidence'=>count($dead).' enabled keyword(s) recorded zero impressions. These keywords also recorded $0 spend.','period'=>'Last 180 days','action'=>'Investigate clusters and conflicts; default ambiguous keywords to Keep + Investigate.','confidence'=>.9,'campaign_id'=>(string)$dead[0]['campaign_id'],'section'=>'ppc-keywords'];return $f;
    }
    private static function unavailable(string $m):array{return ['available'=>false,'status'=>'unavailable','message'=>$m,'keywords'=>[],'non_serving'=>[],'conflicts'=>[],'findings'=>[],'period'=>'Last 180 days'];}
}
