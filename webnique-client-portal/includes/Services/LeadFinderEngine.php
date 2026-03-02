<?php
/**
 * Lead Finder Engine
 *
 * Orchestrates the full prospect-discovery pipeline in two phases
 * designed for reliable bulk operation:
 *
 * Phase 1 – queueSearch()
 *   Queries Google Places Text Search (up to 60 results per keyword+city),
 *   stores the raw candidates in a WordPress transient, and returns
 *   immediately so the browser AJAX call never times out.
 *
 * Phase 2 – processNextCandidate()
 *   Called once per candidate by the browser (looped via JS).
 *   Each call processes exactly one Place result:
 *     1. Franchise detection by name
 *     2. Review count + rating filter
 *     3. Duplicate check (place_id + name+city)
 *     4. Place Details API call → website + phone
 *     5. Homepage fetch (HTML reused by all enrichers)
 *     6. Franchise detection by website content
 *     7. SEO scoring
 *     8. Email extraction
 *     9. Owner name extraction
 *    10. Social media extraction
 *    11. Address parsing
 *    12. DB insert
 *
 * runSearch() (used by daily WP-Cron) runs the full pipeline in one
 * blocking call — acceptable for background jobs with no user-facing timeout.
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
    private const QUEUE_TTL = DAY_IN_SECONDS; // transient lifetime

    // ── Phase 1: Queue ───────────────────────────────────────────────────────

    /**
     * Run a Google Places search and store the raw candidates in a transient.
     * Returns immediately with a batch_id — no web-crawling happens here.
     *
     * @param array{keyword: string, city: string, max_results?: int} $params
     * @return array{ok: bool, batch_id?: string, total?: int, error?: string}
     */
    public static function queueSearch(array $params): array
    {
        $keyword     = sanitize_text_field($params['keyword']      ?? '');
        $city        = sanitize_text_field($params['city']         ?? '');
        $max_results = min(60, max(1, (int)($params['max_results'] ?? 60)));

        if (!$keyword || !$city) {
            return ['ok' => false, 'error' => 'Keyword and city are required'];
        }

        $all_places = [];
        $page_token = '';
        $pages      = 0;

        do {
            if ($pages > 0) {
                sleep(2); // Google requires a pause between paginated requests
            }

            $data = PlacesAPIClient::textSearch($keyword . ' in ' . $city, $page_token);

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

        if (empty($all_places)) {
            return ['ok' => true, 'batch_id' => '', 'total' => 0];
        }

        $batch_id = wp_generate_uuid4();
        set_transient('wnq_lead_queue_' . $batch_id, [
            'keyword'    => $keyword,
            'city'       => $city,
            'places'     => $all_places,
            'next_index' => 0,
            'stats'      => self::emptyStats(count($all_places)),
        ], self::QUEUE_TTL);

        return ['ok' => true, 'batch_id' => $batch_id, 'total' => count($all_places)];
    }

    // ── Phase 2: Process one candidate ──────────────────────────────────────

    /**
     * Process the next unprocessed candidate from a queued batch.
     * One AJAX call per candidate → no PHP timeout risk.
     *
     * @param string $batch_id     Returned by queueSearch()
     * @param array  $filter_params min_reviews, min_rating, min_seo_score
     * @return array{ok: bool, done: bool, progress: int, total: int, stats: array, error?: string}
     */
    public static function processNextCandidate(string $batch_id, array $filter_params): array
    {
        // Do NOT call set_time_limit — the ceiling here is the server's configured
        // max_execution_time. Our HTTP calls are capped at 8+5+4 = 17s worst case,
        // which fits comfortably inside any reasonable PHP time limit (30s+).
        // Calling set_time_limit(30) was reducing the available time on hosts
        // that allow longer execution, causing PHP to die mid-request.

        $queue = get_transient('wnq_lead_queue_' . $batch_id);
        if (!$queue) {
            return ['ok' => false, 'done' => true, 'error' => 'Batch not found or expired'];
        }

        $places     = $queue['places'];
        $next       = (int)$queue['next_index'];
        $total      = count($places);
        $stats      = $queue['stats'];
        $keyword    = $queue['keyword'];
        $city       = $queue['city'];

        // All candidates processed
        if ($next >= $total) {
            delete_transient('wnq_lead_queue_' . $batch_id);
            return ['ok' => true, 'done' => true, 'progress' => $total, 'total' => $total, 'stats' => $stats];
        }

        // *** CRITICAL: Advance the transient index BEFORE processing ***
        // If the browser AbortController fires (45s) and the JS skips this
        // candidate, the next AJAX call will pick up the NEXT candidate instead
        // of retrying this one forever. Without this, a single slow site causes
        // 5 consecutive "timeouts" on the SAME candidate → loop stops at 0/N.
        $done = ($next + 1 >= $total);
        $queue['next_index'] = $next + 1;
        if ($done) {
            delete_transient('wnq_lead_queue_' . $batch_id);
        } else {
            set_transient('wnq_lead_queue_' . $batch_id, $queue, self::QUEUE_TTL);
        }

        // Process the candidate (isolated — queue is already advanced)
        try {
            $outcome = self::processSingleCandidate(
                $places[$next],
                $keyword,
                $city,
                $filter_params
            );
        } catch (\Throwable $e) {
            $outcome = 'low_seo'; // count as filtered, never block the queue
            error_log(sprintf(
                'WNQ Lead Finder: candidate %d/%d threw %s: %s (in %s:%d)',
                $next + 1, $total,
                get_class($e), $e->getMessage(),
                $e->getFile(), $e->getLine()
            ));
        }
        $stats = self::updateStats($stats, $outcome);

        // Write final stats back to transient (best-effort — may already be gone)
        if (!$done) {
            $q = get_transient('wnq_lead_queue_' . $batch_id);
            if ($q) {
                $q['stats'] = $stats;
                set_transient('wnq_lead_queue_' . $batch_id, $q, self::QUEUE_TTL);
            }
        }

        return [
            'ok'       => true,
            'done'     => $done,
            'progress' => $next + 1,
            'total'    => $total,
            'stats'    => $stats,
        ];
    }

    // ── Daily Cron (blocking — runs in background) ───────────────────────────

    /**
     * Full blocking pipeline for WP-Cron.
     * No user-facing timeout risk since it runs in the background.
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
        $keyword  = $industries[$day % count($industries)];
        $city     = $cities[$day % count($cities)];

        self::runSearch([
            'keyword'       => $keyword,
            'city'          => $city,
            'min_reviews'   => (int)($settings['min_reviews']   ?? 20),
            'min_rating'    => (float)($settings['min_rating']  ?? 3.5),
            'min_seo_score' => (int)($settings['min_seo_score'] ?? 2),
            'max_results'   => 60,
        ]);
    }

    /**
     * Blocking single-request search — kept for cron and internal use.
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

        $all_places = [];
        $page_token = '';
        $pages      = 0;

        do {
            if ($pages > 0) sleep(2);
            $data = PlacesAPIClient::textSearch($keyword . ' in ' . $city, $page_token);
            if (!empty($data['error'])) {
                if (empty($all_places)) return ['ok' => false, 'error' => $data['error']];
                break;
            }
            $all_places = array_merge($all_places, $data['results'] ?? []);
            $page_token = $data['next_page_token'] ?? '';
            $pages++;
        } while ($page_token && count($all_places) < $max_results && $pages < 3);

        $stats = self::emptyStats(count($all_places));

        foreach ($all_places as $place) {
            $outcome = self::processSingleCandidate(
                $place,
                $keyword,
                $city,
                ['min_reviews' => $min_reviews, 'min_rating' => $min_rating, 'min_seo_score' => $min_seo]
            );
            $stats = self::updateStats($stats, $outcome);
        }

        return ['ok' => true, 'stats' => $stats];
    }

    // ── Core Processing Logic ────────────────────────────────────────────────

    /**
     * Process a single Google Places result through the full qualification
     * and enrichment pipeline.
     *
     * @return string One of: franchise | low_reviews | duplicate | no_website | low_seo | saved
     */
    private static function processSingleCandidate(
        array $place,
        string $keyword,
        string $city,
        array $filter_params
    ): string {
        $min_reviews = max(0, (int)($filter_params['min_reviews']   ?? 20));
        $min_rating  = max(0.0, (float)($filter_params['min_rating']  ?? 3.5));
        $min_seo     = max(0, (int)($filter_params['min_seo_score'] ?? 2));

        $business_name = sanitize_text_field($place['name'] ?? '');

        // 1. Franchise check by name (free — no API call)
        if (LeadEnrichmentService::isFranchise($business_name)) {
            return 'franchise';
        }

        // 2. Reviews, rating, status filters
        if ((int)($place['user_ratings_total'] ?? 0) < $min_reviews) return 'low_reviews';
        if ((float)($place['rating'] ?? 0) < $min_rating)            return 'low_reviews';
        if (($place['business_status'] ?? '') === 'PERMANENTLY_CLOSED') return 'low_reviews';

        $place_id = $place['place_id'] ?? '';
        if (!$place_id) return 'low_reviews';

        // 3. Dedup by place_id
        if (Lead::findByPlaceId($place_id)) return 'duplicate';

        // 4. Address parsing (needed for city dedup)
        $addr      = LeadEnrichmentService::parseAddress($place['formatted_address'] ?? '');
        $lead_city = $addr['city'] ?: $city;

        // 5. Dedup by name + city
        if (Lead::existsByNameAndCity($business_name, $lead_city)) return 'duplicate';

        // 6. Place Details API → website + phone
        $details = PlacesAPIClient::getDetails($place_id);
        $website = $details['website'] ?? '';
        $phone   = $details['formatted_phone_number'] ?? '';

        if (!$website) return 'no_website';

        // 7. Fetch homepage HTML (shared by all enrichers — one HTTP request total)
        $homepage_html = self::fetchHtml($website);

        // 8. Franchise check against website content
        if ($homepage_html && LeadEnrichmentService::isFranchise($business_name, $homepage_html)) {
            return 'franchise';
        }

        // 9. SEO scoring
        $seo = $homepage_html
            ? LeadSEOScorer::scoreWebsiteFromHtml($homepage_html)
            : LeadSEOScorer::scoreWebsite($website);

        if (!$seo['ok'] || $seo['score'] < $min_seo) return 'low_seo';

        // 10–11. Enrichment (email + social reuse $homepage_html)
        $email_data = LeadEmailExtractor::extractEmail($website, $homepage_html);
        $social     = LeadEnrichmentService::extractSocialMedia($website, $homepage_html);

        $insert_id = Lead::insert([
            'place_id'         => $place_id,
            'business_name'    => $business_name,
            'industry'         => $keyword,
            'owner_first'      => '',
            'owner_last'       => '',
            'website'          => esc_url_raw($website),
            'address'          => sanitize_text_field($addr['street']),
            'city'             => sanitize_text_field($lead_city),
            'state'            => sanitize_text_field($addr['state']),
            'zip'              => sanitize_text_field($addr['zip']),
            'phone'            => sanitize_text_field($phone),
            'email'            => sanitize_email($email_data['email']),
            'email_source'     => $email_data['source'] ? esc_url_raw($email_data['source']) : '',
            'rating'           => (float)($place['rating'] ?? 0),
            'review_count'     => (int)($place['user_ratings_total'] ?? 0),
            'social_facebook'  => $social['facebook']  ? esc_url_raw($social['facebook'])  : '',
            'social_instagram' => $social['instagram'] ? esc_url_raw($social['instagram']) : '',
            'social_linkedin'  => $social['linkedin']  ? esc_url_raw($social['linkedin'])  : '',
            'social_twitter'   => $social['twitter']   ? esc_url_raw($social['twitter'])   : '',
            'social_youtube'   => $social['youtube']   ? esc_url_raw($social['youtube'])   : '',
            'social_tiktok'    => $social['tiktok']    ? esc_url_raw($social['tiktok'])    : '',
            'seo_score'        => (int)$seo['score'],
            'seo_issues'       => $seo['issues'],
            'status'           => 'new',
        ]);

        if (!$insert_id) {
            // DB insert failed — log the MySQL error so it's visible in WP debug log.
            // Most likely cause: schema migration hasn't been run yet (missing columns).
            global $wpdb;
            error_log('WNQ Lead Finder: DB insert failed for "' . $business_name . '" — ' . $wpdb->last_error);
            return 'low_seo'; // count as filtered so stats don't mislead
        }

        return 'saved';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function fetchHtml(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout'             => 5,
            'user-agent'          => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'           => false,
            'redirection'         => 3,
            'limit_response_size' => 512000, // 500 KB — enough for HTML head + visible text
        ]);

        if (is_wp_error($response)) return '';
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return '';

        return wp_remote_retrieve_body($response);
    }

    private static function emptyStats(int $found = 0): array
    {
        return [
            'found'        => $found,
            'franchise'    => 0,
            'low_reviews'  => 0,
            'filtered'     => 0,  // passed reviews/rating — tracked as total - low_reviews
            'duplicate'    => 0,
            'no_website'   => 0,
            'low_seo'      => 0,
            'saved'        => 0,
        ];
    }

    private static function updateStats(array $stats, string $outcome): array
    {
        if ($outcome === 'franchise')   { $stats['franchise']++;   }
        if ($outcome === 'low_reviews') { $stats['low_reviews']++; }
        if ($outcome === 'duplicate')   { $stats['duplicate']++;   }
        if ($outcome === 'no_website')  { $stats['no_website']++;  }
        if ($outcome === 'low_seo')     { $stats['low_seo']++;     }
        if ($outcome === 'saved')       { $stats['saved']++;       }

        // 'filtered' = candidates that passed reviews/rating and dedup (had a website attempt)
        if (in_array($outcome, ['no_website', 'low_seo', 'saved'], true)) {
            $stats['filtered']++;
        }

        return $stats;
    }
}
