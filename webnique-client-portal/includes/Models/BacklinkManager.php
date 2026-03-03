<?php
/**
 * Backlink Manager Model
 *
 * Stores and tracks backlink targets for each client across three categories:
 *  - Citations   : pre-loaded local directory listings (Yelp, YP, BBB, etc.)
 *  - Opportunities: guest posts, resource pages, sponsors, press
 *  - All Links   : aggregated view with live-verification status
 *
 * @package WebNique Portal
 */

namespace WNQ\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class BacklinkManager
{
    /* ═══════════════════════════════════════════
     *  TABLE
     * ═══════════════════════════════════════════ */

    public static function createTable(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_backlinks (
            id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id       varchar(100) NOT NULL,
            link_type       varchar(50) DEFAULT 'citation'
                            COMMENT 'citation|guest_post|resource_page|directory|sponsor|press|partnership',
            target_domain   varchar(500) NOT NULL,
            site_name       varchar(255) DEFAULT NULL,
            submission_url  varchar(1000) DEFAULT NULL
                            COMMENT 'URL where you sign up / submit',
            source_url      varchar(1000) DEFAULT NULL
                            COMMENT 'Live URL where our link actually appears',
            anchor_text     varchar(255) DEFAULT NULL,
            da_estimate     tinyint(3) UNSIGNED DEFAULT NULL
                            COMMENT 'Domain Authority estimate (0-100)',
            status          varchar(30) DEFAULT 'pending'
                            COMMENT 'pending|submitted|live|rejected|lost',
            notes           text DEFAULT NULL,
            outreach_email  longtext DEFAULT NULL
                            COMMENT 'AI-generated outreach email body',
            outreach_sent_at datetime DEFAULT NULL,
            submitted_at    datetime DEFAULT NULL,
            verified_at     datetime DEFAULT NULL,
            verified_live   tinyint(1) DEFAULT 0,
            created_at      datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id   (client_id),
            KEY link_type   (link_type),
            KEY status      (status)
        ) $c;");
    }

    /* ═══════════════════════════════════════════
     *  CITATION DIRECTORY LIBRARY
     *
     *  30 curated local-citation sites ordered by DA.
     *  Tier 1 (DA 80+)  = must-have for every client.
     *  Tier 2 (DA 50-79) = high-value secondaries.
     *  Tier 3 (<50)      = supporting citations.
     * ═══════════════════════════════════════════ */

    public static function getCitationSites(): array
    {
        return [
            // ── Tier 1: Must-Have ─────────────────────────────────────────────
            ['domain' => 'google.com/business',       'name' => 'Google Business Profile',  'da' => 100, 'url' => 'https://business.google.com/'],
            ['domain' => 'facebook.com',              'name' => 'Facebook Business Page',   'da' => 100, 'url' => 'https://www.facebook.com/pages/create'],
            ['domain' => 'apple.com/maps',            'name' => 'Apple Maps Connect',       'da' => 100, 'url' => 'https://mapsconnect.apple.com/'],
            ['domain' => 'bing.com/places',           'name' => 'Bing Places for Business', 'da' => 97,  'url' => 'https://www.bingplaces.com/'],
            ['domain' => 'yelp.com',                  'name' => 'Yelp for Business',        'da' => 93,  'url' => 'https://biz.yelp.com/'],
            ['domain' => 'bbb.org',                   'name' => 'Better Business Bureau',   'da' => 93,  'url' => 'https://www.bbb.org/'],
            ['domain' => 'foursquare.com',            'name' => 'Foursquare',               'da' => 92,  'url' => 'https://business.foursquare.com/'],
            ['domain' => 'mapquest.com',              'name' => 'MapQuest',                 'da' => 87,  'url' => 'https://www.mapquest.com/'],
            ['domain' => 'yellowpages.com',           'name' => 'Yellow Pages',             'da' => 83,  'url' => 'https://www.yellowpages.com/'],
            ['domain' => 'houzz.com',                 'name' => 'Houzz',                    'da' => 83,  'url' => 'https://www.houzz.com/'],
            ['domain' => 'dnb.com',                   'name' => 'Dun & Bradstreet',         'da' => 79,  'url' => 'https://www.dnb.com/'],
            ['domain' => 'nextdoor.com',              'name' => 'Nextdoor Business',        'da' => 80,  'url' => 'https://business.nextdoor.com/'],
            // ── Tier 2: High-Value ────────────────────────────────────────────
            ['domain' => 'manta.com',                 'name' => 'Manta',                    'da' => 70,  'url' => 'https://www.manta.com/'],
            ['domain' => 'angi.com',                  'name' => 'Angi (Angie\'s List)',     'da' => 72,  'url' => 'https://www.angi.com/'],
            ['domain' => 'homeadvisor.com',           'name' => 'HomeAdvisor',              'da' => 73,  'url' => 'https://pro.homeadvisor.com/'],
            ['domain' => 'thumbtack.com',             'name' => 'Thumbtack',                'da' => 69,  'url' => 'https://www.thumbtack.com/'],
            ['domain' => 'superpages.com',            'name' => 'Superpages',               'da' => 71,  'url' => 'https://www.superpages.com/'],
            ['domain' => 'yp.com',                    'name' => 'YP.com',                   'da' => 75,  'url' => 'https://www.yp.com/'],
            ['domain' => 'citysearch.com',            'name' => 'Citysearch',               'da' => 75,  'url' => 'https://www.citysearch.com/'],
            ['domain' => 'chamberofcommerce.com',     'name' => 'Chamber of Commerce',      'da' => 68,  'url' => 'https://www.chamberofcommerce.com/'],
            ['domain' => 'local.com',                 'name' => 'Local.com',                'da' => 68,  'url' => 'https://www.local.com/'],
            // ── Tier 3: Supporting ────────────────────────────────────────────
            ['domain' => 'merchantcircle.com',        'name' => 'Merchant Circle',          'da' => 62,  'url' => 'https://www.merchantcircle.com/'],
            ['domain' => 'alignable.com',             'name' => 'Alignable',                'da' => 55,  'url' => 'https://www.alignable.com/'],
            ['domain' => 'brownbook.net',             'name' => 'Brownbook',                'da' => 55,  'url' => 'https://www.brownbook.net/'],
            ['domain' => 'hotfrog.com',               'name' => 'Hotfrog',                  'da' => 56,  'url' => 'https://www.hotfrog.com/'],
            ['domain' => 'cylex-usa.com',             'name' => 'Cylex USA',                'da' => 53,  'url' => 'https://www.cylex-usa.com/'],
            ['domain' => 'ezlocal.com',               'name' => 'EZlocal',                  'da' => 50,  'url' => 'https://ezlocal.com/'],
            ['domain' => 'showmelocal.com',           'name' => 'ShowMeLocal',              'da' => 44,  'url' => 'https://www.showmelocal.com/'],
            ['domain' => 'n49.com',                   'name' => 'N49',                      'da' => 50,  'url' => 'https://www.n49.com/'],
            ['domain' => 'storeboard.com',            'name' => 'Storeboard',               'da' => 45,  'url' => 'https://www.storeboard.com/'],
        ];
    }

