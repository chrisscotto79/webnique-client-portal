<?php
namespace WNQ\Services;

/** Campaign namespaces reuse the existing client model. Agency keys stay unchanged. */
final class FacebookCampaign
{
    public static function context($value = 'agency'): array
    {
        if ($value === 'agency') return ['id' => 'agency', 'name' => 'Golden Web Marketing'];
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) throw new \InvalidArgumentException('Choose an existing client.');
        $client = \WNQ\Models\Client::getById((int)$value);
        if (!$client || ($client['status'] ?? '') === 'deleted') throw new \InvalidArgumentException('This client no longer exists. No campaign was changed.');
        return ['id' => (string)$client['id'], 'name' => $client['company'] ?: $client['name']];
    }

    public static function key(string $base, string $client): string
    {
        return $client === 'agency' ? $base : $base . '_client_' . $client;
    }

    public static function job(string $client, string $week, string $url): string
    {
        return 'wnq_fb_job_' . ($client === 'agency' ? '' : 'c' . $client . '_') . hash('sha256', $week . '|' . strtolower($url));
    }

    public static function ownsJob(string $client, string $key): bool
    {
        return (bool)preg_match('/^wnq_fb_job_' . ($client === 'agency' ? '' : 'c' . preg_quote($client, '/') . '_') . '[a-f0-9]{64}$/D', $key);
    }

    public static function assertEditable(string $client): void
    {
        $owner = get_option('wnq_fb_campaign_owner', []);
        if (get_option(self::key('wnq_fb_schedule_enabled', $client), false) ||
            (($owner['id'] ?? '') === $client && (get_option('wnq_fb_daily_dispatch', [])['until'] ?? 0) > time())) {
            throw new \InvalidArgumentException('Stop this campaign and wait for its current posting interval before changing its content.');
        }
    }

    public static function image(int $id): ?array
    {
        if (!$id) return null;
        $path = get_attached_file($id);
        if (!$path || !is_file($path) || filesize($path) > 4 * 1024 * 1024) throw new \InvalidArgumentException('Choose an existing image no larger than 4 MB.');
        $info = @getimagesize($path);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) throw new \InvalidArgumentException('Use a JPEG, PNG or WebP image.');
        return ['name' => basename($path), 'mime' => $info['mime'], 'data' => base64_encode(file_get_contents($path))];
    }

    public static function images(array $ids): array
    {
        if (count($ids) > 4) throw new \InvalidArgumentException('Choose up to four images per campaign.');
        $images = []; $bytes = 0;
        foreach ($ids as $id) {
            $image = self::image((int)$id);
            if (!$image) continue;
            $bytes += strlen($image['data']);
            if ($bytes > 5600000) throw new \InvalidArgumentException('Keep all campaign images under 4 MB combined.');
            $images[] = $image;
        }
        return $images;
    }
}
