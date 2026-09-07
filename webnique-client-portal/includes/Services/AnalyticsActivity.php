<?php
/** Read-only, explicitly scoped activity feeds for the backend Analytics tab. */
namespace WNQ\Services;
use WNQ\Models\PpcAccount;
if (!defined('ABSPATH')) exit;

final class AnalyticsActivity
{
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
        return (array)(self::settings($client)['phone_events']??['phone_click']);
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
        $config=\WNQ\Models\AnalyticsConfig::getClientConfig($client)?:[];
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

    public static function save(string $client,string $portalClient,string $events): bool
    {
        if ($client==='') return false;
        $names=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\s,]+/',$events)?:[]))));
        if (!$names || count($names)>20) return false;
        foreach ($names as $name) if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/',$name)) return false;
        if ($portalClient!=='' && !\WNQ\Models\Client::getByClientId($portalClient)) return false;
        // Preserve retired encrypted GHL settings without reading or using them.
        $value=self::settings($client);
        $value['phone_events']=$names;
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
        $connection=PpcAccount::getByClientId(self::adsClient($client))?:[];
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
            $date=\DateTimeImmutable::createFromFormat('!YmdHi',(string)$d[0],new \DateTimeZone('UTC'));
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
            if (!str_starts_with((string)($call['resourceName']??''),'customers/'.$id.'/callViews/')) throw new \RuntimeException('Call account mismatch.');
            $timezone=(string)($row['customer']['timeZone']??$timezone);
            $result[]=['time'=>sanitize_text_field((string)($call['startCallDateTime']??'')),'source'=>'Google Ads',
                'campaign'=>sanitize_text_field((string)($row['campaign']['name']??'')),'duration'=>max(0,(int)($call['callDurationSeconds']??0)),
                'status'=>sanitize_text_field((string)($call['callStatus']??'UNKNOWN'))];
        }
        return ['status'=>count($rows)>=1000?'partial':'available','rows'=>$result,'timezone'=>$timezone,
            'message'=>'Search ad call records reported by Google Ads; not all website calls or unique leads. Call reporting must be enabled. Recordings are not available through this feed.'.(count($rows)>=1000?' Showing the latest 1,000 calls.':'')];
    }

}
