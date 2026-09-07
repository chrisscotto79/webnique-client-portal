<?php
declare(strict_types=1);
namespace WNQ\Models {
    final class AnalyticsConfig {
        public static $broken=false, $config=null, $credentials=null;
        public static function getClientConfig($id) { if(self::$broken) throw new \RuntimeException('fixture failure'); return self::$config; }
        public static function getCredentials() { return self::$credentials; }
    }
    final class ClientPortal {
        public static $lastClient='';
        public static function getAdsReportData($id,$start,$end,$refresh) {
            self::$lastClient=$id;
            return ['has_linked_account'=>true,'configured'=>true,'summary'=>['clicks'=>12,'impressions'=>100,'ctr'=>.12,'conversions'=>2,'cost'=>999,'spend'=>999,'cpc'=>99,'refresh_token'=>'secret'],'campaigns'=>[['name'=>'Search','status'=>'enabled','clicks'=>12,'cost'=>999,'billing'=>'secret']]];
        }
    }
}
namespace {
    if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
    define('ABSPATH',__DIR__.'/');
    set_error_handler(static function($severity,$message,$file,$line){throw new \ErrorException($message,0,$severity,$file,$line);});
    function sanitize_text_field($v){return trim(strip_tags((string)$v));}
    function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
    function get_option($key,$default=[]){return $default;}
    function absint($v){return abs((int)$v);}
    function current_datetime(){return new DateTimeImmutable('2026-09-07',new DateTimeZone('America/New_York'));}
    function check_ajax_referer($a,$b){$GLOBALS['nonceChecked']=true;}
    function current_user_can($v){return $GLOBALS['admin']??true;}
    function is_user_logged_in(){return true;}
    function get_current_user_id(){return 1;}
    function get_user_meta($id,$key,$single){return 'own-client';}
    function wp_send_json_success($data){$GLOBALS['result']=$data;}
    function wp_send_json_error($data){throw new RuntimeException('Unexpected endpoint error');}
    function get_transient($key){return $GLOBALS['cache'][$key]??false;}
    function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;}
    function delete_transient($key){unset($GLOBALS['cache'][$key]);}
    function wp_json_encode($v){return json_encode($v);}
    function is_wp_error($v){return false;}
    function wp_remote_post($url,$args){$GLOBALS['requests'][]=['url'=>$url,'body'=>json_decode($args['body'],true)];return $GLOBALS['response'];}
    function wp_remote_retrieve_response_code($r){return $r['code'];}
    function wp_remote_retrieve_body($r){return $r['body'];}
    function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
    require dirname(__DIR__).'/admin/AnalyticsAdmin.php';
    require dirname(__DIR__).'/includes/API/GoogleSearchConsole.php';
    use WNQ\Admin\AnalyticsAdmin as Admin;
    use WNQ\Models\AnalyticsConfig as Config;
    use WNQ\Models\ClientPortal;
    function invoke($name,...$args){return (new ReflectionMethod(Admin::class,$name))->invoke(null,...$args);}
    $_POST=['client_id'=>'selected','date_range'=>7];
    Admin::ajaxGetAnalyticsData();
    check($GLOBALS['nonceChecked'],'Nonce must be checked.');
    check($result['period']['start']==='2026-09-01' && $result['period']['end']==='2026-09-07','Seven days must contain exactly seven inclusive dates.');
    check($result['google_ads']['status']==='available' && $result['ga4']['status']==='unavailable','Ads must work without GA credentials.');
    check($result['google_ads']['data']['cost']===999.0,'Ads cost must use the saved report spend.');
    foreach(['spend','cpc','billing','refresh_token','secret'] as $forbidden) check(!str_contains(json_encode($result),$forbidden),'Ads response must omit '.$forbidden);
    $GLOBALS['admin']=false;Admin::ajaxGetAnalyticsData();
    check(ClientPortal::$lastClient==='own-client','Non-admin must be scoped to their own client.');
    $GLOBALS['admin']=true;Config::$broken=true;Admin::ajaxGetAnalyticsData();
    check($result['google_ads']['status']==='available','Broken analytics configuration must not block Ads.');
    Config::$broken=false;
    $key=invoke('tokenCacheKey',['client_email'=>'a','private_key'=>'one']);
    check($key!==invoke('tokenCacheKey',['client_email'=>'a','private_key'=>'two']),'Rotated keys need a new token cache.');
    check($key!==invoke('tokenCacheKey',['client_email'=>'b','private_key'=>'one']),'Different identities must not share token cache.');
    $GLOBALS['response']=['code'=>200,'body'=>'not-json'];
    try{invoke('makeGARequest','secret','properties/123',[]);throw new LogicException('Accepted malformed JSON');}catch(Exception $e){check(!$e instanceof LogicException,'Malformed response must fail.');}
    $requests=[];
    try{invoke('makeGARequest','secret','https://example.com',[]);}catch(Exception $e){}
    check($requests===[],'Invalid property must not initiate an HTTP request.');
    $GLOBALS['response']=['code'=>200,'body'=>json_encode(['rows'=>[['dimensionValues'=>[['value'=>'Organic Search']],'metricValues'=>[['value'=>'25'],['value'=>'20']]]]])];
    $sources=invoke('fetchTrafficSources','secret','properties/123','2026-09-01','2026-09-07',100);
    check($sources[0]['percentage']===25.0,'Channel percentages must use all sessions, not only returned rows.');
    $GLOBALS['response']=['code'=>200,'body'=>json_encode(['rows'=>[]])];
    invoke('fetchTopPages','secret','properties/123','2026-09-01','2026-09-07');
    check(end($requests)['body']['dimensions']===[['name'=>'pagePath']],'Top pages must aggregate by path, not split by titles.');
    $class=new ReflectionClass(WNQ\API\GoogleSearchConsole::class);
    $gsc=$class->newInstanceWithoutConstructor();
    $cacheMethod=$class->getMethod('getFromCache');
    $GLOBALS['cache']['wnq_gsc_'.md5('v2|fixture')]=['cached'=>true];
    check($cacheMethod->invoke($gsc,'fixture')===['cached'=>true],'Normal GSC requests retain caching.');
    $class->getProperty('refresh')->setValue($gsc,true);
    check($cacheMethod->invoke($gsc,'fixture')===false,'Explicit refresh must bypass the GSC report cache.');
    echo "Analytics regression checks passed.\n";
}
