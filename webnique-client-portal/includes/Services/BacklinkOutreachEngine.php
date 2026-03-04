<?php
/**
 * Backlink Outreach Engine
 *
 * Handles fully-automated end-to-end outreach for a single backlink record:
 *   1. Scrape the target domain for a contact email address
 *   2. AI-generate a personalised outreach email (if not already saved)
 *   3. Send via wp_mail (uses Hostinger SMTP configured in Settings tab)
 *   4. Mark outreach_sent_at + update status → submitted
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\BacklinkManager;
use WNQ\Models\Client;
use WNQ\Models\SEOHub;

final class BacklinkOutreachEngine
{
    /**
     * Return IDs of all non-citation links that haven't had outreach sent yet.
     *
     * @param  string $client_id
     * @return int[]
     */
    public static function getPendingLinkIds(string $client_id): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id
               FROM {$wpdb->prefix}wnq_backlinks
              WHERE client_id = %s
                AND link_type != 'citation'
                AND (outreach_sent_at IS NULL OR outreach_sent_at = '')
                AND status NOT IN ('live','rejected','lost')
              ORDER BY id ASC",
            $client_id
        ));
        return array_map('intval', $ids ?: []);
    }

    /**
     * Process a single backlink record through the full outreach pipeline.
     *
     * @param  int    $link_id
     * @param  string $client_id
     * @return array{success: bool, step?: string, domain?: string, contact_email?: string, message: string}
     */
    public static function processLink(int $link_id, string $client_id): array
    {
        $row = BacklinkManager::getById($link_id);
        if (!$row) {
            return ['success' => false, 'step' => 'load', 'domain' => '', 'message' => 'Link not found'];
        }

        $domain = $row['target_domain'];
        $url    = str_starts_with($domain, 'http') ? $domain : 'https://' . $domain;

        // ── Step 1: Find contact email ────────────────────────────────────────

        $contact_email = $row['contact_email'] ?? '';
        $email_scraped = false;

        if (!$contact_email || !is_email($contact_email)) {
            $extracted     = LeadEmailExtractor::extractEmail($url);
            $contact_email = $extracted['email'];
            $email_scraped = true;
        }

        if (!$contact_email || !is_email($contact_email)) {
            BacklinkManager::update($link_id, [
                'notes' => trim(($row['notes'] ?? '') . ' | Auto-outreach: no email found on site'),
            ]);
            return [
                'success' => false,
                'step'    => 'scrape',
                'domain'  => $domain,
                'message' => "No contact email found on {$domain}",
            ];
        }

        if ($email_scraped) {
            BacklinkManager::update($link_id, ['contact_email' => $contact_email]);
        }

        // ── Step 2: Generate outreach email (if not already saved) ────────────

        $outreach_body = $row['outreach_email'] ?? '';

        if (!$outreach_body) {
            $profile  = SEOHub::getProfile($client_id);
            $client   = Client::getByClientId($client_id);
            $services = (array)($profile['primary_services'] ?? []);
            $locs     = (array)($profile['service_locations'] ?? []);

            $result = AIEngine::generate('backlink_outreach_email', [
                'business_name' => $client['company'] ?? $client_id,
                'website'       => $client['website'] ?? '',
                'services'      => implode(', ', array_slice($services, 0, 3)),
                'location'      => $locs[0] ?? 'local area',
                'link_type'     => $row['link_type'],
                'target_domain' => $domain,
            ], $client_id, ['max_tokens' => 350, 'cache_ttl' => 0]);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'step'    => 'generate',
                    'domain'  => $domain,
                    'message' => 'AI email generation failed: ' . ($result['error'] ?? 'unknown error'),
                ];
            }

            $outreach_body = $result['content'];
            BacklinkManager::update($link_id, ['outreach_email' => $outreach_body]);
        }

        // ── Step 3: Send via wp_mail ──────────────────────────────────────────

        $client    = Client::getByClientId($client_id);
        $from_name = $client['company'] ?? get_bloginfo('name') ?: 'WebNique';
        $from_addr = get_option('wnq_smtp_user', 'chris@web-nique.com');

        // Extract subject from first line if AI prefixed it with "Subject:"
        $subject    = 'Link Building Opportunity — ' . $from_name;
        $body       = $outreach_body;
        $first_line = strtok($outreach_body, "\n");
        if ($first_line && stripos($first_line, 'subject:') === 0) {
            $subject = trim(substr($first_line, 8));
            $body    = ltrim(substr($outreach_body, strlen($first_line) + 1));
        }

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_addr . '>',
            'Reply-To: ' . $from_addr,
        ];

        $sent = wp_mail($contact_email, $subject, $body, $headers);

        if (!$sent) {
            return [
                'success' => false,
                'step'    => 'send',
                'domain'  => $domain,
                'message' => "Email failed to send to {$contact_email} — check SMTP settings",
            ];
        }

        // ── Step 4: Mark sent ─────────────────────────────────────────────────

        BacklinkManager::update($link_id, [
            'outreach_sent_at' => current_time('mysql'),
            'status'           => 'submitted',
        ]);

        return [
            'success'       => true,
            'domain'        => $domain,
            'contact_email' => $contact_email,
            'message'       => "Email sent to {$contact_email}",
        ];
    }
}
