<?php
/**
 * ServiceCoverageEngine — Service × Location Page Coverage Map
 *
 * Cross-references a client's services and service locations against
 * crawled pages to identify coverage gaps: combinations of
 * (service + city) that have no dedicated landing page.
 *
 * Coverage scoring:
 *   3 = URL slug contains both service and location  (strongest)
 *   2 = Page title or H1 contains both              (good)
 *   1 = Partial match (only service or only location)
 *   0 = No match — gap, page needs to be built
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceCoverageEngine
{
    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Build the full service × location coverage matrix for a client.
     *
     * @return array {
     *   services:    string[]   — service names from profile
     *   locations:   string[]   — location display labels (e.g. "Chicago, IL")
     *   matrix:      array[][]  — [service][location] = ['status', 'url', 'title']
     *   gaps:        array[]    — uncovered pairs with suggested page info
     *   covered:     int        — pairs with status >= 2
     *   total:       int        — total service × location pairs
     *   session_id:  int        — crawl session used (0 if no crawl data)
     *   error:       string     — set only when prerequisites are missing
     * }
     */
    public static function getCoverageMatrix(string $client_id): array
    {
        global $wpdb;

        // ── 1. Services ────────────────────────────────────────────────────
        $profile  = \WNQ\Models\SEOHub::getProfile($client_id);
        $services = array_values(array_filter(array_map('trim', (array)($profile['primary_services'] ?? []))));

        if (empty($services)) {
            return ['error' => 'No services defined. Add services to the client SEO profile first.'];
        }

        // ── 2. Locations ───────────────────────────────────────────────────
        // Prefer rows from wnq_local_seo; fall back to profile service_locations.
        $loc_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT location_name, city, state
             FROM {$wpdb->prefix}wnq_local_seo
             WHERE client_id=%s ORDER BY city ASC",
            $client_id
        ), ARRAY_A) ?: [];

        $locations = [];   // display_label => ['city', 'state']
        if (!empty($loc_rows)) {
            foreach ($loc_rows as $row) {
                $label = trim($row['city'] ?: $row['location_name']);
                if ($row['state']) $label .= ', ' . trim($row['state']);
                if ($label && !isset($locations[$label])) {
                    $locations[$label] = [
                        'city'  => trim($row['city'] ?: $row['location_name']),
                        'state' => trim($row['state']),
                    ];
                }
            }
        } elseif (!empty($profile['service_locations'])) {
            foreach ((array)$profile['service_locations'] as $loc) {
                $loc = trim((string)$loc);
                if ($loc) $locations[$loc] = ['city' => $loc, 'state' => ''];
            }
        }

        if (empty($locations)) {
            return ['error' => 'No service locations defined. Add locations in the Local SEO tab.'];
        }

        // ── 3. Crawled pages (most recent completed session) ───────────────
        $session_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wnq_crawl_sessions
             WHERE client_id=%s AND status='completed'
             ORDER BY completed_at DESC LIMIT 1",
            $client_id
        ));

        $pages = [];
        if ($session_id) {
            $pages = $wpdb->get_results($wpdb->prepare(
                "SELECT url, page_title, h1
                 FROM {$wpdb->prefix}wnq_crawl_pages
                 WHERE session_id=%d AND status_code=200 AND is_indexable=1",
                $session_id
            ), ARRAY_A) ?: [];
        }

        // ── 4. Build matrix ────────────────────────────────────────────────
        $matrix  = [];
        $gaps    = [];
        $covered = 0;
        $total   = 0;

        foreach ($services as $service) {
            foreach ($locations as $label => $loc) {
                $total++;
                $best_score = 0;
                $best_page  = null;

                foreach ($pages as $page) {
                    $score = self::scorePage($page, $service, $loc);
                    if ($score > $best_score) {
                        $best_score = $score;
                        $best_page  = $page;
                        if ($best_score === 3) break;  // perfect URL match — stop scanning
                    }
                }

                $matrix[$service][$label] = [
                    'status' => $best_score,
                    'url'    => $best_page['url'] ?? null,
                    'title'  => $best_page['page_title'] ?? null,
                ];

                if ($best_score >= 2) {
                    $covered++;
                } else {
                    $city_display = $loc['state'] ? $loc['city'] . ', ' . $loc['state'] : $label;
                    $gaps[] = [
                        'service'         => $service,
                        'location'        => $label,
                        'status'          => $best_score,
                        'suggested_slug'  => '/' . self::toSlug($service) . '/' . self::toSlug($loc['city'] ?: $label) . '/',
                        'suggested_title' => $service . ' in ' . $city_display,
                        'url'             => $best_page['url'] ?? null,   // partial match page (status=1)
                    ];
                }
            }
        }

        return [
            'services'   => $services,
            'locations'  => array_keys($locations),
            'matrix'     => $matrix,
            'gaps'       => $gaps,
            'covered'    => $covered,
            'total'      => $total,
            'session_id' => $session_id,
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /**
     * Score how well a crawled page covers a service + location pair.
     * Returns 0–3 (higher = stronger match).
     */
    private static function scorePage(array $page, string $service, array $loc): int
    {
        $url   = strtolower($page['url'] ?? '');
        $title = strtolower($page['page_title'] ?? '');
        $h1    = strtolower($page['h1'] ?? '');

        $svc_slug = self::toSlug($service);
        $loc_slug = self::toSlug($loc['city'] ?: '');

        // Strongest: both service and location appear in the URL path
        if ($svc_slug && $loc_slug && str_contains($url, $svc_slug) && str_contains($url, $loc_slug)) {
            return 3;
        }

        $svc_lc = strtolower($service);
        $loc_lc = strtolower($loc['city']);
        if ($loc['state']) $loc_lc .= ' ' . strtolower($loc['state']);

        $has_svc = $svc_lc && (str_contains($title, $svc_lc) || str_contains($h1, $svc_lc));
        $has_loc = $loc_lc && (str_contains($title, $loc_lc) || str_contains($h1, $loc_lc));

        if ($has_svc && $has_loc) return 2;   // title/H1 has both
        if ($has_svc || $has_loc)  return 1;   // partial
        return 0;
    }

    /**
     * Convert a string to a URL-safe slug for comparison against page URLs.
     */
    private static function toSlug(string $str): string
    {
        $str = strtolower(trim($str));
        $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
        $str = preg_replace('/[\s-]+/', '-', $str);
        return trim($str, '-');
    }
}
