<?php
declare(strict_types=1);
namespace WNQ\Models {
    final class PpcAccount {
        public static function getByClientId($client) { return ['customer_id'=>$client==='second'?'9999999999':'1234567890','time_zone'=>'America/New_York']; }
    }
}
namespace WNQ\Services {
    final class GoogleAdsQueryService {
        public static $rows=[], $error=[], $queries=[];
        public function isConfigured(){return true;}
        public function select($id,$query){self::$queries[]=[$id,$query];return self::$rows;}
        public function errors(){return self::$error;}
    }
}
namespace {
    if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
    require __DIR__.'/analytics-regression.php';
    function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
    function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
    function wp_salt($scheme){return 'activity-test-salt-not-a-production-key';}
    function wp_timezone(){return new DateTimeZone('America/New_York');}
    function wp_unslash($v){return $v;}
    function nocache_headers(){$GLOBALS['nocache']=true;}
    function wp_remote_get($url,$args){
        $GLOBALS['ghl_requests'][]=[$url,$args];
        if(!$GLOBALS['ghl_queue'])throw new RuntimeException('Unexpected GHL request');
        return array_shift($GLOBALS['ghl_queue']);
    }
    function ghlReply($items,$next=null,$total=null){return ['code'=>200,'body'=>json_encode(['submissions'=>$items,'meta'=>['total'=>$total??count($items),'nextPage'=>$next]])];}
    use WNQ\Services\AnalyticsActivity as Activity;
    use WNQ\Services\GoogleAdsQueryService as Ads;
    use WNQ\Models\AnalyticsConfig as Config;
    use WNQ\Admin\AnalyticsAdmin as Admin;
    check(Activity::attribution('Paid Search','1234567890','1234567890')==='Google Ads','Ads attribution needs exact account match');
    check(Activity::attribution('Paid Search','9999999999','1234567890')!=='Google Ads','Other Ads accounts must not be attributed to linked account');
    check(Activity::attribution('Paid Search','(not set)','1234567890')!=='Google Ads','Paid search alone is not proof of Google Ads');
    check(Activity::attribution('Organic Search','(not set)','1234567890')==='Organic search','Organic Search channel should be retained');
    check(Activity::attribution('Organic Search','1234567890','1234567890')==='Google Ads','Verified Ads ID takes precedence over contradictory channel');
    check(Activity::attribution('Unassigned','','')==='Unknown','Unassigned remains unknown');
    check(Activity::save('selected','Location123','fixture-private-token','phone_click, click_to_call'),'Settings must save');
    $stored=Activity::settings('selected');
    check(!str_contains(json_encode($stored),'fixture-private-token'),'Token must be encrypted at rest');
    check(Activity::unseal($stored['token'],'selected','Location123')==='fixture-private-token','Token should decrypt for exact mapping');
    check(Activity::unseal($stored['token'],'second','Location123')==='','Token must be bound to client');
    check(Activity::unseal($stored['token'],'selected','Different123')==='','Token must be bound to location');
    check(!Activity::save('selected','Different123','','phone_click'),'Cannot carry token across locations');
    check(!Activity::save('selected','Location123','','bad-event'),'Reject invalid event names');
    check(Activity::save('selected','Location123','','phone_click, click_to_call'),'Blank token preserves current credential');
    $ga=function($body) {
        check(count($body['dimensions'])===7,'Use explicit dimensions');
        check($body['dimensionFilter']['filter']['inListFilter']['values']===['phone_click','click_to_call'],'Use client event settings');
        return ['metadata'=>['timeZone'=>'America/New_York','subjectToThresholding'=>true],'rowCount'=>2,'rows'=>[
            ['dimensionValues'=>array_map(static fn($v)=>['value'=>$v],['202609071245','phone_click','mobile','Organic Search','(not set)','google','organic']),'metricValues'=>[['value'=>'3'],['value'=>'2']]],
            ['dimensionValues'=>array_map(static fn($v)=>['value'=>$v],['202609071246','click_to_call','desktop','Paid Search','1234567890','google','cpc']),'metricValues'=>[['value'=>'1'],['value'=>'1']]],
        ]];
    };
    $events=Activity::phoneEvents('selected','2026-09-01','2026-09-07',$ga);
    check($events['status']==='partial' && $events['rows'][0]['count']===3,'Thresholding must be disclosed and event grouping preserved');
    check($events['rows'][0]['time']==='2026-09-07 12:45' && $events['rows'][1]['source']==='Google Ads','Time and Ads attribution should be retained');
    Ads::$rows=[['callView'=>['resourceName'=>'customers/1234567890/callViews/1','startCallDateTime'=>'2026-09-07 12:00:00','callDurationSeconds'=>60,'callStatus'=>'RECEIVED'],'campaign'=>['name'=>'Search Campaign'],'customer'=>['timeZone'=>'America/New_York']]];
    $calls=Activity::adsCalls('selected','2026-09-01','2026-09-07');
    check($calls['rows'][0]['duration']===60,'Call duration must be returned without cost or caller details');
    check(str_contains(Ads::$queries[0][1],"campaign.advertising_channel_type = 'SEARCH'"),'Call query must be Search-only');
    try{Activity::adsCalls('second','2026-09-01','2026-09-07');throw new LogicException('Accepted mixed account');}catch(RuntimeException $e){}
    $one=['id'=>'one','createdAt'=>'2026-09-07T14:30:00.000Z','formId'=>'form-one','email'=>'private@example.com','others'=>['secret_answer'=>'not returned']];
    $two=['id'=>'two','createdAt'=>'2026-09-08T01:00:00Z','formId'=>'form-two'];
    $outside=['id'=>'old','createdAt'=>'2026-09-01T01:00:00Z','formId'=>'form-old'];
    $ghl_queue=[ghlReply([$one],2,3),ghlReply([$two,$outside],null,3)];$ghl_requests=[];
    $forms=Activity::forms('selected','2026-09-01','2026-09-07');
    check(count($forms['rows'])===2 && $forms['rows'][0]['time']==='2026-09-07 21:00:00','GHL dates must use explicit timezone and displayed range');
    check(!str_contains(json_encode($forms),'private@example.com')&&!str_contains(json_encode($forms),'secret_answer'),'Only form arrival metadata may leave server');
    check(str_contains($ghl_requests[1][0],'page=2')&&str_contains($ghl_requests[0][0],'locationId=Location123'),'Pagination preserves exact location');
    $ghl_queue=[ghlReply([$one],2,2),['code'=>503,'body'=>'secret-server-error']];
    try{Activity::forms('selected','2026-09-01','2026-09-07');throw new LogicException('Accepted failed page');}catch(RuntimeException $e){}
    Config::$config=['ga4_property_id'=>'properties/123'];Config::$credentials=null;
    $_POST=['client_id'=>'selected','provider'=>'ads_calls','date_range'=>7,'refresh'=>1];
    Admin::ajaxGetActivity();check($result['status']==='available' && $nocache,'Ads calls work without GA4 and prevent browser caching');
    $_POST['provider']='phone_events';Admin::ajaxGetActivity();check($result['status']==='unavailable','GA4 failure is isolated');
    $admin=false;
    try{Admin::ajaxGetActivity();throw new LogicException('Client user accessed activity');}catch(RuntimeException $e){}
    $admin=true;
    check(Activity::save('selected','','','phone_click',true),'Disconnect should work');
    check(Activity::forms('selected','2026-09-01','2026-09-07')['status']==='not_linked','Disconnected account must not return cached private forms');
    echo "Analytics activity regression checks passed.\n";
}
