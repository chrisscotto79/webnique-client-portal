<?php
namespace WNQ\Models;
if (!defined('ABSPATH')) exit;

/** Monthly operations are isolated from legacy launch/checklist records. */
final class MonthlySEO
{
    public const SCHEMA = '1';
    public static function categories(): array
    {
        return ['semrush'=>'SEMrush','gsc'=>'Google Search Console','technical'=>'Technical SEO','onpage'=>'On-Page SEO','content'=>'Content','internal'=>'Internal Linking','backlinks'=>'Backlinks','local'=>'Local SEO / Google Business Profile','tracking'=>'Conversion Tracking','reporting'=>'Monthly Reporting'];
    }
    public static function defaults(): array
    {
        $groups = [
            'semrush'=>[['site-audit','Review Site Audit and record site health',4,'high'],['positions','Review Position Tracking and ranking gains/losses',5,'high'],['organic','Review Organic Research and competitor research',6,'medium'],['keyword-gap','Review Keyword Gap and prioritize opportunities',7,'high'],['backlink-audit','Review Backlink Audit',8,'medium'],['backlink-gap','Review Backlink Gap and prospects',9,'medium']],
            'gsc'=>[['performance','Record clicks, impressions, CTR and average position',5,'high'],['indexing','Review indexing, queries and page opportunities',6,'high']],
            'technical'=>[['audit','Investigate crawl, indexing and Core Web Vitals issues',10,'high'],['fixes','Implement and verify priority technical fixes',18,'high']],
            'onpage'=>[['optimize','Optimize priority page titles, headings and copy',18,'high'],['review','Review updated pages and document improvements',22,'medium']],
            'content'=>[['plan','Choose topics and update the content plan',8,'high'],['publish','Publish or refresh planned content',23,'high']],
            'internal'=>[['opportunities','Find orphan pages and internal link opportunities',12,'medium'],['links','Add relevant links and verify destinations',22,'medium']],
            'backlinks'=>[['prospects','Review relevant link prospects and outreach progress',14,'medium'],['acquired','Verify acquired backlinks and referring domains',26,'medium']],
            'local'=>[['gbp','Review GBP details, posts, photos and reviews',15,'medium'],['citations','Build or correct citations and record GBP updates',24,'medium']],
            'tracking'=>[['test','Test forms, calls and conversion events',7,'high'],['leads','Reconcile organic leads and attribution',27,'high']],
            'reporting'=>[['summary','Compile completed work, wins and month-over-month results',28,'high'],['priorities','Set next month priorities and prepare client update',28,'high']],
        ];
        $rows=[];
        foreach ($groups as $category=>$tasks) foreach ($tasks as [$key,$name,$day,$priority]) $rows[]=['template_key'=>$category.'-'.$key,'category'=>$category,'title'=>$name,'due_day'=>$day,'priority'=>$priority,'enabled'=>1];
        return $rows;
    }
    public static function metrics(): array
    {
        // label, type, maximum (null means no extra maximum)
        return [
            'site_health'=>['SEMrush site health score','number',100], 'rank_gains'=>['Keywords gaining positions','integer',null], 'rank_losses'=>['Keywords losing positions','integer',null],
            'keywords_tracked'=>['Keywords tracked','integer',null], 'referring_domains'=>['Referring domains','integer',null],
            'gsc_clicks'=>['GSC clicks','integer',null], 'gsc_impressions'=>['GSC impressions','integer',null], 'gsc_ctr'=>['GSC CTR (%)','number',100], 'gsc_position'=>['GSC average position','number',null],
            'organic_sessions'=>['Organic sessions','integer',null], 'content_published'=>['Content pieces published','integer',null], 'pages_optimized'=>['Pages optimized','integer',null],
            'backlinks_acquired'=>['Backlinks acquired','integer',null], 'citations_built'=>['Citations built','integer',null], 'gbp_updates'=>['GBP updates','integer',null],
            'technical_fixes'=>['Technical fixes completed','integer',null], 'organic_leads'=>['Organic leads generated','integer',null],
        ];
    }
    public static function narratives(): array
    {
        return ['wins'=>'SEO wins','ranking_notes'=>'Ranking improvements / losses','traffic_notes'=>'Traffic changes and context','opportunities'=>'SEMrush opportunities discovered','competitors'=>'Competitor research and gaps','content_notes'=>'Content published / optimized page URLs','link_notes'=>'Backlinks and citations acquired','technical_notes'=>'Technical fixes and verification','lead_notes'=>'Organic leads and attribution notes','next_priorities'=>'Priorities for next month'];
    }
    public static function month(string $value): string
    {
        if (!preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D',$value)) throw new \InvalidArgumentException('Choose a valid month (2000–2099).');
        return $value;
    }
    public static function date(string $value): string
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Choose a valid date.');
        return $value;
    }
    public static function due(string $month, int $day): string
    {
        self::month($month);
        return $month.'-'.str_pad((string)min(max(1,$day),(int)(new \DateTimeImmutable($month.'-01'))->format('t')),2,'0',STR_PAD_LEFT);
    }
    private static function table(string $name): string { global $wpdb; return $wpdb->prefix.'wnq_seo_'.$name; }
    public static function install(): void
    {
        if (get_option('wnq_monthly_seo_schema')===self::SCHEMA) return;
        global $wpdb; $collate=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $tables=[
            'cycles'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                client_id varchar(100) NOT NULL,
                month_year varchar(7) NOT NULL,
                plan_json longtext NOT NULL,
                generation_complete tinyint(1) NOT NULL DEFAULT 0,
                metrics_json longtext NOT NULL,
                narrative_json longtext NOT NULL,
                revision bigint(20) unsigned NOT NULL DEFAULT 0,
                last_activity datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY client_month (client_id,month_year)",
            'work'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                client_id varchar(100) NOT NULL,
                month_year varchar(7) NOT NULL,
                template_key varchar(100) NOT NULL,
                category varchar(30) NOT NULL,
                title varchar(255) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                priority varchar(10) NOT NULL DEFAULT 'medium',
                due_date date NOT NULL,
                completed_date date DEFAULT NULL,
                notes longtext NOT NULL,
                revision bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY client_month_task (client_id,month_year,template_key),
                KEY due_status (status,due_date),
                KEY client_month (client_id,month_year)",
        ];
        foreach ($tables as $name=>$sql) dbDelta('CREATE TABLE '.self::table($name)." (\n$sql\n) $collate;");
        foreach (['cycles'=>['generation_complete','plan_json','metrics_json','narrative_json','revision','last_activity'],'work'=>['template_key','due_date','completed_date','priority','revision','updated_at']] as $name=>$required) {
            $columns=$wpdb->get_col('SHOW COLUMNS FROM '.self::table($name));
            if (array_diff($required,$columns ?: [])) throw new \RuntimeException('Monthly SEO database upgrade is incomplete. Reload to retry.');
        }
        update_option('wnq_monthly_seo_schema',self::SCHEMA,false);
    }
    public static function plan(string $client): array
    {
        return get_option('wnq_seo_plan_'.hash('sha256',$client),self::defaults());
    }
    public static function savePlan(string $client, array $rows): void
    {
        $clean=[];$seen=[];
        foreach ($rows as $row) {
            $key=sanitize_key($row['template_key'] ?? '');$category=sanitize_key($row['category'] ?? '');
            $title=sanitize_text_field($row['title'] ?? '');$day=filter_var($row['due_day'] ?? null,FILTER_VALIDATE_INT);
            $priority=$row['priority'] ?? '';
            if (!$key || strlen($key)>100 || isset($seen[$key]) || !isset(self::categories()[$category]) || !$title || strlen($title)>255 || !$day || $day<1 || $day>31 || !in_array($priority,['high','medium','low'],true)) throw new \InvalidArgumentException('Check task titles, categories, due days (1–31), and priorities.');
            $seen[$key]=true;$clean[]=['template_key'=>$key,'category'=>$category,'title'=>$title,'due_day'=>$day,'priority'=>$priority,'enabled'=>empty($row['enabled'])?0:1];
        }
        if (count($clean)>100) throw new \InvalidArgumentException('Use up to 100 recurring tasks per client.');
        $key='wnq_seo_plan_'.hash('sha256',$client);
        if (get_option($key,null)!==$clean && !update_option($key,$clean,false)) throw new \RuntimeException('Recurring plan could not be saved.');
    }
    public static function cycle(string $client,string $month): ?array
    {
        global $wpdb; self::month($month);
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('cycles').' WHERE client_id=%s AND month_year=%s',$client,$month),ARRAY_A) ?: null;
    }
    /** A unique cycle stores an immutable plan snapshot. Replays only fill missing tasks. */
    public static function ensure(string $client,string $month): void
    {
        global $wpdb;self::month($month);
        $existing=self::cycle($client,$month);
        if (!empty($existing['generation_complete'])) return;
        $plan=array_values(array_filter(self::plan($client),static fn($row)=>!empty($row['enabled'])));
        $result=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::table('cycles').' (client_id,month_year,plan_json,metrics_json,narrative_json) VALUES (%s,%s,%s,%s,%s)',$client,$month,wp_json_encode($plan),'{}','{}'));
        if ($result===false) throw new \RuntimeException('Could not create the monthly SEO cycle.');
        $cycle=self::cycle($client,$month);
        if (!$cycle) throw new \RuntimeException('Monthly cycle was not stored.');
        foreach (json_decode($cycle['plan_json'],true) ?: [] as $row) {
            $ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::table('work').' (client_id,month_year,template_key,category,title,priority,due_date,notes) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',$client,$month,$row['template_key'],$row['category'],$row['title'],$row['priority'],self::due($month,(int)$row['due_day']),''));
            if ($ok===false) throw new \RuntimeException('Monthly task generation is incomplete. Reload to safely retry.');
        }
        if ($wpdb->update(self::table('cycles'),['generation_complete'=>1],['id'=>$cycle['id']])===false) throw new \RuntimeException('Could not finalize monthly task generation.');
    }
    public static function rollover(): void
    {
        self::install();global $wpdb;
        $current=self::month(current_time('Y-m'));
        foreach (Client::getSEOPlanClients() as $client) {
            $id=$client['client_id'];
            // Catch up missed cron months from the first cycle; never rewrite an existing cycle.
            $first=$wpdb->get_var($wpdb->prepare('SELECT MIN(month_year) FROM '.self::table('cycles').' WHERE client_id=%s',$id)) ?: $current;
            for ($month=$first;$month<=$current;$month=(new \DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m')) self::ensure($id,$month);
        }
    }
    public static function tasks(string $client,string $month,bool $through=false): array
    {
        global $wpdb;self::month($month);
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('work').' WHERE client_id=%s AND month_year'.($through?'<=':'=').'%s ORDER BY due_date ASC,id ASC',$client,$month),ARRAY_A) ?: [];
    }
    public static function history(string $client): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('cycles').' WHERE client_id=%s ORDER BY month_year DESC',$client),ARRAY_A) ?: [];
    }
    public static function status(array $task,string $today): string
    {
        if (in_array($task['status'],['completed','not_applicable'],true)) return $task['status'];
        return $task['due_date']<$today ? 'overdue' : $task['status'];
    }
    public static function stats(array $tasks,string $today): array
    {
        $counts=['total'=>count($tasks),'completed'=>0,'not_applicable'=>0,'overdue'=>0,'today'=>0,'week'=>0,'in_progress'=>0];
        $weekStart=(new \DateTimeImmutable($today))->modify('monday this week')->format('Y-m-d');
        $weekEnd=(new \DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
        $next=[];$last=null;
        foreach ($tasks as $task) {
            $state=self::status($task,$today);
            if (isset($counts[$state])) $counts[$state]++;
            if (!empty($task['updated_at']) && (!$last || $task['updated_at']>$last)) $last=$task['updated_at'];
            if (in_array($state,['completed','not_applicable'],true)) continue;
            if ($task['due_date']<=$today) $counts['today']++;
            if ($task['due_date']>=$weekStart && $task['due_date']<=$weekEnd) $counts['week']++;
            $next[]=$task;
        }
        $priorities=['high'=>0,'medium'=>1,'low'=>2];
        usort($next,static fn($a,$b)=>[$a['due_date'],$priorities[$a['priority']] ?? 1,$a['status']==='in_progress'?0:1,$a['id']]<=>[$b['due_date'],$priorities[$b['priority']] ?? 1,$b['status']==='in_progress'?0:1,$b['id']]);
        $eligible=$counts['total']-$counts['not_applicable'];
        return $counts+['percentage'=>$eligible?round(100*$counts['completed']/$eligible):null,'next'=>$next[0] ?? null,'last_activity'=>$last];
    }
    public static function saveTask(string $client,int $id,array $data): void
    {
        global $wpdb;$table=self::table('work');
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND client_id=%s",$id,$client),ARRAY_A);
        if (!$task) throw new \InvalidArgumentException('Task not found for this client.');
        $status=$data['status'] ?? ''; $priority=$data['priority'] ?? '';
        if (!in_array($status,['pending','in_progress','completed','not_applicable'],true) || !in_array($priority,['high','medium','low'],true)) throw new \InvalidArgumentException('Choose a valid status and priority. Overdue is calculated from the due date.');
        $due=self::date($data['due_date'] ?? '');
        $completed=$status==='completed'?self::date(($data['completed_date'] ?? '') ?: current_time('Y-m-d')):null;
        if ($completed && $completed>current_time('Y-m-d')) throw new \InvalidArgumentException('Completion date cannot be in the future.');
        $revision=(int)($data['revision'] ?? -1);
        $values=['status'=>$status,'priority'=>$priority,'due_date'=>$due,'completed_date'=>$completed,'notes'=>sanitize_textarea_field($data['notes'] ?? ''),'updated_at'=>current_time('mysql'),'revision'=>$revision+1];
        $result=$wpdb->update($table,$values,['id'=>$id,'client_id'=>$client,'revision'=>$revision]);
        if ($result!==1) throw new \RuntimeException('This task changed in another tab or could not be saved. Reload and try again.');
    }
    public static function saveMetrics(string $client,string $month,array $data): void
    {
        global $wpdb; $cycle=self::cycle($client,$month);
        if (!$cycle) throw new \InvalidArgumentException('No monthly cycle exists.');
        $metrics=[];$narrative=[];
        foreach (self::metrics() as $key=>[$label,$type,$max]) {
            $raw=trim((string)($data[$key] ?? ''));
            if ($raw==='') {$metrics[$key]=null;continue;}
            if (!preg_match($type==='integer'?'/^[0-9]+$/D':'/^[0-9]+(?:\.[0-9]+)?$/D',$raw) || (float)$raw>2147483647 || ($max!==null && (float)$raw>$max)) throw new \InvalidArgumentException('Invalid value for '.$label.'.');
            $metrics[$key]=$type==='integer'?(int)$raw:round((float)$raw,2);
        }
        foreach (self::narratives() as $key=>$label) $narrative[$key]=sanitize_textarea_field($data[$key] ?? '');
        $revision=(int)($data['revision'] ?? -1);
        $result=$wpdb->update(self::table('cycles'),['metrics_json'=>wp_json_encode($metrics),'narrative_json'=>wp_json_encode($narrative),'last_activity'=>current_time('mysql'),'revision'=>$revision+1],['id'=>$cycle['id'],'client_id'=>$client,'revision'=>$revision]);
        if ($result!==1) throw new \RuntimeException('This monthly report changed in another tab or could not be saved. Reload and try again.');
    }
    public static function comparison(?float $value,?float $previous): string
    {
        if ($value===null || $previous===null) return 'Not enough data';
        $difference=round($value-$previous,2);
        return ($difference>0?'+':'').$difference.($previous!=0?' ('.($difference>0?'+':'').round(100*$difference/$previous,1).'%)':' (prior month was 0)');
    }
}
