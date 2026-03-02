<?php
/**
 * Lead Finder Admin UI
 *
 * Registers the "Lead Finder" admin page under the WebNique Portal menu.
 * Provides three tabs:
 *   Search   — run a Google Places search to discover + qualify prospects
 *   Leads    — full table with all fields, export to GHL-compatible CSV
 *   Settings — API key, target industries/cities, filters, cron toggle
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\Lead;
use WNQ\Services\LeadFinderEngine;
use WNQ\Services\LeadSEOScorer;
use WNQ\Services\PlacesAPIClient;

final class LeadFinderAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        // Queue-based search (Phase 1: Places API only, returns immediately)
        add_action('wp_ajax_wnq_lead_queue_search',    [self::class, 'ajaxQueueSearch']);
        // Queue-based processing (Phase 2: process one candidate per call)
        add_action('wp_ajax_wnq_lead_process_next',    [self::class, 'ajaxProcessNext']);
        add_action('wp_ajax_wnq_lead_update_status',   [self::class, 'ajaxUpdateStatus']);
        add_action('wp_ajax_wnq_lead_update_notes',    [self::class, 'ajaxUpdateNotes']);
        add_action('wp_ajax_wnq_lead_delete',          [self::class, 'ajaxDelete']);
        add_action('wp_ajax_wnq_lead_test_api',        [self::class, 'ajaxTestApi']);
        add_action('admin_post_wnq_lead_export_csv',    [self::class, 'handleExportCsv']);
        add_action('admin_post_wnq_lead_save_settings', [self::class, 'handleSaveSettings']);
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
        <div class="wrap wnq-lf">
        <style>
        .wnq-lf { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .wnq-lf-header { display:flex;align-items:center;gap:12px;margin-bottom:20px; }
        .wnq-lf-header h1 { margin:0;font-size:24px;font-weight:700; }
        .wnq-lf-badge { background:#2563eb;color:#fff;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:600; }
        .wnq-lf-stats { display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px; }
        .wnq-stat { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 18px;text-align:center;min-width:100px; }
        .wnq-stat .num { font-size:26px;font-weight:700;color:#1e293b;line-height:1; }
        .wnq-stat .lbl { font-size:10px;color:#6b7280;margin-top:3px;text-transform:uppercase;letter-spacing:.5px; }
        .wnq-lf-tabs { display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:20px; }
        .wnq-lf-tab { padding:10px 20px;cursor:pointer;font-size:14px;font-weight:500;color:#6b7280;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none; }
        .wnq-lf-tab.active { color:#2563eb;border-bottom-color:#2563eb; }
        .wnq-card { background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px;margin-bottom:16px; }
        .wnq-card h3 { margin:0 0 14px;font-size:15px;font-weight:600; }
        .wnq-row2 { display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px; }
        .wnq-row3 { display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px; }
        .wnq-row4 { display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-bottom:14px; }
        .wnq-field { display:flex;flex-direction:column;gap:4px; }
        .wnq-field label { font-size:12px;font-weight:600;color:#374151; }
        .wnq-field input,.wnq-field select,.wnq-field textarea { padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:100%;box-sizing:border-box; }
        .wnq-field textarea { resize:vertical;min-height:90px; }
        .wnq-field small { color:#6b7280;font-size:11px; }
        .wnq-btn { padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none; }
        .wnq-btn-primary { background:#2563eb;color:#fff; }
        .wnq-btn-primary:hover { background:#1d4ed8; }
        .wnq-btn-secondary { background:#f3f4f6;color:#374151;border:1px solid #d1d5db; }
        .wnq-btn-secondary:hover { background:#e5e7eb; }
        .wnq-btn-danger { background:#dc2626;color:#fff; }
        .wnq-btn-sm { padding:4px 10px;font-size:11px; }
        .wnq-progress { display:none;align-items:center;gap:10px;padding:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-top:14px;color:#1d4ed8;font-weight:500; }
        .wnq-progress.show { display:flex; }
        .wnq-spinner { width:18px;height:18px;border:3px solid #bfdbfe;border-top-color:#2563eb;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .wnq-result { padding:14px;border-radius:8px;margin-top:14px; }
        .wnq-result-ok  { background:#f0fdf4;border:1px solid #86efac;color:#15803d; }
        .wnq-result-err { background:#fef2f2;border:1px solid #fca5a5;color:#dc2626; }
        .wnq-result strong { display:block;font-size:14px;margin-bottom:6px; }
        .wnq-result ul { margin:0 0 0 18px; }
        /* Leads Table */
        .wnq-filters { display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px; }
        .wnq-filters select,.wnq-filters input { padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px; }
        .wnq-tbl-wrap { overflow-x:auto; }
        table.wnq-tbl { width:100%;border-collapse:collapse;font-size:12px;min-width:1200px; }
        table.wnq-tbl th { background:#f9fafb;padding:9px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;white-space:nowrap; }
        table.wnq-tbl td { padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top; }
        table.wnq-tbl tr:hover td { background:#fafafa; }
        .wnq-seo-badge { display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700; }
        .seo-high   { background:#fee2e2;color:#dc2626; }
        .seo-med    { background:#fef9c3;color:#b45309; }
        .seo-low    { background:#dcfce7;color:#16a34a; }
        .wnq-status { display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;text-transform:capitalize; }
        .st-new        { background:#dbeafe;color:#1d4ed8; }
        .st-contacted  { background:#fef3c7;color:#b45309; }
        .st-qualified  { background:#d1fae5;color:#065f46; }
        .st-closed     { background:#f3f4f6;color:#6b7280; }
        .wnq-issues { display:flex;flex-wrap:wrap;gap:3px; }
        .wnq-issue  { background:#f3f4f6;color:#374151;font-size:9px;padding:1px 5px;border-radius:8px;white-space:nowrap; }
        .wnq-social { display:flex;gap:4px;flex-wrap:wrap; }
        .wnq-social a { display:inline-block;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;text-decoration:none; }
        .s-fb { background:#1877f2;color:#fff; }
        .s-ig { background:#e1306c;color:#fff; }
        .s-li { background:#0a66c2;color:#fff; }
        .s-tw { background:#1da1f2;color:#fff; }
        .s-yt { background:#ff0000;color:#fff; }
        .s-tt { background:#010101;color:#fff; }
        .wnq-paginate { display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:12px;color:#6b7280; }
        .wnq-paginate .pages { display:flex;gap:3px; }
        .wnq-paginate a { padding:3px 8px;border:1px solid #e5e7eb;border-radius:4px;text-decoration:none;color:#374151; }
        .wnq-paginate a.cur { background:#2563eb;color:#fff;border-color:#2563eb; }
        .wnq-notes-edit { width:140px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11px; }
        .wnq-status-sel { font-size:11px;padding:3px 6px;border-radius:5px;border:1px solid #d1d5db; }
        </style>

        <div class="wnq-lf-header">
            <h1>Lead Finder</h1>
            <span class="wnq-lf-badge">Outbound Sales</span>
        </div>

        <div class="wnq-lf-stats">
            <?php foreach ([
                ['num' => $stats['total'],      'lbl' => 'Total'],
                ['num' => $stats['new'],        'lbl' => 'New'],
                ['num' => $stats['contacted'],  'lbl' => 'Contacted'],
                ['num' => $stats['qualified'],  'lbl' => 'Qualified'],
                ['num' => $stats['closed'],     'lbl' => 'Closed'],
                ['num' => $stats['with_email'], 'lbl' => 'Have Email'],
                ['num' => $stats['with_owner'], 'lbl' => 'Have Owner'],
            ] as $s): ?>
                <div class="wnq-stat">
                    <div class="num"><?php echo esc_html($s['num']); ?></div>
                    <div class="lbl"><?php echo esc_html($s['lbl']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

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
        </div>
        <?php
    }

    // ── Tab: Search ──────────────────────────────────────────────────────────

    private static function renderSearchTab(array $settings): void
    {
        $nonce = wp_create_nonce('wnq_lead_queue_search');
        ?>
        <style>
        .wnq-mode-toggle { display:flex;gap:0;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;width:fit-content;margin-bottom:16px; }
        .wnq-mode-toggle button { padding:7px 18px;border:none;background:#f9fafb;font-size:13px;font-weight:500;color:#6b7280;cursor:pointer;border-right:1px solid #d1d5db; }
        .wnq-mode-toggle button:last-child { border-right:none; }
        .wnq-mode-toggle button.active { background:#2563eb;color:#fff; }
        .wnq-progressbar-wrap { background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;margin:8px 0; }
        .wnq-progressbar      { height:100%;background:#2563eb;border-radius:6px;transition:width .3s ease;width:0%; }
        .wnq-live-stats { display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#374151;margin-top:8px; }
        .wnq-live-stats span { display:flex;align-items:center;gap:4px; }
        .wnq-live-stats b { font-size:15px;font-weight:700; }
        .wnq-combo-row { display:flex;gap:6px;align-items:center;margin-bottom:6px; }
        .wnq-combo-row input { flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px; }
        .wnq-combo-row button { padding:5px 10px;border-radius:5px;border:1px solid #d1d5db;background:#f3f4f6;cursor:pointer;font-size:12px; }
        </style>

        <div class="wnq-card">
            <h3>Discover New Prospects</h3>
            <p style="color:#6b7280;margin:-6px 0 14px;font-size:12px;">
                Phase 1 queries Google Places (returns in seconds). Phase 2 crawls each site for SEO issues, owner name, email &amp; social media — processed one at a time with live progress so it never times out.
                Franchises and duplicates are automatically filtered.
            </p>

            <div class="wnq-mode-toggle">
                <button class="active" id="mode-single" onclick="wnqSetMode('single')">Single Search</button>
                <button id="mode-bulk"  onclick="wnqSetMode('bulk')">Bulk Mode</button>
            </div>

            <?php /* ── Single search form ── */ ?>
            <div id="lf-single-form">
                <div class="wnq-row2">
                    <div class="wnq-field">
                        <label>Industry / Keyword</label>
                        <input type="text" id="lf-keyword" placeholder="e.g. roofing contractor, plumber, HVAC company" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    </div>
                    <div class="wnq-field">
                        <label>City</label>
                        <input type="text" id="lf-city" placeholder="e.g. Orlando FL, Baltimore MD">
                    </div>
                </div>
            </div>

            <?php /* ── Bulk search form ── */ ?>
            <div id="lf-bulk-form" style="display:none;">
                <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">
                    One search per line: <strong>keyword | city</strong>. Each runs sequentially — results accumulate in real time.
                </p>
                <textarea id="lf-bulk-lines" style="width:100%;height:140px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace;box-sizing:border-box;"
                    placeholder="roofing contractor | Baltimore MD&#10;plumber | Charlotte NC&#10;HVAC company | Orlando FL&#10;electrician | Richmond VA&#10;landscaping | Tampa FL"></textarea>
            </div>

            <div class="wnq-row4" style="margin-top:14px;">
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
                    <small>0–7 issues. 2+ = real SEO gaps.</small>
                </div>
                <div class="wnq-field">
                    <label>Max per Search</label>
                    <select id="lf-max-results">
                        <option value="20">20</option>
                        <option value="40">40</option>
                        <option value="60" selected>60 (max)</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:4px;">
                <button class="wnq-btn wnq-btn-primary" id="lf-start-btn" onclick="wnqStartSearch()">
                    Start &amp; Qualify Leads
                </button>
                <button class="wnq-btn wnq-btn-secondary" id="lf-stop-btn" onclick="wnqStop()" style="display:none;">
                    Stop
                </button>
            </div>

            <?php /* ── Live progress area ── */ ?>
            <div id="lf-progress-area" style="display:none;margin-top:16px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#374151;margin-bottom:4px;">
                    <span id="lf-progress-label">Starting…</span>
                    <span id="lf-progress-pct">0%</span>
                </div>
                <div class="wnq-progressbar-wrap"><div class="wnq-progressbar" id="lf-bar"></div></div>
                <div class="wnq-live-stats">
                    <span>Found: <b id="ls-found">0</b></span>
                    <span>Saved: <b id="ls-saved" style="color:#16a34a;">0</b></span>
                    <span>Franchise skip: <b id="ls-franchise">0</b></span>
                    <span>Duplicate: <b id="ls-duplicate">0</b></span>
                    <span>No website: <b id="ls-noweb">0</b></span>
                    <span>Low SEO: <b id="ls-lowseo">0</b></span>
                </div>
            </div>

            <div id="lf-result" style="margin-top:12px;"></div>
        </div>

        <script>
        (function() {
            let _stopped   = false;
            let _mode      = 'single';
            let _totSaved  = 0;

            window.wnqSetMode = function(m) {
                _mode = m;
                document.getElementById('lf-single-form').style.display = m === 'single' ? '' : 'none';
                document.getElementById('lf-bulk-form').style.display   = m === 'bulk'   ? '' : 'none';
                document.getElementById('mode-single').classList.toggle('active', m === 'single');
                document.getElementById('mode-bulk').classList.toggle('active',   m === 'bulk');
            };

            window.wnqStop = function() { _stopped = true; };

            window.wnqStartSearch = function() {
                _stopped  = false;
                _totSaved = 0;

                const minReviews = document.getElementById('lf-min-reviews').value;
                const minRating  = document.getElementById('lf-min-rating').value;
                const minSeo     = document.getElementById('lf-min-seo').value;
                const maxResults = document.getElementById('lf-max-results').value;

                let combos = [];
                if (_mode === 'single') {
                    const kw   = document.getElementById('lf-keyword').value.trim();
                    const city = document.getElementById('lf-city').value.trim();
                    if (!kw || !city) { alert('Enter both a keyword and a city.'); return; }
                    combos = [{ keyword: kw, city }];
                } else {
                    const lines = document.getElementById('lf-bulk-lines').value.trim().split('\n');
                    for (const line of lines) {
                        const [kw, city] = line.split('|').map(s => s.trim());
                        if (kw && city) combos.push({ keyword: kw, city });
                    }
                    if (!combos.length) { alert('Enter at least one keyword | city line.'); return; }
                }

                document.getElementById('lf-start-btn').disabled = true;
                document.getElementById('lf-stop-btn').style.display = '';
                document.getElementById('lf-progress-area').style.display = '';
                document.getElementById('lf-result').innerHTML = '';
                wnqResetStats();

                // Cumulative stats across all combos (each batch resets its own stats)
                let _cumStats = { saved: 0, franchise: 0, duplicate: 0, no_website: 0, low_seo: 0 };
                let _lastBatchStats = null;

                function absorbLastBatch() {
                    if (!_lastBatchStats) return;
                    _cumStats.saved      += _lastBatchStats.saved;
                    _cumStats.franchise  += _lastBatchStats.franchise;
                    _cumStats.duplicate  += _lastBatchStats.duplicate;
                    _cumStats.no_website += _lastBatchStats.no_website;
                    _cumStats.low_seo    += _lastBatchStats.low_seo;
                    _lastBatchStats = null;
                }

                (async function runAllCombos() {
                    for (let i = 0; i < combos.length && !_stopped; i++) {
                        const { keyword, city } = combos[i];
                        wnqSetProgressLabel(`Combo ${i+1}/${combos.length}: queuing "${keyword}" in "${city}"…`);

                        // Phase 1: queue the search (Places API only — fast)
                        let queueResp;
                        try {
                            const fd = new FormData();
                            fd.append('action',      'wnq_lead_queue_search');
                            fd.append('nonce',       '<?php echo esc_js($nonce); ?>');
                            fd.append('keyword',     keyword);
                            fd.append('city',        city);
                            fd.append('max_results', maxResults);
                            const r = await fetch(ajaxurl, { method:'POST', body: fd });
                            queueResp = await r.json();
                        } catch(e) {
                            wnqShowError(`Network error queuing "${keyword} | ${city}": ${e.message}`);
                            break;
                        }

                        if (!queueResp.success) {
                            wnqShowError(`${queueResp.data?.message || 'Queue failed'} — skipping "${keyword} | ${city}".`);
                            continue;
                        }

                        const { batch_id, total } = queueResp.data;
                        // Accumulate found count from all combos
                        document.getElementById('ls-found').textContent =
                            parseInt(document.getElementById('ls-found').textContent || 0) + (total || 0);

                        if (!batch_id || total === 0) {
                            continue;
                        }

                        // Phase 2: process one candidate at a time
                        let comboProgress = 0;
                        while (comboProgress < total && !_stopped) {
                            wnqSetProgressLabel(`Combo ${i+1}/${combos.length}: "${keyword}" in "${city}" — ${comboProgress}/${total} candidates…`);

                            let procResp;
                            try {
                                const fd2 = new FormData();
                                fd2.append('action',      'wnq_lead_process_next');
                                fd2.append('nonce',       '<?php echo esc_js($nonce); ?>');
                                fd2.append('batch_id',    batch_id);
                                fd2.append('min_reviews', minReviews);
                                fd2.append('min_rating',  minRating);
                                fd2.append('min_seo',     minSeo);
                                const r2 = await fetch(ajaxurl, { method:'POST', body: fd2 });
                                procResp = await r2.json();
                            } catch(e) {
                                wnqShowError(`Network error during processing: ${e.message}`);
                                _stopped = true;
                                break;
                            }

                            if (!procResp.success) {
                                wnqShowError(procResp.data?.message || 'Processing error');
                                _stopped = true;
                                break;
                            }

                            const d = procResp.data;
                            comboProgress    = d.progress;
                            _lastBatchStats  = d.stats;

                            // Display = cumulative from finished combos + current batch
                            wnqUpdateLiveStats(d.stats);
                            wnqSetProgress(d.progress, d.total);

                            if (d.done) break;
                        }

                        // Fold this combo's final stats into the cumulative totals
                        absorbLastBatch();
                    }

                    // ── All combos done ──
                    document.getElementById('lf-start-btn').disabled = false;
                    document.getElementById('lf-stop-btn').style.display = 'none';
                    const saved = _cumStats.saved;

                    if (!_stopped) {
                        wnqSetProgressLabel('Complete');
                        wnqSetProgress(1, 1);
                        document.getElementById('lf-result').innerHTML =
                            `<div class="wnq-result wnq-result-ok">
                                <strong>All searches complete</strong>
                                <p><b>${saved}</b> new lead${saved !== 1 ? 's' : ''} saved across ${combos.length} search${combos.length !== 1 ? 'es' : ''}.
                                ${saved > 0 ? '<br><a href="admin.php?page=wnq-lead-finder&tab=leads" style="color:#15803d;font-weight:600">View leads &rarr;</a>' : ''}</p>
                            </div>`;
                    } else {
                        wnqSetProgressLabel('Stopped');
                        document.getElementById('lf-result').innerHTML =
                            `<div class="wnq-result" style="background:#fef9c3;border:1px solid #fde68a;color:#92400e;">
                                <strong>Search stopped</strong>
                                <p>${saved} lead${saved !== 1 ? 's' : ''} saved so far. You can run another search to continue.</p>
                            </div>`;
                    }
                })();
            };

            // ── Helpers ──────────────────────────────────────────────────────

            function wnqResetStats() {
                ['ls-found','ls-saved','ls-franchise','ls-duplicate','ls-noweb','ls-lowseo'].forEach(id => {
                    document.getElementById(id).textContent = '0';
                });
                wnqSetProgress(0, 1);
            }

            function wnqUpdateLiveStats(s) {
                // Show cumulative from finished combos + current batch-in-progress
                document.getElementById('ls-saved').textContent     = _cumStats.saved      + s.saved;
                document.getElementById('ls-franchise').textContent = _cumStats.franchise   + s.franchise;
                document.getElementById('ls-duplicate').textContent = _cumStats.duplicate   + s.duplicate;
                document.getElementById('ls-noweb').textContent     = _cumStats.no_website  + s.no_website;
                document.getElementById('ls-lowseo').textContent    = _cumStats.low_seo     + s.low_seo;
            }

            function wnqSetProgress(current, total) {
                const pct = total > 0 ? Math.round((current / total) * 100) : 0;
                document.getElementById('lf-bar').style.width = pct + '%';
                document.getElementById('lf-progress-pct').textContent = pct + '%';
            }

            function wnqSetProgressLabel(msg) {
                document.getElementById('lf-progress-label').textContent = msg;
            }

            function wnqShowError(msg) {
                document.getElementById('lf-result').innerHTML +=
                    `<div class="wnq-result wnq-result-err" style="margin-bottom:6px;"><strong>Error</strong> — ${msg}</div>`;
            }
        })();
        </script>
        <?php
    }

    // ── Tab: Leads ───────────────────────────────────────────────────────────

    private static function renderLeadsTab(): void
    {
        // Filters
        $f_industry = sanitize_text_field($_GET['industry'] ?? '');
        $f_city     = sanitize_text_field($_GET['city']     ?? '');
        $f_state    = sanitize_text_field($_GET['state']    ?? '');
        $f_status   = sanitize_key($_GET['status']          ?? '');
        $f_min_seo  = isset($_GET['min_seo']) ? max(0, (int)$_GET['min_seo']) : null;
        $f_email    = !empty($_GET['has_email']);
        $f_owner    = !empty($_GET['has_owner']);
        $page       = max(1, (int)($_GET['paged'] ?? 1));
        $per_page   = 50;

        $filter_args = array_filter([
            'industry'      => $f_industry,
            'city'          => $f_city,
            'state'         => $f_state,
            'status'        => $f_status,
            'min_seo_score' => $f_min_seo,
            'has_email'     => $f_email  ?: null,
            'has_owner'     => $f_owner  ?: null,
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
        $states     = Lead::getDistinctValues('state');
        $nonce      = wp_create_nonce('wnq_lead_actions');
        $base_url   = admin_url('admin.php?page=wnq-lead-finder&tab=leads');

        $export_params = array_merge(
            ['action' => 'wnq_lead_export_csv'],
            $f_industry ? ['industry' => $f_industry] : [],
            $f_city     ? ['city'     => $f_city]     : [],
            $f_state    ? ['state'    => $f_state]     : [],
            $f_status   ? ['status'   => $f_status]   : []
        );
        $export_url = wp_nonce_url(
            admin_url('admin-post.php?' . http_build_query($export_params)),
            'wnq_lead_export_csv'
        );
        ?>

        <form method="get" class="wnq-filters">
            <input type="hidden" name="page" value="wnq-lead-finder">
            <input type="hidden" name="tab"  value="leads">
            <select name="industry"><option value="">All Industries</option>
                <?php foreach ($industries as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($f_industry, $v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
            </select>
            <select name="city"><option value="">All Cities</option>
                <?php foreach ($cities as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($f_city, $v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
            </select>
            <select name="state"><option value="">All States</option>
                <?php foreach ($states as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($f_state, $v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
            </select>
            <select name="status"><option value="">All Statuses</option>
                <?php foreach (['new', 'contacted', 'qualified', 'closed'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php selected($f_status, $s); ?>><?php echo ucfirst($s); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="min_seo" placeholder="Min SEO" value="<?php echo $f_min_seo !== null ? esc_attr($f_min_seo) : ''; ?>" style="width:80px;">
            <label style="font-size:12px;display:flex;align-items:center;gap:4px;white-space:nowrap;">
                <input type="checkbox" name="has_email" value="1" <?php checked($f_email); ?>> Email
            </label>
            <label style="font-size:12px;display:flex;align-items:center;gap:4px;white-space:nowrap;">
                <input type="checkbox" name="has_owner" value="1" <?php checked($f_owner); ?>> Owner
            </label>
            <button type="submit" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Filter</button>
            <a href="<?php echo esc_url($base_url); ?>" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Reset</a>
            <a href="<?php echo esc_url($export_url); ?>" class="wnq-btn wnq-btn-primary wnq-btn-sm" style="margin-left:auto;">
                Export GHL CSV (<?php echo esc_html($total); ?>)
            </a>
        </form>

        <div class="wnq-card" style="padding:0;overflow:hidden;">
            <?php if (empty($leads)): ?>
                <div style="padding:40px;text-align:center;color:#6b7280;">
                    No leads match your filters.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab=search')); ?>">Run a search</a> to discover prospects.
                </div>
            <?php else: ?>
            <div class="wnq-tbl-wrap">
            <table class="wnq-tbl">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Industry</th>
                        <th>Owner</th>
                        <th>Website</th>
                        <th>Address</th>
                        <th>Location</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Stars / Reviews</th>
                        <th>Social</th>
                        <th>SEO Score</th>
                        <th>SEO Issues</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leads as $lead):
                    $score   = (int)$lead['seo_score'];
                    $seo_cls = $score >= 5 ? 'seo-high' : ($score >= 3 ? 'seo-med' : 'seo-low');
                    $st_cls  = 'st-' . esc_attr($lead['status'] ?: 'new');
                    $issues  = (array)$lead['seo_issues'];
                    $owner   = trim($lead['owner_first'] . ' ' . $lead['owner_last']);
                    $location = trim($lead['city'] . ($lead['state'] ? ', ' . $lead['state'] : ''));
                ?>
                    <tr id="lr-<?php echo (int)$lead['id']; ?>">
                        <td><strong><?php echo esc_html($lead['business_name']); ?></strong></td>
                        <td><?php echo esc_html($lead['industry']); ?></td>
                        <td><?php echo $owner ? esc_html($owner) : '<span style="color:#9ca3af">—</span>'; ?></td>
                        <td>
                            <?php if ($lead['website']): ?>
                                <a href="<?php echo esc_url($lead['website']); ?>" target="_blank" rel="noopener" style="color:#2563eb;font-size:11px;">
                                    <?php echo esc_html(parse_url($lead['website'], PHP_URL_HOST) ?: $lead['website']); ?>
                                </a>
                            <?php else: ?><span style="color:#9ca3af">—</span><?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:#374151;max-width:150px;"><?php echo esc_html($lead['address'] ?: '—'); ?></td>
                        <td style="white-space:nowrap;"><?php echo esc_html($location ?: '—'); ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($lead['phone']): ?>
                                <a href="tel:<?php echo esc_attr(preg_replace('/\D/', '', $lead['phone'])); ?>" style="color:#374151;font-size:11px;"><?php echo esc_html($lead['phone']); ?></a>
                            <?php else: ?><span style="color:#9ca3af">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($lead['email']): ?>
                                <a href="mailto:<?php echo esc_attr($lead['email']); ?>" style="font-size:11px;color:#2563eb;"><?php echo esc_html($lead['email']); ?></a>
                            <?php else: ?><span style="color:#9ca3af">—</span><?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <span style="color:#f59e0b;">&#9733;</span> <?php echo esc_html($lead['rating']); ?>
                            <span style="color:#6b7280;font-size:10px;">(<?php echo esc_html(number_format((int)$lead['review_count'])); ?>)</span>
                        </td>
                        <td>
                            <div class="wnq-social">
                                <?php if ($lead['social_facebook']):  ?><a href="<?php echo esc_url($lead['social_facebook']);  ?>" target="_blank" rel="noopener" class="s-fb">FB</a><?php endif; ?>
                                <?php if ($lead['social_instagram']): ?><a href="<?php echo esc_url($lead['social_instagram']); ?>" target="_blank" rel="noopener" class="s-ig">IG</a><?php endif; ?>
                                <?php if ($lead['social_linkedin']):  ?><a href="<?php echo esc_url($lead['social_linkedin']);  ?>" target="_blank" rel="noopener" class="s-li">in</a><?php endif; ?>
                                <?php if ($lead['social_twitter']):   ?><a href="<?php echo esc_url($lead['social_twitter']);   ?>" target="_blank" rel="noopener" class="s-tw">X</a><?php endif; ?>
                                <?php if ($lead['social_youtube']):   ?><a href="<?php echo esc_url($lead['social_youtube']);   ?>" target="_blank" rel="noopener" class="s-yt">YT</a><?php endif; ?>
                                <?php if ($lead['social_tiktok']):    ?><a href="<?php echo esc_url($lead['social_tiktok']);    ?>" target="_blank" rel="noopener" class="s-tt">TT</a><?php endif; ?>
                                <?php if (!$lead['social_facebook'] && !$lead['social_instagram'] && !$lead['social_linkedin'] && !$lead['social_twitter'] && !$lead['social_youtube'] && !$lead['social_tiktok']): ?>
                                    <span style="color:#9ca3af;font-size:10px;">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="wnq-seo-badge <?php echo $seo_cls; ?>"><?php echo $score; ?>/7</span></td>
                        <td>
                            <div class="wnq-issues">
                                <?php foreach ($issues as $issue): ?>
                                    <span class="wnq-issue" title="<?php echo esc_attr(LeadSEOScorer::issueLabel($issue)); ?>">
                                        <?php echo esc_html(LeadSEOScorer::issueLabel($issue)); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <select class="wnq-status-sel" data-id="<?php echo (int)$lead['id']; ?>">
                                <?php foreach (['new', 'contacted', 'qualified', 'closed'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php selected($lead['status'], $s); ?>><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" class="wnq-notes-edit" data-id="<?php echo (int)$lead['id']; ?>"
                                   value="<?php echo esc_attr($lead['notes'] ?? ''); ?>" placeholder="Add note…">
                        </td>
                        <td>
                            <button class="wnq-btn wnq-btn-danger wnq-btn-sm" onclick="wnqDel(<?php echo (int)$lead['id']; ?>)" title="Delete">✕</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($total > $per_page): ?>
        <div class="wnq-paginate">
            <span>Showing <?php echo esc_html(($page - 1) * $per_page + 1); ?>–<?php echo esc_html(min($page * $per_page, $total)); ?> of <?php echo esc_html($total); ?> leads</span>
            <div class="pages">
                <?php for ($p = 1, $tp = (int)ceil($total / $per_page); $p <= $tp; $p++): ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $p, $base_url)); ?>"
                       class="<?php echo $p === $page ? 'cur' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <script>
        (function() {
            const nonce = '<?php echo esc_js($nonce); ?>';

            // Status change
            document.querySelectorAll('.wnq-status-sel').forEach(sel => {
                sel.addEventListener('change', function() {
                    const fd = new FormData();
                    fd.append('action', 'wnq_lead_update_status');
                    fd.append('nonce',  nonce);
                    fd.append('id',     this.dataset.id);
                    fd.append('status', this.value);
                    fetch(ajaxurl, { method:'POST', body: fd });
                });
            });

            // Notes blur-to-save
            document.querySelectorAll('.wnq-notes-edit').forEach(input => {
                let orig = input.value;
                input.addEventListener('blur', function() {
                    if (this.value === orig) return;
                    orig = this.value;
                    const fd = new FormData();
                    fd.append('action', 'wnq_lead_update_notes');
                    fd.append('nonce',  nonce);
                    fd.append('id',     this.dataset.id);
                    fd.append('notes',  this.value);
                    fetch(ajaxurl, { method:'POST', body: fd });
                });
            });

            window.wnqDel = function(id) {
                if (!confirm('Delete this lead? This cannot be undone.')) return;
                const fd = new FormData();
                fd.append('action', 'wnq_lead_delete');
                fd.append('nonce',  nonce);
                fd.append('id',     id);
                fetch(ajaxurl, { method:'POST', body: fd }).then(() => {
                    const row = document.getElementById('lr-' + id);
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
        $test_nonce = wp_create_nonce('wnq_lead_test_api');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wnq_lead_save_settings', 'wnq_nonce'); ?>
            <input type="hidden" name="action" value="wnq_lead_save_settings">

            <div class="wnq-card">
                <h3>Google Places API</h3>
                <div class="wnq-row2">
                    <div class="wnq-field">
                        <label>API Key</label>
                        <input type="password" name="google_places_key" value="<?php echo esc_attr($settings['google_places_key'] ?? ''); ?>" placeholder="AIza…">
                        <small>
                            Enable <strong>Places API</strong> in
                            <a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank">Google Cloud Console</a>.
                            Billing must be active (~$17/1,000 Place Details calls).
                        </small>
                    </div>
                    <div class="wnq-field" style="justify-content:flex-end;">
                        <button type="button" class="wnq-btn wnq-btn-secondary" onclick="wnqTestApi()">Test API Key</button>
                        <div id="api-test-result" style="margin-top:8px;font-size:12px;"></div>
                    </div>
                </div>
            </div>

            <div class="wnq-card">
                <h3>Daily Automation</h3>
                <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                    <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                    Enable daily cron — runs at 9am, one industry + city per day
                </label>
                <div class="wnq-row2">
                    <div class="wnq-field">
                        <label>Target Industries (one per line)</label>
                        <textarea name="target_industries" placeholder="roofing contractor&#10;plumber&#10;HVAC company&#10;electrician&#10;landscaping"><?php echo esc_textarea($settings['target_industries'] ?? ''); ?></textarea>
                        <small>Each line is used as a Google Places search keyword</small>
                    </div>
                    <div class="wnq-field">
                        <label>Target Cities (one per line)</label>
                        <textarea name="target_cities" placeholder="Baltimore MD&#10;Orlando FL&#10;Charlotte NC&#10;Richmond VA"><?php echo esc_textarea($settings['target_cities'] ?? ''); ?></textarea>
                        <small>Will be combined with industry keywords automatically</small>
                    </div>
                </div>
            </div>

            <div class="wnq-card">
                <h3>Default Qualification Filters</h3>
                <div class="wnq-row3">
                    <div class="wnq-field">
                        <label>Min Google Reviews</label>
                        <input type="number" name="min_reviews" value="<?php echo esc_attr($settings['min_reviews'] ?? 20); ?>" min="0">
                        <small>Confirms the business is established and has customers</small>
                    </div>
                    <div class="wnq-field">
                        <label>Min Rating</label>
                        <input type="number" name="min_rating" value="<?php echo esc_attr($settings['min_rating'] ?? 3.5); ?>" min="0" max="5" step="0.1">
                        <small>Skip businesses with very poor reputations</small>
                    </div>
                    <div class="wnq-field">
                        <label>Min SEO Score</label>
                        <input type="number" name="min_seo_score" value="<?php echo esc_attr($settings['min_seo_score'] ?? 2); ?>" min="0" max="7">
                        <small>Issues found (1–7). 2+ means real SEO problems.</small>
                    </div>
                </div>
            </div>

            <button type="submit" class="wnq-btn wnq-btn-primary">Save Settings</button>
        </form>

        <script>
        function wnqTestApi() {
            const btn = event.target;
            btn.disabled = true; btn.textContent = 'Testing…';
            const fd = new FormData();
            fd.append('action', 'wnq_lead_test_api');
            fd.append('nonce',  '<?php echo esc_js($test_nonce); ?>');
            fetch(ajaxurl, { method:'POST', body: fd })
                .then(r => r.json())
                .then(resp => {
                    btn.disabled = false; btn.textContent = 'Test API Key';
                    document.getElementById('api-test-result').innerHTML = resp.success
                        ? '<span style="color:#16a34a">✓ ' + resp.data.message + '</span>'
                        : '<span style="color:#dc2626">✗ ' + (resp.data?.message || 'Failed') + '</span>';
                });
        }
        </script>

        <?php if (!empty($_GET['settings_saved'])): ?>
            <div class="notice notice-success is-dismissible" style="margin-top:14px;"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php
    }

    // ── AJAX Handlers ────────────────────────────────────────────────────────

    /**
     * Phase 1 — Queue a search: runs the Google Places API only, stores raw
     * candidates in a transient, returns immediately with a batch_id.
     * No website crawling happens here, so this call is always fast (~5–10s).
     */
    public static function ajaxQueueSearch(): void
    {
        check_ajax_referer('wnq_lead_queue_search', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $result = LeadFinderEngine::queueSearch([
            'keyword'     => sanitize_text_field($_POST['keyword']      ?? ''),
            'city'        => sanitize_text_field($_POST['city']         ?? ''),
            'max_results' => (int)($_POST['max_results'] ?? 60),
        ]);

        $result['ok']
            ? wp_send_json_success(['batch_id' => $result['batch_id'] ?? '', 'total' => $result['total'] ?? 0])
            : wp_send_json_error(['message' => $result['error'] ?? 'Queue failed']);
    }

    /**
     * Phase 2 — Process next candidate: web-crawls exactly one Place result
     * from the queued batch. Called in a loop by the browser until done=true.
     * Each call is self-contained with its own 90-second PHP time limit.
     */
    public static function ajaxProcessNext(): void
    {
        check_ajax_referer('wnq_lead_queue_search', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        if (!$batch_id) {
            wp_send_json_error(['message' => 'Missing batch_id']);
        }

        $result = LeadFinderEngine::processNextCandidate($batch_id, [
            'min_reviews'   => (int)($_POST['min_reviews']   ?? 20),
            'min_rating'    => (float)($_POST['min_rating']  ?? 3.5),
            'min_seo_score' => (int)($_POST['min_seo']       ?? 2),
        ]);

        $result['ok']
            ? wp_send_json_success($result)
            : wp_send_json_error(['message' => $result['error'] ?? 'Processing failed']);
    }

    public static function ajaxUpdateStatus(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error();
        }
        $id     = (int)($_POST['id']     ?? 0);
        $status = sanitize_key($_POST['status'] ?? '');
        if (!$id || !in_array($status, ['new', 'contacted', 'qualified', 'closed'], true)) {
            wp_send_json_error();
        }
        Lead::updateStatus($id, $status);
        wp_send_json_success();
    }

    public static function ajaxUpdateNotes(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error();
        }
        $id    = (int)($_POST['id']    ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        if (!$id) wp_send_json_error();
        Lead::updateNotes($id, $notes);
        wp_send_json_success();
    }

    public static function ajaxDelete(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error();
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error();
        Lead::delete($id);
        wp_send_json_success();
    }

    public static function ajaxTestApi(): void
    {
        check_ajax_referer('wnq_lead_test_api', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error();
        }
        $result = PlacesAPIClient::testApiKey();
        $result['ok']
            ? wp_send_json_success(['message' => $result['message']])
            : wp_send_json_error(['message'   => $result['message']]);
    }

    // ── admin_post Handlers ──────────────────────────────────────────────────

    public static function handleExportCsv(): void
    {
        check_admin_referer('wnq_lead_export_csv');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        Lead::exportCsv(array_filter([
            'industry' => sanitize_text_field($_GET['industry'] ?? ''),
            'city'     => sanitize_text_field($_GET['city']     ?? ''),
            'state'    => sanitize_text_field($_GET['state']    ?? ''),
            'status'   => sanitize_key($_GET['status']          ?? ''),
        ]));
    }

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wnq_lead_save_settings', 'wnq_nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        update_option('wnq_lead_finder_settings', [
            'google_places_key' => sanitize_text_field($_POST['google_places_key']  ?? ''),
            'enabled'           => !empty($_POST['enabled']) ? 1 : 0,
            'target_industries' => sanitize_textarea_field($_POST['target_industries'] ?? ''),
            'target_cities'     => sanitize_textarea_field($_POST['target_cities']     ?? ''),
            'min_reviews'       => max(0, (int)($_POST['min_reviews']    ?? 20)),
            'min_rating'        => max(0.0, min(5.0, (float)($_POST['min_rating'] ?? 3.5))),
            'min_seo_score'     => max(0, min(7, (int)($_POST['min_seo_score']    ?? 2))),
        ]);

        wp_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=settings&settings_saved=1'));
        exit;
    }
}
