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

    /** Authenticated encryption bound to the client and selected GHL location. */
    public static function seal(string $token,string $client,string $location): string
    {
        $iv=random_bytes(12);$tag='';
        $encrypted=openssl_encrypt($token,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag,$client.'|'.$location);
        if ($encrypted===false) throw new \RuntimeException('Secure storage unavailable.');
        return base64_encode($iv.$tag.$encrypted);
    }

    public static function unseal(string $payload,string $client,string $location): string
    {
        $raw=base64_decode($payload,true);
        if ($raw===false || strlen($raw)<29) return '';
        $token=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$client.'|'.$location);
        return is_string($token)?$token:'';
    }

    public static function save(string $client,string $location,string $token,string $events,bool $disconnect=false): bool
    {
        if ($client==='') return false;
        $names=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\s,]+/',$events)?:[]))));
        if (!$names || count($names)>20) return false;
        foreach ($names as $name) if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/',$name)) return false;
        if ($location!=='' && !preg_match('/^[A-Za-z0-9_-]{5,100}$/',$location)) return false;
        if (strlen($token)>4096 || preg_match('/[\r\n]/',$token)) return false;
        $old=self::settings($client);
        if (!$disconnect && $location!==($old['location']??'') && $location!=='' && $token==='') return false;
        if ($token!=='' && $location==='') return false;
        $sealed=$disconnect||$location===''?'':(string)($old['token']??'');
        if (!$disconnect && $token!=='') $sealed=self::seal($token,$client,$location);
        $value=['location'=>$disconnect?'':$location,'token'=>$sealed,'phone_events'=>$names];
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
        $connection=PpcAccount::getByClientId($client)?:[];
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
        $connection=PpcAccount::getByClientId($client)?:[];
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

    public static function forms(string $client,string $start,string $end): array
    {
        $settings=self::settings($client);$location=(string)($settings['location']??'');
        if ($location==='' || empty($settings['token'])) return self::unavailable('Connect this client’s GoHighLevel subaccount below to load form arrival times.','not_linked');
        $token=self::unseal((string)$settings['token'],$client,$location);
        if ($token==='') return self::unavailable('Reconnect GoHighLevel: the saved credential could not be opened.');
        $rows=[];$seen=[];$partial=false;
        // GHL date-filter timezone is not specified. Fetch a buffer, then enforce the displayed site's dates.
        $queryStart=(new \DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
        $queryEnd=(new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
        for ($page=1;$page<=5;$page++) {
            $url='https://services.leadconnectorhq.com/forms/submissions?'.http_build_query(['locationId'=>$location,'startAt'=>$queryStart,'endAt'=>$queryEnd,'page'=>$page,'limit'=>100]);
            $response=wp_remote_get($url,['timeout'=>15,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$token,'Version'=>'v3','Accept'=>'application/json']]);
            if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response)!==200) throw new \RuntimeException('GoHighLevel forms unavailable.');
            $data=json_decode(wp_remote_retrieve_body($response),true);
            if (!is_array($data['submissions']??null) || !is_array($data['meta']??null) || !array_key_exists('nextPage',$data['meta']) || !isset($data['meta']['total'])) throw new \RuntimeException('Invalid form report.');
            foreach ($data['submissions'] as $entry) {
                if (isset($entry['locationId']) && (string)$entry['locationId']!==$location) throw new \RuntimeException('Form account mismatch.');
                $id=(string)($entry['id']??'');
                if ($id==='' || isset($seen[$id])) { $partial=true;continue; }
                $seen[$id]=true;
                $raw=(string)($entry['createdAt']??'');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',$raw)) { $partial=true;continue; }
                $time=new \DateTimeImmutable($raw);
                if ($time->format('Y-m-d')!==substr($raw,0,10)) { $partial=true;continue; }
                $local=$time->setTimezone(wp_timezone());
                if ($local->format('Y-m-d')<$start || $local->format('Y-m-d')>$end) continue;
                $rows[]=['time'=>$local->format('Y-m-d H:i:s'),'source'=>'Unknown','form'=>sanitize_text_field((string)($entry['formId']??'Unknown form'))];
            }
            // Never infer a complete report just because a page contains fewer rows.
            $next=$data['meta']['nextPage']??null;
            if ($next===null) { if ((int)($data['meta']['total']??count($seen))>count($seen)) $partial=true;break; }
            if ((int)$next!==$page+1) { $partial=true;break; }
            if ($page===5) $partial=true;
        }
        usort($rows,static fn($a,$b)=>strcmp($b['time'],$a['time']));
        return ['status'=>$partial?'partial':'available','rows'=>$rows,'timezone'=>wp_timezone()->getName(),
            'message'=>'GoHighLevel form submission timestamps only; no contact details or answers are imported. Source remains unknown without verified submission attribution. '.($partial?'Coverage is incomplete; maximum 500 fetched submissions.':'')];
    }
}
