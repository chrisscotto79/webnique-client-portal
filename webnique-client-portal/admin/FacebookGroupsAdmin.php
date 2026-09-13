<?php
namespace WNQ\Admin;

use WNQ\Services\FacebookGroupPlan;

if (!defined('ABSPATH')) exit;

/** Draft configuration only: publishing must be explicitly enabled in a later runner. */
final class FacebookGroupsAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 25);
        add_action('admin_post_wnq_facebook_plan', [self::class, 'save']);
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
            if (strlen($message) > 20000) throw new \InvalidArgumentException('Keep the message under 20,000 bytes.');
            // Saving a draft cannot activate posting or change any Facebook account.
            update_option('wnq_facebook_group_plan', [
                'groups' => $parsed['groups'], 'message' => $message,
                'start_time' => $time, 'timezone' => $timezone,
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
            <div class="notice notice-warning inline"><p><strong>Draft setup — posting is not connected yet.</strong>
                Saving this plan does not post or schedule live Facebook actions. Only include groups that permit your message.
                Group approval and Facebook restrictions still apply; 50 per day is a planning limit, not a guaranteed safe posting rate.</p></div>
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
