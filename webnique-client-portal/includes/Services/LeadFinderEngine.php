<?php
/**
 * Lead Finder Engine
 *
 * Orchestrates the full prospect-discovery pipeline:
 *   1. Query Google Places Text Search (up to 60 results per run)
 *   2. Filter by review count + rating
 *   3. Fetch Place Details (website + phone) for qualifying candidates
 *   4. Score website SEO via LeadSEOScorer
 *   5. Extract email via LeadEmailExtractor
 *   6. Save qualified leads to wp_wnq_leads
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
     *   keyword:      string,
     *   city:         string,
     *   min_reviews?: int,
     *   min_rating?:  float,
     *   min_seo_score?: int,
     *   max_results?: int
     * } $params
     * @return array{ok: bool, error?: string, stats?: array}
     */
    public static function runSearch(array $params): array
    {
        $keyword    = sanitize_text_field($params['keyword']       ?? '');
        $city       = sanitize_text_field($params['city']          ?? '');
        $min_reviews= max(0, (int)($params['min_reviews']          ?? 20));
        $min_rating = max(0.0, (float)($params['min_rating']       ?? 3.5));
        $min_seo    = max(0, (int)($params['min_seo_score']        ?? 2));
        $max_results= min(60, max(1, (int)($params['max_results']  ?? 60)));

        if (!$keyword || !$city) {
            return ['ok' => false, 'error' => 'Keyword and city are required'];
        }

        $query      = $keyword . ' in ' . $city;
        $all_places = [];
        $page_token = '';
        $pages      = 0;

        // Google Places returns max 20 per page; up to 3 pages = 60 results
        do {
            // API requires a brief pause between paginated requests
            if ($pages > 0) {
                sleep(2);
            }

            $data = PlacesAPIClient::textSearch($query, $page_token);

            if (!empty($data['error'])) {
                // If we got some results already, continue with what we have
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
            'found'            => count($all_places),
            'filtered'         => 0,
            'qualified'        => 0,
            'saved'            => 0,
            'skipped_existing' => 0,
            'no_website'       => 0,
            'low_seo_score'    => 0,
        ];

        foreach ($all_places as $place) {
            // Basic filters: reviews, rating, not permanently closed
            if ((int)($place['user_ratings_total'] ?? 0) < $min_reviews) continue;
            if ((float)($place['rating'] ?? 0) < $min_rating) continue;
            if (($place['business_status'] ?? '') === 'PERMANENTLY_CLOSED') continue;

            $stats['filtered']++;

            $place_id = $place['place_id'] ?? '';
            if (!$place_id) continue;

            // Skip if already in our database
            if (Lead::findByPlaceId($place_id)) {
                $stats['skipped_existing']++;
                continue;
            }

            // Fetch website + phone (costs one API call per candidate)
            $details = PlacesAPIClient::getDetails($place_id);
            $website = $details['website'] ?? '';
            $phone   = $details['formatted_phone_number'] ?? '';

            if (!$website) {
                $stats['no_website']++;
                continue;
            }

            // Score website SEO
            $seo = LeadSEOScorer::scoreWebsite($website);
            if (!$seo['ok'] || $seo['score'] < $min_seo) {
                $stats['low_seo_score']++;
                continue;
            }

            $stats['qualified']++;

            // Extract email (best-effort; empty string if none found)
            $email_data = LeadEmailExtractor::extractEmail($website);

            Lead::insert([
                'place_id'      => $place_id,
                'business_name' => sanitize_text_field($place['name'] ?? ''),
                'industry'      => $keyword,
                'city'          => $city,
                'address'       => sanitize_text_field($place['formatted_address'] ?? ''),
                'phone'         => sanitize_text_field($phone),
                'website'       => esc_url_raw($website),
                'rating'        => (float)($place['rating'] ?? 0),
                'review_count'  => (int)($place['user_ratings_total'] ?? 0),
                'seo_score'     => (int)$seo['score'],
                'seo_issues'    => $seo['issues'],
                'email'         => sanitize_email($email_data['email']),
                'email_source'  => esc_url_raw($email_data['source']),
                'status'        => 'new',
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

        // Rotate by day-of-year so each industry+city combination is covered over time
        $day       = (int)date('z'); // 0–365
        $industry  = $industries[$day % count($industries)];
        $city      = $cities[$day % count($cities)];

        self::runSearch([
            'keyword'       => $industry,
            'city'          => $city,
            'min_reviews'   => (int)($settings['min_reviews']   ?? 20),
            'min_rating'    => (float)($settings['min_rating']  ?? 3.5),
            'min_seo_score' => (int)($settings['min_seo_score'] ?? 2),
            'max_results'   => 60,
        ]);
    }
}
