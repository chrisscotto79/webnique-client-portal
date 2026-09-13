<?php
namespace WNQ\Services;

/** Rolling 24-hour exclusion, independent of message, schedule and timezone. */
final class FacebookDailyGuard
{
    public static function groupId(string $url): string
    {
        if (!preg_match('~^https://www\.facebook\.com/groups/([1-9][0-9]*)/$~D', $url, $match)) return '';
        return $match[1];
    }

    public static function reserve(string $id, string $token): bool
    {
        global $wpdb;
        $key = 'wnq_fb_daily_' . $id;
        // Dispatch expires in 120 seconds; padding keeps 24 hours after its latest click.
        $next = ['token' => $token, 'until' => time() + 86400 + 180];
        if (add_option($key, $next, '', false)) return true;
        $old = get_option($key, []);
        if (empty($old['until']) || $old['until'] > time()) return false;
        // Compare-and-swap: two workers cannot replace the same expired guard.
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            maybe_serialize($next), $key, maybe_serialize($old)
        ));
        wp_cache_delete($key, 'options');
        return $changed === 1;
    }

    public static function release(string $id, string $token): void
    {
        global $wpdb;
        $key = 'wnq_fb_daily_' . $id;
        $old = get_option($key, []);
        if (!$old || !hash_equals($old['token'], $token)) return;
        // Only a proven pre-click failure may release its own reservation.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize($old)));
        wp_cache_delete($key, 'options');
    }
}
