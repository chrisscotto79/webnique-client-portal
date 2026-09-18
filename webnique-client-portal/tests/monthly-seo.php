<?php
/** SQLite-backed offline persistence tests. No production WordPress or external requests. */
namespace WNQ\Models {
    final class Client {
        public static array $records=[['client_id'=>'erys','name'=>'Estefania Erys Creative','company'=>'Erys Creative','website'=>'https://eryscreative.com/','status'=>'active'],['client_id'=>'beta','name'=>'Beta','company'=>'Beta Services','website'=>'https://example.test','status'=>'inactive']];
        public static function getSEOClients(?string $status=null): array {return array_values(array_filter(self::$records,fn($row)=>$status===null||$row['status']===$status));}
        public static function getSEOClient(string $id): ?array {foreach(self::$records as $row)if($row['client_id']===$id)return $row;return null;}
    }
}
namespace {
    $base=sys_get_temp_dir().'/wnq-monthly-fixture-'.getmypid().'/';mkdir($base.'wp-admin/includes',0777,true);
    file_put_contents($base.'wp-admin/includes/upgrade.php','<?php function dbDelta($sql) { $GLOBALS["schema_sql"][]=$sql; }');
    define('ABSPATH',$base);define('ARRAY_A','ARRAY_A');define('WNQ_PORTAL_URL','https://fixture.test/assets/');define('WNQ_PORTAL_VERSION','3.12.0');
    register_shutdown_function(function()use($base){unlink($base.'wp-admin/includes/upgrade.php');rmdir($base.'wp-admin/includes');rmdir($base.'wp-admin');rmdir($base);});
    $clock='2026-09-18 10:00:00';$options=[];$schema_sql=[];$allowed=true;$validNonce=true;
    function current_time($format){global $clock;return $format==='mysql'?$clock:(new \DateTimeImmutable($clock))->format($format);}
    function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
    function update_option($k,$v,...$args){$GLOBALS['options'][$k]=$v;return true;}
    function delete_option($k){unset($GLOBALS['options'][$k]);}
    function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
    function sanitize_text_field($v){return trim(strip_tags($v));}
    function sanitize_textarea_field($v){return trim(strip_tags($v));}
    function wp_json_encode($v){return json_encode($v);}
    function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
    function esc_attr($v){return esc_html($v);}
    function esc_url($v){return esc_html($v);}
    function esc_textarea($v){return esc_html($v);}
    function wp_unslash($v){return $v;}
    function current_user_can($v){return $GLOBALS['allowed'];}
    function check_admin_referer($v){if(!$GLOBALS['validNonce'])throw new \RuntimeException('nonce rejected');}
    function wp_die($message,...$args){throw new \RuntimeException($message);}
    function wp_nonce_field($v){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
    function wp_timezone_string(){return 'America/New_York';}
    function admin_url($v){return 'https://fixture.test/wp-admin/'.$v;}
    function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
    function selected($a,$b){if($a===$b)echo ' selected';}
    function checked($v){if($v)echo ' checked';}
    function absint($v){return abs((int)$v);}
    function wp_generate_uuid4(){return 'fixture-unique-id';}
    $wpdb=new class {
        public string $prefix='wp_';public \PDO $db;public int $insert_id=0;public bool $failTask=false;
        function __construct(){ $this->db=new \PDO('sqlite::memory:');$this->db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION); }
        function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){$value=$args[$i++];return $m[0]==='%d'?(string)(int)$value:$this->db->quote((string)$value);},$sql);}
        function query($sql){if($this->failTask&&str_contains($sql,'INSERT IGNORE INTO wp_wnq_seo_work')){$this->failTask=false;return false;} $sql=str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql);return $this->db->exec($sql);}
        function get_row($sql,$format=null){return $this->db->query($sql)->fetch(\PDO::FETCH_ASSOC)?:null;}
        function get_results($sql,$format=null){return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);}
        function get_var($sql){return $this->db->query($sql)->fetchColumn();}
        function get_col($sql){if(str_starts_with($sql,'SHOW COLUMNS FROM '))return array_column($this->get_results('PRAGMA table_info('.substr($sql,18).')'),'name');return $this->db->query($sql)->fetchAll(\PDO::FETCH_COLUMN);}
        function get_charset_collate(){return 'DEFAULT CHARACTER SET utf8mb4';}
        function update($table,$values,$where){$pairs=fn($data)=>implode(' AND ',array_map(fn($key)=>$key.' IS '.($data[$key]===null?'NULL':$this->db->quote((string)$data[$key])),array_keys($data)));$set=implode(',',array_map(fn($key)=>$key.'='.($values[$key]===null?'NULL':$this->db->quote((string)$values[$key])),array_keys($values)));return $this->db->exec("UPDATE $table SET $set WHERE ".$pairs($where));}
    };
    $wpdb->query('CREATE TABLE wp_wnq_seo_cycles(id INTEGER PRIMARY KEY AUTOINCREMENT,client_id TEXT,month_year TEXT,plan_json TEXT,generation_complete INTEGER DEFAULT 0,metrics_json TEXT,narrative_json TEXT,revision INTEGER DEFAULT 0,last_activity TEXT,UNIQUE(client_id,month_year))');
    $wpdb->query("CREATE TABLE wp_wnq_seo_work(id INTEGER PRIMARY KEY AUTOINCREMENT,client_id TEXT,month_year TEXT,template_key TEXT,category TEXT,title TEXT,status TEXT DEFAULT 'pending',priority TEXT,due_date TEXT,completed_date TEXT,notes TEXT,revision INTEGER DEFAULT 0,updated_at TEXT,UNIQUE(client_id,month_year,template_key))");
    require_once __DIR__.'/../includes/Models/MonthlySEO.php';require_once __DIR__.'/../admin/MonthlySEOAdmin.php';
    function ok($yes,$label){$GLOBALS['checks']=($GLOBALS['checks']??0)+1;if(!$yes)throw new \RuntimeException($label);}
    function rejected($callback,$label){try{$callback();}catch(\Throwable $e){ok(true,$label);return;}ok(false,$label);}
    use WNQ\Models\MonthlySEO as M;
    M::install();ok(count($schema_sql)===2,'Both schema tables requested');ok(str_contains($schema_sql[1],'UNIQUE KEY client_month_task'),'Database enforces one task per template/month');
    ok(M::due('2028-02',31)==='2028-02-29','Leap year clamp');ok(M::due('2027-02',31)==='2027-02-28','Short month clamp');rejected(fn()=>M::month('2026-13'),'Invalid month');rejected(fn()=>M::date('2026-02-30'),'Invalid date');
    M::rollover();$tasks=M::tasks('erys','2026-09');$count=count(M::defaults());ok(count($tasks)===$count,'Default tasks created');ok(count(M::categories())===10,'All ten categories');ok(M::tasks('beta','2026-09')===[],'Inactive client not auto-generated');
    M::rollover();ok(count(M::tasks('erys','2026-09'))===$count,'Repeated rollover never duplicates');
    $first=$tasks[0];$data=['status'=>'completed','priority'=>'high','due_date'=>'2026-09-04','completed_date'=>'2026-09-17','notes'=>'Audit fixed and verified','revision'=>0];M::saveTask('erys',$first['id'],$data);
    $snapshot=M::tasks('erys','2026-09');M::ensure('erys','2026-09');ok(M::tasks('erys','2026-09')===$snapshot,'Generation preserves completed work');
    rejected(fn()=>M::saveTask('erys',$first['id'],$data),'Stale task revision rejected');rejected(fn()=>M::saveTask('beta',$first['id'],$data),'Cross-client task update rejected');rejected(fn()=>M::saveTask('erys',$first['id'],array_merge($data,['revision'=>1,'completed_date'=>'2026-10-01'])),'Future completion rejected');
    $metrics=['revision'=>0,'gsc_clicks'=>'120','gsc_impressions'=>'1000','gsc_ctr'=>'12','organic_leads'=>'0','wins'=>'New service page ranked','opportunities'=>'Competitor keyword gap'];M::saveMetrics('erys','2026-09',$metrics);
    $cycle=M::cycle('erys','2026-09');$values=json_decode($cycle['metrics_json'],true);ok($values['organic_leads']===0&&$values['site_health']===null,'Zero is distinct from not recorded');rejected(fn()=>M::saveMetrics('erys','2026-09',$metrics),'Stale report revision rejected');rejected(fn()=>M::saveMetrics('erys','2026-09',['revision'=>1,'gsc_ctr'=>101]),'CTR bounded');rejected(fn()=>M::saveMetrics('erys','2026-09',['revision'=>1,'gsc_clicks'=>'1.2']),'Count must be integer');
    $plan=M::plan('erys');$plan[0]['title']='Custom monthly audit';$plan[0]['due_day']=31;$plan[1]['enabled']=0;$plan[]=['template_key'=>'custom-content','category'=>'content','title'=>'Publish case study','due_day'=>20,'priority'=>'high','enabled'=>1];M::savePlan('erys',$plan);M::ensure('erys','2026-09');ok(M::tasks('erys','2026-09')===$snapshot,'Plan edits do not rewrite current history');
    $clock='2026-11-02 10:00:00';M::rollover();ok(count(M::history('erys'))===3,'Missed October cycle caught up');$october=M::tasks('erys','2026-10');ok(count($october)===$count,'Disabled task replaced by custom task in new month');ok(array_unique(array_column($october,'status'))===['pending'],'New month resets status without changing old month');ok(array_unique(array_column($october,'notes'))===[''],'New month does not copy historical notes');ok(in_array('Custom monthly audit',array_column($october,'title')),'Recurring customization applied');ok(M::cycle('erys','2026-09')===$cycle,'Old metrics and narrative remain unchanged');
    $wpdb->failTask=true;rejected(fn()=>M::ensure('erys','2026-12'),'Partial generation reports failure');ok((int)M::cycle('erys','2026-12')['generation_complete']===0,'Incomplete cycle retryable');M::ensure('erys','2026-12');ok(count(M::tasks('erys','2026-12'))===$count,'Partial generation recovers without duplicates');
    $sample=[['id'=>1,'status'=>'pending','due_date'=>'2026-09-17','priority'=>'high'],['id'=>2,'status'=>'completed','due_date'=>'2026-09-15','priority'=>'high'],['id'=>3,'status'=>'not_applicable','due_date'=>'2026-09-15','priority'=>'low'],['id'=>4,'status'=>'in_progress','due_date'=>'2026-09-20','priority'=>'medium']];$stats=M::stats($sample,'2026-09-18');ok($stats['percentage']===33.0,'N/A excluded from completion denominator');ok($stats['overdue']===1&&$stats['week']===2&&$stats['today']===1,'Actionable weekly and overdue counts');ok($stats['next']['id']===1,'Overdue high-priority recommendation');ok(M::comparison(null,100)==='Not enough data','Missing data not a fabricated delta');ok(M::comparison(120,100)==='+20 (+20%)','Month-over-month comparison');
    $clock='2026-09-18 10:00:00';$allowed=false;rejected(fn()=>WNQ\Admin\MonthlySEOAdmin::save(),'Staff permission enforced');$allowed=true;$validNonce=false;rejected(fn()=>WNQ\Admin\MonthlySEOAdmin::save(),'Nonce enforced');$validNonce=true;
    $_POST=['client'=>'missing','month'=>'2026-09','operation'=>'metrics'];rejected(fn()=>WNQ\Admin\MonthlySEOAdmin::save(),'Unknown client rejected');
    $currentTask=M::tasks('erys','2026-09')[0];
    M::saveTask('erys',$currentTask['id'],array_merge($data,['status'=>'in_progress','revision'=>1]));
    $reopened=M::tasks('erys','2026-09')[0];ok($reopened['completed_date']===null,'Reopening clears completion date');
    M::saveTask('erys',$currentTask['id'],array_merge($data,['revision'=>2]));
    $empty=M::stats([['id'=>1,'status'=>'not_applicable','due_date'=>'2026-09-01','priority'=>'low']],'2026-09-18');ok($empty['percentage']===null&&$empty['next']===null,'All N/A cycle has no artificial completion percent or next action');
    $badPlan=M::plan('erys');$badPlan[]=$badPlan[0];rejected(fn()=>M::savePlan('erys',$badPlan),'Duplicate recurring identities rejected');
    delete_option('wnq_monthly_seo_schema');$wpdb->query('ALTER TABLE wp_wnq_seo_work RENAME COLUMN revision TO missing_revision');
    rejected(fn()=>M::install(),'Incomplete schema upgrade fails visibly');ok(get_option('wnq_monthly_seo_schema')===false,'Failed schema remains retryable');
    $wpdb->query('ALTER TABLE wp_wnq_seo_work RENAME COLUMN missing_revision TO revision');M::install();ok(get_option('wnq_monthly_seo_schema')===M::SCHEMA,'Schema retry succeeds');
    $source=file_get_contents(__DIR__.'/../webnique-client-portal.php');ok(!str_contains($source,'SEO::syncMonthlyChecklistForAllClients()'),'Destructive legacy auto-sync disabled');ok(str_contains($source,"wp_clear_scheduled_hook('wnq_monthly_seo_rollover')"),'Cron unscheduled on deactivation');
    if(in_array('--render',$argv,true)||in_array('--overview',$argv,true)){
        $_GET=['month'=>'2026-09'];if(!in_array('--overview',$argv,true))$_GET['client']='erys';
        echo '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>';WNQ\Admin\MonthlySEOAdmin::render();echo '</body></html>';
    }else echo 'PASS: '.$checks." monthly SEO persistence, rollover, history, metrics, concurrency and permission checks. No external requests.\n";
}