    /* ═══════════════════════════════════════════
     *  CRUD
     * ═══════════════════════════════════════════ */

    public static function insert(array $data): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'wnq_backlinks', $data);
        return (int)$wpdb->insert_id;
    }

    public static function update(int $id, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($wpdb->prefix . 'wnq_backlinks', $data, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wnq_backlinks', ['id' => $id]);
    }

    public static function getById(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_backlinks WHERE id=%d", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * @param array{link_type?: string, status?: string, limit?: int, offset?: int} $args
     */
    public static function getAll(string $client_id, array $args = []): array
    {
        global $wpdb;
        $t     = $wpdb->prefix . 'wnq_backlinks';
        $where = ['client_id = %s'];
        $vals  = [$client_id];

        if (!empty($args['link_type'])) {
            $where[] = 'link_type = %s';
            $vals[]  = $args['link_type'];
        }
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $vals[]  = $args['status'];
        }

        $vals[] = max(1, (int)($args['limit']  ?? 200));
        $vals[] = max(0, (int)($args['offset'] ?? 0));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t}
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY da_estimate DESC, status ASC
                 LIMIT %d OFFSET %d",
                $vals
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function getStats(string $client_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS cnt FROM {$wpdb->prefix}wnq_backlinks
                 WHERE client_id=%s GROUP BY status",
                $client_id
            ),
            ARRAY_A
        ) ?: [];

        $stats = ['total' => 0, 'live' => 0, 'submitted' => 0, 'pending' => 0, 'rejected' => 0, 'lost' => 0];
        foreach ($rows as $r) {
            $key = $r['status'];
            if (isset($stats[$key])) {
                $stats[$key] = (int)$r['cnt'];
            }
            $stats['total'] += (int)$r['cnt'];
        }
        return $stats;
    }

    public static function updateStatus(int $id, string $status): void
    {
        $data = ['status' => $status];
        if ($status === 'submitted') {
            $data['submitted_at'] = current_time('mysql');
        } elseif ($status === 'live') {
            $data['verified_live'] = 1;
            $data['verified_at']   = current_time('mysql');
        } elseif ($status === 'lost') {
            $data['verified_live'] = 0;
            $data['verified_at']   = current_time('mysql');
        }
        self::update($id, $data);
    }

    public static function existsByDomain(string $client_id, string $domain): bool
    {
        global $wpdb;
        return (bool)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wnq_backlinks WHERE client_id=%s AND target_domain=%s LIMIT 1",
                $client_id, $domain
            )
        );
    }

    /**
     * Pre-load the citation site list for a client, skipping any already present.
     *
     * @return int Number of new entries inserted
     */
    public static function seedCitations(string $client_id): int
    {
        $added = 0;
        foreach (self::getCitationSites() as $site) {
            if (self::existsByDomain($client_id, $site['domain'])) {
                continue;
            }
            self::insert([
                'client_id'      => $client_id,
                'link_type'      => 'citation',
                'target_domain'  => $site['domain'],
                'site_name'      => $site['name'],
                'submission_url' => $site['url'],
                'da_estimate'    => $site['da'],
                'status'         => 'pending',
            ]);
            $added++;
        }
        return $added;
    }
}
