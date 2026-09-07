<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/'); define('MINUTE_IN_SECONDS',60);
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function sanitize_text_field($v){return (string)$v;}
function get_transient($key){return str_contains($key,'access_token')?'test-token':false;}
function set_transient($key,$value,$ttl){}
function wp_json_encode($v){return json_encode($v);}
function is_wp_error($v){return false;}
function wp_remote_retrieve_response_code($v){return 200;}
function wp_remote_retrieve_body($v){return json_encode($v);}
function wp_remote_post($url,$args){
    $body=json_decode($args['body'],true);
    check(!str_contains($body['query'],'LIMIT 100'),'Account totals must include every campaign');
    check(str_contains($body['query'],'customer.currency_code'),'Currency requested from account');
    $count=isset($body['pageToken'])?1:100;
    return ['results'=>array_fill(0,$count,['customer'=>['currencyCode'=>'EUR'],'campaign'=>['name'=>'Campaign','status'=>'ENABLED'],'metrics'=>['costMicros'=>1234500,'clicks'=>3,'impressions'=>20,'ctr'=>.15,'conversions'=>.5]])]+(isset($body['pageToken'])?[]:['nextPageToken'=>'second']);
}
require dirname(__DIR__).'/includes/Services/GoogleAdsClient.php';
$api=new WNQ\Services\GoogleAdsClient(array_fill_keys(['developer_token','manager_customer_id','oauth_client_id','oauth_client_secret','refresh_token'],'1234567890'));
$report=$api->accountPerformanceForRange('1234567890','2026-09-01','2026-09-07',true,false);
check(count($report['campaigns'])===101,'Pagination includes campaign 101');
check(abs($report['summary']['spend']-124.6845)<.000001,'Micros converted once and summed');
check($report['summary']['clicks']===303 && $report['summary']['impressions']===2020,'Counts reconcile');
check($report['summary']['ctr']===.15 && $report['summary']['conversions']===50.5,'Weighted CTR and fractional conversions preserved');
check($report['currency_code']==='EUR','Actual account currency returned');
echo "Ads cost, pagination and totals checks passed.\n";
