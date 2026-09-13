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
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, ...$args) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function update_option($key, $value, ...$args) { global $options; $options[$key] = $value; }
function delete_option($key) { global $options; unset($options[$key]); }
function wp_generate_uuid4() { return 'test-token'; }
function callApi($input) { $_POST = $input; try { WNQ\Admin\FacebookGroupsAdmin::publish(); } catch (Response $r) { return $r->payload; } throw new Exception('No response'); }
$checks = 0;
function check($value) { global $checks; if (!$value) throw new Exception('Check failed at ' . ($checks + 1)); $checks++; }
check(!callApi(['op' => 'next'])['success']);
$options['wnq_facebook_group_plan'] = ['groups' => ['https://www.facebook.com/groups/123/'], 'message' => 'Hello', 'timezone' => 'America/New_York', 'start_time' => '09:00', 'repeat' => true];
$allowed = false; check(!callApi(['op' => 'next'])['success']); $allowed = true;
$job = callApi(['op' => 'next', 'mode' => 'test'])['data']['job'];
check($job['message'] === 'Hello');
check(!callApi(['op' => 'next', 'mode' => 'test'])['success']);
check(!callApi(['op' => 'result', 'key' => $job['key'], 'token' => 'wrong', 'status' => 'submitted'])['success']);
check(callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'not_started'])['success']);
$job = callApi(['op' => 'next', 'mode' => 'test'])['data']['job'];
check(isset($job['key']));
callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'unknown']);
check(!callApi(['op' => 'next', 'mode' => 'test'])['success']);
callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'submitted']);
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
callApi(['op' => 'result', 'key' => $job['key'], 'token' => $job['token'], 'status' => 'not_started']);
check(callApi(['op' => 'next', 'mode' => 'test'])['data']['finished']);
check(!WNQ\Services\FacebookDailyGuard::reserve('123', 'other-token'));
WNQ\Services\FacebookDailyGuard::release('123', 'wrong-token');
check(!WNQ\Services\FacebookDailyGuard::reserve('123', 'other-token'));
// Even a new weekly job cannot bypass the separate daily guard.
unset($options[$job['key']]);
check(!callApi(['op' => 'next', 'mode' => 'test'])['success']);
$options['wnq_fb_daily_123']['until'] = time() - 1;
check(callApi(['op' => 'next', 'mode' => 'test'])['success']);
check(WNQ\Services\FacebookDailyGuard::groupId('https://www.facebook.com/groups/named/') === '');
check(WNQ\Services\FacebookDailyGuard::groupId('https://www.facebook.com/groups/123/') === '123');
echo "$checks publishing queue checks passed. No external requests.\n";
