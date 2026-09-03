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
        $negatives = array_merge($this->negatives($customer_id, 'ad_group'), $this->negatives($customer_id, 'campaign'));
        $conflicts = self::conflicts($keywords, $negatives);
        $non_serving = array_values(array_filter($keywords, static fn(array $k): bool => $k['impressions'] === 0));
        foreach ($non_serving as &$keyword) {
            $low_volume = count(array_filter($keyword['status_reasons'], static fn(string $reason): bool => str_contains($reason, 'low_search_volume'))) > 0;
            $keyword['verdict'] = $low_volume ? 'keep_investigate' : 'investigate';
            $keyword['reason'] = $low_volume ? 'Google reports low search volume; do not pause without strategic review.' : 'Enabled keyword returned zero impressions in the full 180-day window; investigate structure and conflicts.';
        } unset($keyword);
        $result = ['available'=>true,'status'=>'ready','message'=>'','keywords'=>$keywords,'non_serving'=>$non_serving,'conflicts'=>$conflicts,'negative_scope'=>'Enabled ad-group and campaign negatives','period'=>'Last 180 days','findings'=>self::findings($non_serving,$conflicts)];
        set_transient($key,$result,15*MINUTE_IN_SECONDS); return $result;
    }

    private function negatives(string $customer_id, string $level): array
    {
        $q = new GoogleAdsQueryService();
        $from = $level === 'campaign' ? 'campaign_criterion' : 'ad_group_criterion';
        $fields = $level === 'campaign' ? 'campaign.id, campaign.name, campaign_criterion.criterion_id, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type' : 'campaign.id, campaign.name, ad_group.id, ad_group.name, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type';
        $rows = $q->select($customer_id, "SELECT {$fields} FROM {$from} WHERE {$from}.negative = TRUE AND {$from}.type = 'KEYWORD' LIMIT 10000");
        if ($q->errors()) return [];
        $out=[]; foreach($rows as $row){$c=(array)($row['campaign']??[]);$g=(array)($row['adGroup']??[]);$x=(array)($row[$level==='campaign'?'campaignCriterion':'adGroupCriterion']??[]);$out[]=['level'=>$level,'negative'=>sanitize_text_field((string)($x['keyword']['text']??'')),'match_type'=>strtolower(sanitize_key((string)($x['keyword']['matchType']??'broad'))),'campaign_id'=>(string)($c['id']??''),'campaign'=>sanitize_text_field((string)($c['name']??'')),'ad_group_id'=>(string)($g['id']??''),'ad_group'=>sanitize_text_field((string)($g['name']??''))];} return $out;
    }

    public static function conflicts(array $keywords,array $negatives): array
    {
        $out=[]; foreach($keywords as $positive) foreach($negatives as $negative){
            if((string)$positive['campaign_id']!==(string)$negative['campaign_id'])continue;
            if($negative['level']==='ad_group'&&(string)$positive['ad_group_id']!==(string)$negative['ad_group_id'])continue;
            if(!self::blocked((string)$positive['keyword'],(string)$negative['negative'],(string)$negative['match_type']))continue;
            $out[]=['positive'=>$positive['keyword'],'positive_match'=>$positive['match_type'],'negative'=>$negative['negative'],'negative_match'=>$negative['match_type'],'level'=>$negative['level'],'campaign'=>$positive['campaign'],'campaign_id'=>$positive['campaign_id'],'ad_group'=>$positive['ad_group'],'priority'=>$positive['match_type']==='exact'?'high':'review','recommendation'=>'Investigate routing before removing the negative; the negative may be intentional.'];
        } return $out;
    }
    private static function blocked(string $positive,string $negative,string $type): bool
    {
        $p=self::words($positive);$n=self::words($negative);if(!$n)return false;
        if($type==='exact')return $p===$n;
        if($type==='phrase')return str_contains(' '.$p.' ',' '.$n.' ');
        $pw=array_unique(explode(' ',$p));foreach(array_unique(explode(' ',$n)) as $word)if(!in_array($word,$pw,true))return false;return true;
    }
    private static function words(string $v):string{return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower($v))?:'');}
    private static function findings(array $dead,array $conflicts):array
    {
        $f=[];if($conflicts)$f[]=['severity'=>'warning','title'=>'Negative keyword conflicts','evidence'=>count($conflicts).' enabled positive keyword(s) are text-level blocked by enabled campaign/ad-group negatives.','period'=>'Current configuration','action'=>'Review exact positives and routing first; do not automatically remove negatives.','confidence'=>.98,'campaign_id'=>(string)$conflicts[0]['campaign_id'],'section'=>'ppc-keywords'];
        if($dead)$f[]=['severity'=>'opportunity','title'=>'Non-serving keyword hygiene','evidence'=>count($dead).' enabled keyword(s) recorded zero impressions. These keywords also recorded $0 spend.','period'=>'Last 180 days','action'=>'Investigate clusters and conflicts; default ambiguous keywords to Keep + Investigate.','confidence'=>.9,'campaign_id'=>(string)$dead[0]['campaign_id'],'section'=>'ppc-keywords'];return $f;
    }
    private static function unavailable(string $m):array{return ['available'=>false,'status'=>'unavailable','message'=>$m,'keywords'=>[],'non_serving'=>[],'conflicts'=>[],'findings'=>[],'period'=>'Last 180 days'];}
}
