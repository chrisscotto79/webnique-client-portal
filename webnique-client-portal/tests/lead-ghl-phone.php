<?php
require __DIR__ . '/lead-ghl-regression.php';
use WNQ\Services\LeadGhlSync as PhoneSync;
function phoneFixture(bool $existing = false): void {
    resetFixture();
    $GLOBALS['wpdb']->leads[1]['phone'] = '(941) 555-0123 ext. 42';
    if ($existing) $GLOBALS['lookup'] = $GLOBALS['contact'];
    $delegate = $GLOBALS['transport'];
    $GLOBALS['transport'] = static function ($url, $args) use ($delegate) {
        $path = parse_url($url, PHP_URL_PATH);
        if (($path === '/contacts/' && $args['method'] === 'POST') || ($path === '/contacts/contact1' && $args['method'] === 'PUT')) {
            $GLOBALS['contact'] = array_merge($GLOBALS['contact'], json_decode($args['body'], true));
        }
        return $delegate($url, $args);
    };
}
check(PhoneSync::phoneKey('(941) 555-0123 ext. 42') === '+19415550123', 'Normalize US main number without extension');
check(PhoneSync::phoneKey('+44 20 7946 0958') === '+442079460958', 'Explicit international country code preserved');
check(PhoneSync::phoneKey('020 7946 0958') === '', 'Do not guess international country code');
check(PhoneSync::phoneKey('unknown') === '', 'No invented phone');
phoneFixture(); PhoneSync::enqueue(1); PhoneSync::work();
check($wpdb->jobs[1]['status'] === 'sent', 'New phone contact sent');
check(json_decode(writes()[0][1]['body'], true)['phone'] === '+19415550123', 'New contact carries normalized phone');
check(count(writes()) === 2, 'No redundant update for newly created phone');
phoneFixture(true); PhoneSync::enqueue(1); PhoneSync::work();
check($wpdb->jobs[1]['status'] === 'sent', 'Existing email contact sent after phone enrichment');
check(writes()[0][1]['method'] === 'PUT' && json_decode(writes()[0][1]['body'], true) === ['phone' => '+19415550123'], 'Only phone is changed on existing contact');
check(str_ends_with(writes()[1][0], '/tags'), 'Phone saved before workflow tag');
phoneFixture(true); $contact['phone'] = '+19415550999'; PhoneSync::enqueue(1); PhoneSync::work();
check(count(writes()) === 0, 'Conflicting existing phone not overwritten or tagged');
phoneFixture(true); $contact['dndSettings'] = ['SMS' => ['status' => 'active']]; PhoneSync::enqueue(1); PhoneSync::work();
check(count(writes()) === 0, 'SMS opt-out blocks combined campaign');
phoneFixture(true); $delegate = $transport;
$transport = static function ($url, $args) use ($delegate) {
    if ($args['method'] === 'PUT') return ['code' => 200, 'body' => '{}'];
    return $delegate($url, $args);
};
PhoneSync::enqueue(1); PhoneSync::work();
check(count(writes()) === 1 && writes()[0][1]['method'] === 'PUT', 'Unconfirmed phone never triggers tag');
phoneFixture(true); $contact['phone'] = '+19415550123'; $contact['tags'][] = PhoneSync::TAG;
PhoneSync::enqueue(1); PhoneSync::work();
check(count(writes()) === 0 && $wpdb->jobs[1]['status'] === 'sent', 'Existing tagged contact is not re-enrolled');
phoneFixture(); $wpdb->leads[1]['phone'] = 'not listed'; PhoneSync::enqueue(1); PhoneSync::work();
check($wpdb->jobs[1]['status'] === 'sent' && !isset(json_decode(writes()[0][1]['body'], true)['phone']), 'Missing phone preserves email handoff');
echo "Phone handoff checks passed; no live GHL changes.\n";
