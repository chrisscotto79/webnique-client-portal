<?php
namespace {
    if (PHP_SAPI !== 'cli') { exit; }
    define('ABSPATH', __DIR__ . '/'); define('WNQ_PORTAL_VERSION','fixture');
}
namespace WNQ\Models {
    final class Lead {
        public static $rows = [], $fail = false;
        public static function findByPlaceId($key) { foreach (self::$rows as $r) { if ($r['place_id'] === $key) return $r; } return null; }
        public static function existsByNameAndCity($name,$city) { return false; }
        public static function insert($data) { if (self::$fail) return 0; $id = count(self::$rows)+1;self::$rows[] = array_merge($data,['id'=>$id]);return $id; }
    }
}
namespace {
    $checks=0; $fetches=[]; $pageHtml='<p>info@business.com</p><p>Call us</p>'; $failHttp=false;
    function check($ok,$label) { global $checks; $checks++;if (!$ok) throw new \RuntimeException($label); }
    function wp_parse_url($v) { return parse_url($v); }
    function sanitize_text_field($v) { return is_scalar($v) ? trim(strip_tags((string)$v)) : ''; }
    function esc_url_raw($v,$protocols=null) { return filter_var($v,FILTER_VALIDATE_URL) && in_array(parse_url($v,PHP_URL_SCHEME),['http','https']) ? $v : ''; }
    function wp_safe_remote_get($url,$args) { $GLOBALS['fetches'][]=[$url,$args];return ['code'=>$GLOBALS['failHttp'] ? 503 : 200,'body'=>$GLOBALS['pageHtml']]; }
    function is_wp_error($r) { return false; }
    function wp_remote_retrieve_response_code($r) { return $r['code']; }
    function wp_remote_retrieve_body($r) { return $r['body']; }
    function plugins_url($path,$file=null) { return 'https://goldenwebmarketing.com/wp-content/plugins/webnique-client-portal/' . str_replace('../','',$path); }
    function admin_url($path) { return 'https://goldenwebmarketing.com/wp-admin/'.$path; }
    function esc_html($v) { return htmlspecialchars((string)$v,ENT_QUOTES); }
    function esc_attr($v) { return esc_html($v); }
    function esc_url($v) { return esc_html($v); }
    function wp_create_nonce($v) { return 'fixture-nonce'; }
    function get_current_user_id() { return 17; }
    function current_user_can($v) { return $GLOBALS['allowed'] ?? true; }
    function check_ajax_referer(...$args) { return $GLOBALS['nonceValid'] ?? true; }
    function wp_send_json_error($data,$status=null) { throw new \RuntimeException($data['message']); }
    function wp_unslash($v) { return $v; }
    require dirname(__DIR__).'/includes/Services/LeadEmailExtractor.php';
    require dirname(__DIR__).'/includes/Services/LeadSEOScorer.php';
    require dirname(__DIR__).'/includes/Services/LeadBrowserIntake.php';
    require dirname(__DIR__).'/admin/LeadBrowserAdmin.php';
    use WNQ\Services\LeadBrowserIntake as Intake;
    use WNQ\Services\LeadEmailExtractor as Extract;
    use WNQ\Models\Lead;
    $url='https://www.google.com/maps/place/Business/data=!1sabc:123';
    check(Intake::identity($url)===Intake::identity(str_replace(':123','%3A123',$url)), 'Canonical Maps ID');
    foreach (['http://www.google.com/maps/place/Test','https://evil.example/maps/place/Test','https://www.google.com/search?q=x'] as $bad) {
        try { Intake::identity($bad);check(false,'Rejected origin/path'); } catch (\RuntimeException $e) {check(true,'Bad Maps URL rejected');}
    }
    $row=['name'=>'Business','maps_url'=>$url,'website'=>'https://business.com','phone'=>'407-555-0123','address'=>'123 Main St, Orlando, FL 32825','reviews'=>450];
    $result=Intake::accept($row,'Plumbers','32825');
    check($result['email']==='info@business.com','Email from website HTML');
    check(Lead::$rows[0]['zip']==='32825','Actual address ZIP extracted');
    check(Lead::$rows[0]['review_count']===450,'High-review businesses retained');
    check(Lead::$rows[0]['owner_first']==='','Names never guessed from email');
    $n=count($fetches);check(Intake::accept($row,'Plumbers','32825')['outcome']==='duplicate','Repeated listing skipped');
    check(count($fetches)===$n,'Duplicates avoid website requests');
    $row['maps_url'].='b';$row['website']='';$row['address']='Service area';
    Intake::accept($row,'Plumbers','32825');
    check(count(Lead::$rows)===2 && Lead::$rows[1]['phone']==='407-555-0123','No website kept for calling');
    check(Lead::$rows[1]['zip']==='','Search ZIP not falsely assigned as business address');
    $row['maps_url'].='c';$row['website']='https://business.com';$failHttp=true;
    Intake::accept($row,'Plumbers','32825');
    check(Lead::$rows[2]['email']==='' && str_contains(Lead::$rows[2]['notes'],'could not be read'),'Failed website retains listing with warning');
    check(count($fetches)-$n<=5,'Website attempts bounded');
    foreach ($fetches as [$u,$args]) {check($args['sslverify']===true && $args['redirection']===2,'Safe fetch TLS and redirect limit');}
    $failHttp=false;$row['maps_url'].='d';$row['closed']=true;
    Intake::accept($row,'Plumbers','32825');check(Lead::$rows[3]['status']==='closed','Closed listing excluded from GHL eligibility');
    foreach (['','3282','32825<script>'] as $zip) {try {Intake::accept($row,'Plumbers',$zip);check(false,'ZIP validation');}catch(\RuntimeException $e){check(true,'Bad ZIP rejected');}}
    $pageHtml='<script type="application/ld+json">{"@type":"LocalBusiness","email":"info@business.com","founder":{"@type":"Person","name":"Jane Doe"}}</script>';
    check(Extract::extractEmail('https://business.com',$pageHtml)['email']==='info@business.com','Email in JSON-LD page source');
    check(Intake::founder($pageHtml)===['first'=>'Jane','last'=>'Doe'],'Explicit founder parsed');
    check(Intake::founder(str_replace('founder','author',$pageHtml))===['first'=>'','last'=>''],'Article author not treated as owner');
    check(Extract::extractEmail('https://business.com','<p>developer@other.com</p><p>info@business.com</p>')['email']==='info@business.com','Business domain preferred over unrelated email');
    $method=new \ReflectionMethod(Extract::class,'extractEmailsFromHtml');
    check($method->invoke(null,'<p>noreply@business.com</p>')===[],'No-reply excluded');
    $allowed=false;try{WNQ\Admin\LeadBrowserAdmin::save();check(false,'Permission');}catch(\RuntimeException $e){check($e->getMessage()==='Access denied.','Staff permissions enforced');}
    $allowed=true;$nonceValid=false;try{WNQ\Admin\LeadBrowserAdmin::save();check(false,'Nonce');}catch(\RuntimeException $e){check(str_contains($e->getMessage(),'Session expired'),'Nonce enforced');}
    if (($argv[1]??'')==='--render') {
        $source=file_get_contents(dirname(__DIR__).'/admin/LeadFinderAdmin.php');preg_match('#<style>(.*?)</style>#s',$source,$m);
        echo '<!doctype html><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{background:#f0f0f1;margin:20px}'.$m[1].'</style><div class="wnq-lf">';
        WNQ\Admin\LeadBrowserAdmin::render();echo '</div>';
    } else echo "PASS: $checks browser intake/email/security assertions (mocked HTTP).\n";
}
