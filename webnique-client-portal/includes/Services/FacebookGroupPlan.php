<?php
namespace WNQ\Services;

/** Pure schedule builder. Never connects to Facebook or posts. */
final class FacebookGroupPlan
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public static function parse(string $input): array
    {
        if (strlen($input) > 150000) {
            throw new \InvalidArgumentException('The group list is too large. Use up to 350 group links.');
        }
        $groups = [];
        $duplicates = 0;
        foreach (preg_split('/\R/u', $input) ?: [] as $line => $value) {
            $value = trim($value);
            if ($value === '') continue;
            if (preg_match('/^\[[^\]]*\]\((https:\/\/[^\s)]+)\)$/D', $value, $markdown)) $value = $markdown[1];
            $url = parse_url($value);
            $host = strtolower($url['host'] ?? '');
            if (!$url || ($url['scheme'] ?? '') !== 'https' ||
                !in_array($host, ['facebook.com', 'www.facebook.com', 'm.facebook.com'], true) ||
                isset($url['user']) || isset($url['pass']) || isset($url['port']) ||
                !preg_match('~^/groups/([a-zA-Z0-9._-]+)/?$~D', $url['path'] ?? '', $match) ||
                in_array(strtolower($match[1]), ['feed', 'discover', 'joins', 'create'], true)) {
                throw new \InvalidArgumentException('Line ' . ($line + 1) . ': use a direct HTTPS Facebook group link, not a post, invitation, or share link.');
            }
            $key = strtolower($match[1]);
            if (isset($groups[$key])) { $duplicates++; continue; }
            $groups[$key] = 'https://www.facebook.com/groups/' . $match[1] . '/';
            if (count($groups) > 350) {
                throw new \InvalidArgumentException('Maximum 350 different group links per weekly plan.');
            }
        }
        return ['groups' => array_values($groups), 'duplicates' => $duplicates];
    }

    public static function batches(array $groups): array
    {
        if (count($groups) > 350) throw new \InvalidArgumentException('Maximum 350 groups.');
        $days = [];
        foreach (self::DAYS as $index => $day) $days[$day] = array_slice($groups, $index * 50, 50);
        return $days;
    }
}
