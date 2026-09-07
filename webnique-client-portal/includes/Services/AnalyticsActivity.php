<?php
/** Read-only, explicitly scoped activity feeds for the backend Analytics tab. */
namespace WNQ\Services;
use WNQ\Models\PpcAccount;
if (!defined('ABSPATH')) exit;

final class AnalyticsActivity
{
    public static function reportingPeriod($selection): array
    {
        $today=current_datetime()->setTime(0,0);
        if ($selection==='month') return ['start'=>$today->format('Y-m-01'),'end'=>$today->format('Y-m-d')];
        if ($selection==='previous_month') return ['start'=>$today->modify('first day of last month')->format('Y-m-d'),'end'=>$today->modify('last day of last month')->format('Y-m-d')];
        $days=in_array((int)$selection,[7,30,90,180,365,730],true)?(int)$selection:30;
        return ['start'=>$today->modify('-'.($days-1).' days')->format('Y-m-d'),'end'=>$today->format('Y-m-d')];
    }
    public static function unavailable(string $message, string $status='unavailable'): array
    {
        return ['status'=>$status,'message'=>$message,'rows'=>[]];
    }

    public static function settings(string $client): array
    {
        $value=get_option('wnq_activity_'.hash('sha256',$client),[]);
        return is_array($value)?$value:[];
    }

    public static function phoneNames(string $client): array
    {
        return self::eventNames($client, 'phone_events', ['phone_click']);
    }

    public static function formNames(string $client): array
    {
        return self::eventNames($client, 'form_events', ['generate_lead']);
    }

    public static function emailNames(string $client): array
    {
        return self::eventNames($client, 'email_events', ['email_click']);
    }

