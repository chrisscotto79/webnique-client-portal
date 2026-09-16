<?php
namespace WNQ\Models {
    class Client {
        public static $rows = [];
        public static function getAll() { return self::$rows; }
        public static function getByClientId($id) { foreach (self::$rows as $row) if ($row['client_id'] === $id) return $row; return null; }
    }
}
namespace {
define('ABSPATH', __DIR__); define('ARRAY_A', 'ARRAY_A');
class DB {
    public $prefix = 'wp_', $rows = [];
    function prepare($sql, ...$args) { return [$sql, $args]; }
    function get_results($sql, $type) { return $this->rows; }
    function get_row($query, $type) { foreach ($this->rows as $row) if ($row['client_id'] === $query[1][0]) return $row; return null; }
}
$wpdb = new DB();
require dirname(__DIR__) . '/includes/Models/AnalyticsConfig.php';
use WNQ\Models\Client;
use WNQ\Models\AnalyticsConfig as Config;
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
Client::$rows = [['client_id' => 'shared-1', 'company' => 'First', 'name' => 'Owner', 'website' => 'https://first.example/']];
check(count(Config::getAllClients()) === 1, 'Portal client appears without duplicate setup');
check(Config::getClientConfig('shared-1')['client_name'] === 'First', 'Defaults reuse shared identity');
$wpdb->rows = [['client_id' => 'legacy-first', 'client_name' => 'Old label', 'website_url' => 'https://first.example/', 'ga4_property_id' => 'properties/123', 'phone_numbers' => '["123"]', 'form_ids' => '["contact"]']];
check(Config::idForPortal(Client::$rows[0]) === 'legacy-first', 'Resolve one exact website');
check(count(Config::getAllClients()) === 1, 'Do not display duplicate for linked legacy config');
$config = Config::getClientConfig('shared-1');
check($config['ga4_property_id'] === 'properties/123' && $config['form_ids'] === ['contact'], 'Preserve saved settings and legacy key');
Client::$rows[] = ['client_id' => 'shared-2', 'name' => 'Other', 'company' => 'Other', 'website' => 'https://first.example/'];
check(Config::idForPortal(Client::$rows[0]) === 'shared-1', 'Ambiguous shared websites must not cross-link clients');
array_pop(Client::$rows);
$wpdb->rows[] = array_merge($wpdb->rows[0], ['client_id' => 'duplicate-config']);
check(Config::idForPortal(Client::$rows[0]) === 'shared-1', 'Ambiguous legacy configs must not be guessed');
$wpdb->rows = [array_merge($wpdb->rows[0], ['client_id' => 'shared-1'])];
check(Config::idForPortal(Client::$rows[0]) === 'shared-1', 'Exact ID always wins');
check(Config::getClientConfig('nonexistent') === null, 'Unknown client is not created');
echo "Shared client directory tests passed: defaults, legacy preservation, exact IDs, ambiguous mappings, unknown clients.\n";
}
