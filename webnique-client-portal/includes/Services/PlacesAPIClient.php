<?php
/**
 * Google Places API Client
 *
 * Wraps the Google Places Text Search and Place Details APIs.
 * Text Search: up to 60 results (3 pages × 20) per query.
 * Place Details: used to retrieve website + phone for each candidate.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class PlacesAPIClient
{
    private const TEXT_SEARCH_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
    private const DETAILS_URL     = 'https://maps.googleapis.com/maps/api/place/details/json';

    private static function apiKey(): string
    {
        $settings = get_option('wnq_lead_finder_settings', []);
        return trim($settings['google_places_key'] ?? '');
    }

    /**
     * Run a text search query against Google Places.
     * Returns up to 20 results per call; pass $page_token to get next page.
     *
     * @param  string $query      e.g. "roofing contractor in Orlando FL"
     * @param  string $page_token Previous response next_page_token for pagination
     * @return array{results: array, next_page_token: string, error?: string}
     */
    public static function textSearch(string $query, string $page_token = ''): array
    {
        $key = self::apiKey();
        if (!$key) {
            return ['results' => [], 'next_page_token' => '', 'error' => 'No Google Places API key configured'];
        }

        $params = ['query' => $query, 'key' => $key];
        if ($page_token) {
            $params['pagetoken'] = $page_token;
        }

        $response = wp_remote_get(
            self::TEXT_SEARCH_URL . '?' . http_build_query($params),
            ['timeout' => 15, 'sslverify' => true]
        );

        if (is_wp_error($response)) {
            return ['results' => [], 'next_page_token' => '', 'error' => $response->get_error_message()];
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $status = $body['status'] ?? '';

        if ($status !== 'OK' && $status !== 'ZERO_RESULTS') {
            $msg = $body['error_message'] ?? $status;
            return ['results' => [], 'next_page_token' => '', 'error' => $msg];
        }

        return [
            'results'          => $body['results'] ?? [],
            'next_page_token'  => $body['next_page_token'] ?? '',
        ];
    }

    /**
     * Fetch Place Details to get website and phone number.
     * Only call this after the candidate passes the review/rating filter.
     *
     * @param  string $place_id Google Place ID
     * @return array  Fields: name, formatted_phone_number, website, business_status
     */
    public static function getDetails(string $place_id): array
    {
        $key = self::apiKey();
        if (!$key) return [];

        $params = [
            'place_id' => $place_id,
            'fields'   => 'name,formatted_phone_number,website,business_status',
            'key'      => $key,
        ];

        $response = wp_remote_get(
            self::DETAILS_URL . '?' . http_build_query($params),
            ['timeout' => 15, 'sslverify' => true]
        );

        if (is_wp_error($response)) return [];

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (($body['status'] ?? '') !== 'OK') return [];

        return $body['result'] ?? [];
    }

    /**
     * Validate that the stored API key is working by running a minimal test query.
     * Returns ['ok' => bool, 'message' => string].
     */
    public static function testApiKey(): array
    {
        $result = self::textSearch('test business in New York');
        if (!empty($result['error'])) {
            return ['ok' => false, 'message' => $result['error']];
        }
        return ['ok' => true, 'message' => 'API key is working correctly.'];
    }
}
