<?php
/**
 * Publishes queued Google Business Profile posts.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

use WNQ\Models\BlogScheduler;
use WNQ\Models\SEOHub;

if (!defined('ABSPATH')) {
    exit;
}

final class GbpPublisher
{
    private const LOCK_OPTION = 'wnq_gbp_publisher_lock';

    public static function processDuePosts(int $limit = 5): array
    {
        $token = self::acquireLock();
        if ($token === '') {
            return ['success' => false, 'message' => 'GBP publisher is already running.'];
        }

        try {
            if (!GoogleBusinessProfileClient::connected()) {
                $ready = BlogScheduler::markDueGbpPostsReady();
                return ['success' => true, 'published' => 0, 'ready' => $ready];
            }

            $published = 0;
            $failed = 0;
            foreach (BlogScheduler::getDueGbpPosts(max(1, min(10, $limit))) as $post) {
                $result = self::publishById((int)$post['id']);
                if (!empty($result['success'])) {
                    $published++;
                } else {
                    $failed++;
                }
            }

            return ['success' => true, 'published' => $published, 'failed' => $failed];
        } finally {
            self::releaseLock($token);
        }
    }

    public static function publishById(int $post_id, bool $manual = false): array
    {
        $post = BlogScheduler::getGbpPost($post_id);
        if (!$post) {
            return ['success' => false, 'message' => 'GBP post was not found.'];
        }
        if (!BlogScheduler::claimGbpPost($post_id, $manual)) {
            return ['success' => false, 'message' => 'GBP post is already publishing or is not ready.'];
        }

        $client = new GoogleBusinessProfileClient();
        $result = $client->publishLocalPost($post);
        if (!empty($result['success'])) {
            BlogScheduler::updateGbpPost($post_id, [
                'status'          => 'published',
                'gbp_post_name'   => (string)($result['post_name'] ?? ''),
                'error_message'   => '',
            ]);
            BlogScheduler::addNotification(
                'gbp_published',
                'Google Business Profile post published',
                wp_trim_words(wp_strip_all_tags((string)$post['summary']), 18),
                (string)($result['search_url'] ?? ''),
                (string)$post['client_id']
            );
            SEOHub::log('gbp_post_published', [
                'schedule_id' => $post_id,
                'client_id'   => $post['client_id'],
                'post_name'   => $result['post_name'] ?? '',
            ], 'success', $manual ? 'manual' : 'cron');

            return ['success' => true, 'message' => 'GBP post published successfully.'];
        }

        $retryable = !empty($result['retryable']);
        $status = !empty($result['configuration_error']) ? 'ready' : ($retryable ? 'scheduled' : 'failed');
        $message = sanitize_textarea_field((string)($result['error'] ?? 'Google Business Profile publishing failed.'));
        BlogScheduler::updateGbpPost($post_id, [
            'status'        => $status,
            'error_message' => $message,
        ]);
        SEOHub::log('gbp_post_publish_failed', [
            'schedule_id' => $post_id,
            'client_id'   => $post['client_id'],
            'error'       => $message,
            'retryable'   => $retryable,
        ], 'failed', $manual ? 'manual' : 'cron');

        return ['success' => false, 'message' => $message];
    }

    private static function acquireLock(): string
    {
        $existing = get_option(self::LOCK_OPTION, []);
        if (is_array($existing) && (int)($existing['expires'] ?? 0) > time()) {
            return '';
        }
        if ($existing) {
            delete_option(self::LOCK_OPTION);
        }

        $token = wp_generate_password(32, false, false);
        if (!add_option(self::LOCK_OPTION, ['token' => $token, 'expires' => time() + 10 * MINUTE_IN_SECONDS], '', false)) {
            return '';
        }
        return $token;
    }

    private static function releaseLock(string $token): void
    {
        $lock = get_option(self::LOCK_OPTION, []);
        if (is_array($lock) && hash_equals((string)($lock['token'] ?? ''), $token)) {
            delete_option(self::LOCK_OPTION);
        }
    }
}
