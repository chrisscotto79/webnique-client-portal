<?php
/**
 * Lead Finder Admin UI
 *
 * Registers the "Lead Finder" admin page under the WebNique Portal menu.
 * Provides three tabs:
 *   Search   — run a Google Places search to discover + qualify prospects
 *   Leads    — browse, filter, update status, and export all saved leads
 *   Settings — configure API key, target industries/cities, filters, and cron
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\Lead;
use WNQ\Services\LeadFinderEngine;
use WNQ\Services\PlacesAPIClient;

final class LeadFinderAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('wp_ajax_wnq_lead_search',        [self::class, 'ajaxSearch']);
        add_action('wp_ajax_wnq_lead_update_status', [self::class, 'ajaxUpdateStatus']);
        add_action('wp_ajax_wnq_lead_delete',        [self::class, 'ajaxDelete']);
        add_action('admin_post_wnq_lead_export_csv', [self::class, 'handleExportCsv']);
        add_action('admin_post_wnq_lead_save_settings', [self::class, 'handleSaveSettings']);
        add_action('wp_ajax_wnq_lead_test_api',      [self::class, 'ajaxTestApi']);
    }

    public static function addMenuPage(): void
    {
        $cap = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page(
            'wnq-portal',
            'Lead Finder',
            'Lead Finder',
            $cap,
            'wnq-lead-finder',
            [self::class, 'renderPage']
        );
    }

    // ── Page Renderer ────────────────────────────────────────────────────────

    public static function renderPage(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        $tab      = sanitize_key($_GET['tab'] ?? 'search');
        $settings = get_option('wnq_lead_finder_settings', []);
        $stats    = Lead::getStats();

        ?>
        <div class="wrap wnq-lead-finder">
        <style>
        .wnq-lead-finder { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .wnq-lf-header   { display:flex; align-items:center; gap:12px; margin-bottom:24px; }
        .wnq-lf-header h1 { margin:0; font-size:24px; font-weight:700; }
        .wnq-lf-badge    { background:#2563eb; color:#fff; border-radius:6px; padding:3px 10px; font-size:12px; font-weight:600; }
        .wnq-lf-stats    { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px; }
        .wnq-stat-box    { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:14px 20px; min-width:110px; text-align:center; }
        .wnq-stat-box .num { font-size:28px; font-weight:700; color:#1e293b; line-height:1; }
        .wnq-stat-box .lbl { font-size:11px; color:#6b7280; margin-top:4px; text-transform:uppercase; letter-spacing:.5px; }
        .wnq-lf-tabs     { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:24px; }
        .wnq-lf-tab      { padding:10px 20px; cursor:pointer; font-size:14px; font-weight:500; color:#6b7280; border:none; background:none; border-bottom:2px solid transparent; margin-bottom:-2px; text-decoration:none; }
        .wnq-lf-tab.active { color:#2563eb; border-bottom-color:#2563eb; }
        .wnq-lf-tab:hover  { color:#1d4ed8; }
        .wnq-card        { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:24px; margin-bottom:20px; }
        .wnq-card h3     { margin:0 0 16px; font-size:16px; font-weight:600; }
        .wnq-form-row    { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
        .wnq-form-row.three { grid-template-columns:1fr 1fr 1fr; }
        .wnq-form-row.four  { grid-template-columns:1fr 1fr 1fr 1fr; }
        .wnq-field       { display:flex; flex-direction:column; gap:5px; }
        .wnq-field label { font-size:13px; font-weight:600; color:#374151; }
        .wnq-field input, .wnq-field select, .wnq-field textarea {
            padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; width:100%; box-sizing:border-box;
        }
        .wnq-field textarea { resize:vertical; min-height:90px; }
        .wnq-field small    { color:#6b7280; font-size:12px; }
        .wnq-btn         { padding:9px 20px; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; border:none; }
        .wnq-btn-primary { background:#2563eb; color:#fff; }
        .wnq-btn-primary:hover { background:#1d4ed8; }
        .wnq-btn-secondary { background:#f3f4f6; color:#374151; border:1px solid #d1d5db; }
        .wnq-btn-secondary:hover { background:#e5e7eb; }
        .wnq-btn-danger  { background:#dc2626; color:#fff; }
        .wnq-btn-danger:hover { background:#b91c1c; }
        .wnq-btn-sm      { padding:5px 12px; font-size:12px; }
        #wnq-search-results { margin-top:20px; }
        .wnq-progress    { display:none; align-items:center; gap:10px; padding:16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; margin-top:16px; color:#1d4ed8; font-weight:500; }
        .wnq-progress.show { display:flex; }
        .wnq-spinner     { width:20px; height:20px; border:3px solid #bfdbfe; border-top-color:#2563eb; border-radius:50%; animation:spin .8s linear infinite; flex-shrink:0; }
        @keyframes spin   { to { transform:rotate(360deg); } }
        .wnq-result-box  { padding:16px; border-radius:8px; margin-top:16px; }
        .wnq-result-ok   { background:#f0fdf4; border:1px solid #86efac; color:#15803d; }
        .wnq-result-err  { background:#fef2f2; border:1px solid #fca5a5; color:#dc2626; }
        .wnq-result-box  strong { display:block; font-size:15px; margin-bottom:8px; }
        .wnq-result-box  ul  { margin:4px 0 0 18px; }
        table.wnq-leads  { width:100%; border-collapse:collapse; font-size:13px; }
        table.wnq-leads th { background:#f9fafb; padding:10px 12px; text-align:left; font-weight:600; color:#374151; border-bottom:2px solid #e5e7eb; white-space:nowrap; }
        table.wnq-leads td { padding:10px 12px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
        table.wnq-leads tr:hover td { background:#f9fafb; }
        .wnq-seo-badge   { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; }
        .seo-high   { background:#fee2e2; color:#dc2626; }
        .seo-medium { background:#fef9c3; color:#b45309; }
        .seo-low    { background:#dcfce7; color:#16a34a; }
        .wnq-status  { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; text-transform:capitalize; }
        .status-new        { background:#dbeafe; color:#1d4ed8; }
        .status-contacted  { background:#fef3c7; color:#b45309; }
        .status-qualified  { background:#d1fae5; color:#065f46; }
        .status-closed     { background:#f3f4f6; color:#6b7280; }
        .wnq-filters     { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:20px; }
        .wnq-filters select, .wnq-filters input { padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; }
        .wnq-pagination  { display:flex; justify-content:space-between; align-items:center; margin-top:16px; font-size:13px; color:#6b7280; }
        .wnq-pagination  .pages { display:flex; gap:4px; }
        .wnq-pagination  a { padding:4px 10px; border:1px solid #e5e7eb; border-radius:5px; text-decoration:none; color:#374151; }
        .wnq-pagination  a.current { background:#2563eb; color:#fff; border-color:#2563eb; }
        .wnq-issues-list { display:flex; flex-wrap:wrap; gap:4px; }
        .wnq-issue-tag   { background:#f3f4f6; color:#374151; font-size:10px; padding:2px 7px; border-radius:10px; white-space:nowrap; }
        .wnq-actions     { display:flex; gap:6px; align-items:center; }
        </style>

        <div class="wnq-lf-header">
            <h1>Lead Finder</h1>
            <span class="wnq-lf-badge">Outbound Sales</span>
        </div>

        <?php /* Stats bar */ ?>
        <div class="wnq-lf-stats">
            <?php foreach ([
                ['num' => $stats['total'],      'lbl' => 'Total Leads'],
                ['num' => $stats['new'],        'lbl' => 'New'],
                ['num' => $stats['contacted'],  'lbl' => 'Contacted'],
                ['num' => $stats['qualified'],  'lbl' => 'Qualified'],
                ['num' => $stats['closed'],     'lbl' => 'Closed'],
                ['num' => $stats['with_email'], 'lbl' => 'Have Email'],
            ] as $s): ?>
                <div class="wnq-stat-box">
                    <div class="num"><?php echo esc_html($s['num']); ?></div>
                    <div class="lbl"><?php echo esc_html($s['lbl']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php /* Tabs */ ?>
        <div class="wnq-lf-tabs">
            <?php foreach (['search' => 'Search', 'leads' => 'All Leads', 'settings' => 'Settings'] as $t => $label): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab=' . $t)); ?>"
                   class="wnq-lf-tab <?php echo $tab === $t ? 'active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php
        match ($tab) {
            'leads'    => self::renderLeadsTab(),
            'settings' => self::renderSettingsTab($settings),
            default    => self::renderSearchTab($settings),
        };
        ?>
        </div><!-- .wnq-lead-finder -->
        <?php
    }

    // ── Tab: Search ──────────────────────────────────────────────────────────

    private static function renderSearchTab(array $settings): void
    {
        $nonce = wp_create_nonce('wnq_lead_search');
        ?>
        <div class="wnq-card">
            <h3>Discover New Prospects</h3>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:20px;font-size:13px;">
                Queries Google Places, filters by reviews and rating, crawls each website for SEO issues, and saves qualified leads automatically.
            </p>

            <div class="wnq-form-row">
                <div class="wnq-field">
                    <label>Industry / Keyword</label>
                    <input type="text" id="lf-keyword" placeholder="e.g. roofing contractor, plumber, HVAC company" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    <small>Describe the type of business you're targeting</small>
                </div>
                <div class="wnq-field">
                    <label>City</label>
                    <input type="text" id="lf-city" placeholder="e.g. Orlando FL, Baltimore MD">
                </div>
            </div>
            <div class="wnq-form-row four">
                <div class="wnq-field">
                    <label>Min Reviews</label>
                    <input type="number" id="lf-min-reviews" value="<?php echo esc_attr($settings['min_reviews'] ?? 20); ?>" min="0">
                </div>
                <div class="wnq-field">
                    <label>Min Rating</label>
                    <input type="number" id="lf-min-rating" value="<?php echo esc_attr($settings['min_rating'] ?? 3.5); ?>" min="0" max="5" step="0.1">
                </div>
                <div class="wnq-field">
                    <label>Min SEO Score</label>
                    <input type="number" id="lf-min-seo" value="<?php echo esc_attr($settings['min_seo_score'] ?? 2); ?>" min="0" max="7">
                    <small>Issues found (higher = worse SEO)</small>
                </div>
                <div class="wnq-field">
                    <label>Max Results</label>
                    <select id="lf-max-results">
                        <option value="20">20</option>
                        <option value="40">40</option>
                        <option value="60" selected>60 (max)</option>
                    </select>
                </div>
            </div>

            <button class="wnq-btn wnq-btn-primary" id="lf-search-btn" onclick="wnqLeadSearch()">
                Search &amp; Qualify Leads
            </button>

            <div class="wnq-progress" id="lf-progress">
                <div class="wnq-spinner"></div>
                <span id="lf-progress-text">Searching Google Places and scoring websites&hellip; This may take 30–90 seconds.</span>
            </div>

            <div id="lf-search-result"></div>
        </div>

        <script>
        function wnqLeadSearch() {
            const keyword = document.getElementById('lf-keyword').value.trim();
            const city    = document.getElementById('lf-city').value.trim();
            if (!keyword || !city) { alert('Please enter both a keyword and a city.'); return; }

            document.getElementById('lf-search-btn').disabled = true;
            document.getElementById('lf-progress').classList.add('show');
            document.getElementById('lf-search-result').innerHTML = '';

            const data = new FormData();
            data.append('action',      'wnq_lead_search');
            data.append('nonce',       '<?php echo esc_js($nonce); ?>');
            data.append('keyword',     keyword);
            data.append('city',        city);
            data.append('min_reviews', document.getElementById('lf-min-reviews').value);
            data.append('min_rating',  document.getElementById('lf-min-rating').value);
            data.append('min_seo',     document.getElementById('lf-min-seo').value);
            data.append('max_results', document.getElementById('lf-max-results').value);

            fetch(ajaxurl, { method:'POST', body: data })
                .then(r => r.json())
                .then(resp => {
                    document.getElementById('lf-progress').classList.remove('show');
                    document.getElementById('lf-search-btn').disabled = false;

                    const el = document.getElementById('lf-search-result');
                    if (resp.success) {
                        const s = resp.data.stats;
                        el.innerHTML = `<div class="wnq-result-box wnq-result-ok">
                            <strong>✓ Search Complete</strong>
                            <ul>
                                <li>Found: <b>${s.found}</b> places on Google</li>
                                <li>Passed review/rating filter: <b>${s.filtered}</b></li>
                                <li>Had a website: <b>${s.filtered - s.no_website}</b></li>
                                <li>Passed SEO score threshold: <b>${s.qualified}</b></li>
                                <li>New leads saved: <b>${s.saved}</b></li>
                                <li>Already in database (skipped): <b>${s.skipped_existing}</b></li>
                            </ul>
                            ${s.saved > 0 ? '<br><a href="admin.php?page=wnq-lead-finder&tab=leads" style="color:#15803d;font-weight:600">→ View saved leads</a>' : ''}
                        </div>`;
                    } else {
                        el.innerHTML = `<div class="wnq-result-box wnq-result-err">
                            <strong>✗ Search Failed</strong>
                            <p>${resp.data?.message || 'Unknown error'}</p>
                        </div>`;
                    }
                })
                .catch(err => {
                    document.getElementById('lf-progress').classList.remove('show');
                    document.getElementById('lf-search-btn').disabled = false;
                    document.getElementById('lf-search-result').innerHTML =
                        `<div class="wnq-result-box wnq-result-err"><strong>✗ Request Error</strong><p>${err.message}</p></div>`;
                });
        }
        </script>
        <?php
    }

    // ── Tab: Leads ───────────────────────────────────────────────────────────

    private static function renderLeadsTab(): void
    {
        $industry = sanitize_text_field($_GET['industry'] ?? '');
        $city     = sanitize_text_field($_GET['city']     ?? '');
        $status   = sanitize_key($_GET['status']          ?? '');
        $min_seo  = isset($_GET['min_seo']) ? max(0, (int)$_GET['min_seo']) : null;
        $has_email= !empty($_GET['has_email']);
        $page     = max(1, (int)($_GET['paged'] ?? 1));
        $per_page = 25;

        $filter_args = array_filter([
            'industry'      => $industry,
            'city'          => $city,
            'status'        => $status,
            'min_seo_score' => $min_seo,
            'has_email'     => $has_email ?: null,
        ], fn($v) => $v !== null && $v !== '');

        $total = Lead::count($filter_args);
        $leads = Lead::getAll(array_merge($filter_args, [
            'limit'   => $per_page,
            'offset'  => ($page - 1) * $per_page,
            'orderby' => sanitize_key($_GET['orderby'] ?? 'scraped_at'),
            'order'   => strtoupper(sanitize_key($_GET['order'] ?? 'DESC')),
        ]));

        $industries = Lead::getDistinctValues('industry');
        $cities     = Lead::getDistinctValues('city');
        $statuses   = ['new', 'contacted', 'qualified', 'closed'];
        $nonce      = wp_create_nonce('wnq_lead_actions');

        $base_url = admin_url('admin.php?page=wnq-lead-finder&tab=leads');
        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=wnq_lead_export_csv' .
                ($industry ? '&industry=' . urlencode($industry) : '') .
                ($city     ? '&city='     . urlencode($city)     : '') .
                ($status   ? '&status='   . urlencode($status)   : '')),
            'wnq_lead_export_csv'
        );

        ?>
        <?php /* Filters */ ?>
        <form method="get" class="wnq-filters">
            <input type="hidden" name="page" value="wnq-lead-finder">
            <input type="hidden" name="tab"  value="leads">
            <select name="industry">
                <option value="">All Industries</option>
                <?php foreach ($industries as $ind): ?>
                    <option value="<?php echo esc_attr($ind); ?>" <?php selected($industry, $ind); ?>><?php echo esc_html($ind); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="city">
                <option value="">All Cities</option>
                <?php foreach ($cities as $c): ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected($city, $c); ?>><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="min_seo" placeholder="Min SEO score" value="<?php echo $min_seo !== null ? esc_attr($min_seo) : ''; ?>" style="width:120px;">
            <label style="font-size:13px;display:flex;align-items:center;gap:5px;">
                <input type="checkbox" name="has_email" value="1" <?php checked($has_email); ?>> Has email
            </label>
            <button type="submit" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Filter</button>
            <a href="<?php echo esc_url($base_url); ?>" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Reset</a>
            <a href="<?php echo esc_url($export_url); ?>" class="wnq-btn wnq-btn-secondary wnq-btn-sm" style="margin-left:auto;">Export CSV</a>
        </form>

        <div class="wnq-card" style="padding:0;overflow:hidden;">
            <?php if (empty($leads)): ?>
                <div style="padding:40px;text-align:center;color:#6b7280;">
                    <p>No leads found. <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab=search')); ?>">Run a search</a> to discover prospects.</p>
                </div>
            <?php else: ?>
            <table class="wnq-leads">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Location</th>
                        <th>Reviews</th>
                        <th>SEO Score</th>
                        <th>SEO Issues</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leads as $lead):
                    $score    = (int)$lead['seo_score'];
                    $seo_cls  = $score >= 5 ? 'seo-high' : ($score >= 3 ? 'seo-medium' : 'seo-low');
                    $stat_cls = 'status-' . esc_attr($lead['status']);
                    $issues   = (array)$lead['seo_issues'];
                ?>
                    <tr id="lead-row-<?php echo (int)$lead['id']; ?>">
                        <td>
                            <strong><?php echo esc_html($lead['business_name']); ?></strong><br>
                            <?php if ($lead['website']): ?>
                                <a href="<?php echo esc_url($lead['website']); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#6b7280;"><?php echo esc_html(parse_url($lead['website'], PHP_URL_HOST) ?: $lead['website']); ?></a>
                            <?php endif; ?>
                            <?php if ($lead['phone']): ?>
                                <br><span style="font-size:11px;color:#6b7280;"><?php echo esc_html($lead['phone']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html($lead['city']); ?><br>
                            <span style="font-size:11px;color:#6b7280;"><?php echo esc_html($lead['industry']); ?></span>
                        </td>
                        <td>
                            <strong><?php echo esc_html($lead['review_count']); ?></strong><br>
                            <span style="font-size:11px;color:#6b7280;">★ <?php echo esc_html($lead['rating']); ?></span>
                        </td>
                        <td>
                            <span class="wnq-seo-badge <?php echo $seo_cls; ?>"><?php echo $score; ?>/7</span>
                        </td>
                        <td>
                            <div class="wnq-issues-list">
                                <?php foreach ($issues as $issue): ?>
                                    <span class="wnq-issue-tag" title="<?php echo esc_attr(\WNQ\Services\LeadSEOScorer::issueLabel($issue)); ?>">
                                        <?php echo esc_html(\WNQ\Services\LeadSEOScorer::issueLabel($issue)); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($lead['email']): ?>
                                <a href="mailto:<?php echo esc_attr($lead['email']); ?>" style="font-size:12px;"><?php echo esc_html($lead['email']); ?></a>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select class="wnq-status-select" data-id="<?php echo (int)$lead['id']; ?>" style="font-size:12px;padding:4px 8px;border-radius:6px;border:1px solid #d1d5db;">
                                <?php foreach (['new', 'contacted', 'qualified', 'closed'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php selected($lead['status'], $s); ?>><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <div class="wnq-actions">
                                <?php if ($lead['website']): ?>
                                    <a href="<?php echo esc_url($lead['website']); ?>" target="_blank" class="wnq-btn wnq-btn-secondary wnq-btn-sm" title="View site">↗</a>
                                <?php endif; ?>
                                <button class="wnq-btn wnq-btn-danger wnq-btn-sm" onclick="wnqDeleteLead(<?php echo (int)$lead['id']; ?>)" title="Delete">✕</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php /* Pagination */ ?>
        <?php if ($total > $per_page): ?>
        <div class="wnq-pagination">
            <span>Showing <?php echo esc_html(($page - 1) * $per_page + 1); ?>–<?php echo esc_html(min($page * $per_page, $total)); ?> of <?php echo esc_html($total); ?> leads</span>
            <div class="pages">
                <?php
                $total_pages = (int)ceil($total / $per_page);
                for ($p = 1; $p <= $total_pages; $p++):
                    $page_url = add_query_arg(['paged' => $p], $base_url);
                ?>
                    <a href="<?php echo esc_url($page_url); ?>" class="<?php echo $p === $page ? 'current' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <script>
        (function() {
            const nonce = '<?php echo esc_js($nonce); ?>';

            // Status change
            document.querySelectorAll('.wnq-status-select').forEach(sel => {
                sel.addEventListener('change', function() {
                    const id     = this.dataset.id;
                    const status = this.value;
                    const data   = new FormData();
                    data.append('action', 'wnq_lead_update_status');
                    data.append('nonce',  nonce);
                    data.append('id',     id);
                    data.append('status', status);
                    fetch(ajaxurl, { method:'POST', body: data });
                });
            });

            window.wnqDeleteLead = function(id) {
                if (!confirm('Delete this lead? This cannot be undone.')) return;
                const data = new FormData();
                data.append('action', 'wnq_lead_delete');
                data.append('nonce',  nonce);
                data.append('id',     id);
                fetch(ajaxurl, { method:'POST', body: data }).then(() => {
                    const row = document.getElementById('lead-row-' + id);
                    if (row) row.remove();
                });
            };
        })();
        </script>
        <?php
    }

    // ── Tab: Settings ────────────────────────────────────────────────────────

    private static function renderSettingsTab(array $settings): void
    {
        $action_url  = esc_url(admin_url('admin-post.php'));
        $test_nonce  = wp_create_nonce('wnq_lead_test_api');
        ?>
        <form method="post" action="<?php echo $action_url; ?>">
            <?php wp_nonce_field('wnq_lead_save_settings', 'wnq_nonce'); ?>
            <input type="hidden" name="action" value="wnq_lead_save_settings">

            <div class="wnq-card">
                <h3>Google Places API</h3>
                <div class="wnq-form-row">
                    <div class="wnq-field">
                        <label>API Key</label>
                        <input type="password" name="google_places_key" value="<?php echo esc_attr($settings['google_places_key'] ?? ''); ?>" placeholder="AIza...">
                        <small>
                            Get your key from <a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank">Google Cloud Console</a>.
                            Enable: <strong>Places API</strong>. Billing must be active (~$17 per 1,000 Place Details calls).
                        </small>
                    </div>
                    <div class="wnq-field" style="justify-content:flex-end;">
                        <button type="button" class="wnq-btn wnq-btn-secondary" onclick="wnqTestApi()">Test API Key</button>
                        <div id="api-test-result" style="margin-top:8px;font-size:13px;"></div>
                    </div>
                </div>
            </div>

            <div class="wnq-card">
                <h3>Daily Automation</h3>
                <div class="wnq-form-row">
                    <div class="wnq-field">
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                            &nbsp;Enable daily cron (runs at 9am, one industry + city per day)
                        </label>
                    </div>
                </div>
                <div class="wnq-form-row">
                    <div class="wnq-field">
                        <label>Target Industries (one per line)</label>
                        <textarea name="target_industries" rows="6" placeholder="roofing contractor&#10;plumber&#10;HVAC company&#10;electrician&#10;landscaping company"><?php echo esc_textarea($settings['target_industries'] ?? ''); ?></textarea>
                    </div>
                    <div class="wnq-field">
                        <label>Target Cities (one per line)</label>
                        <textarea name="target_cities" rows="6" placeholder="Baltimore MD&#10;Orlando FL&#10;Charlotte NC&#10;Richmond VA"><?php echo esc_textarea($settings['target_cities'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="wnq-card">
                <h3>Default Qualification Filters</h3>
                <div class="wnq-form-row three">
                    <div class="wnq-field">
                        <label>Min Google Reviews</label>
                        <input type="number" name="min_reviews" value="<?php echo esc_attr($settings['min_reviews'] ?? 20); ?>" min="0">
                        <small>Minimum review count to consider a business established</small>
                    </div>
                    <div class="wnq-field">
                        <label>Min Rating</label>
                        <input type="number" name="min_rating" value="<?php echo esc_attr($settings['min_rating'] ?? 3.5); ?>" min="0" max="5" step="0.1">
                        <small>Skip businesses with very poor reputations</small>
                    </div>
                    <div class="wnq-field">
                        <label>Min SEO Score</label>
                        <input type="number" name="min_seo_score" value="<?php echo esc_attr($settings['min_seo_score'] ?? 2); ?>" min="0" max="7">
                        <small>Issues found (1–7). Higher = more SEO problems = better prospect</small>
                    </div>
                </div>
            </div>

            <button type="submit" class="wnq-btn wnq-btn-primary">Save Settings</button>
        </form>

        <script>
        function wnqTestApi() {
            const btn = document.querySelector('[onclick="wnqTestApi()"]');
            btn.disabled = true;
            btn.textContent = 'Testing…';
            const data = new FormData();
            data.append('action', 'wnq_lead_test_api');
            data.append('nonce',  '<?php echo esc_js($test_nonce); ?>');
            fetch(ajaxurl, { method:'POST', body: data })
                .then(r => r.json())
                .then(resp => {
                    btn.disabled   = false;
                    btn.textContent = 'Test API Key';
                    const el = document.getElementById('api-test-result');
                    if (resp.success) {
                        el.innerHTML = '<span style="color:#16a34a;">✓ ' + resp.data.message + '</span>';
                    } else {
                        el.innerHTML = '<span style="color:#dc2626;">✗ ' + (resp.data?.message || 'Failed') + '</span>';
                    }
                });
        }
        </script>

        <?php if (!empty($_GET['settings_saved'])): ?>
            <div class="notice notice-success is-dismissible" style="margin-top:16px;"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php
    }

    // ── AJAX Handlers ────────────────────────────────────────────────────────

    public static function ajaxSearch(): void
    {
        check_ajax_referer('wnq_lead_search', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $result = LeadFinderEngine::runSearch([
            'keyword'       => sanitize_text_field($_POST['keyword']      ?? ''),
            'city'          => sanitize_text_field($_POST['city']         ?? ''),
            'min_reviews'   => (int)($_POST['min_reviews']   ?? 20),
            'min_rating'    => (float)($_POST['min_rating']  ?? 3.5),
            'min_seo_score' => (int)($_POST['min_seo']       ?? 2),
            'max_results'   => (int)($_POST['max_results']   ?? 60),
        ]);

        if ($result['ok']) {
            wp_send_json_success(['stats' => $result['stats']]);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? 'Search failed']);
        }
    }

    public static function ajaxUpdateStatus(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $id     = (int)($_POST['id']     ?? 0);
        $status = sanitize_key($_POST['status'] ?? '');

        if (!$id || !in_array($status, ['new', 'contacted', 'qualified', 'closed'], true)) {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }

        Lead::updateStatus($id, $status);
        wp_send_json_success();
    }

    public static function ajaxDelete(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'Invalid ID']);
        }

        Lead::delete($id);
        wp_send_json_success();
    }

    public static function ajaxTestApi(): void
    {
        check_ajax_referer('wnq_lead_test_api', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $result = PlacesAPIClient::testApiKey();
        if ($result['ok']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    // ── admin_post Handlers ──────────────────────────────────────────────────

    public static function handleExportCsv(): void
    {
        check_admin_referer('wnq_lead_export_csv');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        $args = array_filter([
            'industry' => sanitize_text_field($_GET['industry'] ?? ''),
            'city'     => sanitize_text_field($_GET['city']     ?? ''),
            'status'   => sanitize_key($_GET['status']          ?? ''),
        ]);

        Lead::exportCsv($args);
    }

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wnq_lead_save_settings', 'wnq_nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        update_option('wnq_lead_finder_settings', [
            'google_places_key' => sanitize_text_field($_POST['google_places_key'] ?? ''),
            'enabled'           => !empty($_POST['enabled']) ? 1 : 0,
            'target_industries' => sanitize_textarea_field($_POST['target_industries'] ?? ''),
            'target_cities'     => sanitize_textarea_field($_POST['target_cities']     ?? ''),
            'min_reviews'       => max(0, (int)($_POST['min_reviews']   ?? 20)),
            'min_rating'        => max(0.0, min(5.0, (float)($_POST['min_rating'] ?? 3.5))),
            'min_seo_score'     => max(0, min(7, (int)($_POST['min_seo_score'] ?? 2))),
        ]);

        wp_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=settings&settings_saved=1'));
        exit;
    }
}
