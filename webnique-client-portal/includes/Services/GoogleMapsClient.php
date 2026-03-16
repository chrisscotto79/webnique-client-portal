<?php
/**
 * Google Maps Client — no API key required.
 *
 * Scrapes Google Maps search results and individual place pages using
 * plain HTTP requests (wp_remote_get). No Google Places API key needed.
 *
 * Usage:
 *   // 1. Search a ZIP for businesses matching a keyword
 *   $result = GoogleMapsClient::search('pressure washing 34211');
 *   // $result['results'] = [{name, place_url, review_count, rating, address}]
 *
 *   // 2. Get phone + website from a place page
 *   $info = GoogleMapsClient::getPlaceDetails('https://www.google.com/maps/place/...');
 *   // $info = [phone, website, review_count, rating, address]
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleMapsClient
{
    private const SEARCH_BASE = 'https://www.google.com/maps/search/';
    private const MAPS_HOST   = 'https://www.google.com';

    /** Realistic Chrome User-Agent */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Search Google Maps for businesses matching the query.
     * Builds URL: https://www.google.com/maps/search/{query}
     *
     * @param  string $query  e.g. "pressure washing 34211"
     * @return array{results: array, error?: string}
     */
    public static function search(string $query): array
    {
        $url  = self::SEARCH_BASE . rawurlencode(trim($query)) . '?hl=en';
        $html = self::fetch($url);

        if (!$html) {
            return ['results' => [], 'error' => 'Could not fetch Google Maps search page'];
        }

        $places = self::parseSearchResults($html);

        return ['results' => $places];
    }

    /**
     * Fetch phone, website, rating, and review count from a Google Maps place page.
     *
     * @param  string $place_url  Full URL from search results
     * @return array{phone: string, website: string, rating: float, review_count: int, address: string}
     */
    public static function getPlaceDetails(string $place_url): array
    {
        $html = self::fetch($place_url . (strpos($place_url, '?') === false ? '?' : '&') . 'hl=en');

        return [
            'phone'        => $html ? self::extractPhone($html)       : '',
            'website'      => $html ? self::extractWebsite($html)     : '',
            'rating'       => $html ? self::extractRating($html)      : 0.0,
            'review_count' => $html ? self::extractReviewCount($html) : 0,
            'address'      => $html ? self::extractAddress($html)     : '',
        ];
    }

    // ── Private: HTTP ────────────────────────────────────────────────────────

    private static function fetch(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout'             => 15,
            'user-agent'          => self::UA,
            'sslverify'           => false,
            'redirection'         => 5,
            'limit_response_size' => 524288, // 512 KB
            'headers'             => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control'   => 'no-cache',
                'Pragma'          => 'no-cache',
                // Pre-accept Google consent so we don't land on the consent page
                'Cookie'          => 'CONSENT=YES+cb; SOCS=CAISNQgDEitib3FfaWRlbnRpdHlmcm9udGVuZHVpX2xvZ2luX3BhZ2VfMjAyMzA4MTMQARoCZW4',
            ],
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return '';
        }

        return wp_remote_retrieve_body($response);
    }

    // ── Private: Parse search results ────────────────────────────────────────

    /**
     * Extract business listings from a Google Maps search results page.
     * Returns up to ~20 results (one page of Google Maps results).
     *
     * @return array  Each item: {name, place_url, review_count, rating, address}
     */
    private static function parseSearchResults(string $html): array
    {
        $results = [];
        $seen    = [];

        // Strategy 1: Find /maps/place/ links — present in server-rendered HTML for SEO
        // URL pattern: /maps/place/NAME/@lat,lng,zoom/data=... or /maps/place/NAME/data=...
        preg_match_all(
            '|(/maps/place/[^"\'<>\s]+/data=[^"\'<>\s]+)|',
            $html,
            $matches
        );

        $urls = array_unique($matches[1] ?? []);

        foreach ($urls as $path) {
            $full_url = self::MAPS_HOST . $path;
            if (isset($seen[$full_url])) continue;
            $seen[$full_url] = true;

            $name = self::nameFromPlaceUrl($path);
            if (!$name || strlen($name) < 2) continue;

            // Try to extract review count + rating from search page HTML near this result
            $review_count = self::reviewCountNearName($html, $name);
            $rating       = self::ratingNearName($html, $name);

            $results[] = [
                'name'         => $name,
                'place_url'    => $full_url,
                'review_count' => $review_count,
                'rating'       => $rating,
                'address'      => '',
            ];
        }

        // Strategy 2 (fallback): aria-label on result containers
        if (empty($results)) {
            preg_match_all('/aria-label="([^"]{3,80})"[^>]*>.*?href="(\/maps\/place\/[^"]+)"/',
                $html, $m2, PREG_SET_ORDER);
            foreach ($m2 as $m) {
                $name     = trim($m[1]);
                $full_url = self::MAPS_HOST . $m[2];
                if (isset($seen[$full_url]) || !$name) continue;
                $seen[$full_url] = true;
                $results[] = [
                    'name'         => $name,
                    'place_url'    => $full_url,
                    'review_count' => 0,
                    'rating'       => 0.0,
                    'address'      => '',
                ];
            }
        }

        return $results;
    }

    /**
     * Extract the business name from a /maps/place/... URL path.
     * The name is the segment between /place/ and /@, /data=, or end.
     */
    private static function nameFromPlaceUrl(string $path): string
    {
        // Strip query string / fragment
        $path = strtok($path, '?#');

        // /maps/place/NAME/@... or /maps/place/NAME/data=...
        if (preg_match('|/maps/place/([^/@]+)|', $path, $m)) {
            $raw = $m[1];
            // URL-decode: replace + with space then urldecode
            $name = urldecode(str_replace('+', ' ', $raw));
            // Strip trailing comma-separated address components that sometimes appear
            $name = preg_replace('/,.*$/', '', $name);
            return trim($name);
        }

        return '';
    }

    /**
     * Try to find a review count in the HTML near (within 300 chars of) the business name.
     */
    private static function reviewCountNearName(string $html, string $name): int
    {
        $pos = mb_stripos($html, $name);
        if ($pos === false) return 0;

        $snippet = mb_substr($html, $pos, 600);

        // Patterns: "23 reviews", "(45)", "4.5 (12)"
        if (preg_match('/\b(\d{1,5})\s+reviews?/i', $snippet, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/\((\d{1,5})\)/', $snippet, $m)) {
            return (int)$m[1];
        }

        return 0;
    }

    /**
     * Try to find a star rating in the HTML near the business name.
     */
    private static function ratingNearName(string $html, string $name): float
    {
        $pos = mb_stripos($html, $name);
        if ($pos === false) return 0.0;

        $snippet = mb_substr($html, $pos, 400);

        if (preg_match('/\b([1-5]\.\d)\b/', $snippet, $m)) {
            return (float)$m[1];
        }

        return 0.0;
    }

    // ── Private: Parse place detail page ─────────────────────────────────────

    /**
     * Extract US phone number from a Google Maps place page.
     * Google includes tel: links in the HTML for accessibility.
     */
    private static function extractPhone(string $html): string
    {
        // tel: links (most reliable — Google uses these for click-to-call)
        if (preg_match('/href="tel:([^"]+)"/i', $html, $m)) {
            $raw = trim($m[1]);
            // Normalise to (XXX) XXX-XXXX
            $digits = preg_replace('/\D/', '', $raw);
            if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
            if (strlen($digits) === 10) {
                return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
            }
            return $raw; // Return as-is if format is unusual
        }

        // Fallback: visible US phone pattern in text
        $text = strip_tags($html);
        if (preg_match('/\(?\b(\d{3})\)?[\s.\-](\d{3})[\s.\-](\d{4})\b/', $text, $m)) {
            return "({$m[1]}) {$m[2]}-{$m[3]}";
        }

        return '';
    }

    /**
     * Extract the business website URL from a Google Maps place page.
     * Google renders a "Visit website" link for business sites.
     */
    private static function extractWebsite(string $html): string
    {
        // Google Maps wraps the website URL via a redirect or directly as an href
        // Pattern 1: data-tooltip or aria-label containing "website"
        if (preg_match(
            '/href="(https?:\/\/[^"]{8,})"[^>]*(?:data-tooltip="(?:Open )?[Ww]ebsite"|aria-label="[^"]*[Ww]ebsite[^"]*")/i',
            $html, $m
        )) {
            return self::cleanUrl($m[1]);
        }

        // Pattern 2: Google redirect URL that contains the actual URL as a parameter
        // e.g. https://www.google.com/url?q=https://businesssite.com&...
        if (preg_match('/href="https:\/\/www\.google\.com\/url\?(?:[^"]*&)?q=(https?:\/\/[^&"]+)/i', $html, $m)) {
            $url = urldecode($m[1]);
            if (!self::isGoogleDomain($url)) {
                return self::cleanUrl($url);
            }
        }

        // Pattern 3: Direct external href that is not Google
        // Look for links in likely "website" button/list contexts
        if (preg_match_all('/href="(https?:\/\/[^"]{8,})"/i', $html, $all_m)) {
            foreach ($all_m[1] as $candidate) {
                if (!self::isGoogleDomain($candidate) && self::looksLikeBizSite($candidate)) {
                    return self::cleanUrl($candidate);
                }
            }
        }

        return '';
    }

    private static function extractRating(string $html): float
    {
        // aria-label="4.5 stars"
        if (preg_match('/aria-label="(\d+\.?\d*)\s+stars?"/i', $html, $m)) return (float)$m[1];
        // JSON: "ratingValue":"4.5"
        if (preg_match('/"ratingValue"\s*:\s*"?(\d+\.?\d*)"?/', $html, $m)) return (float)$m[1];
        // Plain text: "Rated 4.5 out of 5"
        if (preg_match('/[Rr]ated\s+(\d+\.?\d+)\s+out\s+of/i', $html, $m)) return (float)$m[1];
        return 0.0;
    }

    private static function extractReviewCount(string $html): int
    {
        // aria-label="123 reviews"
        if (preg_match('/aria-label="([\d,]+)\s+reviews?"/i', $html, $m)) return (int)str_replace(',', '', $m[1]);
        // JSON: "reviewCount":42
        if (preg_match('/"reviewCount"\s*:\s*(\d+)/i', $html, $m)) return (int)$m[1];
        // "(42)" near rating
        if (preg_match('/\b([1-5]\.\d)\s*\((\d[\d,]*)\)/i', $html, $m)) return (int)str_replace(',', '', $m[2]);
        // "42 Google reviews"
        if (preg_match('/([\d,]+)\s+(?:Google\s+)?reviews?/i', $html, $m)) return (int)str_replace(',', '', $m[1]);
        return 0;
    }

    private static function extractAddress(string $html): string
    {
        // JSON-LD "streetAddress"
        if (preg_match('/"streetAddress"\s*:\s*"([^"]+)"/i', $html, $m)) return $m[1];
        // aria-label on address element
        if (preg_match('/aria-label="([^"]{10,80}(?:Ave|Blvd|Dr|Rd|St|Way|Ln|Ct|Pl)[^"]{0,30})"/i', $html, $m)) return $m[1];
        return '';
    }

    // ── Private: URL helpers ─────────────────────────────────────────────────

    private static function isGoogleDomain(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        return (bool)preg_match('/(?:^|\.)(?:google|googleapis|gstatic|googleusercontent|goo\.gl|maps\.app)\./', $host);
    }

    private static function looksLikeBizSite(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        // Must have a proper domain (not just an IP or localhost)
        if (!preg_match('/\.[a-z]{2,}$/i', $host)) return false;
        // Skip common CDNs / social platforms that appear in Maps HTML
        $skip = ['facebook.com','twitter.com','instagram.com','linkedin.com',
                 'youtube.com','yelp.com','tripadvisor.com','foursquare.com',
                 'apple.com','microsoft.com','amazon.com','cdn.','static.'];
        foreach ($skip as $pattern) {
            if (stripos($host, $pattern) !== false) return false;
        }
        return true;
    }

    private static function cleanUrl(string $url): string
    {
        // Strip UTM params and tracking fragments
        $url = preg_replace('/[?&]utm_[^&"]+/', '', $url);
        return rtrim($url, '/&?');
    }
}
