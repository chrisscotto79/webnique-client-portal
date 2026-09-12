<?php
/** Offline history tests. No WordPress database or network required. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__); define('ARRAY_A', 'ARRAY_A');
function sanitize_text_field($v) { return strip_tags($v); }
function get_current_user_id() { return 17; }
final class HistoryDb {
    public $prefix = 'wp_', $last_error = '', $rows = [], $legacy = [], $row = null, $writes = [];
    function prepare($sql, ...$values) { return $sql; }
    function get_results($sql, $mode) { return str_contains($sql, 'SELECT notes') ? $this->legacy : $this->rows; }
    function get_row($sql, $mode) { return $this->row; }
    function insert($table, $data) { $this->writes[] = $data; return 1; }
    function update($table, $data, $where) { $this->writes[] = $data; return 1; }
}
$wpdb = new HistoryDb();
require __DIR__ . '/../includes/Models/LeadSearchHistory.php';
use WNQ\Models\LeadSearchHistory as H;
$checks = 0;
function ok($v) { global $checks; $checks++; if (!$v) { throw new RuntimeException('Check failed: ' . $checks); } }
function rejects($fn) { try { $fn(); } catch (RuntimeException $e) { ok(true); return; } ok(false); }
ok(H::keyword('  PLUMBERS   Near Me ') === 'plumbers near me');
ok(H::zips("01234,32825\n32825;32826") === ['01234','32825','32826']);
ok(count(H::zips(implode(',', range(10000,10249)))) === 250);
foreach (['', '1234', '32825bad', implode(',', range(10000,10250))] as $bad) { rejects(fn() => H::zips($bad)); }
$run = '12345678-1234-1234-1234-123456789012';
H::begin($run, 'Plumbers', '01234'); ok(count($wpdb->writes) === 1);
$wpdb->row = $wpdb->writes[0] + ['status'=>'started'];
H::begin($run, 'PLUMBERS', '01234'); ok(count($wpdb->writes) === 1);
rejects(fn() => H::begin($run, 'Roofers', '01234'));
H::finish($run, ['saved'=>150,'emails'=>-2,'limited'=>true]);
ok($wpdb->writes[1]['saved'] === 100 && $wpdb->writes[1]['emails'] === 0 && $wpdb->writes[1]['coverage'] === 'limited');
$wpdb->row['status'] = 'completed'; H::finish($run, []); ok(count($wpdb->writes) === 2);
$wpdb->row['user_id'] = 18; rejects(fn() => H::finish($run, []));
$wpdb->rows = [['zip'=>'01234','status'=>'completed']];
$wpdb->legacy = [['notes'=>"Maps: example\nSearch: Plumbers in 32825\n",'scraped_at'=>'2026-09-10 12:00:00']];
$prior = H::check('plumbers', ['01234','32825','32826']);
ok($prior[0]['previous']['status'] === 'completed');
ok($prior[1]['previous']['status'] === 'legacy'); ok($prior[2]['previous'] === null);
$wpdb->rows = []; ok(H::check('roofers', ['32825'])[0]['previous'] === null);
$wpdb->last_error = 'fixture DB error'; rejects(fn() => H::check('plumbers', ['32825']));
echo "PASS: $checks history checks; database mocked.\n";
