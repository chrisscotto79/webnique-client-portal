<?php
// Exercise the real endpoint: retired providers must fail before any data access.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__ . '/');
function check_ajax_referer($action, $field) { $GLOBALS['nonce_checked'] = true; }
function current_user_can($capability) { return $GLOBALS['allowed']; }
function nocache_headers() {}
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim((string)$value); }
function sanitize_key($value) { return strtolower((string)$value); }
function wp_send_json_error($data, $status = 200) { $GLOBALS['response'] = [$data, $status]; }
require dirname(__DIR__) . '/admin/AnalyticsAdmin.php';
$_POST = ['client_id' => 'fixture', 'provider' => 'gbp_summary'];
foreach ([true => 400, false => 403] as $allowed => $expected) {
    $GLOBALS['allowed'] = (bool)$allowed;
    \WNQ\Admin\AnalyticsAdmin::ajaxGetActivity();
    if (empty($GLOBALS['nonce_checked']) || $GLOBALS['response'][1] !== $expected) {
        throw new RuntimeException('Retired provider or permission check failed.');
    }
}
echo "Retired Analytics provider and permission checks passed.\n";
