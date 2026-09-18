<?php
namespace WNQ\Models {
    // Persistence is stubbed; identity resolution uses the real Client/Analytics models.
    final class SEOHub {
        public static array $keys = [], $profiles = [];
        public static bool $fail = false;
        public static function generateAgentKey($id, $url, $name) {
            if (self::$fail) return false;
            self::$keys[] = ['client_id' => $id, 'site_url' => $url];
            return 'test-only-not-a-real-key';
        }
        public static function getProfile($id) { return self::$profiles[$id] ?? null; }
        public static function upsertProfile($id, $data) { self::$profiles[$id] = $data; return true; }
        public static function log(...$args) {}
    }
}
namespace {
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__); define('ARRAY_A', 'ARRAY_A');
function get_option($name, $default = false) { return $name === 'wnq_clients_schema_version' ? '5' : $default; }
function wp_unslash($v) { return stripslashes($v); }
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function esc_url_raw($v) { return $v; }
function check_admin_referer($v) { if (!$GLOBALS['nonce']) throw new RuntimeException('Invalid nonce'); }
function current_user_can($v) { return $GLOBALS['allowed']; }
function wp_die($v) { throw new RuntimeException($v); }
function admin_url($v) { return 'https://hub.example/wp-admin/' . $v; }
class Redirect extends RuntimeException {}
function wp_redirect($url) { throw new Redirect($url); }
class DirectoryDB {
    public $prefix = 'wp_', $portal = [], $analytics = [], $writes = 0;
    function prepare($sql, ...$args) { return [$sql, $args]; }
    function get_results($sql, $type) {
        if (str_contains($sql, 'wnq_analytics_config')) return array_values(array_filter($this->analytics, fn($r) => !empty($r['is_active'])));
        if (str_contains($sql, 'wnq_clients')) return $this->portal;
        throw new RuntimeException('Unexpected query');
    }
    function get_row($query, $type) {
        $rows = str_contains($query[0], 'wnq_analytics_config') ? $this->analytics : $this->portal;
        foreach ($rows as $row) if ($row['client_id'] === $query[1][0]) return $row;
        return null;
    }
    function insert(...$args) { $this->writes++; throw new RuntimeException('Directory reads must not write'); }
    function update(...$args) { $this->writes++; throw new RuntimeException('Directory reads must not write'); }
}
$wpdb = new DirectoryDB(); $allowed = true; $nonce = true;
require dirname(__DIR__) . '/includes/Models/Client.php';
require dirname(__DIR__) . '/includes/Models/AnalyticsConfig.php';
require dirname(__DIR__) . '/includes/Core/SEOOSBootstrap.php';
use WNQ\Models\Client;
use WNQ\Models\SEOHub;
use WNQ\Core\SEOOSBootstrap;
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$shared = ['client_id'=>'shared','name'=>'Owner','company'=>'Shared Company','website'=>'https://shared.example/','status'=>'active','email'=>'owner@example.test'];
$erys = ['client_id'=>'estefania-erys-creative','client_name'=>'Estefania Erys Creative','website_url'=>'https://eryscreative.com/','ga4_property_id'=>'properties/554543484','search_console_url'=>'sc-domain:eryscreative.com','is_active'=>1];
$wpdb->portal = [$shared]; $wpdb->analytics = [$erys];
check(count(Client::getSEOClients()) === 2, 'Analytics-only client must appear alongside shared clients');
check(Client::getSEOClient($erys['client_id'])['website'] === $erys['website_url'], 'Erys profile resolves using original ID');
check(Client::getSEOClient($erys['client_id'])['email'] === '', 'Do not fabricate missing contact data');
check(Client::getByClientId($erys['client_id']) === null, 'SEO reads must not create billing/login identities');
check(count(Client::getAll()) === 1, 'Canonical billing list is unchanged');
$wpdb->portal[] = array_merge($shared, ['client_id'=>'brand-new','website'=>'https://new.example/']);
check(count(Client::getSEOClients()) === 3, 'New shared clients appear immediately, without saved Analytics settings');
array_pop($wpdb->portal);
$wpdb->analytics[] = array_merge($erys,['client_id'=>'old-shared','website_url'=>'http://www.shared.example']);
check(count(Client::getSEOClients()) === 2, 'One unambiguous shared/legacy match appears once');
check(Client::getSEOClient('old-shared')['client_id'] === 'old-shared', 'Existing legacy keys retain exact identity');
$wpdb->portal[] = array_merge($shared,['client_id'=>'same-site']);
check(count(Client::getSEOClients()) === 4, 'Ambiguous companies are never merged');
array_pop($wpdb->portal);
$wpdb->analytics[] = array_merge($erys,['client_id'=>'another-config','website_url'=>'https://shared.example/']);
check(count(Client::getSEOClients()) === 4, 'Ambiguous Analytics mappings remain distinct');
$wpdb->analytics = [$erys,array_merge($erys,['client_id'=>'shared','client_name'=>'Stale name'])];
check(Client::getSEOClient('shared')['company'] === 'Shared Company', 'Exact shared identity wins');
check(count(Client::getSEOClients()) === 2, 'Exact IDs deduplicate');
$wpdb->analytics[] = array_merge($erys,['client_id'=>'inactive','is_active'=>0]);
check(Client::getSEOClient('inactive') === null && count(Client::getSEOClients()) === 2, 'Inactive legacy configs are excluded');
$wpdb->portal[] = array_merge($shared,['client_id'=>$erys['client_id'],'status'=>'deleted']);
check(Client::getSEOClient($erys['client_id']) === null && count(Client::getSEOClients()) === 1, 'Deleted canonical identities cannot be resurrected via Analytics');
array_pop($wpdb->portal);
$wpdb->portal[0]['status']='paused';
check(count(Client::getSEOClients('active')) === 1, 'Active filter is consistent');
$wpdb->portal[0]['status']='active';
check(Client::getSEOClient('missing') === null, 'Unknown client never falls back');
check($wpdb->writes === 0, 'Directory lookup never mutates stored records');
function submit($id, $url='https://eryscreative.com/') {
    $_POST = ['client_id'=>$id,'site_url'=>$url,'site_name'=>'Erys'];
    try { SEOOSBootstrap::handleGenerateAgentKey(); } catch (Redirect $e) { return $e->getMessage(); }
    throw new RuntimeException('Expected redirect');
}
check(str_contains(submit($erys['client_id']), 'generated=1'), 'Erys key generation succeeds');
check(SEOHub::$keys[0]['client_id'] === $erys['client_id'], 'Key belongs to exact selected Erys identity');
check(isset(SEOHub::$profiles[$erys['client_id']]), 'Successful key creates SEO setup state');
check(str_contains(submit('missing'), 'error=invalid_client'), 'Invalid client rejected');
check(str_contains(submit(''), 'error=missing_fields'), 'Missing client rejected');
check(count(SEOHub::$keys) === 1, 'Rejected requests do not create keys');
SEOHub::$fail = true;
check(str_contains(submit('shared'), 'error=generation_failed'), 'Database failure is reported');
check(!isset(SEOHub::$profiles['shared']), 'Failed key generation does not create misleading SEO profile');
$allowed=false;
try { submit($erys['client_id']); throw new LogicException('Unauthorized generation'); } catch (RuntimeException $e) { check($e->getMessage()==='Access denied.', 'Capability enforced'); }
$allowed=true; $nonce=false;
try { submit($erys['client_id']); throw new LogicException('Invalid nonce accepted'); } catch (RuntimeException $e) { check($e->getMessage()==='Invalid nonce', 'Nonce enforced'); }
echo "SEO directory and agent-key tests passed: Erys, new clients, exact IDs, legacy links, ambiguity, deleted/inactive records, read-only lookup, generation, failures, permissions.\n";
}
