<?php
declare(strict_types=1);
namespace WNQ\Models {
    class Client { public static function getByClientId($id) { return ['client_id'=>$id]; } }
    class PpcAccount { public static function getByClientId($id) { return ['customer_id'=>'1234567890','time_zone'=>'America/New_York']; } }
}
namespace WNQ\Services {
    class GoogleAdsQueryService {
        public static $fail=false;
        public function isConfigured(){return true;}
        public function errors(){return [];}
        public function select($id,$query){
            if(self::$fail) throw new \RuntimeException('failure');
            return array_map(static fn($pair)=>['callView'=>['resourceName'=>'customers/'.$id.'/callViews/'.$pair[0],'callDurationSeconds'=>$pair[1]]],[[1,19],[2,20],[3,21],[2,20]]);
        }
    }
}
namespace {
    if(PHP_SAPI!=='cli') exit;
    define('ABSPATH',__DIR__.'/');
    function check($condition,$message){if(!$condition)throw new \RuntimeException($message);}
    function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
    function get_transient($key){return $key==='wnq_gbp_access_token'?'private-fixture-token':false;}
    function sanitize_text_field($v){return (string)$v;}
    function sanitize_textarea_field($v){return (string)$v;}
    function sanitize_key($v){return strtolower((string)$v);}
    function current_datetime(){return new \DateTimeImmutable('2026-09-07',new \DateTimeZone('America/New_York'));}
    function is_wp_error($v){return false;}
    function wp_remote_retrieve_response_code($v){return $v['code'];}
    function wp_remote_retrieve_body($v){return json_encode($v['data']);}
    function wp_remote_request($url,$args){$GLOBALS['http'][]=[$url,$args];return ['code'=>200,'data'=>$GLOBALS['gbp']];}
    require dirname(__DIR__).'/includes/Services/AnalyticsActivity.php';
    require dirname(__DIR__).'/includes/Services/GoogleBusinessProfileClient.php';
    use WNQ\Services\AnalyticsActivity as Activity;
    check(Activity::reportingPeriod('month')===['start'=>'2026-09-01','end'=>'2026-09-07'],'Month boundaries');
    check(Activity::reportingPeriod('previous_month')===['start'=>'2026-08-01','end'=>'2026-08-31'],'Previous month boundaries');
    $key='wnq_activity_'.hash('sha256','client');
    $options[$key]=['lead_events_confirmed'=>true];
    $ga=static function($body){
        $phone=in_array('phone_click',$body['dimensionFilter']['filter']['inListFilter']['values'],true);
        $dims=$phone?['202609071200','phone_click','mobile','Paid Search','1234567890','google','cpc']:['generate_lead'];
        return ['rows'=>[['dimensionValues'=>array_map(static fn($v)=>['value'=>$v],$dims),'metricValues'=>[['value'=>'4'],['value'=>'3']]]]];
    };
    $report=Activity::leadSummary('client','2026-09-01','2026-09-07',$ga);
    check($report['all_recorded_calls']===3 && $report['verified_calls']===2,'Deduplication and 20 second boundary');
    check($report['total_verified_leads']===5 && $report['website_phone_clicks']===4,'Phone clicks excluded from totals');
    check(!str_contains(Activity::leadSummary('client','2026-01-01','2026-09-07',$ga)['period_label'],'this month'),'Long date labels');
    $report=Activity::leadSummary('client','2026-09-01','2026-09-07',static function(){throw new \RuntimeException('GA unavailable');});
    check($report['verified_calls']===2 && $report['total_verified_leads']===null,'GA failure preserves Ads');
    \WNQ\Services\GoogleAdsQueryService::$fail=true;
    $report=Activity::leadSummary('client','2026-09-01','2026-09-07',$ga);
    check($report['form_leads']===3 && $report['verified_calls']===null,'Ads failure preserves GA');
    \WNQ\Services\GoogleAdsQueryService::$fail=false;
    $options[$key]=[];
    check(Activity::leadSummary('client','2026-09-01','2026-09-07',$ga)['total_verified_leads']===5,'GA4 key events count without a local confirmation checkbox');
    $options[$key]=['lead_events_confirmed'=>true];
    $limited=static function($body)use($ga){$data=$ga($body);$data['metadata']['samplingMetadatas']=[['samplesReadCount'=>1]];return $data;};
    check(Activity::leadSummary('client','2026-09-01','2026-09-07',$limited)['total_verified_leads']===null,'Sampled reports do not supply complete totals');
    $options['wnq_gbp_client_mappings']=['client'=>['location_name'=>'locations/123','location_title'=>'Example']];
    $metrics=['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'=>50,'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'=>30,'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'=>15,'BUSINESS_IMPRESSIONS_MOBILE_MAPS'=>25,'CALL_CLICKS'=>4,'WEBSITE_CLICKS'=>9,'BUSINESS_DIRECTION_REQUESTS'=>2];
    $items=[];foreach($metrics as $metric=>$value)$items[]=['dailyMetric'=>$metric,'timeSeries'=>['datedValues'=>[['date'=>['year'=>2026,'month'=>9,'day'=>7],'value'=>(string)$value]]]];
    $gbp=['multiDailyMetricTimeSeries'=>[['dailyMetricTimeSeries'=>$items]]];
    $api=new \WNQ\Services\GoogleBusinessProfileClient();
    $report=$api->analyticsForClient('client','2026-09-01','2026-09-07');
    check($report['metrics']['profile_views']===120 && $report['metrics']['maps_views']===40,'GBP totals combine both devices');
    check(!str_contains($http[0][0],'dailyMetrics=BUSINESS_IMPRESSIONS_MAPS&'),'No invalid GBP enum');
    check($http[0][1]['method']==='GET','Read only GBP request');
    check(!str_contains(json_encode($report),'private-fixture-token'),'No token returned');
    $gbp=[];
    check($api->analyticsForClient('client','2026-09-01','2026-09-07')['status']==='unavailable','Malformed GBP is not zero activity');
    $gbp=['multiDailyMetricTimeSeries'=>[['dailyMetricTimeSeries'=>[$items[0]]]]];
    $report=$api->analyticsForClient('client','2026-09-01','2026-09-07');
    check($report['status']==='partial' && $report['metrics']['maps_views']===null,'Missing GBP metrics are unavailable');
    check($api->analyticsForClient('other','2026-09-01','2026-09-07')['status']==='not_linked','Client isolation');
    echo "Analytics audit regression checks passed.\n";
}
