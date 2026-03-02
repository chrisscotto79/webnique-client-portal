<?php
/**
 * Lead Finder Engine
 *
 * Orchestrates the full prospect-discovery pipeline:
 *   1.  Query Google Places Text Search (up to 60 results per run)
 *   2.  Skip known franchise / chain businesses
 *   3.  Filter by review count + rating
 *   4.  Skip duplicates (by place_id AND by business_name + city)
 *   5.  Fetch Place Details (website + phone) for qualifying candidates
 *   6.  Fetch homepage HTML (reused by multiple enrichers — one HTTP request)
 *   7.  Franchise-check against website content
 *   8.  Score website SEO via LeadSEOScorer
 *   9.  Extract email via LeadEmailExtractor
 *   10. Extract owner first/last name via LeadEnrichmentService
 *   11. Extract social media links via LeadEnrichmentService
 *   12. Parse street / state / zip from formatted_address
 *   13. Save qualified, enriched lead to wp_wnq_leads
 *
 * Also exposes runDailyCron() for the WP-Cron daily job.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\Lead;

final class LeadFinderEngine
{
    /**
     * Run a single industry + city search and save qualified leads.
     *
     * @param array{
     *   keyword:        string,
     *   city:           string,
     *   min_reviews?:   int,
     *   min_rating?:    float,
     *   min_seo_score?: int,
     *   max_results?:   int
     * } $params
     * @return array{ok: bool, error?: string, stats?: array}
     */
    public static function runSearch(array $params): array
    {
        $keyword     = sanitize_text_field($params['keyword']        ?? '');
        $city        = sanitize_text_field($params['city']           ?? '');
        $min_reviews = max(0, (int)($params['min_reviews']           ?? 20));
        $min_rating  = max(0.0, (float)($params['min_rating']        ?? 3.5));
        $min_seo     = max(0, (int)($params['min_seo_score']         ?? 2));
        $max_results = min(60, max(1, (int)($params['max_results']   ?? 60)));

        if (!$keyword || !$city) {
            return ['ok' => false, 'error' => 'Keyword and city are required'];
        }

        $query      = $keyword . ' in ' . $city;
        $all_places = [];
        $page_token = '';
        $pages      = 0;

        // Google Places returns max 20 per page; up to 3 pages = 60 results
        do {
            if ($pages > 0) {
                sleep(2); // Required pause between paginated requests
            }

            $data = PlacesAPIClient::textSearch($query, $page_token);

            if (!empty($data['error'])) {
                if (empty($all_places)) {
                    return ['ok' => false, 'error' => $data['error']];
                }
                break;
            }

            $all_places = array_merge($all_places, $data['results'] ?? []);
            $page_token = $data['next_page_token'] ?? '';
            $pages++;

        } while ($page_token && count($all_places) < $max_results && $pages < 3);

        $stats = [
            'found'             => count($all_places),
            'franchise_skipped' => 0,
            'filtered'          => 0,
            'skipped_existing'  => 0,
            'no_website'        => 0,
            'low_seo_score'     => 0,
            'qualified'         => 0,
            'saved'             => 0,
        ];

        foreach ($all_places as $place) {
            $business_name = sanitize_text_field($place['name'] ?? '');

            // Early franchise check by name (before any API calls)
            if (LeadEnrichmentService::isFranchise($business_name)) {
                $stats['franchise_skipped']++;
                continue;
            }

            // Basic filters: reviews, rating, not permanently closed
            if ((int)($place['user_ratings_total'] ?? 0) < $min_reviews)  continue;
            if ((float)($place['rating'] ?? 0) < $min_rating)              continue;
            if (($place['business_status'] ?? '') === 'PERMANENTLY_CLOSED') continue;

            $stats['filtered']++;

            $place_id = $place['place_id'] ?? '';
            if (!$place_id) continue;

            // Dedup by Google place_id
            if (Lead::findByPlaceId($place_id)) {
                $stats['skipped_existing']++;
                continue;
            }

            // Parse address components
            $addr     = LeadEnrichmentService::parseAddress($place['formatted_address'] ?? '');
            $lead_city = $addr['city'] ?: $city;

            // Secondary dedup by name + city
            if (Lead::existsByNameAndCity($business_name, $lead_city)) {
                $stats['skipped_existing']++;
                continue;
            }

            // Fetch website + phone (one Places Details API call per candidate)
            $details = PlacesAPIClient::getDetails($place_id);
            $website = $details['website'] ?? '';
            $phone   = $details['formatted_phone_number'] ?? '';

            if (!$website) {
                $stats['no_website']++;
                continue;
            }

            // Fetch homepage HTML once — reused by scorer, email extractor, enrichment
            $homepage_html = self::fetchHtml($website);

            // Franchise check against homepage content
            if ($homepage_html && LeadEnrichmentService::isFranchise($business_name, $homepage_html)) {
                $stats['franchise_skipped']++;
                continue;
            }

            // Score SEO
            $seo = $homepage_html
                ? LeadSEOScorer::scoreWebsiteFromHtml($homepage_html)
                : LeadSEOScorer::scoreWebsite($website);

            if (!$seo['ok'] || $seo['score'] < $min_seo) {
                $stats['low_seo_score']++;
                continue;
            }

            $stats['qualified']++;

            // Parallel enrichment (all using already-fetched homepage_html)
            $email_data  = LeadEmailExtractor::extractEmail($website, $homepage_html);
            $owner       = LeadEnrichmentService::extractOwnerName($website, $homepage_html);
            $social      = LeadEnrichmentService::extractSocialMedia($website, $homepage_html);

            Lead::insert([
                'place_id'         => $place_id,
                'business_name'    => $business_name,
                'industry'         => $keyword,
                'owner_first'      => sanitize_text_field($owner['first']),
                'owner_last'       => sanitize_text_field($owner['last']),
                'website'          => esc_url_raw($website),
                'address'          => sanitize_text_field($addr['street']),
                'city'             => sanitize_text_field($lead_city),
                'state'            => sanitize_text_field($addr['state']),
                'zip'              => sanitize_text_field($addr['zip']),
                'phone'            => sanitize_text_field($phone),
                'email'            => sanitize_email($email_data['email']),
                'email_source'     => esc_url_raw($email_data['source']),
                'rating'           => (float)($place['rating'] ?? 0),
                'review_count'     => (int)($place['user_ratings_total'] ?? 0),
                'social_facebook'  => esc_url_raw($social['facebook']),
                'social_instagram' => esc_url_raw($social['instagram']),
                'social_linkedin'  => esc_url_raw($social['linkedin']),
                'social_twitter'   => esc_url_raw($social['twitter']),
                'social_youtube'   => esc_url_raw($social['youtube']),
                'social_tiktok'    => esc_url_raw($social['tiktok']),
                'seo_score'        => (int)$seo['score'],
                'seo_issues'       => $seo['issues'],
                'status'           => 'new',
            ]);

            $stats['saved']++;
        }

        return ['ok' => true, 'stats' => $stats];
    }

    /**
     * Daily cron handler.
     * Rotates through configured industries + cities (one combination per day).
     */
    public static function runDailyCron(): void
    {
        $settings = get_option('wnq_lead_finder_settings', []);
        if (empty($settings['enabled'])) return;

        $industries = array_values(array_filter(
            array_map('trim', explode("\n", $settings['target_industries'] ?? ''))
        ));
        $cities = array_values(array_filter(
            array_map('trim', explode("\n", $settings['target_cities'] ?? ''))
        ));

        if (empty($industries) || empty($cities)) return;

        $day      = (int)date('z');
        $industry = $industries[$day % count($industries)];
        $city     = $cities[$day % count($cities)];

        self::runSearch([
            'keyword'       => $industry,
            'city'          => $city,
            'min_reviews'   => (int)($settings['min_reviews']   ?? 20),
            'min_rating'    => (float)($settings['min_rating']  ?? 3.5),
            'min_seo_score' => (int)($settings['min_seo_score'] ?? 2),
            'max_results'   => 60,
        ]);
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private static function fetchHtml(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout'    => 12,
            'user-agent' => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'  => false,
            'redirection'=> 5,
        ]);

        if (is_wp_error($response)) return '';
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return '';

        return wp_remote_retrieve_body($response);
    }
}
