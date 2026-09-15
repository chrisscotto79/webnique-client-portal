<?php
require __DIR__ . '/facebook-publish.php';
$options = [];
$path = tempnam(sys_get_temp_dir(), 'wnq-image-test-');
file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
function get_attached_file($id) { return $id === 71 ? $GLOBALS['path'] : false; }
try {
    $planKey = WNQ\Services\FacebookCampaign::key('wnq_facebook_group_plan', '11');
    $options[$planKey] = ['groups' => ['https://www.facebook.com/groups/991/'], 'message' => 'Image campaign', 'timezone' => gmdate('H') === '23' ? 'Pacific/Honolulu' : 'UTC', 'start_time' => '00:00', 'cutoff' => '23:59', 'repeat' => true];
    $saved = callApi(['op' => 'save_images', 'client' => '11', 'image_ids' => '71']);
    check($saved['success'] && $saved['data']['image_ids'] === [71]);
    check($options[$planKey]['image_ids'] === [71]);
    check($options[$planKey]['message'] === 'Image campaign');
    check(!isset($options['wnq_facebook_group_plan']));
    check(!callApi(['op' => 'save_images', 'client' => '11', 'image_ids' => '999'])['success']);
    check($options[$planKey]['image_ids'] === [71]);
    $job = callApi(['op' => 'next', 'client' => '11', 'mode' => 'test'])['data']['job'];
    check($job['image_count'] === 1 && count($job['images']) === 1);
    check($job['images'][0]['mime'] === 'image/png');
    check(base64_decode($job['images'][0]['data']) === file_get_contents($path));
    check(!callApi(['op' => 'save_images', 'client' => '11', 'image_ids' => ''])['success']);
    $options['wnq_fb_daily_dispatch']['until'] = time() - 1;
    check(callApi(['op' => 'save_images', 'client' => '11', 'image_ids' => ''])['success']);
    check($options[$planKey]['image_ids'] === []);
    echo "12 image persistence, preservation, dispatch payload and running-campaign checks passed.\n";
} finally { unlink($path); }
