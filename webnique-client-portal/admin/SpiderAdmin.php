<?php
/**
 * SpiderAdmin — SEO Spider & Advanced Analysis Admin UI
 *
 * Tabs: Spider | Page Speed | Content | Competitors | Local SEO
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

use WNQ\Models\Client;
use WNQ\Models\SEOHub;
use WNQ\Services\CrawlEngine;
use WNQ\Services\PageSpeedEngine;
use WNQ\Services\ContentAnalyzer;
use WNQ\Services\CompetitorTracker;
use WNQ\Services\LocalSEOEngine;

if (!defined('ABSPATH')) {
    exit;
}

final class SpiderAdmin
{
    public static function register(): void
    {
        add_action('admin_menu',            [self::class, 'addMenuPage'], 21);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_wnq_spider',    [self::class, 'handleAjax']);
    }

    public static function addMenuPage(): void
    {
        $cap = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page(
            'wnq-seo-hub',
            'SEO Spider & Analysis',
            'SEO Spider',
            $cap,
            'wnq-seo-spider',
            [self::class, 'renderPage']
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'wnq-seo-spider') === false) return;
        wp_enqueue_style('wnq-seohub', WNQ_PORTAL_URL . 'assets/admin/seohub.css', [], WNQ_PORTAL_VERSION);
        wp_enqueue_style('wnq-spider', WNQ_PORTAL_URL . 'assets/admin/spider.css', ['wnq-seohub'], WNQ_PORTAL_VERSION);
        wp_enqueue_script('wnq-spider', WNQ_PORTAL_URL . 'assets/admin/spider.js', ['jquery'], WNQ_PORTAL_VERSION, true);
        wp_localize_script('wnq-spider', 'WNQ_SPIDER', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wnq_spider_nonce'),
        ]);
    }

    // ── Main Router ────────────────────────────────────────────────────────

    public static function renderPage(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied.');
        }

        $tab       = sanitize_key($_GET['tab'] ?? 'spider');
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();

        echo '<div class="wrap wnq-hub-wrap">';
        echo '<div class="wnq-hub-masthead">';
        echo '<div class="wnq-hub-logo">🔭 WebNique<span>SEO OS</span></div>';
        echo '<nav class="wnq-hub-nav">';
        $nav = ['wnq-seo-hub' => 'Dashboard', 'wnq-seo-hub-clients' => 'Clients',
                'wnq-seo-hub-keywords' => 'Keywords', 'wnq-seo-hub-content' => 'Content',
                'wnq-seo-hub-audits' => 'Audits', 'wnq-seo-spider' => 'Spider',
                'wnq-seo-hub-reports' => 'Reports', 'wnq-seo-hub-blog' => 'Blog',
                'wnq-seo-hub-api' => 'API', 'wnq-seo-hub-settings' => 'Settings'];
        foreach ($nav as $slug => $label) {
            $active = (strpos($_SERVER['QUERY_STRING'] ?? '', 'page=' . $slug) !== false && $slug !== 'wnq-seo-hub')
                      || ($_GET['page'] ?? '') === $slug ? 'active' : '';
            echo '<a href="' . admin_url('admin.php?page=' . $slug) . '" class="' . $active . '">' . $label . '</a>';
        }
        echo '</nav></div>';

        echo '<h1 class="wnq-hub-page-title">🕷️ SEO Spider &amp; Advanced Analysis</h1>';

        // Tab bar
        $tabs = ['spider' => '🕷️ Spider', 'pagespeed' => '⚡ Page Speed', 'content' => '📄 Content', 'competitors' => '🏆 Competitors', 'local' => '📍 Local SEO'];
        echo '<div style="display:flex;gap:2px;padding:0 24px;background:#f9fafb;border-bottom:1px solid #e5e7eb;">';
        foreach ($tabs as $t => $label) {
            $active_style = $tab === $t ? 'border-bottom:3px solid #0d539e;color:#0d539e;font-weight:700;' : 'border-bottom:3px solid transparent;color:#6b7280;';
            echo '<a href="' . esc_url(add_query_arg(['tab' => $t, 'client_id' => $client_id], admin_url('admin.php?page=wnq-seo-spider'))) . '"
                    style="padding:14px 16px;text-decoration:none;font-size:13px;' . $active_style . '">' . $label . '</a>';
        }
        echo '</div>';

        // Client selector (shared across tabs)
        echo '<div class="wnq-hub-section">';
        echo '<div class="wnq-client-selector" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
        echo '<select onchange="location.href=\'' . admin_url('admin.php?page=wnq-seo-spider&tab=' . $tab . '&client_id=') . '\'+this.value"><option value="">— Select Client —</option>';
        foreach ($clients as $c) {
            $sel = $client_id === $c['client_id'] ? 'selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '" ' . $sel . '>' . esc_html($c['company'] ?: $c['name']) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        if ($client_id) {
            match($tab) {
                'pagespeed'   => self::renderPageSpeed($client_id),
                'content'     => self::renderContent($client_id),
                'competitors' => self::renderCompetitors($client_id),
                'local'       => self::renderLocalSEO($client_id),
                default       => self::renderSpider($client_id),
            };
        } else {
            echo '<div style="text-align:center;padding:60px;color:#6b7280;"><p>Select a client above to begin.</p></div>';
        }

        echo '</div>';
        echo '</div>';
    }

    // ── Spider Tab ─────────────────────────────────────────────────────────

    private static function renderSpider(string $client_id): void
    {
        $sessions    = CrawlEngine::getSessions($client_id, 10);
        $agent_keys = SEOHub::getAgentKeys($client_id);
        $site_url   = '';
        foreach ($agent_keys as $k) {
            if ($k['status'] === 'active') {
                $site_url = $k['site_url'];
                break;
            }
        }

        // Active/running session
        $active_session = null;
        foreach ($sessions as $s) {
            if ($s['status'] === 'running') { $active_session = $s; break; }
        }

        // View single session results?
        $view_session_id = (int)($_GET['session_id'] ?? 0);
        if ($view_session_id) {
            self::renderSessionResults($view_session_id, $client_id);
            return;
        }

        // Start Crawl Form
        echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:20px;margin-bottom:20px;">';
        echo '<h3 style="margin:0 0 14px;color:#1e3a5f;">🕷️ Start New Crawl</h3>';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<div><label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px;">Start URL</label>';
        echo '<input type="url" id="spider-start-url" value="' . esc_attr($site_url) . '" placeholder="https://example.com" style="width:320px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;"></div>';
        echo '<div><label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px;">Max Depth</label>';
        echo '<select id="spider-max-depth" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">';
        foreach ([2 => '2 levels', 3 => '3 levels', 4 => '4 levels', 5 => '5 levels'] as $v => $l) {
            echo '<option value="' . $v . '" ' . ($v === 3 ? 'selected' : '') . '>' . $l . '</option>';
        }
        echo '</select></div>';
        echo '<button id="spider-start-btn" class="wnq-btn wnq-btn-primary" onclick="wnqSpiderStart(\'' . esc_js($client_id) . '\')">▶ Start Crawl</button>';
        echo '</div>';
        echo '<div id="spider-progress" style="display:none;margin-top:16px;">';
        echo '  <div style="display:flex;justify-content:space-between;margin-bottom:6px;"><strong id="spider-status-text" style="color:#0d539e;">Starting crawl…</strong><span id="spider-pct" style="font-weight:700;">0 crawled</span></div>';
        echo '  <div style="background:#dbeafe;border-radius:6px;height:10px;overflow:hidden;">';
        echo '    <div id="spider-bar" style="background:linear-gradient(90deg,#0d539e,#3b82f6);height:100%;width:5%;border-radius:6px;transition:width 0.4s;"></div>';
        echo '  </div>';
        echo '  <div id="spider-counts" style="margin-top:8px;font-size:12px;color:#6b7280;"></div>';
        echo '</div>';
        echo '</div>';

        // Session history
        if (empty($sessions)) {
            echo '<div style="text-align:center;padding:40px;color:#9ca3af;">No crawl sessions yet. Start a crawl above.</div>';
        } else {
            echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 12px;">Crawl History</h3>';
            echo '<table class="wnq-hub-table"><thead><tr><th>Start URL</th><th>Status</th><th>Crawled</th><th>Issues</th><th>Started</th><th>Actions</th></tr></thead><tbody>';
            foreach ($sessions as $s) {
                $status_color = $s['status'] === 'completed' ? '#059669' : ($s['status'] === 'running' ? '#d97706' : '#dc2626');
                echo '<tr>';
                echo '<td><a href="' . esc_url($s['start_url']) . '" target="_blank" style="font-size:12px;">' . esc_html($s['start_url']) . '</a></td>';
                echo '<td><span style="font-weight:700;color:' . $status_color . ';">' . esc_html(ucfirst($s['status'])) . '</span></td>';
                echo '<td>' . number_format((int)$s['urls_crawled']) . ' pages</td>';
                echo '<td>' . ($s['issues_found'] > 0 ? '<span style="color:#dc2626;font-weight:700;">' . $s['issues_found'] . '</span>' : '0') . '</td>';
                echo '<td style="font-size:12px;">' . esc_html(substr($s['started_at'], 0, 16)) . '</td>';
                echo '<td style="white-space:nowrap;">';
                if ($s['status'] === 'completed') {
                    echo '<a class="wnq-btn wnq-btn-sm" href="' . esc_url(add_query_arg(['tab' => 'spider', 'client_id' => $client_id, 'session_id' => $s['id']], admin_url('admin.php?page=wnq-seo-spider'))) . '">View Results</a> ';
                } else {
                    // Running/stuck — offer Reset so user can restart the crawl
                    echo '<button class="wnq-btn wnq-btn-sm" style="color:#d97706;" onclick="wnqSpiderResetAndResume(' . $s['id'] . ', \'' . esc_js($client_id) . '\')">↺ Reset &amp; Recrawl</button> ';
                }
                echo '<button class="wnq-btn wnq-btn-sm" style="color:#dc2626;" onclick="wnqSpiderDeleteSession(' . $s['id'] . ', \'' . esc_js($client_id) . '\')">Delete</button>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    private static function renderSessionResults(int $session_id, string $client_id): void
    {
        $session = CrawlEngine::getSession($session_id);
        if (!$session || $session['client_id'] !== $client_id) {
            echo '<div style="color:#dc2626;padding:20px;">Session not found.</div>';
            return;
        }

        $stats = CrawlEngine::getSessionStats($session_id);
        $sub   = sanitize_key($_GET['sub'] ?? 'overview');

        // Back link
        echo '<p style="margin-bottom:12px;"><a href="' . esc_url(add_query_arg(['tab' => 'spider', 'client_id' => $client_id], admin_url('admin.php?page=wnq-seo-spider'))) . '" style="color:#0d539e;">← Back to Sessions</a></p>';

        // Sub-tab bar
        $subs = ['overview' => 'Overview', 'pages' => 'All Pages', 'broken' => 'Broken Links', 'redirects' => 'Redirects', 'duplicates' => 'Duplicates', 'architecture' => 'Architecture'];
        echo '<div style="display:flex;gap:2px;margin-bottom:20px;border-bottom:1px solid #e5e7eb;">';
        foreach ($subs as $k => $label) {
            $active_s = $sub === $k ? 'border-bottom:3px solid #0d539e;color:#0d539e;font-weight:700;' : 'border-bottom:3px solid transparent;color:#6b7280;';
            echo '<a href="' . esc_url(add_query_arg(['tab' => 'spider', 'client_id' => $client_id, 'session_id' => $session_id, 'sub' => $k], admin_url('admin.php?page=wnq-seo-spider'))) . '"
                    style="padding:10px 14px;text-decoration:none;font-size:12px;' . $active_s . '">' . $label . '</a>';
        }
        echo '</div>';

        match($sub) {
            'pages'        => self::renderPagesTab($session_id, $stats),
            'broken'       => self::renderBrokenTab($session_id),
            'redirects'    => self::renderRedirectsTab($session_id),
            'duplicates'   => self::renderDuplicatesTab($session_id),
            'architecture' => self::renderArchitectureTab($session_id),
            default        => self::renderOverviewTab($session, $stats, $client_id, $session_id),
        };
    }

    private static function renderOverviewTab(array $session, array $stats, string $client_id, int $session_id): void
    {
        $issues_summary = CrawlEngine::getIssuesSummary($session_id);

        // Stats bar
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
        $stat_items = [
            ['Total Pages', $stats['total'], '#374151', ''],
            ['OK (200)', $stats['ok'], '#059669', 'background:#d1fae5;'],
            ['Broken', $stats['broken'], '#dc2626', $stats['broken'] > 0 ? 'background:#fee2e2;' : ''],
            ['Redirects', $stats['redirects'], '#d97706', ''],
            ['No-Index', $stats['noindex'], '#6b7280', ''],
            ['Missing Title', $stats['missing_title'], '#dc2626', $stats['missing_title'] > 0 ? 'background:#fff1f2;' : ''],
            ['Missing H1', $stats['missing_h1'], '#dc2626', $stats['missing_h1'] > 0 ? 'background:#fff1f2;' : ''],
            ['Thin Content', $stats['thin_content'], '#d97706', ''],
            ['No Schema', $stats['no_schema'], '#d97706', ''],
        ];
        foreach ($stat_items as [$label, $val, $color, $bg]) {
            echo '<div style="flex:1;min-width:100px;padding:12px 14px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;text-align:center;' . $bg . '">';
            echo '<div style="font-size:22px;font-weight:800;color:' . $color . ';">' . number_format((int)$val) . '</div>';
            echo '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' . $label . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // Issues breakdown
        if (!empty($issues_summary)) {
            $issue_labels = [
                'broken_link_404'          => ['Broken Links (404)', 'critical'],
                'missing_title'            => ['Missing Page Title', 'critical'],
                'missing_h1'               => ['Missing H1', 'critical'],
                'missing_meta_description' => ['Missing Meta Description', 'warning'],
                'thin_content'             => ['Thin Content (<300 words)', 'warning'],
                'no_schema'                => ['No Structured Data', 'warning'],
                'no_internal_links'        => ['No Internal Links', 'info'],
                'noindex'                  => ['Noindex Pages', 'info'],
                'title_too_long'           => ['Title Too Long (>60 chars)', 'warning'],
                'title_too_short'          => ['Title Too Short (<20 chars)', 'warning'],
                'meta_desc_too_long'       => ['Meta Description Too Long', 'info'],
                'missing_alt_text'         => ['Missing Image Alt Text', 'warning'],
                'canonical_points_elsewhere' => ['Canonical → Different URL', 'info'],
            ];
            echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 12px;">Issues Found</h3>';
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:20px;">';
            foreach ($issues_summary as $type => $count) {
                [$label, $sev] = $issue_labels[$type] ?? [str_replace('_', ' ', $type), 'info'];
                $bg  = $sev === 'critical' ? '#fee2e2' : ($sev === 'warning' ? '#fef3c7' : '#f3f4f6');
                $col = $sev === 'critical' ? '#dc2626' : ($sev === 'warning' ? '#d97706' : '#6b7280');
                echo '<div style="background:' . $bg . ';border-radius:8px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;">';
                echo '<span style="font-size:12px;color:#374151;">' . esc_html($label) . '</span>';
                echo '<span style="font-size:16px;font-weight:800;color:' . $col . ';">' . $count . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }

        // Sitemap button
        echo '<div style="margin-top:16px;">';
        echo '<a href="' . esc_url(add_query_arg(['action' => 'wnq_spider_sitemap', 'session_id' => $session_id, '_wpnonce' => wp_create_nonce('wnq_spider_sitemap')], admin_url('admin-post.php'))) . '" class="wnq-btn wnq-btn-primary" target="_blank">⬇ Download XML Sitemap</a>';
        echo '</div>';
    }

    private static function renderPagesTab(int $session_id, array $stats): void
    {
        $filter = sanitize_text_field($_GET['issue_filter'] ?? '');
        $pages  = CrawlEngine::getPages($session_id, array_filter(['issue' => $filter, 'limit' => 300]));

        echo '<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">';
        echo '<a class="wnq-btn wnq-btn-sm" href="' . esc_url(add_query_arg('issue_filter', '', remove_query_arg('issue_filter'))) . '">All</a>';
        foreach (['broken_link_404' => '404 Broken', 'missing_title' => 'No Title', 'missing_h1' => 'No H1', 'thin_content' => 'Thin', 'noindex' => 'Noindex'] as $k => $l) {
            echo '<a class="wnq-btn wnq-btn-sm" href="' . esc_url(add_query_arg('issue_filter', $k)) . '">' . $l . '</a>';
        }
        echo '</div>';

        echo '<div style="overflow-x:auto;"><table class="wnq-hub-table"><thead><tr><th>URL</th><th>Status</th><th>Title</th><th>H1</th><th>Words</th><th>Issues</th></tr></thead><tbody>';
        if (empty($pages)) {
            echo '<tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">No pages found for this filter.</td></tr>';
        }
        foreach ($pages as $p) {
            $status_color = $p['status_code'] == 200 ? '#059669' : ($p['status_code'] >= 400 ? '#dc2626' : '#d97706');
            $issues_arr   = $p['issues'] ? json_decode($p['issues'], true) : [];
            echo '<tr>';
            echo '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><a href="' . esc_url($p['url']) . '" target="_blank" style="font-size:11px;">' . esc_html($p['url']) . '</a></td>';
            echo '<td style="font-weight:700;color:' . $status_color . ';">' . $p['status_code'] . '</td>';
            echo '<td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html($p['page_title'] ?: '—') . '</td>';
            echo '<td style="font-size:11px;">' . esc_html($p['h1'] ? substr($p['h1'], 0, 40) : '—') . '</td>';
            echo '<td>' . number_format((int)$p['word_count']) . '</td>';
            echo '<td style="font-size:11px;">' . esc_html(implode(', ', $issues_arr ?: [])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function renderBrokenTab(int $session_id): void
    {
        $broken = CrawlEngine::getBrokenLinks($session_id);
        if (empty($broken)) {
            echo '<div style="text-align:center;padding:40px;color:#059669;font-size:16px;">✅ No broken links found!</div>';
            return;
        }
        echo '<p style="color:#6b7280;margin-bottom:14px;font-size:13px;">' . count($broken) . ' broken or errored URLs found.</p>';
        echo '<table class="wnq-hub-table"><thead><tr><th>URL</th><th>HTTP Status</th><th>Found at Depth</th></tr></thead><tbody>';
        foreach ($broken as $b) {
            $color = $b['status_code'] == 404 ? '#dc2626' : '#d97706';
            echo '<tr>';
            echo '<td><a href="' . esc_url($b['url']) . '" target="_blank" style="font-size:12px;">' . esc_html($b['url']) . '</a></td>';
            echo '<td><span style="font-weight:700;color:' . $color . ';">' . ($b['status_code'] ?: 'Error') . '</span></td>';
            echo '<td>Depth ' . (int)$b['depth'] . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderRedirectsTab(int $session_id): void
    {
        $redirects = CrawlEngine::getRedirectPages($session_id);
        if (empty($redirects)) {
            echo '<div style="text-align:center;padding:40px;color:#059669;">✅ No redirect chains detected!</div>';
            return;
        }
        echo '<p style="color:#6b7280;margin-bottom:14px;font-size:13px;">' . count($redirects) . ' URLs with redirects.</p>';
        echo '<table class="wnq-hub-table"><thead><tr><th>Original URL</th><th>Status</th><th>Final URL</th><th>Hops</th></tr></thead><tbody>';
        foreach ($redirects as $r) {
            $chain = $r['redirect_chain'] ? json_decode($r['redirect_chain'], true) : [];
            echo '<tr>';
            echo '<td style="font-size:11px;max-width:250px;word-break:break-all;">' . esc_html($r['url']) . '</td>';
            echo '<td><span style="font-weight:700;color:#d97706;">' . $r['status_code'] . '</span></td>';
            echo '<td style="font-size:11px;max-width:250px;word-break:break-all;"><a href="' . esc_url($r['final_url'] ?: $r['url']) . '" target="_blank">' . esc_html($r['final_url'] ?: '—') . '</a></td>';
            echo '<td>' . (int)$r['redirect_count'] . ($r['redirect_count'] > 1 ? ' <span style="color:#d97706;font-size:11px;">⚠ Chain</span>' : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderDuplicatesTab(int $session_id): void
    {
        $dup_content = CrawlEngine::getDuplicateContent($session_id);
        $dup_titles  = CrawlEngine::getDuplicateTitles($session_id);

        echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 12px;">Duplicate Content (same MD5)</h3>';
        if (empty($dup_content)) {
            echo '<p style="color:#059669;margin-bottom:20px;">✅ No exact duplicate content detected.</p>';
        } else {
            foreach ($dup_content as $group) {
                echo '<div style="background:#fff8f1;border:1px solid #fed7aa;border-radius:8px;padding:12px 16px;margin-bottom:10px;">';
                echo '<strong style="color:#c2410c;">' . $group['count'] . ' identical pages</strong>';
                echo '<ul style="margin:8px 0 0;padding-left:20px;font-size:12px;">';
                foreach ($group['pages'] as $p) {
                    echo '<li><a href="' . esc_url($p['url']) . '" target="_blank">' . esc_html($p['url']) . '</a> (' . number_format((int)$p['word_count']) . ' words)</li>';
                }
                echo '</ul></div>';
            }
        }

        echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:20px 0 12px;">Duplicate Page Titles</h3>';
        if (empty($dup_titles)) {
            echo '<p style="color:#059669;">✅ No duplicate titles detected.</p>';
        } else {
            echo '<table class="wnq-hub-table"><thead><tr><th>Title</th><th>Count</th><th>URLs</th></tr></thead><tbody>';
            foreach ($dup_titles as $d) {
                $urls = explode('|||', $d['urls']);
                echo '<tr>';
                echo '<td style="font-weight:600;">' . esc_html($d['page_title'] ?? '') . '</td>';
                echo '<td><span style="color:#d97706;font-weight:700;">' . $d['cnt'] . '</span></td>';
                echo '<td style="font-size:11px;">' . implode('<br>', array_map('esc_html', $urls)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private static function renderArchitectureTab(int $session_id): void
    {
        $by_depth = CrawlEngine::getArchitecture($session_id);
        echo '<p style="color:#6b7280;font-size:13px;margin-bottom:16px;">Site structure by crawl depth. Ideal: important pages within 3 clicks of homepage.</p>';
        foreach ($by_depth as $depth => $pages) {
            $indent = $depth * 16;
            echo '<div style="margin-bottom:16px;">';
            echo '<div style="font-size:13px;font-weight:700;color:#1e3a5f;margin-bottom:8px;padding-left:' . $indent . 'px;">📁 Depth ' . $depth . ' <span style="font-weight:400;color:#9ca3af;">(' . count($pages) . ' pages)</span></div>';
            echo '<div style="padding-left:' . ($indent + 16) . 'px;">';
            foreach (array_slice($pages, 0, 50) as $p) {
                $status_color = $p['status_code'] == 200 ? '#059669' : '#dc2626';
                $indexable    = $p['is_indexable'] ? '' : ' <span style="font-size:10px;color:#9ca3af;">[noindex]</span>';
                echo '<div style="font-size:12px;padding:3px 0;border-bottom:1px solid #f3f4f6;">';
                echo '<span style="color:' . $status_color . ';font-weight:700;margin-right:8px;">' . $p['status_code'] . '</span>';
                echo '<a href="' . esc_url($p['url']) . '" target="_blank" style="color:#374151;">' . esc_html($p['url']) . '</a>';
                echo $indexable;
                if ($p['page_title']) echo ' <span style="color:#9ca3af;">— ' . esc_html(substr($p['page_title'], 0, 50)) . '</span>';
                echo '</div>';
            }
            if (count($pages) > 50) echo '<p style="font-size:11px;color:#9ca3af;margin:4px 0;">… and ' . (count($pages) - 50) . ' more</p>';
            echo '</div></div>';
        }
    }

    // ── Page Speed Tab ─────────────────────────────────────────────────────

    private static function renderPageSpeed(string $client_id): void
    {
        $results = PageSpeedEngine::getLatestByUrl($client_id);

        echo '<div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">';
        echo '<button class="wnq-btn wnq-btn-primary" onclick="wnqSpiderPSI(\'' . esc_js($client_id) . '\')">⚡ Analyze Key Pages</button>';
        echo '<span style="font-size:12px;color:#6b7280;">Runs Google PageSpeed Insights on your top pages. Requires PSI API key in AI Settings.</span>';
        echo '</div>';
        echo '<div id="spider-action-result" style="margin-bottom:12px;"></div>';

        if (empty($results)) {
            echo '<div style="text-align:center;padding:40px;color:#9ca3af;">No PageSpeed data yet. Click "Analyze Key Pages" above.</div>';
            return;
        }

        $cwv_colors = ['Good' => '#059669', 'Needs Improvement' => '#d97706', 'Poor' => '#dc2626', '—' => '#9ca3af'];

        echo '<table class="wnq-hub-table"><thead><tr><th>URL</th><th>Device</th><th>Performance</th><th>SEO</th><th>LCP</th><th>CLS</th><th>FCP</th><th>Checked</th></tr></thead><tbody>';
        foreach ($results as $r) {
            $perf_color = $r['performance_score'] >= 90 ? '#059669' : ($r['performance_score'] >= 50 ? '#d97706' : '#dc2626');
            $lcp_label  = PageSpeedEngine::lcpGrade($r['lcp_ms']);
            $cls_label  = PageSpeedEngine::clsGrade($r['cls_score'] !== null ? (float)$r['cls_score'] : null);
            echo '<tr>';
            echo '<td style="font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><a href="' . esc_url($r['url']) . '" target="_blank">' . esc_html($r['url']) . '</a></td>';
            echo '<td>' . esc_html(ucfirst($r['strategy'])) . '</td>';
            echo '<td style="font-weight:800;color:' . $perf_color . ';">' . ($r['performance_score'] ?? '—') . '</td>';
            echo '<td>' . ($r['seo_score'] ?? '—') . '</td>';
            echo '<td><span style="color:' . ($cwv_colors[$lcp_label] ?? '#9ca3af') . ';font-size:12px;">' . ($r['lcp_ms'] ? number_format($r['lcp_ms']) . 'ms' : '—') . '</span></td>';
            echo '<td><span style="color:' . ($cwv_colors[$cls_label] ?? '#9ca3af') . ';font-size:12px;">' . ($r['cls_score'] ?? '—') . '</span></td>';
            echo '<td>' . ($r['fcp_ms'] ? number_format($r['fcp_ms']) . 'ms' : '—') . '</td>';
            echo '<td style="font-size:11px;">' . esc_html(substr($r['checked_at'], 0, 16)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // ── Content Tab ────────────────────────────────────────────────────────

    private static function renderContent(string $client_id): void
    {
        $audit = ContentAnalyzer::auditClient($client_id);
        $kws   = SEOHub::getKeywords($client_id);

        // Intent classification
        echo '<div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">';
        echo '<button class="wnq-btn wnq-btn-primary" onclick="wnqSpiderClassifyIntent(\'' . esc_js($client_id) . '\')">🏷️ Classify Keyword Intent</button>';
        echo '</div>';
        echo '<div id="spider-action-result" style="margin-bottom:12px;"></div>';

        // Keyword intent breakdown
        if (!empty($kws)) {
            $intent_counts = [];
            foreach ($kws as $kw) {
                $intent = $kw['intent'] ?? ContentAnalyzer::classifyIntent($kw['keyword']);
                $intent_counts[$intent] = ($intent_counts[$intent] ?? 0) + 1;
            }
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">';
            $colors = ['transactional' => '#d1fae5', 'informational' => '#f3f4f6', 'commercial' => '#dbeafe', 'navigational' => '#fef3c7'];
            foreach ($intent_counts as $intent => $count) {
                echo '<div style="padding:10px 16px;background:' . ($colors[$intent] ?? '#f3f4f6') . ';border-radius:8px;text-align:center;">';
                echo '<div style="font-size:18px;font-weight:800;color:#1e3a5f;">' . $count . '</div>';
                echo '<div style="font-size:11px;color:#6b7280;">' . ucfirst($intent) . '</div>';
                echo '</div>';
            }
            echo '</div>';
        }

        // Duplicate titles
        echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 10px;">Duplicate Page Titles</h3>';
        if (empty($audit['duplicate_titles'])) {
            echo '<p style="color:#059669;margin-bottom:16px;">✅ No duplicate titles found.</p>';
        } else {
            echo '<table class="wnq-hub-table" style="margin-bottom:20px;"><thead><tr><th>Title</th><th>Count</th><th>URLs</th></tr></thead><tbody>';
            foreach ($audit['duplicate_titles'] as $d) {
                $urls = explode('|||', $d['urls']);
                echo '<tr><td style="font-weight:600;">' . esc_html($d['title']) . '</td>';
                echo '<td><span style="color:#d97706;font-weight:700;">' . $d['cnt'] . '</span></td>';
                echo '<td style="font-size:11px;">' . implode('<br>', array_map('esc_html', $urls)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Duplicate meta
        echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 10px;">Duplicate Meta Descriptions</h3>';
        if (empty($audit['duplicate_meta'])) {
            echo '<p style="color:#059669;margin-bottom:16px;">✅ No duplicate meta descriptions found.</p>';
        } else {
            echo '<table class="wnq-hub-table" style="margin-bottom:20px;"><thead><tr><th>Meta Description</th><th>Count</th><th>URLs</th></tr></thead><tbody>';
            foreach ($audit['duplicate_meta'] as $d) {
                $urls = explode('|||', $d['urls']);
                echo '<tr><td style="font-size:12px;">' . esc_html(substr($d['meta_description'], 0, 100)) . '</td>';
                echo '<td><span style="color:#d97706;font-weight:700;">' . $d['cnt'] . '</span></td>';
                echo '<td style="font-size:11px;">' . implode('<br>', array_map('esc_html', $urls)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Title length issues
        if (!empty($audit['short_titles']) || !empty($audit['long_titles'])) {
            echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 10px;">Title Length Issues</h3>';
            echo '<table class="wnq-hub-table"><thead><tr><th>URL</th><th>Title</th><th>Length</th><th>Issue</th></tr></thead><tbody>';
            foreach ($audit['short_titles'] as $r) {
                echo '<tr><td style="font-size:11px;">' . esc_html($r['page_url']) . '</td>';
                echo '<td>' . esc_html($r['title']) . '</td><td>' . $r['title_len'] . '</td><td><span style="color:#d97706;">Too Short</span></td></tr>';
            }
            foreach ($audit['long_titles'] as $r) {
                echo '<tr><td style="font-size:11px;">' . esc_html($r['page_url']) . '</td>';
                echo '<td>' . esc_html(substr($r['title'], 0, 70)) . '…</td><td>' . $r['title_len'] . '</td><td><span style="color:#d97706;">Too Long</span></td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    // ── Competitors Tab ────────────────────────────────────────────────────

    private static function renderCompetitors(string $client_id): void
    {
        $competitors = CompetitorTracker::getCompetitors($client_id);
        $comparison  = CompetitorTracker::getComparison($client_id);

        // Save competitors form
        echo '<details style="margin-bottom:20px;"><summary class="wnq-btn">⚙️ Manage Competitors</summary>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-top:8px;">';
        wp_nonce_field('wnq_save_competitors');
        echo '<input type="hidden" name="action" value="wnq_save_competitors">';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
        echo '<p style="font-size:12px;color:#6b7280;margin-bottom:12px;">Enter up to 5 competitor domains (one per row).</p>';
        for ($i = 0; $i < 5; $i++) {
            $c = $competitors[$i] ?? [];
            echo '<div style="display:flex;gap:8px;margin-bottom:8px;">';
            echo '<input type="text" name="competitors[' . $i . '][domain]" value="' . esc_attr($c['domain'] ?? '') . '" placeholder="competitor.com" style="flex:2;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">';
            echo '<input type="text" name="competitors[' . $i . '][label]" value="' . esc_attr($c['label'] ?? '') . '" placeholder="Label (optional)" style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">';
            echo '</div>';
        }
        echo '<button type="submit" class="wnq-btn wnq-btn-primary" style="margin-top:8px;">Save Competitors</button>';
        echo '</form></details>';

        // Summary
        $summary = $comparison['summary'];
        if (!empty($summary)) {
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">';
            foreach (['top_3' => 'Top 3', 'top_10' => 'Top 10', 'top_20' => 'Top 20', 'not_ranking' => 'Not Ranking'] as $k => $l) {
                $col = $k === 'not_ranking' ? '#dc2626' : ($k === 'top_3' ? '#059669' : '#0d539e');
                echo '<div style="padding:12px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;text-align:center;">';
                echo '<div style="font-size:22px;font-weight:800;color:' . $col . ';">' . ($summary[$k] ?? 0) . '</div>';
                echo '<div style="font-size:11px;color:#6b7280;">' . $l . '</div></div>';
            }
            echo '</div>';
        }

        // Keyword comparison table
        if (!empty($comparison['keywords'])) {
            echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 10px;">Keyword Rankings</h3>';
            echo '<table class="wnq-hub-table"><thead><tr><th>Keyword</th><th>Cluster</th><th>Your Position</th><th>Trend</th></tr></thead><tbody>';
            foreach ($comparison['keywords'] as $kw) {
                $pos   = $kw['client_position'];
                $color = $pos === null ? '#9ca3af' : ($pos <= 10 ? '#059669' : ($pos <= 20 ? '#d97706' : '#6b7280'));
                echo '<tr>';
                echo '<td><strong>' . esc_html($kw['keyword']) . '</strong></td>';
                echo '<td style="font-size:12px;color:#6b7280;">' . esc_html($kw['cluster'] ?: '—') . '</td>';
                echo '<td style="font-weight:700;color:' . $color . ';">' . ($pos !== null ? '#' . number_format($pos, 1) : 'Not ranking') . '</td>';
                echo '<td style="font-size:12px;">' . esc_html($kw['client_trend']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    // ── Local SEO Tab ──────────────────────────────────────────────────────

    private static function renderLocalSEO(string $client_id): void
    {
        $audit       = LocalSEOEngine::auditLocalSEO($client_id);
        $opps        = LocalSEOEngine::getLocalOpportunities($client_id);
        $locations   = $audit['locations'];

        // Add location form
        echo '<details style="margin-bottom:20px;"><summary class="wnq-btn">+ Add Service Location</summary>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-top:8px;">';
        wp_nonce_field('wnq_save_local_location');
        echo '<input type="hidden" name="action" value="wnq_save_local_location">';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
        echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">';
        foreach (['location_name' => 'Location Name *', 'city' => 'City', 'state' => 'State', 'zip' => 'ZIP', 'phone' => 'Phone', 'address' => 'Address'] as $n => $l) {
            echo '<div><label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:3px;">' . $l . '</label>';
            echo '<input type="text" name="' . $n . '" placeholder="' . esc_attr($l) . '" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;box-sizing:border-box;"></div>';
        }
        echo '<div><label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:3px;">Google Business URL</label>';
        echo '<input type="url" name="gmb_url" placeholder="https://g.page/..." style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;box-sizing:border-box;"></div>';
        echo '</div>';
        echo '<button type="submit" class="wnq-btn wnq-btn-primary" style="margin-top:10px;">Save Location</button>';
        echo '</form></details>';

        // Issues
        if (!empty($audit['issues'])) {
            echo '<div style="margin-bottom:20px;">';
            foreach ($audit['issues'] as $issue) {
                $bg  = $issue['severity'] === 'warning' ? '#fef3c7' : '#f3f4f6';
                $col = $issue['severity'] === 'warning' ? '#d97706' : '#6b7280';
                echo '<div style="padding:10px 14px;background:' . $bg . ';border-radius:8px;margin-bottom:8px;font-size:13px;color:' . $col . ';">⚠ ' . esc_html($issue['message']) . '</div>';
            }
            echo '</div>';
        }

        // Locations
        if (!empty($locations)) {
            echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 10px;">Service Locations</h3>';
            echo '<table class="wnq-hub-table" style="margin-bottom:20px;"><thead><tr><th>Location</th><th>City</th><th>Phone</th><th>GMB</th><th>Actions</th></tr></thead><tbody>';
            foreach ($locations as $loc) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($loc['location_name']) . '</strong>' . ($loc['address'] ? '<br><small>' . esc_html($loc['address']) . '</small>' : '') . '</td>';
                echo '<td>' . esc_html($loc['city'] . ($loc['state'] ? ', ' . $loc['state'] : '')) . '</td>';
                echo '<td>' . esc_html($loc['phone'] ?: '—') . '</td>';
                echo '<td>' . ($loc['gmb_url'] ? '<a href="' . esc_url($loc['gmb_url']) . '" target="_blank" style="color:#0d539e;">View GMB →</a>' : '<span style="color:#dc2626;">Missing</span>') . '</td>';
                echo '<td><form method="post" action="' . admin_url('admin-post.php') . '" style="display:inline;">';
                wp_nonce_field('wnq_delete_local_' . $loc['id'], '_wpnonce', false);
                echo '<input type="hidden" name="action" value="wnq_delete_local_location">';
                echo '<input type="hidden" name="location_id" value="' . $loc['id'] . '">';
                echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
                echo '<button type="submit" class="wnq-btn wnq-btn-sm" style="color:#dc2626;" onclick="return confirm(\'Delete this location?\')">Delete</button>';
                echo '</form></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Keyword opportunities
        if (!empty($opps)) {
            echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 10px;">📍 Local Keyword Opportunities</h3>';
            echo '<p style="font-size:12px;color:#6b7280;margin-bottom:12px;">These local keywords are not yet tracked. Consider adding them.</p>';
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
            foreach ($opps as $kw) {
                echo '<span style="background:#dbeafe;color:#1e40af;padding:4px 10px;border-radius:99px;font-size:12px;">' . esc_html($kw) . '</span>';
            }
            echo '</div>';
        }

        // City coverage
        if (!empty($audit['city_coverage'])) {
            echo '<h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:20px 0 10px;">Keyword Coverage by City</h3>';
            echo '<table class="wnq-hub-table"><thead><tr><th>City</th><th>Keywords Tracked</th></tr></thead><tbody>';
            foreach ($audit['city_coverage'] as $city => $count) {
                echo '<tr><td>' . esc_html($city) . '</td><td>' . $count . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    // ── AJAX Handler ───────────────────────────────────────────────────────

    public static function handleAjax(): void
    {
        check_ajax_referer('wnq_spider_nonce', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $action    = sanitize_text_field($_POST['spider_action'] ?? '');
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $session_id = (int)($_POST['session_id'] ?? 0);

        switch ($action) {
            case 'start_crawl':
                $url  = esc_url_raw($_POST['start_url'] ?? '');
                $opts = ['max_depth' => (int)($_POST['max_depth'] ?? 3)];
                if (!$url) wp_send_json_error(['message' => 'No URL provided']);
                $id = CrawlEngine::startCrawl($client_id, $url, $opts);
                wp_send_json_success(['session_id' => $id, 'message' => 'Crawl started']);
                break;

            case 'crawl_batch':
                if (!$session_id) wp_send_json_error(['message' => 'No session ID']);
                $result = CrawlEngine::crawlBatch($session_id);
                wp_send_json_success($result);
                break;

            case 'delete_session':
                if (!$session_id) wp_send_json_error(['message' => 'No session ID']);
                CrawlEngine::deleteSession($session_id);
                wp_send_json_success(['message' => 'Session deleted']);
                break;

            case 'reset_session':
                if (!$session_id) wp_send_json_error(['message' => 'No session ID']);
                CrawlEngine::resetSession($session_id);
                wp_send_json_success(['session_id' => $session_id, 'message' => 'Session reset — ready to resume']);
                break;

            case 'analyze_psi':
                if (!$client_id) wp_send_json_error(['message' => 'No client ID']);
                $results = PageSpeedEngine::analyzeClientPages($client_id, 5);
                $count   = count($results);
                wp_send_json_success(['message' => "Analyzed $count pages. Reload to see results.", 'data' => $results]);
                break;

            case 'classify_intent':
                if (!$client_id) wp_send_json_error(['message' => 'No client ID']);
                $count = ContentAnalyzer::classifyClientKeywords($client_id);
                wp_send_json_success(['message' => "$count keywords classified. Reload to see results."]);
                break;

            default:
                wp_send_json_error(['message' => 'Unknown action: ' . $action]);
        }
    }
}
