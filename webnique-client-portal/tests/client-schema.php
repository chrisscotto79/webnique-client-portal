<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/fixtures/schema/');
$options = []; $sql = '';
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function dbDelta($statement) { $GLOBALS['sql'] = $statement; }
class SchemaDB {
    public $prefix = 'wp_', $missing = false;
    function get_charset_collate() { return ''; }
    function query($query) { return 1; }
    function get_col($query, $index) { return $this->missing ? [] : ['business_address','city','state','primary_color','secondary_color','body_font','heading_font','payment_due_day','next_payment_due_date']; }
}
$wpdb = new SchemaDB();
require dirname(__DIR__) . '/includes/Models/Client.php';
WNQ\Models\Client::createTable();
if (!str_starts_with($sql, 'CREATE TABLE wp_wnq_clients (') || str_contains($sql, '-- ') || ($options['wnq_clients_schema_version'] ?? '') !== '5') throw new RuntimeException('Invalid dbDelta schema');
$options = []; $wpdb->missing = true; WNQ\Models\Client::createTable();
if (isset($options['wnq_clients_schema_version'])) throw new RuntimeException('Incomplete migration must not be marked successful');
echo "Client schema checks passed: dbDelta syntax and incomplete-upgrade retry.\n";
