<?php
declare(strict_types=1);
require __DIR__.'/analytics-audit.php';
use WNQ\Services\AnalyticsActivity as Activity;
$request=static function($body) {
    check($body['dimensionFilter']['filter']['inListFilter']['values']===['generate_lead','email_click'],'Only intended form/email events');
    check($body['orderBys'][0]['desc']===true,'Newest GA4 activity requested first');
    return ['metadata'=>['timeZone'=>'UTC'],'rows'=>array_map(static fn($r)=>[
        'dimensionValues'=>array_map(static fn($v)=>['value'=>$v],[$r[0],$r[1],'mobile','Organic Search','']),
        'metricValues'=>[['value'=>$r[2]],['value'=>$r[3]]],
    ],[['202609061800','generate_lead','3','2'],['202609061700','email_click','1','0'],['202609061900','generate_lead','2','0']])];
};
$report=Activity::recentActivity('client','2026-09-01','2026-09-07',$request);
check($report['status']==='available' && count($report['rows'])===5,'Three deduplicated calls plus form/email rows');
check($report['rows'][0]['type']==='Form key event' && $report['rows'][0]['count']===2.0,'Only generate_lead key events counted');
check($report['rows'][0]['time']==='2026-09-06 14:00:00','UTC GA4 converted to portal timezone');
check($report['rows'][1]['type']==='Email event' && $report['rows'][1]['count']===1,'Non-key email interaction visible');
check($report['yesterday']==='2026-09-06','Yesterday uses portal calendar');
$failed=Activity::recentActivity('client','2026-09-01','2026-09-07',null);
check($failed['status']==='partial' && count($failed['rows'])===3,'GA failure preserves calls');
\WNQ\Services\GoogleAdsQueryService::$fail=true;
$failed=Activity::recentActivity('client','2026-09-01','2026-09-07',$request);
check($failed['status']==='partial' && count($failed['rows'])===2,'Ads failure preserves GA4');
$empty=Activity::recentActivity('client','2026-09-01','2026-09-07',static fn($body)=>['rows'=>[]]);
check($empty['status']==='partial' && $empty['sources']['Google Ads']==='unavailable','Empty GA4 does not conceal Ads failure');
echo "Recent lead activity checks passed.\n";
