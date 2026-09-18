<?php
require_once __DIR__ . '/../includes/Services/FacebookGroupPlan.php';
use WNQ\Services\FacebookGroupPlan as Plan;
$checks = 0;
function verify($ok, $message) {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$example = 'https://www.facebook.com/groups/518654505927406';
$result = Plan::parse("$example\nhttps://m.facebook.com/groups/518654505927406/?ref=share\n[$example]($example)");
verify(count($result['groups']) === 1, 'Canonical duplicate removal');
verify($result['duplicates'] === 2, 'Duplicate count');
verify($result['groups'][0] === $example . '/', 'Canonical URL');
verify(Plan::parse('')['groups'] === [], 'Empty draft');
$links = [];
for ($i = 1; $i <= 350; $i++) $links[] = 'https://www.facebook.com/groups/' . $i;
$batches = Plan::batches(Plan::parse(implode("\n", $links))['groups']);
verify(count($batches) === 7, 'Seven days');
foreach ($batches as $day => $groups) verify(count($groups) === 50, $day . ' has 50');
verify(str_ends_with($batches['Tuesday'][0], '/51/'), 'Tuesday boundary');
verify(str_ends_with($batches['Sunday'][49], '/350/'), 'Sunday boundary');
foreach (['http://facebook.com/groups/1', 'https://facebook.com.evil.test/groups/1',
    'https://user@facebook.com/groups/1', 'https://facebook.com:443/groups/1',
    'https://facebook.com/groups/1/posts/2', 'https://facebook.com/groups/feed',
    'javascript:alert(1)', 'https://facebook.com/share/abc',
    implode("\n", $links) . "\nhttps://facebook.com/groups/351"] as $bad) {
    try { Plan::parse($bad); verify(false, 'Must reject invalid input'); }
    catch (InvalidArgumentException $e) { verify(true, 'Rejected'); }
}


$small = Plan::batches(array_slice($links, 0, 5));
verify(count($small['Monday']) === 5 && count($small['Friday']) === 0, 'Small weekly plans assign Monday, not every day');
verify(array_keys(array_filter($small)) === ['Monday'], 'Dashboard assigned weekdays reflect nonempty batches');

echo "$checks checks passed; no Facebook requests made.\n";
