<?php
namespace WNQ\Services;

use WNQ\Models\Lead;

if (!defined('ABSPATH')) { exit; }

/** Browser-sourced Maps listings; website requests remain server-side and URL-safe. */
final class LeadBrowserIntake
{
    public static function addressParts(string $address): array
    {
        $address = trim(preg_replace('/\s+/u', ' ', $address));
        // US Maps addresses may be city-only, or omit ZIP. Never use the search ZIP.
        $address = preg_replace('/,\s*(?:USA|United States(?: of America)?)\s*$/i', '', $address);
        if (preg_match('/(?:^|,\s*)([^,\d]+),\s*([A-Z]{2})(?:\s+(\d{5})(?:-\d{4})?)?\s*$/', $address, $m)) {
            return ['city'=>trim($m[1]), 'state'=>$m[2], 'zip'=>$m[3] ?? ''];
        }
        return ['city'=>'','state'=>'','zip'=>''];
    }
    public static function identity(string $url): string
    {
        $parts = wp_parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.google.com'
            || !str_starts_with($parts['path'] ?? '', '/maps/place/')) {
            throw new \RuntimeException('A valid Google Maps listing URL is required.');
        }
        preg_match('/!1s([^!\/]+)/', $parts['path'], $m);
        return 'maps_' . hash('sha256', rawurldecode($m[1] ?? $parts['path']));
    }

    /** Require a matching named business and a single unambiguous locality. */
    public static function websiteCity(string $html, string $name): string
    {
        preg_match_all('~<script\b[^>]*type=["\x27]application/ld\+json["\x27][^>]*>(.*?)</script>~is', $html, $blocks);
        $cities = [];
        $normalize = static fn($v) => strtolower(trim(preg_replace('/\s+/', ' ', $v)));
        $walk = function ($node) use (&$walk, &$cities, $name, $normalize) {
            if (!is_array($node)) return;
            if (is_string($node['name'] ?? null) && $normalize($node['name']) === $normalize($name)
                && is_array($node['address'] ?? null) && is_string($node['address']['addressLocality'] ?? null)) {
                $city = sanitize_text_field($node['address']['addressLocality']);
                if ($city !== '' && strlen($city) <= 100) $cities[$normalize($city)] = $city;
            }
            foreach ($node as $value) { if (is_array($value)) $walk($value); }
        };
        foreach ($blocks[1] as $json) { $walk(json_decode($json, true, 32)); }
        return count($cities) === 1 ? reset($cities) : '';
    }

    public static function accept(array $row, string $keyword, string $zip): array
    {
        $keyword = sanitize_text_field($keyword);
        if ($keyword === '' || strlen($keyword) > 100 || !preg_match('/^\d{5}$/D', $zip)) {
            throw new \RuntimeException('Enter a niche keyword and a five-digit ZIP code.');
        }
        $name = sanitize_text_field($row['name'] ?? '');
        if ($name === '') { throw new \RuntimeException('Listing has no business name.'); }
        $maps = esc_url_raw($row['maps_url'] ?? '');
        $key = self::identity($maps);
        if ($existing = Lead::findByPlaceId($key)) { return self::result($existing, 'duplicate'); }
        $website = esc_url_raw($row['website'] ?? '', ['http', 'https']);
        $html = ''; $websiteFailed = false;
        if ($website !== '') {
            $response = wp_safe_remote_get($website, ['timeout' => 6, 'redirection' => 2, 'sslverify' => true,
                'limit_response_size' => 350000, 'user-agent' => 'GoldenWebMarketing-LeadFinder/1.0']);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $html = wp_remote_retrieve_body($response);
            } else { $websiteFailed = true; }
        }
        $email = ['email' => '', 'source' => ''];
        if ($website !== '') { $email = LeadEmailExtractor::extractEmail($website, $html, true); }
        $owner = self::founder($html);
        $seo = LeadSEOScorer::scoreWebsiteFromHtml($html);
        $address = sanitize_text_field($row['address'] ?? '');
        $parts = self::addressParts($address);
        if ($parts['city'] === '') { $parts['city'] = self::websiteCity($html, $name); }
        $city = $parts['city']; $state = $parts['state']; $actualZip = $parts['zip'];
        $notes = 'Maps: ' . $maps . "\nSearch: " . $keyword . ' in ' . $zip . "\n";
        $notes .= $email['email'] ? 'Email found in website HTML; not mailbox-verified.' : ($websiteFailed ? 'Website could not be read; no email found.' : ($website ? 'No public email found on checked pages.' : 'No website on listing; retained for cold calling.'));
        if ($owner['first']) { $notes .= "\nFounder name from website structured data: " . $website; }
        if (!empty($row['detail_warning'])) { $notes .= "\nListing details incomplete; review Google Maps."; }
        if (!empty($row['closed'])) { $notes .= "\nListing marked closed; excluded from outreach."; }
        $data = [
            'place_id' => $key, 'business_name' => $name, 'industry' => sanitize_text_field($row['industry'] ?? '') ?: $keyword,
            'website' => $website, 'address' => $address, 'city' => trim($city), 'state' => $state, 'zip' => $actualZip,
            'phone' => sanitize_text_field($row['phone'] ?? ''), 'email' => $email['email'], 'email_source' => $email['source'],
            'owner_first' => $owner['first'], 'owner_last' => $owner['last'],
            'seo_score' => $seo['score'], 'seo_issues' => $seo['issues'], 'seo_checked' => $seo['ok'],
            'rating' => max(0, min(5, (float)($row['rating'] ?? 0))), 'review_count' => max(0, (int)($row['reviews'] ?? 0)),
            'status' => !empty($row['closed']) ? 'closed' : 'new', 'notes' => $notes,
        ];
        // Older importers may have a different Maps/source ID. Do not merge on name alone.
        if ($data['city'] !== '' && Lead::existsByNameAndCity($name, $data['city'])) {
            return ['outcome' => 'duplicate', 'name' => $name, 'email' => '', 'phone' => $data['phone'], 'message' => 'Business already exists in the lead list.'];
        }
        $id = Lead::insert($data);
        if (!$id) {
            if ($existing = Lead::findByPlaceId($key)) { return self::result($existing, 'duplicate'); }
            throw new \RuntimeException('Could not save this listing. Retry; already-saved listings will be skipped.');
        }
        return self::result(array_merge($data, ['id' => $id]), 'saved');
    }

    private static function result(array $lead, string $outcome): array
    {
        return ['id' => (int)$lead['id'], 'outcome' => $outcome, 'name' => $lead['business_name'],
            'email' => $lead['email'], 'phone' => $lead['phone'], 'email_source' => $lead['email_source'] ?? '',
            'message' => $outcome === 'duplicate' ? 'Already in lead list.' : ($lead['email'] ? 'Website email found; review before outreach.' : 'Saved for calling; no email found.')];
    }

    /** Only explicit founder data, not reviewer/author names or guesses from email prefixes. */
    public static function founder(string $html): array
    {
        preg_match_all('#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $scripts);
        $walk = static function ($node) use (&$walk): string {
            if (!is_array($node)) { return ''; }
            $founder = $node['founder'] ?? null;
            if (is_array($founder) && ($founder['@type'] ?? '') === 'Person' && is_string($founder['name'] ?? null)) { return trim($founder['name']); }
            foreach ($node as $child) { if (is_array($child) && ($name = $walk($child))) { return $name; } }
            return '';
        };
        foreach ($scripts[1] ?? [] as $json) {
            $name = $walk(json_decode($json, true));
            if ($name && strlen($name) < 160) {
                $parts = preg_split('/\s+/', sanitize_text_field($name), 2);
                return ['first' => $parts[0], 'last' => $parts[1] ?? ''];
            }
        }
        return ['first' => '', 'last' => ''];
    }
}
