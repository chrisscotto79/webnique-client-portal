<?php
namespace WNQ\Admin;

use WNQ\Services\FacebookGroupPlan;
use WNQ\Services\FacebookDailyGuard;
use WNQ\Services\FacebookCampaign;

require_once __DIR__ . '/../includes/Services/FacebookCampaign.php';

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
        wp_enqueue_media();
        $client = self::pageClient($_GET['client'] ?? 'agency');
        wp_enqueue_style('wnq-task-dashboard', WNQ_PORTAL_URL . 'assets/css/task-dashboard.css', [], WNQ_PORTAL_VERSION);
        wp_enqueue_script('wnq-facebook-publish', WNQ_PORTAL_URL . 'assets/js/' . (isset($_GET['client']) ? 'facebook-publish.js' : 'facebook-dashboard.js'), ['media-views'], WNQ_PORTAL_VERSION, true);
        wp_localize_script('wnq-facebook-publish', 'WNQFacebook', [
            'client' => $client['id'], 'clientName' => $client['name'],
            'ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wnq_facebook_publish'),
        ]);
    }

    public static function publish(): void
    {
        if (!self::allowed()) wp_send_json_error(['message' => 'Not authorized.'], 403);
        check_ajax_referer('wnq_facebook_publish', 'nonce');
        $token = wp_generate_uuid4();
        if (!FacebookDailyGuard::reserve('campaign_mutex', $token, 60)) wp_send_json_error(['message' => 'Another campaign request is saving. Try again shortly.'], 409);
        // wp_send_json exits in WordPress; shutdown releases the mutex in that path.
        register_shutdown_function(static function () use ($token) { FacebookDailyGuard::release('campaign_mutex', $token); });
        try {
            self::publishRequest();
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 409);
        } finally {
            FacebookDailyGuard::release('campaign_mutex', $token);
        }
    }

    private static function publishRequest(): void
    {
        if (!self::allowed()) wp_send_json_error(['message' => 'Not authorized.'], 403);
        check_ajax_referer('wnq_facebook_publish', 'nonce');
        if (($_POST['op'] ?? '') === 'dashboard') wp_send_json_success(['campaigns' => self::campaignRows(), 'next_at' => get_option('wnq_fb_daily_dispatch', [])['until'] ?? 0]);
        if (($_POST['op'] ?? '') === 'resolve') wp_send_json_error(['message' => 'Manual submission review is no longer used. Unconfirmed attempts are skipped.']);
        $client = FacebookCampaign::context($_POST['client'] ?? $_GET['client'] ?? 'agency');
        $clientId = $client['id'];
        $plan = get_option(FacebookCampaign::key('wnq_facebook_group_plan', $clientId), []);
        $plan = array_merge(['groups' => [], 'message' => '', 'timezone' => 'America/New_York', 'start_time' => '09:00', 'repeat' => false], is_array($plan) ? $plan : []);
        $op = sanitize_key($_POST['op'] ?? '');
        if ($op === 'save_images') {
            // Dispatched jobs already contain immutable image bytes. Once stopped,
            // changing future images need not wait for the anti-duplicate cooldown.
            if (get_option(FacebookCampaign::key('wnq_fb_schedule_enabled', $clientId), false)) {
                throw new \InvalidArgumentException('Press Stop in Schedule controls, then choose your images again. This campaign is still running.');
            }
            $value = $_POST['image_ids'] ?? '';
            if (!is_string($value) || !preg_match('/^(?:[1-9][0-9]*(?:,[1-9][0-9]*)*)?$/D', $value)) throw new \InvalidArgumentException('Invalid image selection.');
            $ids = $value === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $value))));
            FacebookCampaign::images($ids); // Validate before changing anything.
            $plan['image_ids'] = $ids;
            update_option(FacebookCampaign::key('wnq_facebook_group_plan', $clientId), $plan, false);
            wp_send_json_success(['client' => $clientId, 'image_ids' => $ids]);
        }
        if ($op === 'stop') { update_option(FacebookCampaign::key('wnq_fb_schedule_enabled', $clientId), false, false); wp_send_json_success([]); }
        if (!in_array($op, ['progress', 'resolve', 'result'], true) && (empty($plan['groups']) || trim($plan['message'] ?? '') === '')) {
            wp_send_json_error(['message' => 'Save your group links and message first.']);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone($plan['timezone']));
        $week = $now->format('o-W');
        if (in_array($op, ['progress', 'resolve'], true) && isset($_POST['week']) && preg_match('/^[0-9]{4}-(?:0[1-9]|[1-4][0-9]|5[0-3])$/D', (string)$_POST['week'])) $week = $_POST['week'];
        if (in_array($op, ['start', 'resume'], true)) {
            if ($op === 'start') update_option(FacebookCampaign::key('wnq_fb_first_week', $clientId), $week, false);
            update_option(FacebookCampaign::key('wnq_fb_schedule_enabled', $clientId), true, false);
            wp_send_json_success([]);
        }
        $mode = sanitize_key($_POST['mode'] ?? 'today');
        $groups = $mode === 'test' ? array_slice($plan['groups'], 0, 1) : FacebookGroupPlan::batches($plan['groups'], (int)($plan['daily_limit'] ?? 50))[$now->format('l')];
        if ($op === 'progress') {
            $historyGroups = get_option(FacebookCampaign::key('wnq_fb_history_' . $week, $clientId), $plan['groups']);
            if ($week === $now->format('o-W')) $historyGroups = array_values(array_unique(array_merge($historyGroups, $plan['groups'])));
            $counts = ['total' => count($groups), 'submitted' => 0, 'pending' => 0, 'skipped' => 0];
            $rows = [];
            foreach ($historyGroups as $url) {
                $key = FacebookCampaign::job($clientId, $week, $url);
                $state = get_option($key, []);
                $status = $state['status'] ?? '';
                $held = $status === 'unknown' || ($status === 'reserved' && ($state['expires_at'] ?? PHP_INT_MAX) < time());
                $rows[] = ['url' => $url, 'status' => $held ? 'skipped' : ($status ?: 'waiting'), 'message' => $state['message'] ?? '', 'confirmation' => $state['confirmation'] ?? 'legacy', 'updated_at' => $state['updated_at'] ?? $state['created_at'] ?? null];
                if (in_array($url, $groups, true)) {
                    if (isset($counts[$status]) && $status !== 'total') $counts[$status]++;
                    elseif ($held) $counts['skipped']++;
                }
            }
            wp_send_json_success(['counts' => $counts, 'rows' => $rows, 'enabled' => (bool)get_option(FacebookCampaign::key('wnq_fb_schedule_enabled', $clientId), false), 'client' => $clientId, 'owner' => get_option('wnq_fb_campaign_owner', []), 'week' => $week, 'next_at' => get_option('wnq_fb_daily_dispatch', [])['until'] ?? 0]);
        }
        if ($op === 'next') {
            if ($mode !== 'test' && !get_option(FacebookCampaign::key('wnq_fb_schedule_enabled', $clientId), false)) wp_send_json_success(['stopped' => true, 'message' => 'Weekly schedule stopped. Select Resume to continue.']);
            $cutoff = $plan['cutoff'] ?? '18:00';
            if ($now->format('H:i') >= $cutoff) wp_send_json_success(['finished' => true, 'message' => 'Daily cutoff reached. No more posts will start today.']);
            $mode = sanitize_key($_POST['mode'] ?? 'today');
            if ($mode === 'scheduled' && $now->format('H:i') < $plan['start_time']) {
                wp_send_json_success(['waiting' => true]);
            }
            $first = get_option(FacebookCampaign::key('wnq_fb_first_week', $clientId), '');
            // A direct Publish click authorizes a manual attempt independently of
            // the recurring schedule. Per-group weekly/daily guards still apply.
            if ($mode === 'scheduled' && !$plan['repeat'] && $first && $first !== $week) {
                wp_send_json_success(['finished' => true, 'message' => 'One-time week finished. Enable repeat and save to run another week.']);
            }
            $groups = FacebookGroupPlan::batches($plan['groups'], (int)($plan['daily_limit'] ?? 50))[$now->format('l')];
            // A single saved group can be tested immediately, regardless of weekday.
            if ($mode === 'test') $groups = array_slice($plan['groups'], 0, 1);
            $historyKey = FacebookCampaign::key('wnq_fb_history_' . $week, $clientId);
            update_option($historyKey, array_values(array_unique(array_merge(get_option($historyKey, []), $plan['groups']))), false);
            $dailyKey = FacebookCampaign::key('wnq_fb_attempts_' . $now->format('Y-m-d'), $clientId);
            if ((int)get_option($dailyKey, 0) >= (int)($plan['daily_limit'] ?? 50)) wp_send_json_success(['finished' => true, 'message' => 'This client’s daily attempt limit has been reached. Resume on the next scheduled day.']);
            foreach ($groups as $url) {
                $groupId = FacebookDailyGuard::groupId($url);
                $key = FacebookCampaign::job($clientId, $week, $url);
                if (!$groupId) { add_option($key, ['status' => 'skipped'], '', false); continue; }
                $state = get_option($key, []);
                if (in_array($state['status'] ?? '', ['submitted', 'pending'], true)) continue;
                if ($state) continue;
                $messages = array_merge([$plan['message']], $plan['messages'] ?? []);
                $index = array_search($url, $plan['groups'], true);
                $message = $messages[$index % count($messages)];
                if (!empty($plan['link'])) $message .= "\n" . $plan['link'];
                $images = FacebookCampaign::images($plan['image_ids'] ?? [(int)($plan['image_id'] ?? 0)]);
                $token = wp_generate_uuid4();
                if (!FacebookDailyGuard::reserve($groupId, $token)) {
                    add_option($key, ['status' => 'skipped'], '', false); continue;
                }
                // One global dispatch lease covers all WordPress tabs and tests.
                // Up to 120 seconds to dispatch + one minute after a potential click.
                if (!FacebookDailyGuard::reserve('dispatch', $token, 180)) {
                    FacebookDailyGuard::release($groupId, $token);
                    $until = get_option('wnq_fb_daily_dispatch', [])['until'] ?? time() + 60;
                    wp_send_json_success(['waiting' => true, 'next_at' => $until, 'message' => 'Waiting for the one-minute posting interval.']);
                }
                update_option('wnq_fb_campaign_owner', $client, false);
                // Atomic unique option reserves this group before any browser-side click.
                $expires = min(time() + 120, $now->setTime((int)substr($cutoff, 0, 2), (int)substr($cutoff, 3, 2))->getTimestamp());
                if (!add_option($key, ['status' => 'reserved', 'token' => $token, 'group_id' => $groupId, 'expires_at' => $expires, 'client_id' => $clientId, 'client_name' => $client['name'], 'url' => $url, 'week' => $week, 'created_at' => time(), 'post_text' => $message, 'image_ids' => $plan['image_ids'] ?? []], '', false)) {
                    FacebookDailyGuard::release($groupId, $token);
                    FacebookDailyGuard::release('dispatch', $token);
                    continue;
                }
                update_option($dailyKey, (int)get_option($dailyKey, 0) + 1, false);
                if ($mode === 'scheduled') add_option(FacebookCampaign::key('wnq_fb_first_week', $clientId), $week, '', false);
                wp_send_json_success(['job' => ['key' => $key, 'token' => $token,
                    'expires_at' => $expires,
                    'client_id' => $clientId, 'client_name' => $client['name'],
                    'image_count' => count($images), 'images' => $images, 'url' => $url, 'message' => $message]]);
            }
            wp_send_json_success(['finished' => true, 'message' => $mode === 'test' ? 'Test not sent: this group was already handled or blocked by duplicate protection. See its weekly status.' : 'Today’s batch has no remaining eligible groups. See weekly statuses for posted or skipped groups.']);
        }
        if ($op === 'result') {
            $key = sanitize_key($_POST['key'] ?? '');
            $token = sanitize_text_field($_POST['token'] ?? '');
            if (!FacebookCampaign::ownsJob($clientId, $key)) wp_send_json_error(['message' => 'Invalid job.']);
            $state = get_option($key, []);
            if (!$state || !is_string($state['token'] ?? null) || !hash_equals($state['token'], $token)) wp_send_json_error(['message' => 'Job ownership mismatch.']);
            $status = sanitize_key($_POST['status'] ?? 'unknown');
            if (!in_array($status, ['submitted', 'pending', 'unknown', 'not_started'], true)) $status = 'unknown';
            if (in_array($state['status'], ['submitted', 'pending'], true)) wp_send_json_success([]);
            FacebookDailyGuard::renew('dispatch', $token, 60);
            // Every attempted group is terminal, including unconfirmed outcomes.
            // Keep its exclusion guard; never retry a potentially submitted post.
            update_option($key, array_merge($state, ['updated_at' => time(),
                'status' => in_array($status, ['submitted', 'pending'], true) ? $status : 'skipped',
                'confirmation' => in_array($status, ['submitted', 'pending'], true) ? 'browser' : 'none',
                'message' => substr(sanitize_text_field(wp_unslash($_POST['message'] ?? '')), 0, 500)]), false);
            wp_send_json_success([]);
        }
        wp_send_json_error(['message' => 'Unknown action.']);
    }

    private static function campaignRows(): array
    {
        $clients = [['id' => 'agency', 'name' => 'Golden Web Marketing']];
        foreach (\WNQ\Models\Client::getAll() as $record) {
            if (($record['status'] ?? '') === 'deleted') continue;
            $clients[] = ['id' => (string)$record['id'], 'name' => $record['company'] ?: $record['name']];
        }
        $rows = [];
        foreach ($clients as $client) {
            $id = $client['id'];
            $plan = get_option(FacebookCampaign::key('wnq_facebook_group_plan', $id), []);
            if (!is_array($plan)) $plan = [];
            $zone = $plan['timezone'] ?? 'America/New_York';
            try { $now = new \DateTimeImmutable('now', new \DateTimeZone($zone)); }
            catch (\Exception $e) { $zone = 'America/New_York'; $now = new \DateTimeImmutable('now', new \DateTimeZone($zone)); }
            $groups = $plan['groups'] ?? [];
            $today = FacebookGroupPlan::batches($groups, (int)($plan['daily_limit'] ?? 50))[$now->format('l')];
            $counts = ['submitted' => 0, 'pending' => 0, 'skipped' => 0, 'reserved' => 0];
            foreach ($today as $url) {
                $state = get_option(FacebookCampaign::job($id, $now->format('o-W'), $url), []);
                $status = $state['status'] ?? '';
                if ($status === 'unknown' || ($status === 'reserved' && ($state['expires_at'] ?? 0) < time())) $status = 'skipped';
                if (isset($counts[$status])) $counts[$status]++;
            }
            $rows[] = $client + ['ready' => !empty($groups) && trim($plan['message'] ?? '') !== '',
                'enabled' => (bool)get_option(FacebookCampaign::key('wnq_fb_schedule_enabled', $id), false),
                'groups' => count($groups), 'today' => count($today), 'counts' => $counts,
                'schedule' => ($plan['start_time'] ?? '09:00') . '–' . ($plan['cutoff'] ?? '18:00'),
                'timezone' => $zone, 'edit' => admin_url('admin.php?page=wnq-facebook-groups&client=' . rawurlencode($id))];
        }
        return $rows;
    }

    private static function renderDashboard(): void
    {
        ?>
        <div class="wrap wnq-task-dashboard" id="fb-dashboard">
            <header class="task-header"><div><span class="task-eyebrow">CAMPAIGN OPERATIONS</span><h1>Facebook Groups <span id="fb-task-count"></span></h1></div><span class="task-pill">60-second interval</span></header>
            <div class="task-toolbar"><label class="screen-reader-text" for="fb-task-search">Search companies</label><input id="fb-task-search" type="search" placeholder="Search companies…"><button class="button" id="fb-dash-connect">Check connection</button><button class="button" id="fb-dash-login">Open Facebook</button></div>
            <p class="task-muted">Run multiple company schedules from one dashboard. Campaigns take turns in one browser tab, one minute after each result. Keep this page and Chrome open.</p>
            <div class="task-table-scroll"><table class="task-table"><thead><tr><th>Company / campaign</th><th>Groups</th><th>Schedule</th><th>Today’s results</th><th>Status</th><th>Actions</th></tr></thead><tbody id="fb-task-rows"><tr><td colspan="6">Loading campaigns…</td></tr></tbody></table></div>
            <footer class="task-actions"><button class="button task-start" id="fb-dash-start">▶ Start all ready</button><button class="button" id="fb-dash-resume">▶ Resume enabled</button><button class="button task-stop" id="fb-dash-stop">■ Pause all</button><span id="fb-dash-connection" role="status">Checking companion…</span></footer>
            <p id="fb-dash-status" role="status" aria-live="polite">No browser run started on this page.</p>
            <p id="fb-dash-error" role="alert" hidden></p>
            <p class="task-muted">Unconfirmed attempts are skipped without a review queue or automatic retry. Facebook may still moderate a submitted post. Edit a company to configure its message, images and groups.</p>
        </div>
        <?php
    }

    private static function allowed(): bool
    {
        return current_user_can('manage_options') || current_user_can('wnq_manage_portal');
    }

    private static function pageClient($value): array
    {
        try { return FacebookCampaign::context($value); }
        catch (\InvalidArgumentException $e) { wp_die(esc_html($e->getMessage())); }
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
        $client = self::pageClient($_POST['client'] ?? 'agency');
        $clientId = $client['id'];
        $lock = wp_generate_uuid4();
        if (!FacebookDailyGuard::reserve('campaign_mutex', $lock, 60)) wp_die('Another campaign request is saving. Please try again.');
        register_shutdown_function(static function () use ($lock) { FacebookDailyGuard::release('campaign_mutex', $lock); });
        try {
            FacebookCampaign::assertEditable($clientId);
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
            $limit = filter_var($_POST['daily_limit'] ?? 50, FILTER_VALIDATE_INT);
            if (!$limit || $limit < 1 || $limit > 50) throw new \InvalidArgumentException('Daily limit must be between 1 and 50.');
            if (count($parsed['groups']) > $limit * 7) throw new \InvalidArgumentException('This daily limit fits ' . ($limit * 7) . ' groups per week. Reduce the list or increase the limit.');
            $variants = sanitize_textarea_field(wp_unslash((string)($_POST['messages'] ?? '')));
            $messages = array_values(array_filter(array_map('trim', preg_split('/\\R---\\R/u', $variants))));
            if (strlen($variants) > 100000 || count($messages) > 20) throw new \InvalidArgumentException('Use up to 20 additional messages, under 100,000 bytes total.');
            foreach ($messages as $variant) if (strlen($variant) > 19000) throw new \InvalidArgumentException('Each message must be under 19,000 bytes.');
            $link = trim(wp_unslash((string)($_POST['link'] ?? '')));
            if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !in_array(parse_url($link, PHP_URL_SCHEME), ['https', 'http'], true) || strlen($link) > 1000)) throw new \InvalidArgumentException('Enter a valid HTTP or HTTPS link.');
            if (strlen($message) + strlen($link) + 1 > 20000) throw new \InvalidArgumentException('Message and link together must be under 20,000 bytes.');
            foreach ($messages as $variant) if (strlen($variant) + strlen($link) + 1 > 20000) throw new \InvalidArgumentException('Each message plus link must be under 20,000 bytes.');
            $imageIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['image_ids'] ?? $_POST['image_id'] ?? '0'))))));
            if (array_filter($imageIds, fn($id) => $id < 1)) throw new \InvalidArgumentException('Invalid image selection.');
            FacebookCampaign::images($imageIds);
            // Preserve a snapshot of the old group's current-week history before editing links.
            $oldPlan = get_option(FacebookCampaign::key('wnq_facebook_group_plan', $clientId), []);
            if (!empty($oldPlan['groups'])) {
                $oldWeek = (new \DateTimeImmutable('now', new \DateTimeZone($oldPlan['timezone'])))->format('o-W');
                $historyKey = FacebookCampaign::key('wnq_fb_history_' . $oldWeek, $clientId);
                update_option($historyKey, array_values(array_unique(array_merge(get_option($historyKey, []), $oldPlan['groups']))), false);
            }
            // Saving a draft cannot activate posting or change any Facebook account.
            update_option(FacebookCampaign::key('wnq_facebook_group_plan', $clientId), array_merge($oldPlan, [
                'messages' => $messages, 'link' => $link, 'image_ids' => $imageIds, 'daily_limit' => $limit,
                'groups' => $parsed['groups'], 'message' => $message,
                'start_time' => $time, 'timezone' => $timezone,
                'cutoff' => $cutoff,
                'repeat' => isset($_POST['repeat']), 'enabled' => false,
            ]), false);
            update_option(FacebookCampaign::key('wnq_fb_schedule_enabled', $clientId), false, false);
            $notice = 'Draft saved. ' . count($parsed['groups']) . ' groups; ' . $parsed['duplicates'] . ' duplicate links removed. No posts sent.';
        } catch (\InvalidArgumentException $e) {
            $notice = $e->getMessage() . ' Previous draft was not changed.';
        }
        FacebookDailyGuard::release('campaign_mutex', $lock);
        set_transient('wnq_fb_notice_' . $clientId . '_' . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=wnq-facebook-groups&client=' . rawurlencode($clientId)));
        exit;
    }

    public static function render(): void
    {
        if (!self::allowed()) wp_die('Not authorized.');
        if (!isset($_GET['client'])) { self::renderDashboard(); return; }
        $client = self::pageClient($_GET['client'] ?? 'agency');
        $clientId = $client['id'];
        $plan = get_option(FacebookCampaign::key('wnq_facebook_group_plan', $clientId), []);
        $plan = array_merge(['groups' => [], 'message' => '', 'start_time' => '09:00',
            'timezone' => 'America/New_York', 'repeat' => false], is_array($plan) ? $plan : []);
        $notice = get_transient('wnq_fb_notice_' . get_current_user_id());
        delete_transient('wnq_fb_notice_' . get_current_user_id());
        ?>
        <div class="wrap" style="max-width:1100px">
            <h1>Facebook Groups</h1><p><a href="<?php echo esc_url(admin_url('admin.php?page=wnq-facebook-groups')); ?>">← All campaigns dashboard</a></p>
            <form method="get" id="fb-client-form">
                <input type="hidden" name="page" value="wnq-facebook-groups">
                <label for="fb-client"><strong>Company</strong></label>
                <select name="client" id="fb-client">
                    <option value="agency" <?php selected($clientId, 'agency'); ?>>Golden Web Marketing</option>
                    <?php foreach (\WNQ\Models\Client::getAll() as $record): ?>
                        <option value="<?php echo esc_attr($record['id']); ?>" <?php selected($clientId, (string)$record['id']); ?>><?php echo esc_html($record['company'] ?: $record['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" id="fb-switch">Load client</button>
            </form>
            <p><strong>Campaign: <?php echo esc_html($client['name']); ?></strong>. Client selection does not switch your Facebook identity. Verify the signed-in Facebook profile/Page before starting.</p>
            <p>One group list. Seven daily batches. Up to 50 groups per day.</p>
            <?php if ($notice): ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <div class="notice notice-warning inline"><p><strong>Facebook browser publishing.</strong>
                Saving alone does not publish. Only include groups that permit your message.
                Group approval and Facebook restrictions still apply; 50 per day is a planning limit, not a guaranteed safe posting rate.</p></div>
            <div style="background:white;padding:20px;margin:16px 0">
                <h2>Schedule controls</h2>
                <p>One background tab · One post every 1 minute after the previous result · Keep Chrome and this page open.</p>
                <button type="button" class="button button-primary" id="fb-start">Start</button>
                <button type="button" class="button" id="fb-test">Test</button>
                <button type="button" class="button" id="fb-stop">Stop</button>
                <button type="button" class="button" id="fb-resume">Resume</button>
                <button type="button" class="button" id="fb-connect">Check connection</button>
                <p id="fb-connection" role="status">Checking companion…</p>
                <p id="fb-status" role="status" aria-live="polite">Stopped. Save your plan, then Test or Start.</p>
                <p id="fb-progress" role="status">Progress will appear after saving a plan.</p>
                <div id="fb-errors" role="alert" hidden style="border-left:4px solid #d63638;padding:12px;background:#fff3f3"></div>
                <details><summary>Setup / Facebook sign-in</summary><p>Load the facebook-companion folder in Chrome Extensions using Load unpacked. Refresh this page after reloading the extension.</p><button type="button" class="button" id="fb-login">Open Facebook / sign in</button></details>
                <details><summary>Posting rules & safety</summary><p>Test publishes the saved message to the first group, even if today is not Monday.
                    Daily safety: maximum one submission per numeric group ID per rolling 24 hours, in addition to the weekly limit. Named group links must be replaced with numeric group-ID links before publishing.
                    A group is reserved before publishing to prevent automatic duplicate retries. Login prompts stop the run; unconfirmed submissions are skipped while other groups continue.
                    Submitted does not necessarily mean publicly visible; group moderators may need to approve it. Stop prevents new jobs and requests cancellation before Post; a post already clicked cannot be recalled.</p></details>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wnq_facebook_plan">
                <input type="hidden" name="client" value="<?php echo esc_attr($clientId); ?>">
                <?php wp_nonce_field('wnq_facebook_plan'); ?>
                <h2><label for="fb-groups">Group links</label></h2>
                <p>Paste one direct group link per line. Your daily limit determines the Monday batch size, then Tuesday, through Sunday.
                    Exact duplicate links are removed. A group's numeric ID and named link may still refer to the same group; use one format per group.</p>
                <textarea id="fb-groups" name="groups" rows="12" class="large-text" maxlength="150000" placeholder="https://www.facebook.com/groups/example/"><?php echo esc_textarea(implode("\n", $plan['groups'])); ?></textarea>
                <h2><label for="fb-message">Message</label></h2>
                <textarea id="fb-message" name="message" rows="7" class="large-text" maxlength="20000"><?php echo esc_textarea($plan['message']); ?></textarea>
                <p><label for="fb-messages">Additional saved messages (optional; separate with a line containing ---). Messages rotate in group-list order.</label>
                <textarea id="fb-messages" name="messages" rows="5" class="large-text"><?php echo esc_textarea(implode("\n---\n", $plan['messages'] ?? [])); ?></textarea></p>
                <p><label>Link appended to every message <input type="url" name="link" class="large-text" value="<?php echo esc_attr($plan['link'] ?? ''); ?>"></label></p>
                <p><input type="hidden" id="fb-image-id" name="image_ids" value="<?php echo esc_attr(implode(',', $plan['image_ids'] ?? [(int)($plan['image_id'] ?? 0)])); ?>">
                <button type="button" class="button" id="fb-image">Choose images</button> <button type="button" class="button" id="fb-image-clear">Remove images</button>
                <span id="fb-image-label"><?php echo esc_html(!empty($plan['image_ids']) ? implode(', ', array_map('get_the_title', $plan['image_ids'])) : (!empty($plan['image_id']) ? get_the_title($plan['image_id']) : 'No images')); ?></span> · Up to four JPEG, PNG or WebP images, 4 MB combined.</p>
                <p id="fb-image-save-status" role="status">Press Stop before changing images. Your image selection saves immediately for this client; Save plan is not required for images. A post already in progress keeps its original images.</p>
                <div id="fb-image-previews" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
                <?php foreach ($plan['image_ids'] ?? [] as $imageId): ?>
                    <?php echo wp_get_attachment_image($imageId, 'thumbnail', false, ['style' => 'width:96px;height:96px;object-fit:cover']); ?>
                <?php endforeach; ?>
                </div>
                <p><label>Daily posting limit <input type="number" name="daily_limit" min="1" max="50" required value="<?php echo esc_attr($plan['daily_limit'] ?? 50); ?>"></label></p>
                <p><label for="fb-time">Daily start time</label>
                    <input id="fb-time" name="start_time" type="time" required value="<?php echo esc_attr($plan['start_time']); ?>">
                    <label for="fb-cutoff">Daily cutoff</label><input id="fb-cutoff" name="cutoff" type="time" required value="<?php echo esc_attr($plan['cutoff'] ?? '18:00'); ?>">
                    <label for="fb-timezone">Timezone</label>
                    <select id="fb-timezone" name="timezone">
                    <?php foreach (\DateTimeZone::listIdentifiers() as $zone): ?>
                        <option value="<?php echo esc_attr($zone); ?>" <?php selected($plan['timezone'], $zone); ?>><?php echo esc_html($zone); ?></option>
                    <?php endforeach; ?></select></p>
                <p><label><input type="checkbox" name="repeat" value="1" <?php checked($plan['repeat']); ?>> Repeat the same group batches each week</label></p>
                <?php submit_button('Save plan'); ?>
            </form>
            <p><label for="fb-history-week">History week</label> <input type="week" id="fb-history-week" value="<?php echo esc_attr((new \DateTimeImmutable('now', new \DateTimeZone($plan['timezone'])))->format('o-\\WW')); ?>"><button type="button" class="button" id="fb-history-load">View results</button></p>
            <p>History is retained by client and week. Viewing an older week does not change the running schedule.</p>
            <details><summary>Posting history and results for selected week</summary><div id="fb-history-results"></div></details>
            <h2>Weekly preview · <?php echo count($plan['groups']); ?> groups</h2>
            <?php foreach (FacebookGroupPlan::batches($plan['groups'], (int)($plan['daily_limit'] ?? 50)) as $day => $groups): ?>
                <details style="background:white;padding:12px;margin-bottom:8px;border:1px solid #ddd">
                    <summary><?php echo esc_html($day); ?> · <?php echo count($groups); ?> groups</summary>
                    <ul><?php foreach ($groups as $url): ?><li><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($url); ?>"><?php echo esc_html($url); ?></a> — <span data-fb-group="<?php echo esc_attr($url); ?>">Loading status…</span></li><?php endforeach; ?></ul>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
