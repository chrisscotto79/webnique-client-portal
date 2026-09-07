<?php
/** Phase 12 read-only intelligence for Search campaigns only. */
namespace WNQ\Services;

use WNQ\Models\PpcMemory;

if (!defined('ABSPATH')) exit;

final class PpcAdvancedSearchService
{
    public function report(string $client_id, string $customer_id, array $search, array $ads, array $keywords, bool $refresh = false): array
    {
        $memory_available=true;try { $memory = PpcMemory::context($client_id, $customer_id); } catch (\Throwable $e) { $memory_available=false;$memory=['memories'=>[],'feedback'=>[],'rules'=>[]]; }
        $modules = [];
        foreach (['anomalies','quality_score'] as $name) {
            try { $modules[$name] = $name === 'anomalies' ? $this->anomalies($customer_id,$refresh) : $this->qualityScore($customer_id,$refresh); }
            catch (\Throwable $e) { $modules[$name] = self::unavailable(ucwords(str_replace('_',' ',$name)).' is temporarily unavailable.'); }
        }
        $local = [
            'ngrams'=>fn()=>empty($search['available'])?self::unavailable('N-gram intelligence is unavailable because search-term evidence did not load.'):self::ngrams((array)($search['terms']??[]),$memory),
            'routing'=>fn()=>empty($search['available'])||empty($keywords['available'])?self::unavailable('Routing intelligence requires both search-term and keyword evidence.'):self::routing((array)($search['terms']??[]),(array)($keywords['keywords']??[]),(array)($keywords['negatives']??[]),$memory),
            'messaging'=>fn()=>empty($search['available'])||empty($ads['available'])?self::unavailable('Messaging-gap intelligence requires both search-term and RSA evidence.'):self::messaging((array)($search['terms']??[]),(array)($ads['ads']??[]),(array)($ads['claim_config']??[]),(array)($ads['website_evidence']??[]),$memory),
        ];
        foreach ($local as $name=>$builder) {
            try { $modules[$name]=$builder(); } catch (\Throwable $e) { $modules[$name]=self::unavailable(ucwords($name).' is temporarily unavailable.'); }
            $dependencies = $name === 'routing' ? [$search,$keywords] : ($name === 'messaging' ? [$search,$ads] : [$search]);
            $partial = !$memory_available;
            foreach ($dependencies as $source) {
                if (($source['status']??'') === 'partial' || !empty($source['errors'])) $partial = true;
            }
            if (!empty($modules[$name]['available']) && $partial) {
                $modules[$name]['status'] = 'partial';
                $modules[$name]['message'] = trim(($modules[$name]['message']??'').' Some source evidence or client memory is incomplete. Findings are provisional; missing findings do not establish that the account is healthy.');
            }
        }
        $modules['memory']=['available'=>$memory_available,'status'=>$memory_available?'ready':'unavailable','message'=>$memory_available?'':'Persistent PPC memory is temporarily unavailable.','memories'=>(array)$memory['memories'],'feedback'=>(array)$memory['feedback'],'rules'=>(array)$memory['rules'],'period'=>'Durable client history'];
        return $modules;
    }

    private function anomalies(string $customer_id, bool $refresh): array
    {
        $key='wnq_ppc_anomaly_v2_'.md5($customer_id);
        if(!$refresh && is_array($cached=get_transient($key))) return $cached;
        $end=current_datetime()->modify('-1 day')->format('Y-m-d');
        $start=current_datetime()->modify('-35 days')->format('Y-m-d');
        $query=new GoogleAdsQueryService();
        $rows=$query->select($customer_id,"SELECT campaign.id, campaign.name, segments.date, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.search_impression_share, metrics.search_budget_lost_impression_share, metrics.search_rank_lost_impression_share FROM campaign WHERE campaign.advertising_channel_type = 'SEARCH' AND campaign.status = 'ENABLED' AND segments.date BETWEEN '{$start}' AND '{$end}' ORDER BY campaign.id, segments.date");
        if($query->errors()) return self::unavailable('Campaign anomaly evidence is unavailable.');
        $result=self::analyzeAnomalies($rows,$start,$end); set_transient($key,$result,15*MINUTE_IN_SECONDS); return $result;
    }

