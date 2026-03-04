<?php
/**
 * Backlink Verifier
 *
 * Checks whether submitted/live backlinks still resolve via a HEAD request.
 * Used by the weekly cron and the "Verify Live Links" admin button.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\BacklinkManager;

final class BacklinkVerifier
{
    /**
     * Verify all submitted + live links for one client.
     *
     * @return array{checked:int, live:int, lost:int}
     */
    public static function verifyClientLinks(string $client_id): array
    {
        // Fetch links that have a real URL to check
        $submitted = BacklinkManager::getAll($client_id, ['status' => 'submitted', 'limit' => 100]);
        $live      = BacklinkManager::getAll($client_id, ['status' => 'live',      'limit' => 100]);
        $all       = array_merge($live, $submitted);

        $results = ['checked' => 0, 'live' => 0, 'lost' => 0];

        foreach ($all as $link) {
            // Prefer the source_url (where the link actually lives) over submission_url
            $check_url = !empty($link['source_url'])
                ? $link['source_url']
                : (!empty($link['submission_url']) ? $link['submission_url'] : '');

            if (!$check_url) {
                continue;
            }

            $is_live = self::checkUrl($check_url);
            $results['checked']++;

            if ($is_live) {
                $results['live']++;
                BacklinkManager::update((int)$link['id'], [
                    'verified_live' => 1,
                    'verified_at'   => current_time('mysql'),
                    'status'        => 'live',
                ]);
            } else {
                $results['lost']++;
                // Only downgrade to 'lost' if it was previously confirmed live
                if ($link['verified_live']) {
                    BacklinkManager::updateStatus((int)$link['id'], 'lost');
                } else {
                    // Just record the verification attempt
                    BacklinkManager::update((int)$link['id'], [
                        'verified_at' => current_time('mysql'),
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * Verify links for all clients (used by weekly cron).
     *
     * @return array{clients:int, checked:int, live:int, lost:int}
     */
    public static function verifyAllClients(): array
    {
        $clients = \WNQ\Models\Client::getAll();
        $totals  = ['clients' => 0, 'checked' => 0, 'live' => 0, 'lost' => 0];

        foreach ($clients as $client) {
            $r = self::verifyClientLinks($client['client_id']);
            $totals['clients']++;
            $totals['checked'] += $r['checked'];
            $totals['live']    += $r['live'];
            $totals['lost']    += $r['lost'];
        }

        return $totals;
    }

    /**
     * Send a HEAD request and return true if the URL resolves (2xx / 3xx).
     */
    public static function checkUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $response = wp_remote_head($url, [
            'timeout'     => 8,
            'sslverify'   => false,
            'redirection' => 3,
            'user-agent'  => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 400;
    }
}
