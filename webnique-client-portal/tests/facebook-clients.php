<?php
require __DIR__ . '/facebook-publish.php';
use WNQ\Services\FacebookCampaign as Campaign;
use WNQ\Services\FacebookGroupPlan as Plan;
$options = [];
$base = ['groups' => ['https://www.facebook.com/groups/501/', 'https://www.facebook.com/groups/502/'], 'message' => 'Agency message', 'timezone' => gmdate('H') === '23' ? 'Pacific/Honolulu' : 'UTC', 'start_time' => '00:00', 'cutoff' => '23:59', 'repeat' => true];
$options['wnq_facebook_group_plan'] = $base;
$options[Campaign::key('wnq_facebook_group_plan', '11')] = array_merge($base, ['message' => 'Alpha message', 'messages' => ['Alpha alternative'], 'link' => 'https://alpha.example/', 'daily_limit' => 2]);
$options[Campaign::key('wnq_facebook_group_plan', '22')] = array_merge($base, ['message' => 'Beta message', 'groups' => ['https://www.facebook.com/groups/601/']]);
$before = $checks;
check(!callApi(['op' => 'start', 'client' => '999'])['success']);
check(!callApi(['op' => 'start', 'client' => ['11']])['success']);
check(callApi(['op' => 'start', 'client' => '11'])['success']);
check(!callApi(['op' => 'start', 'client' => '22'])['success']);
check(!callApi(['op' => 'next', 'mode' => 'test', 'client' => 'agency'])['success']);
$a = callApi(['op' => 'next', 'mode' => 'test', 'client' => '11'])['data']['job'];
check($a['client_id'] === '11' && $a['client_name'] === 'Alpha Tree');
check($a['message'] === "Alpha message\nhttps://alpha.example/");
check(Campaign::ownsJob('11', $a['key']) && !Campaign::ownsJob('22', $a['key']));
check(!callApi(['op' => 'result', 'client' => '22', 'key' => $a['key'], 'token' => $a['token'], 'status' => 'submitted'])['success']);
check(!callApi(['op' => 'result', 'client' => 'agency', 'key' => $a['key'], 'token' => $a['token'], 'status' => 'submitted'])['success']);
check(callApi(['op' => 'result', 'client' => '11', 'key' => $a['key'], 'token' => $a['token'], 'status' => 'submitted'])['success']);
check(callApi(['op' => 'progress', 'client' => '11', 'mode' => 'test'])['data']['counts']['submitted'] === 1);
check(callApi(['op' => 'progress', 'client' => '22', 'mode' => 'test'])['data']['counts']['submitted'] === 0);
try { Campaign::assertEditable('11'); check(false); } catch (InvalidArgumentException $e) { check(true); }
callApi(['op' => 'stop', 'client' => '22']);
check(!callApi(['op' => 'start', 'client' => '22'])['success']); // Stopping Beta cannot stop Alpha.
callApi(['op' => 'stop', 'client' => '11']);
check(!callApi(['op' => 'start', 'client' => '22'])['success']); // Outstanding cooldown still owns the browser.
$options['wnq_fb_daily_dispatch']['until'] = time() - 1;
check(callApi(['op' => 'start', 'client' => '22'])['success']);
$b = callApi(['op' => 'next', 'mode' => 'test', 'client' => '22'])['data']['job'];
check($b['message'] === 'Beta message' && $b['client_id'] === '22');
check(!callApi(['op' => 'resume', 'client' => '11'])['success']);
callApi(['op' => 'result', 'client' => '22', 'key' => $b['key'], 'token' => $b['token'], 'status' => 'pending']);
callApi(['op' => 'stop', 'client' => '22']);
$options['wnq_fb_daily_dispatch']['until'] = time() - 1;
check(callApi(['op' => 'resume', 'client' => '11'])['success']);
// A test never bypasses the same client's daily attempt cap.
$day = (new DateTimeImmutable('now', new DateTimeZone($base['timezone'])))->format('Y-m-d');
$options[Campaign::key('wnq_fb_attempts_' . $day, '11')] = 2;
check(str_contains(callApi(['op' => 'next', 'mode' => 'test', 'client' => '11'])['data']['message'], 'daily attempt limit'));
check($options['wnq_facebook_group_plan'] === $base); // No legacy plan mutation.
$week = (new DateTimeImmutable('now', new DateTimeZone($base['timezone'])))->format('o-W');
$options[Campaign::key('wnq_facebook_group_plan', '11')]['groups'] = ['https://www.facebook.com/groups/900/'];
check(in_array('https://www.facebook.com/groups/501/', array_column(callApi(['op' => 'progress', 'client' => '11', 'week' => $week])['data']['rows'], 'url'), true));
check(!callApi(['op' => 'resolve', 'client' => '22', 'key' => $a['key'], 'resolution' => 'skipped'])['success']);
check(count(Plan::batches(range(1, 14), 2)['Monday']) === 2);
check(Plan::batches(range(1, 14), 2)['Sunday'] === [13, 14]);
check(Campaign::key('wnq_facebook_group_plan', 'agency') === 'wnq_facebook_group_plan');
// Mutex prevents two concurrent control requests from changing campaign ownership.
check(WNQ\Services\FacebookDailyGuard::reserve('campaign_mutex', 'other-request', 60));
check(!callApi(['op' => 'stop', 'client' => '11'])['success']);
WNQ\Services\FacebookDailyGuard::release('campaign_mutex', 'other-request');
echo ($checks - $before) . " client isolation, legacy preservation, history, limits and campaign locking checks passed.\n";
// Save endpoint regression: normal WordPress redirects are intercepted, not executed.
class SavedRedirect extends Exception {}
function check_admin_referer(...$args) {}
function sanitize_textarea_field($value) { return $value; }
function get_current_user_id() { return 1; }
function set_transient($key, $value, ...$args) { $GLOBALS['notices'][$key] = $value; }
function admin_url($value) { return $value; }
function wp_safe_redirect($url) { throw new SavedRedirect($url); }
function wp_die($message, ...$args) { throw new Exception($message); }
$options['wnq_fb_daily_dispatch']['until'] = time() - 1;
callApi(['op' => 'stop', 'client' => '11']);
$untouched = $options[Campaign::key('wnq_facebook_group_plan', '22')];
$save = ['client' => '11', 'groups' => 'https://www.facebook.com/groups/800/', 'message' => 'Fresh Alpha', 'messages' => "Variant one\n---\nVariant two", 'link' => 'https://alpha.example/new', 'image_ids' => '', 'daily_limit' => '3', 'start_time' => '09:00', 'cutoff' => '18:00', 'timezone' => 'America/New_York'];
$_POST = $save;
try { WNQ\Admin\FacebookGroupsAdmin::save(); } catch (SavedRedirect $e) { check(str_contains($e->getMessage(), 'client=11')); }
$saved = $options[Campaign::key('wnq_facebook_group_plan', '11')];
check($saved['messages'] === ['Variant one', 'Variant two'] && $saved['daily_limit'] === 3);
check($saved['message'] === 'Fresh Alpha' && $saved['link'] === 'https://alpha.example/new' && $saved['image_ids'] === []);
check($options[Campaign::key('wnq_facebook_group_plan', '22')] === $untouched && $options['wnq_facebook_group_plan'] === $base);
$_POST = array_merge($save, ['daily_limit' => '0']);
try { WNQ\Admin\FacebookGroupsAdmin::save(); } catch (SavedRedirect $e) {}
check($options[Campaign::key('wnq_facebook_group_plan', '11')] === $saved);
$_POST = array_merge($save, ['link' => 'javascript:alert(1)']);
try { WNQ\Admin\FacebookGroupsAdmin::save(); } catch (SavedRedirect $e) {}
check($options[Campaign::key('wnq_facebook_group_plan', '11')] === $saved);
echo "6 client-specific save and invalid-input preservation checks passed.\n";
