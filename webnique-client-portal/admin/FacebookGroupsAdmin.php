<?php
namespace WNQ\Admin;

use WNQ\Services\FacebookGroupPlan;
use WNQ\Services\FacebookDailyGuard;

require_once __DIR__ . '/../includes/Services/FacebookDailyGuard.php';

if (!defined('ABSPATH')) exit;

/** Configuration and browser publishing controller. Facebook login stays in Chrome. */
final class FacebookGroupsAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 25);
        add_action('admin_post_wnq_facebook_plan', [self::class, 'save']);
        add_action('wp_ajax_wnq_facebook_publish', [self::class, 'publish']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function assets(): void
    {
        if (($_GET['page'] ?? '') !== 'wnq-facebook-groups' || !self::allowed()) return;
        wp_enqueue_script('wnq-facebook-publish', WNQ_PORTAL_URL . 'assets/js/facebook-publish.js', [], WNQ_PORTAL_VERSION, true);
        wp_localize_script('wnq-facebook-publish', 'WNQFacebook', [
            'ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wnq_facebook_publish'),
        ]);
    }

    public static function publish(): void
    {
        if (!self::allowed()) wp_send_json_error(['message' => 'Not authorized.'], 403);
        check_ajax_referer('wnq_facebook_publish', 'nonce');
        $plan = get_option('wnq_facebook_group_plan', []);
        $op = sanitize_key($_POST['op'] ?? '');
        if (empty($plan['groups']) || trim($plan['message'] ?? '') === '') {
            wp_send_json_error(['message' => 'Save your group links and message first.']);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone($plan['timezone']));
        $week = $now->format('o-W');
        $mode = sanitize_key($_POST['mode'] ?? 'today');
        $groups = $mode === 'test' ? array_slice($plan['groups'], 0, 1) : FacebookGroupPlan::batches($plan['groups'])[$now->format('l')];
        if ($op === 'progress') {
            $counts = ['total' => count($groups), 'submitted' => 0, 'pending' => 0, 'skipped' => 0, 'review' => 0];
            $review = [];
            foreach ($plan['groups'] as $url) {
                $key = 'wnq_fb_job_' . hash('sha256', $week . '|' . strtolower($url));
                $state = get_option($key, []);
                $status = $state['status'] ?? '';
                $held = $status === 'unknown' || ($status === 'reserved' && ($state['expires_at'] ?? PHP_INT_MAX) < time());
                if (in_array($url, $groups, true)) {
                    if (isset($counts[$status]) && $status !== 'total') $counts[$status]++;
                    elseif ($held) $counts['review']++;
                }
                if ($held) $review[] = ['key' => $key, 'url' => $url];
                elseif ($status === 'skipped' && !empty($state['message'])) $review[] = ['key' => $key, 'url' => $url, 'skipped' => true, 'message' => $state['message']];
            }
            wp_send_json_success(['counts' => $counts, 'review' => $review]);
        }
        if ($op === 'resolve') {
            $key = sanitize_key($_POST['key'] ?? '');
            $valid = false;
            foreach ($plan['groups'] as $url) if ($key === 'wnq_fb_job_' . hash('sha256', $week . '|' . strtolower($url))) $valid = true;
            $state = $valid ? get_option($key, []) : [];
            if (!$state || !($state['status'] === 'unknown' || ($state['status'] === 'reserved' && ($state['expires_at'] ?? PHP_INT_MAX) < time()))) {
                wp_send_json_error(['message' => 'This job is not ready for manual review.']);
            }
            $resolution = sanitize_key($_POST['resolution'] ?? '');
            if (!in_array($resolution, ['submitted', 'skipped'], true)) wp_send_json_error(['message' => 'Invalid resolution.']);
            // Keep both daily exclusion and weekly record even when the user says not posted.
            update_option($key, array_merge($state, ['status' => $resolution]), false);
            wp_send_json_success([]);
        }
        if ($op === 'next') {
            $cutoff = $plan['cutoff'] ?? '18:00';
            if ($now->format('H:i') >= $cutoff) wp_send_json_success(['finished' => true, 'message' => 'Daily cutoff reached. No more posts will start today.']);
            $mode = sanitize_key($_POST['mode'] ?? 'today');
            if ($mode === 'scheduled' && $now->format('H:i') < $plan['start_time']) {
                wp_send_json_success(['waiting' => true]);
            }
            $first = get_option('wnq_fb_first_week', '');
            if (!$plan['repeat'] && $first && $first !== $week) {
                wp_send_json_success(['finished' => true, 'message' => 'One-time week finished. Enable repeat and save to run another week.']);
            }
            $groups = FacebookGroupPlan::batches($plan['groups'])[$now->format('l')];
            // A single saved group can be tested immediately, regardless of weekday.
            if ($mode === 'test') $groups = array_slice($plan['groups'], 0, 1);
            foreach ($groups as $url) {
                $groupId = FacebookDailyGuard::groupId($url);
                $key = 'wnq_fb_job_' . hash('sha256', $week . '|' . strtolower($url));
                if (!$groupId) { add_option($key, ['status' => 'skipped'], '', false); continue; }
                $state = get_option($key, []);
                if (in_array($state['status'] ?? '', ['submitted', 'pending'], true)) continue;
                if ($state) continue;
                $token = wp_generate_uuid4();
                if (!FacebookDailyGuard::reserve($groupId, $token)) {
                    add_option($key, ['status' => 'skipped'], '', false); continue;
                }
                // Atomic unique option reserves this group before any browser-side click.
                $expires = min(time() + 120, $now->setTime((int)substr($cutoff, 0, 2), (int)substr($cutoff, 3, 2))->getTimestamp());
                if (!add_option($key, ['status' => 'reserved', 'token' => $token, 'group_id' => $groupId, 'expires_at' => $expires], '', false)) {
                    FacebookDailyGuard::release($groupId, $token);
                    continue;
                }
                add_option('wnq_fb_first_week', $week, '', false);
                wp_send_json_success(['job' => ['key' => $key, 'token' => $token,
                    'expires_at' => $expires,
                    'url' => $url, 'message' => $plan['message']]]);
            }
            wp_send_json_success(['finished' => true, 'message' => 'Today’s batch is complete or has no groups.']);
        }
        if ($op === 'result') {
            $key = sanitize_key($_POST['key'] ?? '');
            $token = sanitize_text_field($_POST['token'] ?? '');
            if (!preg_match('/^wnq_fb_job_[a-f0-9]{64}$/D', $key)) wp_send_json_error(['message' => 'Invalid job.']);
            $state = get_option($key, []);
            if (!$state || !hash_equals($state['token'], $token)) wp_send_json_error(['message' => 'Job ownership mismatch.']);
            $status = sanitize_key($_POST['status'] ?? 'unknown');
            if (!in_array($status, ['submitted', 'pending', 'unknown', 'not_started'], true)) $status = 'unknown';
            if (in_array($state['status'], ['submitted', 'pending'], true)) wp_send_json_success([]);
            if ($status === 'not_started' && $state['status'] === 'reserved') {
                if (!empty($state['group_id'])) FacebookDailyGuard::release($state['group_id'], $token);
                if (($_POST['scope'] ?? '') === 'group') update_option($key, array_merge($state, ['status' => 'skipped', 'message' => substr(sanitize_text_field(wp_unslash($_POST['message'] ?? '')), 0, 500)]), false);
                else delete_option($key);
            } else update_option($key, array_merge($state, ['status' => $status === 'not_started' ? 'unknown' : $status]), false);
            wp_send_json_success([]);
        }
        wp_send_json_error(['message' => 'Unknown action.']);
    }

    private static function allowed(): bool
    {
        return current_user_can('manage_options') || current_user_can('wnq_manage_portal');
    }

    public static function menu(): void
    {
        add_submenu_page('wnq-portal', 'Facebook Groups', 'Facebook Groups',
            current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options',
            'wnq-facebook-groups', [self::class, 'render']);
    }

    public static function save(): void
    {
        if (!self::allowed()) wp_die('Not authorized.', '', ['response' => 403]);
        check_admin_referer('wnq_facebook_plan');
        try {
            foreach (['groups', 'message', 'start_time', 'timezone'] as $key) {
                if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                    throw new \InvalidArgumentException('Missing or invalid plan fields.');
                }
            }
            $parsed = FacebookGroupPlan::parse(wp_unslash($_POST['groups']));
            $time = wp_unslash($_POST['start_time']);
            $timezone = wp_unslash($_POST['timezone']);
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
                throw new \InvalidArgumentException('Choose a valid daily start time.');
            }
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException('Choose a valid timezone.');
            }
            $message = sanitize_textarea_field(wp_unslash($_POST['message']));
            $cutoff = isset($_POST['cutoff']) && is_string($_POST['cutoff']) ? wp_unslash($_POST['cutoff']) : '18:00';
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $cutoff) || $cutoff <= $time) throw new \InvalidArgumentException('Cutoff must be later than the start time on the same day.');
            if (strlen($message) > 20000) throw new \InvalidArgumentException('Keep the message under 20,000 bytes.');
            // Saving a draft cannot activate posting or change any Facebook account.
            update_option('wnq_facebook_group_plan', [
                'groups' => $parsed['groups'], 'message' => $message,
                'start_time' => $time, 'timezone' => $timezone,
                'cutoff' => $cutoff,
                'repeat' => isset($_POST['repeat']), 'enabled' => false,
            ], false);
            $notice = 'Draft saved. ' . count($parsed['groups']) . ' groups; ' . $parsed['duplicates'] . ' duplicate links removed. No posts sent.';
        } catch (\InvalidArgumentException $e) {
            $notice = $e->getMessage() . ' Previous draft was not changed.';
        }
        set_transient('wnq_fb_notice_' . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=wnq-facebook-groups'));
        exit;
    }

    public static function render(): void
    {
        if (!self::allowed()) wp_die('Not authorized.');
        $plan = get_option('wnq_facebook_group_plan', []);
        $plan = array_merge(['groups' => [], 'message' => '', 'start_time' => '09:00',
            'timezone' => 'America/New_York', 'repeat' => false], is_array($plan) ? $plan : []);
        $notice = get_transient('wnq_fb_notice_' . get_current_user_id());
        delete_transient('wnq_fb_notice_' . get_current_user_id());
        ?>
        <div class="wrap" style="max-width:1100px">
            <h1>Facebook Groups</h1>
            <p>One group list. Seven daily batches. Up to 50 groups per day.</p>
            <?php if ($notice): ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <div class="notice notice-warning inline"><p><strong>Facebook browser publishing.</strong>
                Saving alone does not publish. Only include groups that permit your message.
                Group approval and Facebook restrictions still apply; 50 per day is a planning limit, not a guaranteed safe posting rate.</p></div>
            <div style="background:white;padding:20px;margin:16px 0">
                <h2>Connect & publish</h2>
                <p>Install the separate <strong>facebook-companion</strong> folder with Chrome → Extensions → Developer mode → Load unpacked, then refresh this page.</p>
                <p>Keep Chrome, this WordPress tab, and your computer awake. Facebook login stays in Chrome; WordPress never receives your password or cookies.</p>
                <button type="button" class="button" id="fb-connect">Check connection</button>
                <button type="button" class="button" id="fb-login">Open Facebook / sign in</button>
                <button type="button" class="button" id="fb-test">Publish to first saved group</button>
                <button type="button" class="button button-primary" id="fb-start">Start daily schedule</button>
                <button type="button" class="button" id="fb-now">Publish today’s batch now</button>
                <button type="button" class="button" id="fb-stop">Pause</button>
                <p id="fb-status" role="status" aria-live="polite">Checking companion…</p>
                <p id="fb-progress" role="status">Progress will appear after saving a plan.</p>
                <details><summary>Submissions needing review</summary><p>Check the group first. Marking “not posted” skips it for this week and does not retry or remove the daily guard.</p><div id="fb-review"></div></details>
                <p>Uses one reusable Facebook tab. Test publishes the saved message to the first group, even if today is not Monday.
                    Daily safety: maximum one submission per numeric group ID per rolling 24 hours, in addition to the weekly limit. Named group links must be replaced with numeric group-ID links before publishing.
                    A group is reserved before publishing to prevent automatic duplicate retries. Login prompts or uncertain submissions pause the run.
                    Submitted does not necessarily mean publicly visible; group moderators may need to approve it.</p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wnq_facebook_plan">
                <?php wp_nonce_field('wnq_facebook_plan'); ?>
                <h2><label for="fb-groups">Group links</label></h2>
                <p>Paste one direct group link per line. First 50 → Monday, next 50 → Tuesday, through Sunday.
                    Exact duplicate links are removed. A group's numeric ID and named link may still refer to the same group; use one format per group.</p>
                <textarea id="fb-groups" name="groups" rows="12" class="large-text" maxlength="150000" placeholder="https://www.facebook.com/groups/example/"><?php echo esc_textarea(implode("\n", $plan['groups'])); ?></textarea>
                <h2><label for="fb-message">Message</label></h2>
                <textarea id="fb-message" name="message" rows="7" class="large-text" maxlength="20000"><?php echo esc_textarea($plan['message']); ?></textarea>
                <p><label for="fb-time">Daily start time</label>
                    <input id="fb-time" name="start_time" type="time" required value="<?php echo esc_attr($plan['start_time']); ?>">
                    <label for="fb-cutoff">Daily cutoff</label><input id="fb-cutoff" name="cutoff" type="time" required value="<?php echo esc_attr($plan['cutoff'] ?? '18:00'); ?>">
                    <label for="fb-timezone">Timezone</label>
                    <select id="fb-timezone" name="timezone">
                    <?php foreach (\DateTimeZone::listIdentifiers() as $zone): ?>
                        <option value="<?php echo esc_attr($zone); ?>" <?php selected($plan['timezone'], $zone); ?>><?php echo esc_html($zone); ?></option>
                    <?php endforeach; ?></select></p>
                <p><label><input type="checkbox" name="repeat" value="1" <?php checked($plan['repeat']); ?>> Repeat the same group batches each week</label></p>
                <?php submit_button('Save draft & preview week'); ?>
            </form>
            <h2>Weekly preview · <?php echo count($plan['groups']); ?> groups</h2>
            <?php foreach (FacebookGroupPlan::batches($plan['groups']) as $day => $groups): ?>
                <details style="background:white;padding:12px;margin-bottom:8px;border:1px solid #ddd">
                    <summary><?php echo esc_html($day); ?> · <?php echo count($groups); ?> groups</summary>
                    <ul><?php foreach ($groups as $url): ?><li><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($url); ?>"><?php echo esc_html($url); ?></a></li><?php endforeach; ?></ul>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