    public static function analyzeAnomalies(array $rows, string $start, string $end): array
    {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$first || !$last || $first->format('Y-m-d') !== $start || $last->format('Y-m-d') !== $end || $first->modify('+34 days')->format('Y-m-d') !== $end) {
            return self::unavailable('Anomaly detection requires exactly 35 complete calendar days.');
        }
        $period = $first->modify('+28 days')->format('Y-m-d') . ' – ' . $end . ' vs ' . $start . ' – ' . $first->modify('+27 days')->format('Y-m-d');
        $campaigns = [];
        foreach ($rows as $row) {
            $id = (string)($row['campaign']['id'] ?? '');
            $date = (string)($row['segments']['date'] ?? '');
            if ($id === '' || $date < $start || $date > $end) continue;
            $m = (array)($row['metrics'] ?? []);
            $campaigns[$id]['name'] = sanitize_text_field((string)($row['campaign']['name'] ?? 'Campaign'));
            $campaigns[$id]['days'][$date] = [
                'impressions'=>(int)($m['impressions']??0), 'clicks'=>(int)($m['clicks']??0),
                'cost'=>(float)($m['costMicros']??0)/1000000, 'conversions'=>(float)($m['conversions']??0),
                'search_is'=>self::nullable($m['searchImpressionShare']??null),
                'lost_budget'=>self::nullable($m['searchBudgetLostImpressionShare']??null),
                'lost_rank'=>self::nullable($m['searchRankLostImpressionShare']??null),
            ];
        }
        $findings = []; $summaries = []; $insufficient = 0;
        $definitions = [
            'spend'=>['min'=>10, 'den'=>'spend', 'count'=>true],
            'clicks'=>['min'=>15, 'den'=>'clicks', 'count'=>true],
            'cpc'=>['min'=>20, 'den'=>'clicks'], 'ctr'=>['min'=>200, 'den'=>'impressions'],
            'conversions'=>['min'=>20, 'den'=>'clicks', 'count'=>true],
            'conversion_rate'=>['min'=>20, 'den'=>'clicks'], 'cpa'=>['min'=>3, 'den'=>'conversions'],
            'search_is'=>['min'=>200, 'den'=>'impressions'], 'lost_is_budget'=>['min'=>200, 'den'=>'impressions'],
            'lost_is_rank'=>['min'=>200, 'den'=>'impressions'],
        ];
        foreach ($campaigns as $id => $campaign) {
            $weeks = [];
            for ($w=0; $w<5; $w++) {
                $days = [];
                for ($d=0; $d<7; $d++) $days[] = $campaign['days'][$first->modify('+'.($w*7+$d).' days')->format('Y-m-d')] ?? self::zeroDay();
                $weeks[] = self::week($days);
            }
            $current = $weeks[4]; $baseline = array_slice($weeks,0,4); $anomalies = []; $eligible = 0;
            foreach ($definitions as $metric=>$guard) {
                $values = array_column($baseline,$metric);
                // Every baseline week must have support; a brand-new campaign is not a baseline.
                if (min(array_column($baseline,$guard['den'])) < $guard['min'] || in_array(null,$values,true) || ($current[$metric]??null) === null) continue;
                // Additive counts may legitimately fall to zero. Rates still need a current denominator.
                if (empty($guard['count']) && $current[$guard['den']] < $guard['min']) continue;
                if (in_array($metric,['conversions','conversion_rate'],true) && min(array_column($baseline,'conversions')) < 3) continue;
                $eligible++;
                $mean = array_sum($values)/4;
                $value = (float)$current[$metric];
                $relative = abs($value-$mean)/max(abs($mean),.01);
                $sd = self::sd($values,$mean);
                $flat = $sd < .0001;
                $z = $flat ? null : abs($value-$mean)/$sd;
                if ($relative < ($flat ? .5 : .3) || (!$flat && $z < 2.5)) continue;
                // Share movements also require a material absolute difference of ten percentage points.
                if (in_array($metric,['search_is','lost_is_budget','lost_is_rank'],true) && abs($value-$mean) < .1) continue;
                $anomalies[] = ['metric'=>$metric, 'direction'=>$value>$mean?'up':'down', 'current'=>$value,
                    'baseline'=>$mean, 'relative_change'=>$mean>0?($value-$mean)/$mean:null, 'z_score'=>$z,
                    'minimum_data'=>$current[$guard['den']], 'guard'=>$guard['min']];
            }
            if (!$eligible) $insufficient++;
            if (!$anomalies) continue;
            $summaries[] = ['campaign_id'=>(string)$id, 'campaign'=>$campaign['name'], 'current'=>$current, 'anomalies'=>$anomalies, 'period'=>$period];
            foreach ($anomalies as $a) $findings[] = [
                'severity'=>'warning', 'title'=>'Search campaign anomaly: '.self::label($a['metric']).' '.$a['direction'],
                'evidence'=>$campaign['name'].' moved from a weekly baseline of '.self::formatMetric($a['metric'],$a['baseline']).' to '.self::formatMetric($a['metric'],$a['current']).'. Investigate reporting lag and account changes before interpreting this movement.',
                'period'=>$period, 'action'=>'Investigate auction conditions, query mix, tracking, budgets, and recent changes before drawing a conclusion.',
                'confidence'=>.65, 'data_confidence'=>.75, 'campaign_id'=>(string)$id, 'campaign'=>$campaign['name'], 'section'=>'ppc-anomalies',
            ];
        }
        return ['available'=>true, 'status'=>$insufficient?'partial':'ready', 'campaigns'=>$summaries, 'findings'=>$findings,
            'period'=>$period, 'message'=>$insufficient ? $insufficient.' campaign(s) lack a sufficient historical baseline.' : '',
            'method'=>'Four weekly baselines; each must meet the metric’s volume floor. Variable baselines require ≥30% movement and ≥2.5 standard deviations; flat baselines require ≥50%. Censored or missing impression shares are excluded.'];
    }

    public static function ngrams(array $terms, array $memory=[]): array
    {
        $grams = [];
        foreach ($terms as $term) {
            $query = self::normalize((string)($term['query']??''));
            $tokens = $query === '' ? [] : explode(' ',$query);
            foreach ([1,2,3] as $n) {
                $seen = [];
                for ($i=0; $i<=count($tokens)-$n; $i++) {
                    $text = implode(' ',array_slice($tokens,$i,$n));
                    if (isset($seen[$text])) continue;
                    $seen[$text] = true;
                    $key = $n.'|'.$text;
                    $grams[$key] ??= ['ngram'=>$text,'size'=>$n,'queries'=>0,'impressions'=>0,'clicks'=>0,'cost'=>0.0,'conversions'=>0.0,'query_set'=>[]];
                    // Metrics are additive across routes; support counts distinct query strings.
                    $grams[$key]['query_set'][$query] = true;
                    foreach (['impressions','clicks','cost','conversions'] as $metric) $grams[$key][$metric] += (float)($term[$metric]??0);
                }
            }
        }
        foreach ($grams as &$g) {
            $g['queries'] = count($g['query_set']);
            $g['examples'] = array_slice(array_keys($g['query_set']),0,5);
            unset($g['query_set']);
            $g['cost'] = round($g['cost'],2); $g['conversions'] = round($g['conversions'],2);
            $g['conversion_rate'] = $g['clicks']>0 ? $g['conversions']/$g['clicks'] : null;
            $g['cpa'] = $g['conversions']>0 ? $g['cost']/$g['conversions'] : null;
            $g['classification'] = 'monitor';
            if ($g['queries']>=3 && $g['clicks']>=8 && $g['cost']>0 && $g['conversions']==0) $g['classification']='waste_investigation';
            elseif ($g['queries']>=2 && $g['clicks']>=5 && $g['conversions']>=2) $g['classification']='high_performing_intent';
            $g['client_rule_context']=array_column(PpcMemory::rulesForText($g['ngram'],$memory),'content');
        }
        unset($g);
        $grams = array_values(array_filter($grams,static fn($g)=>$g['queries']>=2));
        usort($grams,static fn($a,$b)=>$b['cost']<=>$a['cost']);
        $waste = array_values(array_filter($grams,static fn($g)=>$g['classification']==='waste_investigation'));
        $wins = array_values(array_filter($grams,static fn($g)=>$g['classification']==='high_performing_intent'));
        $findings = [];
        foreach ([['rows'=>$waste,'severity'=>'warning','title'=>'Recurring n-gram waste patterns'],['rows'=>$wins,'severity'=>'opportunity','title'=>'High-performing search intent patterns']] as $group) {
            if ($group['rows']) $findings[]=['severity'=>$group['severity'],'title'=>$group['title'],'evidence'=>count($group['rows']).' recurring patterns meet review thresholds. Patterns overlap; their totals must not be summed.','period'=>'Last 30 days','action'=>'Review the underlying queries, conversion lag, and client rules before proposing changes.','confidence'=>.65,'data_confidence'=>.7,'campaign_id'=>'','section'=>'ppc-ngrams'];
        }
        return ['available'=>true,'status'=>'ready','items'=>$grams,'waste'=>$waste,'winners'=>$wins,'findings'=>$findings,'period'=>'Last 30 days','message'=>'Counts represent distinct queries. Pattern totals overlap and cannot be added together.'];
    }

    public static function routing(array $terms,array $keywords,array $negatives,array $memory=[]):array
    {
        $cases=[];
        foreach($terms as $term){if((int)($term['clicks']??0)<2)continue;$query=self::normalize((string)($term['query']??''));$currentScore=self::similarity($query,self::normalize((string)($term['keyword']??'')));$best=null;
            foreach($keywords as $keyword){if((string)($keyword['ad_group_id']??'')===(string)($term['ad_group_id']??''))continue;$candidate=self::normalize((string)($keyword['keyword']??''));if($candidate===''||!str_contains(' '.$query.' ',' '.$candidate.' '))continue;$score=self::similarity($query,$candidate);if($score<.6||$score<$currentScore+.15)continue;if(!$best||$score>$best['score'])$best=['score'=>$score,'keyword'=>$keyword];}
            if(!$best)continue;$negative_matches=PpcNegativeInventoryService::conflicts([['criterion_id'=>'route-check','keyword'=>$query,'match_type'=>'exact','campaign_id'=>(string)$best['keyword']['campaign_id'],'campaign'=>(string)$best['keyword']['campaign'],'ad_group_id'=>(string)$best['keyword']['ad_group_id'],'ad_group'=>(string)$best['keyword']['ad_group']]],$negatives);$rules=PpcMemory::rulesForText($query,$memory);
            $cases[]=['query'=>(string)$term['query'],'campaign_id'=>(string)$term['campaign_id'],'campaign'=>(string)$term['campaign'],'ad_group_id'=>(string)$term['ad_group_id'],'ad_group'=>(string)$term['ad_group'],'matched_keyword'=>(string)$term['keyword'],'better_campaign_id'=>(string)$best['keyword']['campaign_id'],'better_campaign'=>(string)$best['keyword']['campaign'],'better_ad_group_id'=>(string)$best['keyword']['ad_group_id'],'better_ad_group'=>(string)$best['keyword']['ad_group'],'better_keyword'=>(string)$best['keyword']['keyword'],'similarity'=>round($best['score'],2),'existing_negative_context'=>count($negative_matches),'client_rule_context'=>array_map(static fn($r)=>(string)$r['content'],$rules),'clicks'=>(int)$term['clicks'],'cost'=>(float)$term['cost'],'conversions'=>(float)$term['conversions']];}
        usort($cases,static fn($a,$b)=>$b['cost']<=>$a['cost']);$findings=$cases?[['severity'=>'warning','title'=>'Possible Search query routing leakage','evidence'=>count($cases).' query route(s) may have more relevant enabled keyword coverage in another ad group or campaign.','period'=>'Last 30 days + current keyword/negative configuration','action'=>'Inspect intent, match behavior, and existing negatives. Treat each route as an investigation only.','confidence'=>.7,'campaign_id'=>(string)$cases[0]['campaign_id'],'campaign'=>(string)$cases[0]['campaign'],'section'=>'ppc-routing']]:[];return ['available'=>true,'status'=>'ready','cases'=>$cases,'findings'=>$findings,'period'=>'Last 30 days + current configuration'];
    }

    public static function messaging(array $terms, array $ads, array $claims, array $website, array $memory=[]): array
    {
        $routes = []; $gaps = [];
        foreach ($terms as $term) {
            $campaign = (string)($term['campaign_id']??''); $group = (string)($term['ad_group_id']??'');
            if ($campaign === '' || $group === '') continue;
            $routes[$campaign.'|'.$group][] = $term;
        }
        foreach ($routes as $termsForRoute) {
            $route = $termsForRoute[0];
            $active = array_filter($ads,static fn($ad)=>
                ($ad['status']??'')==='enabled' && ($ad['campaign_status']??'')==='enabled' && ($ad['ad_group_status']??'')==='enabled'
                && (string)($ad['campaign_id']??'')===(string)$route['campaign_id'] && (string)($ad['ad_group_id']??'')===(string)$route['ad_group_id']);
            if (!$active) continue;
            $texts = [];
            foreach ($active as $ad) foreach (array_merge((array)($ad['headlines']??[]),(array)($ad['descriptions']??[])) as $asset) $texts[]=self::normalize((string)($asset['text']??''));
            $themes = self::ngrams($termsForRoute,$memory)['winners'];
            foreach ($themes as $theme) {
                $needle = $theme['ngram'];
                if (strlen($needle)<4 || array_filter($texts,static fn($text)=>self::hasPhrase($text,$needle))) continue;
                $source = '';
                foreach ($claims as $claim) {
                    // Saved approval applies to an individual claim with its own source.
                    if (!empty($claim['source']) && self::hasPhrase(self::normalize((string)($claim['claim']??'')),$needle)) { $source=(string)$claim['source']; break; }
                }
                if ($source === '' && !empty($website['available'])) foreach ((array)($website['pages']??[]) as $page) {
                    if (!empty($page['url']) && self::hasPhrase(self::normalize((string)($page['text']??'')),$needle)) { $source=(string)$page['url']; break; }
                }
                $gaps[] = ['theme'=>$needle,'campaign_id'=>(string)$route['campaign_id'],'campaign'=>(string)($route['campaign']??''),
                    'ad_group_id'=>(string)$route['ad_group_id'],'ad_group'=>(string)($route['ad_group']??''),
                    'queries'=>$theme['queries'],'clicks'=>$theme['clicks'],'conversions'=>$theme['conversions'],'cpa'=>$theme['cpa'],
                    'claim_verified'=>$source!=='','source'=>$source,'client_rule_context'=>$theme['client_rule_context'],
                    'recommendation'=>$source!=='' ? 'Review source-supported messaging around “'.$needle.'” in '.(string)($route['ad_group']??'this ad group').'. Verify the full wording before approving copy.' : 'Verify this theme against an approved client source before recommending copy.'];
            }
        }
        $verified = array_filter($gaps,static fn($g)=>$g['claim_verified']);
        $findings = $gaps ? [['severity'=>'opportunity','title'=>'Search intent to RSA messaging gaps','evidence'=>count($gaps).' ad-group theme gaps; '.count($verified).' have source evidence. Metrics are scoped to each ad group.','period'=>'Last 30 days + current enabled RSA configuration','action'=>'Review source-backed themes and verify the full factual claims before drafting exact copy.','confidence'=>.65,'data_confidence'=>.7,'campaign_id'=>'','section'=>'ppc-messaging']] : [];
        return ['available'=>true,'status'=>empty($website['available'])?'partial':'ready','message'=>empty($website['available'])?'Website evidence is unavailable. Only explicitly approved claim sources can support messaging.':'','gaps'=>$gaps,'findings'=>$findings,'period'=>'Last 30 days + current configuration'];
    }

    private function qualityScore(string $customer_id,bool $refresh):array
    {
        $key='wnq_ppc_qs_v2_'.md5($customer_id);if(!$refresh&&is_array($cached=get_transient($key)))return $cached;$end=current_datetime()->modify('-1 day')->format('Y-m-d');$start=current_datetime()->modify('-30 days')->format('Y-m-d');$query=new GoogleAdsQueryService();$rows=$query->select($customer_id,"SELECT campaign.id, campaign.name, ad_group.id, ad_group.name, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.quality_info.quality_score, ad_group_criterion.quality_info.search_predicted_ctr, ad_group_criterion.quality_info.creative_quality_score, ad_group_criterion.quality_info.post_click_quality_score, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.search_impression_share FROM keyword_view WHERE campaign.advertising_channel_type = 'SEARCH' AND campaign.status = 'ENABLED' AND ad_group.status = 'ENABLED' AND ad_group_criterion.status = 'ENABLED' AND segments.date BETWEEN '{$start}' AND '{$end}'");if($query->errors())return self::unavailable('Quality Score evidence is unavailable.');$items=[];
        foreach($rows as $row){$q=(array)($row['adGroupCriterion']['qualityInfo']??[]);$m=(array)($row['metrics']??[]);$score=(int)($q['qualityScore']??0);$cost=((float)($m['costMicros']??0))/1000000;$conversions=(float)($m['conversions']??0);$search_is=self::nullable($m['searchImpressionShare']??null);$significance=$cost+((int)($m['impressions']??0)/100)+($conversions*25)+($search_is===null?0:((1-$search_is)*(int)($m['impressions']??0)/100));$issues=array_filter(['expected_ctr'=>self::bucket($q['searchPredictedCtr']??''),'ad_relevance'=>self::bucket($q['creativeQualityScore']??''),'landing_page_experience'=>self::bucket($q['postClickQualityScore']??'')],static fn($v)=>$v==='below_average');if(!$issues||($cost<10&&(int)($m['clicks']??0)<10&&(int)($m['impressions']??0)<200))continue;$items[]=['campaign_id'=>(string)($row['campaign']['id']??''),'campaign'=>sanitize_text_field((string)($row['campaign']['name']??'')),'ad_group_id'=>(string)($row['adGroup']['id']??''),'ad_group'=>sanitize_text_field((string)($row['adGroup']['name']??'')),'criterion_id'=>(string)($row['adGroupCriterion']['criterionId']??''),'keyword'=>sanitize_text_field((string)($row['adGroupCriterion']['keyword']['text']??'')),'quality_score'=>$score?:null,'expected_ctr'=>self::bucket($q['searchPredictedCtr']??''),'ad_relevance'=>self::bucket($q['creativeQualityScore']??''),'landing_page_experience'=>self::bucket($q['postClickQualityScore']??''),'impressions'=>(int)($m['impressions']??0),'clicks'=>(int)($m['clicks']??0),'cost'=>round($cost,2),'conversions'=>round($conversions,2),'cpa'=>$conversions?round($cost/$conversions,2):null,'search_is'=>$search_is,'economic_significance'=>round($significance,2),'issues'=>array_keys($issues)];}
        usort($items,static fn($a,$b)=>$b['economic_significance']<=>$a['economic_significance']);$findings=$items?[['severity'=>'opportunity','title'=>'Economically significant keyword quality issues','evidence'=>count($items).' enabled Search keyword(s) have a below-average quality component and sufficient traffic or spend to merit investigation.','period'=>'Last 30 complete days + current Quality Score components','action'=>'Investigate the below-average component alongside spend, conversions, CPA, and impression share. Low Quality Score alone is not a change recommendation.','confidence'=>.82,'campaign_id'=>(string)$items[0]['campaign_id'],'campaign'=>(string)$items[0]['campaign'],'section'=>'ppc-quality-score']]:[];$result=['available'=>true,'status'=>'ready','items'=>$items,'findings'=>$findings,'period'=>'Last 30 complete days + current Quality Score components'];set_transient($key,$result,15*MINUTE_IN_SECONDS);return $result;
    }

    private static function week(array $days): array
    {
        $sum=['impressions'=>0,'clicks'=>0,'spend'=>0.0,'conversions'=>0.0];
        $weighted=['search_is'=>[0.0,0.0,true],'lost_is_budget'=>[0.0,0.0,true],'lost_is_rank'=>[0.0,0.0,true]];
        foreach ($days as $d) {
            $sum['impressions']+=$d['impressions']; $sum['clicks']+=$d['clicks']; $sum['spend']+=$d['cost']; $sum['conversions']+=$d['conversions'];
            if (!$d['impressions']) continue;
            $share=$d['search_is'];
            // Google censors shares below 10% and losses above 90%; they are not exact values.
            $eligible=($share!==null && $share>=.1 && $share<=1) ? $d['impressions']/$share : null;
            foreach (['search_is'=>'search_is','lost_budget'=>'lost_is_budget','lost_rank'=>'lost_is_rank'] as $field=>$key) {
                $value=$d[$field];
                if ($eligible===null || $value===null || $value<0 || $value>1 || ($field!=='search_is' && $value>.9)) { $weighted[$key][2]=false; continue; }
                $weighted[$key][0]+=$value*$eligible; $weighted[$key][1]+=$eligible;
            }
        }
        $sum['cpc']=$sum['clicks'] ? $sum['spend']/$sum['clicks'] : null;
        $sum['ctr']=$sum['impressions'] ? $sum['clicks']/$sum['impressions'] : null;
        $sum['conversion_rate']=$sum['clicks'] ? $sum['conversions']/$sum['clicks'] : null;
        $sum['cpa']=$sum['conversions'] ? $sum['spend']/$sum['conversions'] : null;
        foreach ($weighted as $key=>$values) $sum[$key]=$values[2] && $values[1]>0 ? $values[0]/$values[1] : null;
        return $sum;
    }
    private static function hasPhrase(string $text, string $phrase): bool { return $phrase!=='' && str_contains(' '.$text.' ',' '.$phrase.' '); }
    private static function zeroDay():array{return ['impressions'=>0,'clicks'=>0,'cost'=>0.0,'conversions'=>0.0,'search_is'=>null,'lost_budget'=>null,'lost_rank'=>null];}
    private static function sd(array $values,float $mean):float{return sqrt(array_sum(array_map(static fn($v)=>(($v-$mean)**2),$values))/max(1,count($values)));}
    private static function normalize(string $v):string{return trim(preg_replace('/\s+/',' ',preg_replace('/[^a-z0-9]+/i',' ',strtolower($v)))??'');}
    private static function similarity(string $a,string $b):float{$aa=array_unique(array_filter(explode(' ',$a)));$bb=array_unique(array_filter(explode(' ',$b)));if(!$aa||!$bb)return 0;return count(array_intersect($aa,$bb))/count(array_unique(array_merge($aa,$bb)));}
    private static function bucket($v):string{return strtolower(sanitize_key((string)$v))?:'unknown';}
    private static function nullable($v):?float{return is_numeric($v)?(float)$v:null;}
    private static function label(string $v):string{return ucwords(str_replace('_',' ',$v));}
    private static function formatMetric(string $metric,float $v):string{if(in_array($metric,['spend','cpc','cpa'],true))return number_format($v,2).' account currency units';if(in_array($metric,['ctr','conversion_rate','search_is','lost_is_budget','lost_is_rank'],true))return number_format($v*100,1).'%';return number_format($v,2);}
    private static function unavailable(string $message):array{return ['available'=>false,'status'=>'unavailable','message'=>$message,'items'=>[],'cases'=>[],'gaps'=>[],'campaigns'=>[],'findings'=>[]];}
}
