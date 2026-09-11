<?php
/** Offline integration tests: no WordPress installation or live GHL requests. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
$options = []; $requests = []; $assertions = 0;
function check($ok, $message) { global $assertions; ++$assertions; if (!$ok) { throw new RuntimeException($message); } }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function wp_salt($type) { return 'offline-fixture-key-not-a-real-wordpress-salt'; }
function wp_json_encode($v) { return json_encode($v); }
function get_current_user_id() { return 17; }
function current_user_can($cap) { return $GLOBALS['allowed'] ?? true; }
function esc_html($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function admin_url($v) { return 'https://wordpress.example/wp-admin/' . $v; }
function checked($v) { if ($v) { echo 'checked'; } }
function wp_nonce_field($v) { echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">'; }
function get_transient($v) { return false; }
function wp_create_nonce($v) { return 'fixture-nonce'; }
function check_ajax_referer($v) { if (empty($GLOBALS['validNonce'])) { throw new RuntimeException('nonce rejected'); } }
function wp_send_json_success($v) { $GLOBALS['ajaxResult'] = $v; }
function wp_send_json_error($v, $status) { $GLOBALS['ajaxError'] = $status; }
function check_admin_referer($v) { if (empty($GLOBALS['validNonce'])) { throw new RuntimeException('nonce rejected'); } }
function wp_die($v, ...$args) { throw new RuntimeException($v); }
function wp_parse_url($url) { return parse_url($url); }
function current_time($format) { return gmdate('Y-m-d H:i:s'); }
function do_action($hook, ...$args) { $GLOBALS['actions'][] = [$hook, $args]; }
function is_wp_error($v) { return $v instanceof Exception; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_request($url, $args) {
    $GLOBALS['requests'][] = [$url, $args];
    return ($GLOBALS['transport'])($url, $args);
}
function wp_safe_remote_get($url, $args) {
    $GLOBALS['fetches'][] = [$url, $args];
    return ['code' => 200, 'body' => '<p>info@business.com</p><p>Call us</p>'];
}
final class GhlDbFixture {
    public $prefix = 'wp_', $insert_id = 91, $jobs = [], $leads = [], $insertFail = false, $lock = true;
    function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $arg) { $sql = preg_replace('/%[sd]/', is_int($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'", $sql, 1); }
        return $sql;
    }
    function get_var($sql) { if (str_contains($sql, 'MAX(id)')) { return $this->leads ? max(array_keys($this->leads)) : 0; } return str_contains($sql, 'GET_LOCK') ? (int)$this->lock : (str_contains($sql, 'RELEASE_LOCK') ? 1 : null); }
    function get_results($sql, $format) {
        if (str_contains($sql, "contact_id <> ''")) { return array_slice(array_values(array_filter($this->jobs, static fn($j) => $j['contact_id'] !== '' && in_array($j['status'], ['review','failed','queued'], true))), 0, 3); }
        if (str_contains($sql, 'GROUP BY status')) {
            $counts = []; foreach ($this->jobs as $job) { $counts[$job['status']] = ($counts[$job['status']] ?? 0) + 1; }
            $rows = []; foreach ($counts as $status => $total) { $rows[] = compact('status','total'); } return $rows;
        }
        if (preg_match('/WHERE id > (\d+) AND id <= (\d+)/', $sql, $m)) {
            $rows = []; ksort($this->leads);
            foreach ($this->leads as $id => $lead) { if ($id > $m[1] && $id <= $m[2]) { $rows[] = ['id'=>$id]; } }
            return array_slice($rows, 0, 500);
        }
        return [];
    }
    function get_row($sql, $format) {
        if (str_contains($sql, 'FROM wp_wnq_leads WHERE id')) { preg_match('/id = (\d+)/', $sql, $m); return $this->leads[(int)$m[1]] ?? null; }
        if (str_contains($sql, 'email_key =')) { preg_match("/email_key = '([^']+)'/", $sql, $m); foreach ($this->jobs as $j) { if ($j['email_key'] === $m[1]) { return $j; } } return null; }
        foreach ($this->jobs as $j) { if ($j['status'] === 'queued') { return $j; } }
        return null;
    }
    function query($sql) {
        if (str_contains($sql, "stage <> 'tag_started'")) {
            $count = 0;
            foreach ($this->jobs as &$j) {
                if (in_array($j['status'], ['review','failed'], true) && $j['contact_id'] !== '' && $j['stage'] !== 'tag_started' && $j['message'] === 'GHL did not return a confirmed boolean DND status. No campaign tag applied.') {
                    $j['status']='queued'; $j['attempts']=0; $count++;
                }
            }
            return $count;
        }
        if (str_contains($sql, "WHERE status='processing'")) {
            foreach ($this->jobs as &$j) { if ($j['status'] === 'processing') { $j['status'] = 'review'; } }
        } elseif (str_contains($sql, "WHERE status='queued' AND mode='auto'")) {
            foreach ($this->jobs as &$j) { if ($j['status'] === 'queued' && $j['mode'] === 'auto') { $j['status'] = 'held'; } }
        }
        return 1;
    }
    function insert($table, $data) {
        if ($this->insertFail) { return false; }
        if ($table === 'wp_wnq_leads') { $this->insert_id = 92; return 1; }
        foreach ($this->jobs as $j) { if ($j['email_key'] === $data['email_key']) { return false; } }
        $id = count($this->jobs) + 1;
        $this->jobs[$id] = array_merge(['id' => $id, 'status' => 'queued', 'stage' => '', 'contact_id' => '', 'attempts' => 0, 'message' => ''], $data);
        return 1;
    }
    function update($table, $data, $where) {
        $this->jobs[$where['id']] = array_merge($this->jobs[$where['id']], $data); return 1;
    }
}
require dirname(__DIR__) . '/includes/Services/LeadGhlSync.php';
require dirname(__DIR__) . '/includes/Services/LeadEmailExtractor.php';
require dirname(__DIR__) . '/includes/Models/Lead.php';
require dirname(__DIR__) . '/admin/LeadGhlAdmin.php';
use WNQ\Services\LeadGhlSync as Sync;
function resetFixture() {
    global $wpdb, $requests, $transport, $contact, $lookup, $options;
    $wpdb = new GhlDbFixture(); $requests = []; $options = [];
    $wpdb->leads[1] = ['id' => 1, 'email' => 'owner@business.com', 'phone' => '', 'status' => 'new', 'business_name' => 'Test Business', 'city' => '', 'owner_first' => 'Owner', 'review_count'=>12, 'company_fit'=>'independent'];
    $contact = ['id' => 'contact1', 'email' => 'owner@business.com', 'locationId' => Sync::LOCATION, 'dnd' => false, 'tags' => ['existing-tag']];
    $lookup = null;
    $transport = static function ($url, $args) {
        global $contact, $lookup;
        $path = parse_url($url, PHP_URL_PATH);
        if (str_starts_with($path, '/locations/')) { $body = ['tags' => [['name' => Sync::TAG, 'locationId' => Sync::LOCATION]]]; }
        elseif ($path === '/contacts/search/duplicate') { $body = ['contact' => $lookup]; }
        elseif (str_ends_with($path, '/tags')) { $body = ['tags' => ['existing-tag', Sync::TAG]]; }
        else { $body = ['contact' => $contact]; }
        return ['code' => 200, 'body' => json_encode($body)];
    };
    Sync::saveSettings('fixture-private-token-only', false);
}
function writes() { return array_values(array_filter($GLOBALS['requests'], fn($r) => $r[1]['method'] !== 'GET')); }
resetFixture();
check(!Sync::settings()['automatic'], 'Manual by default');
check(!str_contains($options['wnq_lead_ghl_token'], 'fixture-private-token-only'), 'Token encrypted at rest');
check(Sync::configured(), 'Token decrypts');
$sealed = $options['wnq_lead_ghl_token']; $options['wnq_lead_ghl_token'] = base64_encode('tampered data');
check(!Sync::configured(), 'Tampered token rejected'); $options['wnq_lead_ghl_token'] = $sealed;
Sync::test(); check(count(writes()) === 0, 'Connection test read-only');
Sync::onCreated(1); check(!$wpdb->jobs, 'Manual mode never auto-enqueues');
check(Sync::enqueue(1), 'Manual approval queues');
check(!Sync::enqueue(1), 'Repeated approval deduplicated');
Sync::work();
check($wpdb->jobs[1]['status'] === 'sent', 'New contact successfully tagged');
check(count(writes()) === 2, 'Only create plus tag');
check($wpdb->jobs[1]['approved_by'] === 17, 'Approver saved');
$body = json_decode(writes()[0][1]['body'], true);
check($body['companyName'] === 'Test Business' && !isset($body['tags']) && !isset($body['dnd']), 'Contact payload does not overwrite tags or suppression');
check(json_decode(writes()[1][1]['body'], true) === ['tags' => [Sync::TAG]], 'Exact campaign tag');
foreach ($requests as [$url, $args]) { check($args['redirection'] === 0 && $args['sslverify'] === true, 'Secure credential transport'); }
$before = count($requests); Sync::work(); check(count($requests) === $before, 'Sent contact never replayed');
$wpdb->leads[2] = array_merge($wpdb->leads[1], ['id' => 2]);
check(!Sync::enqueue(2), 'Same email under another source ID blocked');
resetFixture(); $lookup = $contact; Sync::enqueue(1); Sync::work();
check(count(writes()) === 1 && str_ends_with(writes()[0][0], '/tags'), 'Existing contact reused without overwriting profile');
resetFixture(); $lookup = $contact; $contact['tags'][] = strtolower(Sync::TAG); Sync::enqueue(1); Sync::work();
check(!$requests || count(writes()) === 0, 'Existing campaign tag not applied twice');
foreach (['dnd', 'unsubscribeEmail', 'bounceEmail', 'deleted'] as $flag) {
    resetFixture(); $lookup = $contact; $contact[$flag] = true; Sync::enqueue(1); Sync::work();
    check(count(writes()) === 0, 'Suppression honored: ' . $flag);
}
resetFixture(); $lookup = $contact; $contact['dndSettings']['Email']['status'] = 'active'; Sync::enqueue(1); Sync::work();
check(count(writes()) === 0, 'Email-specific DND honored');
resetFixture(); $lookup = $contact; $contact['locationId'] = 'different-location'; Sync::enqueue(1); Sync::work();
check(count(writes()) === 0, 'Wrong-location contact blocked');
resetFixture(); $lookup = $contact; $contact['email'] = 'different@business.com'; Sync::enqueue(1); Sync::work();
check(count(writes()) === 0, 'Wrong-email contact blocked');
resetFixture(); $lookup = $contact; unset($contact['dnd']); Sync::enqueue(1); Sync::work();
check(count(writes()) === 1 && $wpdb->jobs[1]['status'] === 'sent', 'Omitted DND permits tagging without changing DND');
resetFixture(); $wpdb->leads[1]['phone'] = '(555) 123-4567'; $delegate = $transport;
$transport = static fn($url, $args) => str_contains($url, 'number=') ? ['code' => 200, 'body' => '{"contact":{"id":"phone-other"}}'] : $delegate($url, $args);
Sync::enqueue(1); Sync::work(); check(count(writes()) === 0, 'Phone conflict blocks create');
resetFixture(); Sync::saveSettings('', true); check(!$wpdb->jobs, 'Enabling automatic mode does not backfill');
Sync::onCreated(1); check($wpdb->jobs[1]['mode'] === 'auto', 'New lead auto queued');
Sync::saveSettings('', false); check($wpdb->jobs[1]['status'] === 'held', 'Off holds automatic backlog');
Sync::saveSettings('', true); Sync::work(); check(count(writes()) === 0, 'Re-enable does not resume held backlog');
check(Sync::enqueue(1), 'Held job requires manual approval'); Sync::work(); check($wpdb->jobs[1]['status'] === 'sent', 'Approved held job processes');
foreach (['closed', 'contacted', 'suppressed'] as $status) {
    resetFixture(); $wpdb->leads[1]['status'] = $status; check(!Sync::enqueue(1), 'Ineligible status: ' . $status);
}
resetFixture(); $wpdb->leads[1]['email'] = 'invalid'; check(!Sync::enqueue(1), 'Invalid email blocked');
resetFixture(); Sync::enqueue(1); $wpdb->jobs[1]['status'] = 'suppressed'; check(!Sync::enqueue(1), 'Suppression cannot be overridden by approval');
resetFixture(); Sync::enqueue(1); $wpdb->leads[1]['email'] = 'changed@business.com'; Sync::work(); check(!writes(), 'Changed email invalidates approval');
resetFixture(); Sync::enqueue(1); unset($wpdb->leads[1]); Sync::work(); check(!writes(), 'Deleted lead not sent');
resetFixture(); Sync::enqueue(1); $wpdb->lock = false; Sync::work(); check(!$requests, 'Concurrent worker cannot enter');
resetFixture(); $transport = static fn() => ['code' => 401, 'body' => '{"message":"sensitive-provider-error"}'];
Sync::enqueue(1); Sync::work(); Sync::work(); Sync::work();
check($wpdb->jobs[1]['status'] === 'failed' && $wpdb->jobs[1]['attempts'] === 3, 'Read failures bounded to three attempts');
check(!str_contains($wpdb->jobs[1]['message'], 'sensitive'), 'Raw errors never persisted');
resetFixture(); $delegate = $transport;
$transport = static fn($url, $args) => str_contains($url, 'search/duplicate') ? ['code' => 200, 'body' => '{}'] : $delegate($url, $args);
Sync::enqueue(1); Sync::work(); check(!writes(), 'Malformed lookup does not cause create');
resetFixture(); $delegate = $transport;
$transport = static fn($url, $args) => $args['method'] === 'POST' ? new RuntimeException('network timeout') : $delegate($url, $args);
Sync::enqueue(1); Sync::work(); check($wpdb->jobs[1]['status'] === 'review', 'Uncertain create requires review');
Sync::enqueue(1); Sync::work(); check(count(writes()) === 1, 'Uncertain create not repeated');
resetFixture(); $lookup = $contact; $delegate = $transport;
$transport = static fn($url, $args) => $args['method'] === 'POST' ? new RuntimeException('timeout') : $delegate($url, $args);
Sync::enqueue(1); Sync::work(); check($wpdb->jobs[1]['stage'] === 'tag_started', 'Tag journal precedes request');
Sync::enqueue(1); Sync::work(); check(count(writes()) === 1, 'Uncertain tag not repeated');
$contact['tags'][] = Sync::TAG; Sync::enqueue(1); Sync::work(); check($wpdb->jobs[1]['status'] === 'sent', 'Uncertain tag reconciled read-only');
resetFixture(); $wpdb->insertFail = true; $actions = [];
check(WNQ\Models\Lead::insert(['email' => 'x@business.com']) === 0, 'Failed insert never returns stale ID');
check(!$actions, 'Failed insert never triggers handoff');
$wpdb->insertFail = false; WNQ\Models\Lead::insert(['email' => 'x@business.com']); check($actions[0][0] === 'wnq_lead_created', 'Successful insert emits new lead hook');
$extract = new ReflectionMethod(WNQ\Services\LeadEmailExtractor::class, 'extractEmailsFromHtml');
foreach (['<p>info@business.com</p><p>Call us</p>', '<p>info@business.com</p><p>Orlando</p>', '<a href="mailto:info&#64;business.com">Email</a>'] as $html) {
    check($extract->invoke(null, $html) === ['info@business.com'], 'Email extraction preserves HTML boundaries/entities');
}
check($extract->invoke(null, '<script>const email="bad@business.com"</script>') === [], 'Scripts excluded');
$result = WNQ\Services\LeadEmailExtractor::extractEmail('https://business.com/services', '<p>No email</p>');
check($result['source'] === 'https://business.com/contact', 'Fallback uses website root');
check($GLOBALS['fetches'][0][1]['sslverify'] === true, 'Extractor verifies TLS');
check(WNQ\Models\Lead::csvCell('=HYPERLINK("bad")')[0] === "'", 'CSV formula escaped');
check(WNQ\Models\Lead::csvCell('Business') === 'Business', 'Normal CSV unchanged');
resetFixture(); ob_start(); WNQ\Admin\LeadGhlAdmin::render(); $html = ob_get_clean();
check(!str_contains($html, 'fixture-private-token-only') && !str_contains($html, $options['wnq_lead_ghl_token']), 'UI never renders plaintext or encrypted token');
check(str_contains($html, 'role="switch"') && !str_contains($html, 'value="1" checked'), 'Rendered switch defaults off');
check(str_contains($html, '_wpnonce'), 'Settings form protected by nonce');
ob_start(); WNQ\Admin\LeadGhlAdmin::row($wpdb->leads[1]); $rowHtml = ob_get_clean();
ob_start(); WNQ\Admin\LeadGhlAdmin::listForm(); $listHtml = ob_get_clean();
$rowHtml .= $listHtml;
check(str_contains($rowHtml, 'Approve &amp; Send') && str_contains($rowHtml, 'confirm('), 'Explicit live-send confirmation');
resetFixture();
$bulk = WNQ\Admin\LeadGhlAdmin::approveList([1, 1, 999]);
check($bulk === ['queued' => 1, 'skipped' => 1, 'reasons'=>['Lead no longer exists'=>1]], 'Bulk approval deduplicates IDs and explains missing leads');
check(count(writes()) === 0, 'Bulk approval only queues; preflight does not enroll contacts');
check(WNQ\Admin\LeadGhlAdmin::approveList([1])['skipped'] === 1, 'Bulk does not requeue pending lead');
foreach ([[], range(1,51), ['bad'], [[1]]] as $invalid) {
    try { WNQ\Admin\LeadGhlAdmin::approveList($invalid); check(false, 'Invalid bulk accepted'); }
    catch (RuntimeException $e) { check(true, 'Invalid bulk rejected'); }
}
resetFixture(); $transport = static fn() => ['code' => 200, 'body' => '{"tags":[]}'];
try { WNQ\Admin\LeadGhlAdmin::approveList([1]); check(false, 'Missing tag accepted'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), Sync::LOCATION) && !$wpdb->jobs, 'Missing tag explains location and queues nothing'); }
$transport = static fn() => ['code' => 200, 'body' => '{}'];
try { Sync::test(); check(false, 'Invalid tags accepted'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'valid tag list'), 'Malformed tag response distinguished'); }
resetFixture();
foreach ([['review_count'=>50], ['review_count'=>0], ['company_fit'=>'unknown'], ['company_fit'=>'chain'], ['company_fit'=>'large']] as $change) {
    check(!Sync::eligible(array_replace($wpdb->leads[1], $change)), 'Qualification gate holds excluded or uncertain prospect');
}
check(Sync::eligible(array_replace($wpdb->leads[1], ['review_count'=>49])), '49 reviews qualifies');
$options['wnq_lead_seo_min'] = 3;
check(!Sync::eligible($wpdb->leads[1]), 'Missing SEO assessment fails enabled threshold');
check(!Sync::eligible(array_replace($wpdb->leads[1], ['seo_checked'=>1,'seo_score'=>2])), 'Too few SEO issues fails threshold');
check(Sync::eligible(array_replace($wpdb->leads[1], ['seo_checked'=>1,'seo_score'=>3])), 'SEO issue threshold correct direction');
resetFixture(); Sync::enqueue(1); $wpdb->leads[1]['company_fit']='chain'; Sync::work(); check(!writes(), 'Queued prospect rechecked before handoff');
resetFixture();
$wpdb->leads[1]['company_fit'] = 'unknown';
$wpdb->leads[1]['review_count'] = 100;
$options['wnq_lead_seo_min'] = 7;
check(!Sync::enqueue(1, false, true), 'Automatic imports cannot override outreach rules');
check(WNQ\Admin\LeadGhlAdmin::approveList([1])['queued'] === 1, 'Explicit approval overrides outreach rules');
check($wpdb->jobs[1]['mode'] === 'override' && $wpdb->jobs[1]['approved_by'] === 17, 'Override approval audited');
Sync::work(); check($wpdb->jobs[1]['status'] === 'sent', 'Worker honors explicit override through tag confirmation: ' . $wpdb->jobs[1]['message']);
foreach ([['email'=>''], ['status'=>'closed'], ['notes'=>'temporarily closed']] as $change) {
    resetFixture(); check(Sync::enqueue(1, true, true), 'Override queued');
    $wpdb->leads[1] = array_replace($wpdb->leads[1], $change);
    Sync::work(); check(!writes(), 'Worker retains hard safety checks for overrides');
}
resetFixture();
for ($i = 2; $i <= 503; $i++) { $wpdb->leads[$i] = array_replace($wpdb->leads[1], ['id'=>$i, 'email'=>"owner{$i}@business.com", 'company_fit'=>'unknown']); }
$wpdb->leads[502]['email'] = '';
$wpdb->leads[503]['email'] = $wpdb->leads[1]['email'];
$all = WNQ\Admin\LeadGhlAdmin::approveAll();
check($all['queued'] === 501 && $all['skipped'] === 2, 'All-list approval spans multiple pages, skips invalid and duplicate emails');
check($all['reasons']['Missing or invalid email'] === 1 && $all['reasons']['Existing handoff: queued'] === 1, 'Exact skip reasons counted');
check(!writes(), 'All-list approval does not issue live-write requests');
check(WNQ\Admin\LeadGhlAdmin::approveAll()['queued'] === 0, 'Repeated all-list approval is idempotent');
$wpdb->jobs[1]['status'] = 'suppressed';
check(!Sync::enqueue(1, true, true), 'Manual override cannot bypass suppression');
$allowed = false;
try { WNQ\Admin\LeadGhlAdmin::approveAll(); check(false, 'Unauthorized all-list approval accepted'); }
catch (RuntimeException $e) { check($e->getMessage() === 'Access denied', 'All-list approval enforces permission'); }
$allowed = true;
resetFixture();
check(Sync::contactBlockReason(array_diff_key($contact, ['dnd'=>1]), $contact['email']) === '', 'Omitted DND defers delivery suppression to GHL');
$contact['dndSettings'] = ['email'=>['status'=>'active']];
check(!Sync::contactSafe($contact, $contact['email']), 'Lowercase email DND blocks handoff');
$contact['dndSettings'] = ['Email'=>['status'=>'permanent']];
check(!Sync::contactSafe($contact, $contact['email']), 'Uppercase email DND blocks handoff');
resetFixture(); Sync::enqueue(1, true, true);
$validNonce = true;
WNQ\Admin\LeadGhlAdmin::drain();
check($ajaxResult['sent'] === 1 && $ajaxResult['queued'] === 0, 'AJAX drains previously approved contact and returns progress');
$before = count(writes()); WNQ\Admin\LeadGhlAdmin::drain();
check(count(writes()) === $before, 'Repeated AJAX cannot resend completed job');
$allowed = false; WNQ\Admin\LeadGhlAdmin::drain();
check($ajaxError === 403, 'AJAX requires capability');
$allowed = true; $validNonce = false;
try { WNQ\Admin\LeadGhlAdmin::drain(); check(false, 'AJAX accepted missing nonce'); }
catch (RuntimeException $e) { check($e->getMessage() === 'nonce rejected', 'AJAX requires nonce'); }
resetFixture();
for ($i = 1; $i <= 3; $i++) { $wpdb->leads[$i] = array_replace($wpdb->leads[1], ['id'=>$i,'email'=>"batch{$i}@business.com"]); Sync::enqueue($i, true, true); }
$transport = static function ($url, $args) {
    if (str_contains($url, '/locations/')) { $body = ['tags'=>[['name'=>Sync::TAG]]]; }
    elseif (str_contains($url, '/search/duplicate')) { parse_str(parse_url($url, PHP_URL_QUERY), $q); $body = ['contact'=>['id'=>$q['email']]]; }
    elseif (str_ends_with($url, '/tags')) { $body = ['tags'=>[Sync::TAG]]; }
    else { $id = rawurldecode(basename(parse_url($url, PHP_URL_PATH))); $body = ['contact'=>['id'=>$id,'email'=>$id,'locationId'=>Sync::LOCATION,'dnd'=>false,'tags'=>[]]]; }
    return ['code'=>200,'body'=>json_encode($body)];
};
Sync::batch(); check(Sync::progress()['sent'] === 3, 'Cron batch handles multiple contacts in one tick');
resetFixture(); Sync::enqueue(1, true, true);
$wpdb->jobs[1]['contact_id'] = 'contact1'; $wpdb->jobs[1]['status'] = 'review';
foreach (['false', null, false] as $value) {
    $contact['dnd'] = $value;
    $report = implode(' ', Sync::diagnose());
    check(str_contains($report, 'DND ' . gettype($value)), 'Diagnostic distinguishes live DND type');
    check(!str_contains($report, $contact['email']) && !str_contains($report, 'fixture-private-token-only'), 'Diagnostic excludes PII and token');
}
unset($contact['dnd']); check(str_contains(implode(' ', Sync::diagnose()), 'DND missing'), 'Diagnostic distinguishes absent DND');
check(!writes() && $wpdb->jobs[1]['status'] === 'review', 'Diagnostics cannot write contacts or requeue');
resetFixture();
foreach ([null, 'false', 0, [], 'unknown'] as $value) {
    $contact['dnd']=$value; check(!Sync::contactSafe($contact, $contact['email']), 'Present malformed DND is blocked');
}
unset($contact['dnd']);
foreach ([['Email'=>['status'=>'active']], ['email'=>['status'=>'permanent']], ['all'=>[]]] as $settings) {
    $contact['dndSettings']=$settings; check(!Sync::contactSafe($contact, $contact['email']), 'Channel suppression enforced even without global DND');
}
resetFixture(); Sync::enqueue(1, true, true);
$old = 'GHL did not return a confirmed boolean DND status. No campaign tag applied.';
$wpdb->jobs[1] = array_replace($wpdb->jobs[1], ['status'=>'review','contact_id'=>'contact1','stage'=>'create_started','message'=>$old]);
foreach (['suppressed','sent','queued','review','review'] as $n=>$state) {
    $wpdb->jobs[$n+2] = array_replace($wpdb->jobs[1], ['id'=>$n+2,'status'=>$state]);
}
$wpdb->jobs[5]['message']='Phone/email contact conflict.';
$wpdb->jobs[6]['stage']='tag_started';
check(Sync::retryMissingDnd() === 1, 'Targeted retry excludes conflicts, suppression, sent, queued and uncertain tags');
check(Sync::retryMissingDnd() === 0 && !writes(), 'Targeted retry is idempotent and only queues');
check($wpdb->jobs[1]['contact_id']==='contact1' && $wpdb->jobs[1]['mode']==='override', 'Existing identity and approval retained');
unset($contact['dnd']); Sync::work();
check($wpdb->jobs[1]['status']==='sent' && count(writes())===1 && str_ends_with(writes()[0][0], '/tags'), 'Recovered job reuses contact, applies tag only');
resetFixture(); $wpdb->lock=false;
try { WNQ\Models\Lead::deleteAll(); check(false,'Delete during handoff accepted'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(),'progress'),'Deletion serialized against handoffs'); }
$allowed = false;
try { WNQ\Admin\LeadGhlAdmin::handle(); check(false, 'Unauthorized request accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'Access denied', 'Handler checks staff capability'); }
$allowed = true;
try { WNQ\Admin\LeadGhlAdmin::handle(); check(false, 'Invalid nonce accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'nonce rejected', 'Handler enforces nonce before actions'); }
if (($argv[1] ?? '') === '--render') {
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px system-ui;background:#f0f0f1;color:#1e293b;margin:24px}.wnq-card{background:white;padding:22px;border:1px solid #ddd;border-radius:10px;margin:16px 0}.wnq-btn{display:inline-block;border:1px solid #ccc;border-radius:6px;padding:10px 14px;text-decoration:none;cursor:pointer}.wnq-btn-primary{background:#2563eb;color:white}.wnq-field{display:flex;flex-direction:column;gap:6px}.wnq-field input{padding:12px}.wnq-tbl-wrap{overflow:auto}th,td{padding:10px;text-align:left}small{color:#64748b}</style></head><body>' . $html . '<div class="wnq-card">' . $rowHtml . '</div></body></html>';
} else { echo "PASS: {$assertions} assertions; all network responses mocked, no live GHL requests.\n"; }
