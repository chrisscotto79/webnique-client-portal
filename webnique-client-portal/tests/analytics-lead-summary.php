<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
function get_option(string $key, $default = []) { return $GLOBALS['test_options'][$key] ?? $default; }
function sanitize_key($value): string { return strtolower((string)preg_replace('/[^a-z0-9_\-]/i', '', (string)$value)); }
function sanitize_text_field($value): string { return trim((string)$value); }
require_once dirname(__DIR__) . '/includes/Services/AnalyticsActivity.php';

$client = 'fixture';
$GLOBALS['test_options']['wnq_activity_' . hash('sha256', $client)] = [
    'phone_events' => ['phone_click'], 'form_events' => ['generate_lead'], 'email_events' => ['email_click'], 'min_call_duration' => 20, 'lead_events_confirmed'=>true,
];
assert(\WNQ\Services\AnalyticsActivity::callThreshold($client) === 20);
assert(\WNQ\Services\AnalyticsActivity::attribution('Paid Search', '1234567890', '1234567890') === 'Google Ads');
assert(\WNQ\Services\AnalyticsActivity::attribution('Paid Search', '1234567890', '0987654321') === 'Other');
assert(\WNQ\Services\AnalyticsActivity::attribution('Organic Search', '', '') === 'Organic search');

$request = static function (array $body): array {
    assert($body['metrics'][1]['name'] === 'keyEvents');
    return ['rowCount' => 2, 'rows' => [
        ['dimensionValues' => [['value'=>'generate_lead']], 'metricValues' => [['value'=>'5'],['value'=>'3']]],
        ['dimensionValues' => [['value'=>'email_click']], 'metricValues' => [['value'=>'2'],['value'=>'2']]],
    ]];
};
$report = \WNQ\Services\AnalyticsActivity::leadEvents($client, '2026-09-01', '2026-09-07', $request);
assert($report['status'] === 'available');
assert($report['form_leads'] === 3 && $report['email_leads'] === 2);
assert(strpos($report['message'], 'GA4 key-event counts') !== false);
foreach ([[], ['lead_events_confirmed'=>false]] as $settings) {
    $GLOBALS['test_options']['wnq_activity_' . hash('sha256', $client)] = $settings;
    $report = \WNQ\Services\AnalyticsActivity::leadEvents($client, '2026-09-01', '2026-09-07', $request);
    assert($report['form_leads'] === 3 && $report['email_leads'] === 2);
}
$empty = \WNQ\Services\AnalyticsActivity::leadEvents($client, '2026-09-01', '2026-09-07', static fn($body) => ['rowCount'=>0, 'rows'=>[]]);
assert($empty['status'] === 'available' && $empty['form_leads'] === 0 && $empty['email_leads'] === 0);
$rawOnly = \WNQ\Services\AnalyticsActivity::leadEvents($client, '2026-09-01', '2026-09-07', static fn($body) => ['rows'=>[
    ['dimensionValues'=>[['value'=>'generate_lead']], 'metricValues'=>[['value'=>'9'], ['value'=>'0']]],
]]);
assert($rawOnly['form_leads'] === 0 && $rawOnly['email_leads'] === 0);
echo "Analytics lead summary unit checks passed.\n";
