<?php
define('ABSPATH', __DIR__);
require_once __DIR__ . '/../includes/Services/FacebookGroupPlan.php';
require_once __DIR__ . '/../admin/FacebookGroupsAdmin.php';
class Response extends Exception { public $payload; public function __construct($payload) { $this->payload = $payload; } }
$options = []; $allowed = true;
function maybe_serialize($value) { return serialize($value); }
function wp_cache_delete(...$args) {}
$wpdb = new class {
    public $options = 'wp_options';
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function query($query) {
        global $options;
        [$sql, $args] = $query;
        if (str_starts_with($sql, 'UPDATE')) {
            [$next, $key, $old] = $args;
            if (serialize($options[$key] ?? null) !== $old) return 0;
            $options[$key] = unserialize($next); return 1;
        }
        [$key, $old] = $args;
        if (serialize($options[$key] ?? null) !== $old) return 0;
        unset($options[$key]); return 1;
    }
};
function current_user_can($cap) { global $allowed; return $allowed; }
function wp_send_json_success($data) { throw new Response(['success' => true, 'data' => $data]); }
function wp_send_json_error($data, $status = 200) { throw new Response(['success' => false, 'data' => $data]); }
function check_ajax_referer(...$args) {}
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, ...$args) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function update_option($key, $value, ...$args) { global $options; $options[$key] = $value; }
function delete_option($key) { global $options; unset($options[$key]); }
function wp_generate_uuid4() { return 'test-token'; }
function callApi($input, $advanceInterval = true) {
    global $options;
    // Existing queue cases simulate the next polling opportunity after cooldown.
    if ($advanceInterval && ($input['op'] ?? '') === 'next' && isset($options['wnq_fb_daily_dispatch'])) $options['wnq_fb_daily_dispatch']['until'] = time() - 1;
    $_POST = $input; try { WNQ\Admin\FacebookGroupsAdmin::publish(); } catch (Response $r) { return $r->payload; } throw new Exception('No response');
}
$checks = 0;
function check($value) { global $checks; if (!$value) throw new Exception('Check failed at ' . ($checks + 1)); $checks++; }
check(!callApi(['op' => 'next'])['success']);
$options['wnq_facebook_group_plan'] = ['groups' => ['https://www.facebook.com/groups/123/'], 'message' => 'Hello', 'timezone' => gmdate('H') === '23' ? 'Pacific/Honolulu' : 'UTC', 'start_time' => '09:00', 'cutoff' => '23:59', 'repeat' => true];
$options['wnq_fb_schedule_enabled'] = true;
$allowed = false; check(!callApi(['op' => 'next'])['success']); $allowed = true;
$job = callApi(['op' => 'next', 'mode' => 'test'])['data']['job'];
check($job['message'] === 'Hello');
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
check(!callApi(['op' => 'result', 'key' => $job['key'], 'token' => 'wrong', 'status' => 'submitted'])['success']);
check(callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'not_started'])['success']);
$job = callApi(['op' => 'next', 'mode' => 'test'])['data']['job'];
check(isset($job['key']));
callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'unknown']);
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
check(callApi(['op' => 'progress', 'mode' => 'test'])['data']['counts']['review'] === 1);
callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'submitted']);
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'not_started']);
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
check(!WNQ\Services\FacebookDailyGuard::reserve('123', 'other-token'));
WNQ\Services\FacebookDailyGuard::release('123', 'wrong-token');
check(!WNQ\Services\FacebookDailyGuard::reserve('123', 'other-token'));
// Even a new weekly job cannot bypass the separate daily guard.
unset($options[$job['key']]);
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
$options['wnq_fb_daily_123']['until'] = time() - 1;
unset($options[$job['key']]);
check(callApi(['op' => 'next', 'mode' => 'test'])['success']);
check(WNQ\Services\FacebookDailyGuard::groupId('https://www.facebook.com/groups/named/') === '');
check(WNQ\Services\FacebookDailyGuard::groupId('https://www.facebook.com/groups/123/') === '123');
check(!callApi(['op' => 'resolve', 'key' => $job['key'], 'resolution' => 'skipped'])['success']);
$options[$job['key']]['status'] = 'unknown';
check(callApi(['op' => 'resolve', 'key' => $job['key'], 'resolution' => 'skipped'])['success']);
check(!WNQ\Services\FacebookDailyGuard::reserve('123', 'new-token'));
check(callApi(['op' => 'progress', 'mode' => 'test'])['data']['counts']['skipped'] === 1);
$options['wnq_facebook_group_plan']['cutoff'] = '00:00';
check(str_contains(callApi(['op' => 'next', 'mode' => 'test'])['data']['message'], 'cutoff'));
// A blocked first group must not prevent reaching later eligible groups.
$options['wnq_facebook_group_plan']['cutoff'] = '23:59';
$groups = [];
for ($i = 1; $i <= 350; $i++) $groups[] = 'https://www.facebook.com/groups/' . ($i + 1000) . '/';
$options['wnq_facebook_group_plan']['groups'] = $groups;
$now = new DateTimeImmutable('now', new DateTimeZone($options['wnq_facebook_group_plan']['timezone']));
$today = WNQ\Services\FacebookGroupPlan::batches($groups)[$now->format('l')];
$firstKey = 'wnq_fb_job_' . hash('sha256', $now->format('o-W') . '|' . $today[0]);
$options[$firstKey] = ['status' => 'unknown', 'token' => 'old'];
$next = callApi(['op' => 'next', 'mode' => 'today'])['data']['job'];
check($next['url'] === $today[1]);
callApi(['op' => 'result', 'key' => $next['key'], 'token' => $next['token'], 'status' => 'not_started', 'scope' => 'group']);
check(callApi(['op' => 'next', 'mode' => 'today'])['data']['job']['url'] === $today[2]);
// Reproduce a Monday manual test after a one-time week recorded last week.
$options['wnq_facebook_group_plan']['groups'] = ['https://www.facebook.com/groups/999999/'];
$options['wnq_facebook_group_plan']['repeat'] = false;
$options['wnq_facebook_group_plan']['start_time'] = '00:00';
$options['wnq_fb_first_week'] = $now->modify('-7 days')->format('o-W');
check(str_contains(callApi(['op' => 'next', 'mode' => 'scheduled'])['data']['message'], 'One-time week finished'));
$manual = callApi(['op' => 'next', 'mode' => 'test'])['data']['job'];
check($manual['url'] === 'https://www.facebook.com/groups/999999/');
check($options['wnq_fb_first_week'] === $now->modify('-7 days')->format('o-W'));
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
callApi(['op' => 'result', 'key' => $manual['key'], 'token' => $manual['token'], 'status' => 'submitted']);
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
check(!WNQ\Services\FacebookDailyGuard::reserve('999999', 'different-token'));
unset($options['wnq_fb_first_week']);
$options['wnq_facebook_group_plan']['groups'] = ['https://www.facebook.com/groups/999998/'];
check(isset(callApi(['op' => 'next', 'mode' => 'test'])['data']['job']));
check(!isset($options['wnq_fb_first_week']));
// Same server cooldown applies to a second group, even from another tab/test.
$options['wnq_facebook_group_plan']['groups'] = ['https://www.facebook.com/groups/888888/'];
$first = callApi(['op' => 'next', 'mode' => 'test'])['data']['job'];
callApi(['op' => 'result', 'key' => $first['key'], 'token' => $first['token'], 'status' => 'submitted']);
$options['wnq_facebook_group_plan']['groups'] = ['https://www.facebook.com/groups/888889/'];
check(callApi(['op' => 'next', 'mode' => 'test'], false)['data']['waiting']);
check($options['wnq_fb_daily_dispatch']['until'] >= time() + 359);
check(!isset($options['wnq_fb_daily_888889']), 'Cooldown must not consume the second group');
check(isset(callApi(['op' => 'next', 'mode' => 'test'])['data']['job']));
callApi(['op' => 'stop']);
check(callApi(['op' => 'next', 'mode' => 'scheduled'])['data']['stopped']);
callApi(['op' => 'resume']);
check($options['wnq_fb_schedule_enabled']);
$oldWeek = $options['wnq_fb_first_week'] ?? '';
callApi(['op' => 'start']);
check($options['wnq_fb_first_week'] === $now->format('o-W'));
$snapshot = callApi(['op' => 'progress', 'mode' => 'test'])['data'];
check(count($snapshot['rows']) === 1);
check($snapshot['rows'][0]['status'] === 'reserved');
if (in_array('--render', $argv, true)) {
    function get_transient(...$args) { return false; }
    function delete_transient(...$args) {}
    function get_current_user_id() { return 1; }
    function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES); }
    function esc_attr($value) { return esc_html($value); }
    function esc_url($value) { return esc_html($value); }
    function esc_textarea($value) { return esc_html($value); }
    function admin_url($value) { return 'https://goldenwebmarketing.com/wp-admin/' . $value; }
    function wp_nonce_field(...$args) {}
    function selected($a, $b) { if ($a === $b) echo 'selected'; }
    function checked($a) { if ($a) echo 'checked'; }
    function submit_button($label) { echo '<button>' . esc_html($label) . '</button>'; }
    WNQ\Admin\FacebookGroupsAdmin::render();
} else echo "$checks publishing queue checks passed. No external requests.\n";