    private static function eventNames(string $client, string $key, array $defaults): array
    {
        $names = (array)(self::settings($client)[$key] ?? $defaults);
        $names = array_values(array_unique(array_filter(array_map('trim', $names), static fn($name) => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', (string)$name))));
        return $names ?: $defaults;
    }

    /** Minimum duration used to classify a Google Ads call as verified. */
    public static function callThreshold(string $client): int
    {
        $value = self::settings($client)['min_call_duration'] ?? null;
        if (is_numeric($value) && (int)$value >= 0 && (int)$value <= 3600) return (int)$value;
        // SNS Hauling's existing qualification rule is 20 seconds; retain that safe default for clients without an override.
        return 20;
    }

    /** Resolve the Analytics identity to the portal identity used by Reports/PPC. No name guessing. */
    public static function adsClient(string $client): string
    {
        $explicit=(string)(self::settings($client)['ads_portal_client_id']??'');
        if ($explicit!=='') {
            if (class_exists('\\WNQ\\Models\\Client') && !\WNQ\Models\Client::getByClientId($explicit)) throw new \RuntimeException('Saved Ads client mapping no longer exists.');
            return $explicit;
        }
        if (class_exists('\\WNQ\\Models\\Client') && \WNQ\Models\Client::getByClientId($client)) return $client;
        $legacy=get_option('wnq_google_ads_settings_'.md5($client),[]);
        if (preg_match('/^\d{10}$/',preg_replace('/\D/','',(string)($legacy['customer_id']??'')))) return $client;
        try { $config=\WNQ\Models\AnalyticsConfig::getClientConfig($client)?:[]; }
        catch (\Throwable $e) { return $client; }
        $property=self::property((string)($config['ga4_property_id']??''));
        $site=self::site((string)($config['website_url']??''));
        $matches=[];
        foreach (class_exists('\\WNQ\\Models\\Client') ? \WNQ\Models\Client::getAll() : [] as $candidate) {
            $otherProperty=self::property((string)($candidate['google_analytics_property_id']??''));
            $otherSite=self::site((string)($candidate['website']??''));
            // A contradictory configured property or website is not safe to auto-resolve.
            if ($property!=='' && $otherProperty!=='' && $property!==$otherProperty) continue;
            if ($site!=='' && $otherSite!=='' && $site!==$otherSite) continue;
            if (($property!=='' && $property===$otherProperty) || ($site!=='' && $site===$otherSite)) $matches[(string)$candidate['client_id']]=true;
        }
        if (count($matches)>1) throw new \RuntimeException('Ambiguous Ads client mapping; select the portal client in tracking settings.');
        return $matches ? (string)array_key_first($matches) : $client;
    }

    private static function property(string $value): string
    {
        return preg_match('#^(?:properties/)?([0-9]+)$#',trim($value),$m)?$m[1]:'';
    }

    private static function site(string $value): string
    {
        $value=trim($value);
        if ($value==='' || !preg_match('#^https?://#i',$value)) return '';
        $parts=parse_url($value);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['query'])) return '';
        return strtolower(preg_replace('/^www\./i','',(string)$parts['host'])).(isset($parts['port'])?':'.$parts['port']:'').rtrim((string)($parts['path']??''),'/');
    }

    public static function save(string $client,string $portalClient,string $events,string $formEvents='generate_lead',string $emailEvents='email_click',$threshold=20,bool $confirmed=false): bool
    {
        if ($client==='') return false;
        $names=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\s,]+/',$events)?:[]))));
        if (!$names || count($names)>20) return false;
        foreach ($names as $name) if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/',$name)) return false;
        $parse = static function(string $input): array {
            $out=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\\s,]+/',$input)?:[]))));
            if (!$out || count($out)>20) return [];
            foreach ($out as $name) if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/',$name)) return [];
            return $out;
        };
        $form=$parse($formEvents); $email=$parse($emailEvents);
        if (!$form || !$email || !is_numeric($threshold) || (int)$threshold<0 || (int)$threshold>3600) return false;
        if (array_intersect($names,$form) || array_intersect($names,$email) || array_intersect($form,$email)) return false;
        if ($portalClient!=='' && !\WNQ\Models\Client::getByClientId($portalClient)) return false;
        // Preserve retired encrypted GHL settings without reading or using them.
        $value=self::settings($client);
        $value['phone_events']=$names;
        $value['form_events']=$form;
        $value['email_events']=$email;
        $value['lead_events_confirmed']=$confirmed;
        $value['min_call_duration']=(int)$threshold;
        $value['ads_portal_client_id']=$portalClient;
        $key='wnq_activity_'.hash('sha256',$client);
        return update_option($key,$value,false) || get_option($key) === $value;
    }

    public static function attribution(string $channel,string $adsId,string $linkedId): string
    {
        if (preg_match('/^[0-9]{10}$/',$adsId)) return $linkedId!==''&&hash_equals($linkedId,$adsId)?'Google Ads':'Other';
        if ($channel==='Organic Search') return 'Organic search';
        if ($channel==='' || in_array($channel,['Unassigned','(not set)'],true)) return 'Unknown';
        return 'Other';
    }

    public static function phoneEvents(string $client,string $start,string $end,callable $request): array
    {
        try { $connection=PpcAccount::getByClientId(self::adsClient($client))?:[]; } catch (\Throwable $e) { $connection=[]; }
        $body=[
            'dateRanges'=>[['startDate'=>$start,'endDate'=>$end]],
            'dimensions'=>array_map(static fn($n)=>['name'=>$n],['dateHourMinute','eventName','deviceCategory','sessionDefaultChannelGroup','sessionGoogleAdsCustomerId','sessionSource','sessionMedium']),
            'metrics'=>[['name'=>'eventCount'],['name'=>'keyEvents']],
            'dimensionFilter'=>['filter'=>['fieldName'=>'eventName','inListFilter'=>['values'=>self::phoneNames($client)]]],
            'orderBys'=>[['dimension'=>['dimensionName'=>'dateHourMinute'],'desc'=>true]],
            'limit'=>1000,
        ];
        $data=$request($body);
        $rows=[];
        foreach ((array)($data['rows']??[]) as $row) {
            $d=array_column((array)($row['dimensionValues']??[]),'value');
            $m=array_column((array)($row['metricValues']??[]),'value');
            if (count($d)!==7 || count($m)!==2 || !preg_match('/^\d{12}$/',(string)$d[0])) throw new \RuntimeException('Invalid event report.');
            $timezone=(string)($data['metadata']['timeZone']??'UTC');
            try { $tz=new \DateTimeZone($timezone); } catch (\Throwable $e) { $tz=new \DateTimeZone('UTC'); $timezone='UTC'; }
            $date=\DateTimeImmutable::createFromFormat('!YmdHi',(string)$d[0],$tz);
            if (!$date || $date->format('YmdHi')!==$d[0]) throw new \RuntimeException('Invalid event time.');
            $rows[]=['time'=>$date->format('Y-m-d H:i'),'event'=>sanitize_text_field($d[1]),'device'=>sanitize_text_field($d[2]),
                'source'=>self::attribution($d[3],$d[4],(string)($connection['customer_id']??'')),
                'channel'=>sanitize_text_field($d[3]),'count'=>max(0,(int)$m[0]),'key_events'=>max(0,(float)$m[1])];
        }
        $partial=(int)($data['rowCount']??count($rows))>count($rows) || !empty($data['metadata']['subjectToThresholding']) || !empty($data['metadata']['dataLossFromOtherRow']) || !empty($data['metadata']['samplingMetadatas']);
        return ['status'=>$partial?'partial':'available','rows'=>$rows,'timezone'=>sanitize_text_field((string)($data['metadata']['timeZone']??'GA4 property timezone')),
            'message'=>'Phone clicks, not confirmed calls. Rows group events within one minute by device and session attribution. Google Ads requires the exact linked Ads account ID; unverified paid traffic stays Other. These may overlap Ads calls. '.($partial?'Coverage is limited by the 1,000-row cap or GA4 reporting restrictions.':'')];
    }

    public static function adsCalls(string $client,string $start,string $end): array
    {
        $connection=PpcAccount::getByClientId(self::adsClient($client))?:[];
        $id=(string)($connection['customer_id']??'');
        if (!preg_match('/^\d{10}$/',$id)) return self::unavailable('No Google Ads account is linked to this client.','not_linked');
        $api=new GoogleAdsQueryService();
        if (!$api->isConfigured()) return self::unavailable('Google Ads credentials are unavailable.');
        $rows=$api->select($id,"SELECT call_view.resource_name, call_view.start_call_date_time, call_view.call_duration_seconds, call_view.call_status, campaign.name, customer.time_zone FROM call_view WHERE campaign.advertising_channel_type = 'SEARCH' AND call_view.start_call_date_time >= '{$start} 00:00:00' AND call_view.start_call_date_time <= '{$end} 23:59:59' ORDER BY call_view.start_call_date_time DESC LIMIT 1000");
        if ($api->errors()) return self::unavailable('Google Ads call records are unavailable. Check call reporting and account access.');
        $result=[];$timezone=(string)($connection['time_zone']??'Google Ads account timezone');
        foreach ($rows as $row) {
            $call=(array)($row['callView']??[]);
            $record=(string)($call['resourceName']??'');
            if (!preg_match('#^customers/'.preg_quote($id,'#').'/callViews/[^/]+$#',$record)) throw new \RuntimeException('Call account mismatch.');
            $timezone=(string)($row['customer']['timeZone']??$timezone);
            $result[]=['record_id'=>$record,'time'=>sanitize_text_field((string)($call['startCallDateTime']??'')),'source'=>'Google Ads',
                'campaign'=>sanitize_text_field((string)($row['campaign']['name']??'')),'duration'=>max(0,(int)($call['callDurationSeconds']??0)),
                'status'=>sanitize_text_field((string)($call['callStatus']??'UNKNOWN'))];
        }
        // call_view can contain duplicate rows after retries; the resource name is the stable record identity.
        $unique=[]; $deduped=[];
        foreach ($result as $row) { $key=(string)($row['record_id']??''); if ($key!=='' && isset($unique[$key])) continue; if ($key!=='') $unique[$key]=true; $deduped[]=$row; }
        return ['status'=>count($rows)>=1000?'partial':'available','rows'=>$deduped,'timezone'=>$timezone,
            'message'=>'Search ad call records reported by Google Ads; not all website calls or unique leads. Call reporting must be enabled. Recordings are not available through this feed.'.(count($rows)>=1000?' Showing the latest 1,000 calls.':'')];
    }

    /** Report configured GA4 key events used as confirmed form and email leads. */
    public static function leadEvents(string $client,string $start,string $end,callable $request): array
    {
        if (empty(self::settings($client)['lead_events_confirmed'])) return self::unavailable('Confirm that the configured form and email events record completed submissions in Tracking connections. Clicks alone cannot verify leads.');
        // Keep event categories mutually exclusive so one GA4 event cannot become two lead types.
        $phone=self::phoneNames($client);
        $formNames=array_values(array_diff(self::formNames($client),$phone));
        $emailNames=array_values(array_diff(self::emailNames($client),array_merge($phone,$formNames)));
        $names=array_values(array_unique(array_merge($formNames,$emailNames)));
        if (!$names) return self::unavailable('No distinct GA4 form or email lead events are configured.');
        $data=$request(['dateRanges'=>[['startDate'=>$start,'endDate'=>$end]],
            'dimensions'=>[['name'=>'eventName']],
            'metrics'=>[['name'=>'eventCount'],['name'=>'keyEvents']],
            'dimensionFilter'=>['filter'=>['fieldName'=>'eventName','inListFilter'=>['values'=>$names]]], 'limit'=>1000]);
        $rows=[];
        foreach ((array)($data['rows']??[]) as $row) {
            $d=array_column((array)($row['dimensionValues']??[]),'value'); $m=array_column((array)($row['metricValues']??[]),'value');
            if (count($d)!==1 || count($m)!==2) throw new \RuntimeException('Invalid lead event report.');
            $event=sanitize_text_field($d[0]); if (!in_array($event,$names,true)) continue;
            $rows[]=['event'=>$event,'count'=>max(0,(int)$m[0]),'key_events'=>max(0,(float)$m[1])];
        }
        $partial=(int)($data['rowCount']??count($rows))>count($rows) || !empty($data['metadata']['subjectToThresholding']) || !empty($data['metadata']['dataLossFromOtherRow']) || !empty($data['metadata']['samplingMetadatas']);
        $form=$email=0;
        foreach ($rows as $row) { if (in_array($row['event'],$formNames,true)) $form+=(int)$row['key_events']; if (in_array($row['event'],$emailNames,true)) $email+=(int)$row['key_events']; }
        return ['status'=>$partial?'partial':'available','rows'=>$rows,'form_leads'=>$form,'email_leads'=>$email,
            'message'=>'Form and email leads are counted only when the configured GA4 events are marked as key events; raw event counts are not treated as confirmed leads.'.($partial?' Coverage may be limited by GA4 reporting restrictions.':'')];
    }

    /** Combine independent Ads call records and GA4 lead evidence for the Client Analytics summary. */
    public static function leadSummary(string $client,string $start,string $end,?callable $request=null): array
    {
        try { $ads=self::adsCalls($client,$start,$end); } catch (\Throwable $e) { $ads=self::unavailable('Google Ads call records are unavailable. Check the linked account and call reporting permissions.'); }
        $threshold=self::callThreshold($client);
        $adsAvailable=in_array($ads['status'],['available','partial'],true);
        $all=$verified=null;
        if ($adsAvailable) { $all=count($ads['rows']); $verified=0; foreach ($ads['rows'] as $row) if ((int)($row['duration']??0)>=$threshold) $verified++; }
        $phones=$leads=$gaUnavailable=null;
        if ($request) {
            try { $phones=self::phoneEvents($client,$start,$end,$request); } catch (\Throwable $e) { $phones=self::unavailable('GA4 phone-click data are unavailable.'); }
            try { $leads=self::leadEvents($client,$start,$end,$request); } catch (\Throwable $e) { $leads=self::unavailable('GA4 form and email lead data are unavailable.'); }
            if (!$phones || !$leads || !in_array($phones['status'],['available','partial'],true) || !in_array($leads['status'],['available','partial'],true)) $gaUnavailable=self::unavailable(($leads['message']??'').' '.($phones['status']==='unavailable'?($phones['message']??''):''));
        }
        else $gaUnavailable=self::unavailable('GA4 is unavailable. Google Ads call counts can still be shown independently.');
        $gaAvailable=$phones && $leads && in_array($phones['status'],['available','partial'],true) && in_array($leads['status'],['available','partial'],true);
        $breakdown=['google_ads_recorded_calls'=>$all,'google_ads_verified_calls'=>$verified,'ga4_ads_phone_clicks'=>null,'ga4_organic_phone_clicks'=>null,'ga4_other_phone_clicks'=>null,'ga4_unknown_phone_clicks'=>null,'forms'=>$leads['form_leads']??null,'emails'=>$leads['email_leads']??null];
        if ($phones && in_array($phones['status'],['available','partial'],true)) { $counts=['Google Ads'=>0,'Organic search'=>0,'Other'=>0,'Unknown'=>0]; foreach ($phones['rows'] as $row) $counts[$row['source']??'Unknown']=($counts[$row['source']??'Unknown']??0)+(int)($row['count']??0); $breakdown['ga4_ads_phone_clicks']=$counts['Google Ads'];$breakdown['ga4_organic_phone_clicks']=$counts['Organic search'];$breakdown['ga4_other_phone_clicks']=$counts['Other'];$breakdown['ga4_unknown_phone_clicks']=$counts['Unknown']; }
        $form=$breakdown['forms']; $email=$breakdown['emails']; $total=($verified!==null && $form!==null && $email!==null)?$verified+$form+$email:null;
        if (($ads['status']??'')!=='available' || ($leads['status']??'')!=='available') $total=null;
        $periodLabel='during '.$start.' – '.$end;
        $unmatchedPaid=false;
        if ($phones && in_array($phones['status'],['available','partial'],true)) foreach ($phones['rows'] as $row) {
            $channel=strtolower((string)($row['channel']??''));
            if (($row['source']??'')==='Other' && (str_contains($channel,'paid') || str_contains($channel,'cpc') || str_contains($channel,'cross-network'))) { $unmatchedPaid=true; break; }
        }
        $warning=$unmatchedPaid?'Some paid phone-click activity could not be attributed to the linked Google Ads account. These interactions are excluded from Verified Calls.':'';
        return ['status'=>($adsAvailable && $gaAvailable)?(($ads['status']==='partial'||$phones['status']==='partial'||$leads['status']==='partial')?'partial':'available'):($adsAvailable||$gaAvailable?'partial':'unavailable'),
            'threshold_seconds'=>$threshold,'all_recorded_calls'=>$all,'verified_calls'=>$verified,'form_leads'=>$form,'email_leads'=>$email,'total_verified_leads'=>$total,'website_phone_clicks'=>($phones&&in_array($phones['status'],['available','partial'],true))?array_sum(array_column($phones['rows'],'count')):null,
            'breakdown'=>$breakdown,'warning'=>$warning,'period_label'=>$periodLabel,'period'=>['start'=>$start,'end'=>$end,'timezone'=>$ads['timezone']??($phones['timezone']??'Provider reporting timezone')],
            'message'=>'Phone clicks are interactions, not calls, and are never added to Verified Calls. Calls are unique Google Ads call records; verified calls meet the configured '.$threshold.'-second minimum. '.($gaUnavailable['message']??'').' '.($ads['message']??'')];
    }

}
