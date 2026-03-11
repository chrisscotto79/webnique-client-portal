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

final class LeadFinderAdmin
{
    public static function register(): void
    {
        // Priority 22 — must run AFTER SEOHubAdmin::addMenuPages() (priority 20) so that
        // the wnq-seo-hub parent slug exists when add_submenu_page() is called.
        add_action('admin_menu', [self::class, 'addMenuPage'], 22);
        // Queue-based search (Phase 1: parse URLs, store in transient)
        add_action('wp_ajax_wnq_lead_queue_manual',       [self::class, 'ajaxQueueManual']);
        // Queue-based processing (Phase 2: crawl one URL per call)
        add_action('wp_ajax_wnq_lead_process_next_manual', [self::class, 'ajaxProcessNextManual']);
        add_action('wp_ajax_wnq_lead_update_status',   [self::class, 'ajaxUpdateStatus']);
        add_action('wp_ajax_wnq_lead_update_notes',    [self::class, 'ajaxUpdateNotes']);
        add_action('wp_ajax_wnq_lead_delete',          [self::class, 'ajaxDelete']);
        add_action('wp_ajax_wnq_lead_bulk_action',     [self::class, 'ajaxBulkAction']);
        add_action('wp_ajax_wnq_lead_run_migration',   [self::class, 'ajaxRunMigration']);
        add_action('admin_post_wnq_lead_export_csv',    [self::class, 'handleExportCsv']);
        add_action('admin_post_wnq_lead_save_settings', [self::class, 'handleSaveSettings']);
    }

    public static function addMenuPage(): void
    {
        $cap = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page(
            'wnq-seo-hub',
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
        .wnq-progress { display:none;flex-direction:column;gap:6px;padding:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-top:14px;color:#1d4ed8;font-weight:500; }
        .wnq-progress.show { display:flex; }
        .wnq-progress-row { display:flex;align-items:center;gap:10px; }
        .wnq-sub-status { font-size:11px;color:#3b82f6;font-weight:400;margin-top:0; }
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
        .wnq-migration-banner { display:flex;align-items:center;gap:14px;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:14px 18px;margin-bottom:18px; }
        .wnq-migration-banner .wnq-mi-icon { font-size:22px;flex-shrink:0; }
        .wnq-migration-banner .wnq-mi-text { flex:1;font-size:13px;color:#92400e; }
        .wnq-migration-banner .wnq-mi-text strong { display:block;margin-bottom:2px;font-size:14px; }
        /* Bulk actions */
        .wnq-bulk-bar { display:none;align-items:center;gap:10px;background:#1e293b;color:#fff;padding:9px 14px;border-radius:8px;margin-bottom:10px;font-size:12px; }
        .wnq-bulk-bar.show { display:flex; }
        .wnq-bulk-count { font-weight:600;flex:1; }
        .wnq-bulk-bar select { padding:4px 8px;border-radius:5px;border:none;font-size:12px; }
        table.wnq-tbl tr.wnq-selected td { background:#eff6ff; }
        table.wnq-tbl th:first-child,table.wnq-tbl td:first-child { width:32px;padding-right:0; }
        /* Priority badges */
        .wnq-prio { display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700; }
        .prio-hot  { background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5; }
        .prio-warm { background:#fff7ed;color:#c2410c;border:1px solid #fed7aa; }
        .prio-mild { background:#fefce8;color:#a16207;border:1px solid #fde68a; }
        .prio-cold { background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd; }
        /* Copy button */
        .wnq-copy { background:none;border:none;cursor:pointer;color:#9ca3af;font-size:10px;padding:0 2px;line-height:1;vertical-align:middle; }
        .wnq-copy:hover { color:#2563eb; }
        /* Search history chips */
        .wnq-hist-wrap { display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px; }
        .wnq-hist-chip { padding:3px 10px;border-radius:12px;background:#f3f4f6;border:1px solid #d1d5db;font-size:11px;cursor:pointer;color:#374151; }
        .wnq-hist-chip:hover { background:#e5e7eb; }
        </style>

        <div class="wnq-lf-header">
            <h1>Lead Finder</h1>
            <span class="wnq-lf-badge">Outbound Sales</span>
        </div>

        <?php if (Lead::tableNeedsMigration()): ?>
        <div class="wnq-migration-banner" id="wnq-migration-banner">
            <div class="wnq-mi-icon">⚠️</div>
            <div class="wnq-mi-text">
                <strong>Database Update Required</strong>
                Your <code>wp_wnq_leads</code> table is missing columns added in a recent update
                (state/zip, social media fields, export tracking). Click the button to apply the update instantly.
            </div>
            <button class="wnq-btn wnq-btn-primary" id="wnq-run-migration">Fix Database</button>
        </div>
        <script>
        document.getElementById('wnq-run-migration').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Updating…';
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'wnq_lead_run_migration',
                    nonce: '<?php echo esc_js(wp_create_nonce('wnq_lead_migration')); ?>'
                })
            })
            .then(r => r.json())
            .then(function(d) {
                if (d.ok) {
                    document.getElementById('wnq-migration-banner').style.display = 'none';
                    location.reload();
                } else {
                    btn.textContent = 'Error – ' + (d.error || 'unknown');
                    btn.disabled = false;
                }
            })
            .catch(function() {
                btn.textContent = 'Network error — try again';
                btn.disabled = false;
            });
        });
        </script>
        <?php endif; ?>

        <div class="wnq-lf-stats">
            <?php foreach ([
                ['num' => $stats['total'],      'lbl' => 'Total'],
                ['num' => $stats['new'],        'lbl' => 'New'],
                ['num' => $stats['contacted'],  'lbl' => 'Contacted'],
                ['num' => $stats['qualified'],  'lbl' => 'Qualified'],
                ['num' => $stats['closed'],     'lbl' => 'Closed'],
                ['num' => $stats['with_email'],   'lbl' => 'Have Email'],
                ['num' => $stats['not_exported'], 'lbl' => 'Not Exported', 'hi' => true],
            ] as $s): ?>
                <div class="wnq-stat" <?php if (!empty($s['hi'])): ?>style="border-color:#2563eb;"<?php endif; ?>>
                    <div class="num" <?php if (!empty($s['hi'])): ?>style="color:#2563eb;"<?php endif; ?>><?php echo esc_html($s['num']); ?></div>
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
        $nonce = wp_create_nonce('wnq_lead_manual');
        ?>
        <style>
        .wnq-progressbar-wrap { background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;margin:8px 0; }
        .wnq-progressbar      { height:100%;background:#2563eb;border-radius:6px;transition:width .3s ease;width:0%; }
        .wnq-live-stats { display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#374151;margin-top:8px; }
        .wnq-live-stats span { display:flex;align-items:center;gap:4px; }
        .wnq-live-stats b { font-size:15px;font-weight:700; }
        </style>

        <div class="wnq-card">
            <h3>Add Prospects Manually</h3>
            <p style="color:#6b7280;margin:-6px 0 14px;font-size:12px;">
                Paste one website URL per line. Optionally use <code>Business Name | URL</code> format.
                Each site is crawled for SEO issues, email &amp; social media — processed one at a time so it never times out.
                Franchises and duplicates are automatically filtered.
            </p>

            <div class="wnq-field" style="margin-bottom:14px;">
                <label>Website URLs (one per line)</label>
                <textarea id="lf-urls" style="width:100%;height:160px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace;box-sizing:border-box;"
                    placeholder="https://example-plumber.com&#10;Acme Roofing | https://acmeroofing.com&#10;https://bestelectrician.net"></textarea>
            </div>

            <div class="wnq-row2" style="margin-bottom:14px;">
                <div class="wnq-field">
                    <label>Industry / Label</label>
                    <input type="text" id="lf-industry" placeholder="e.g. roofing contractor" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    <small>Tags all leads in this batch for filtering &amp; export</small>
                </div>
                <div class="wnq-field">
                    <label>Min SEO Score</label>
                    <input type="number" id="lf-min-seo" value="<?php echo esc_attr($settings['min_seo_score'] ?? 2); ?>" min="0" max="7">
                    <small>0–7 issues found. 2+ means real SEO gaps.</small>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:4px;">
                <button class="wnq-btn wnq-btn-primary" id="lf-start-btn" onclick="wnqStartSearch()">
                    Analyze &amp; Save Leads
                </button>
                <button class="wnq-btn wnq-btn-secondary" id="lf-stop-btn" onclick="wnqStop()" style="display:none;">
                    Stop
                </button>
            </div>

            <div id="lf-progress-area" style="display:none;margin-top:16px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#374151;margin-bottom:4px;">
                    <span id="lf-progress-label">Starting…</span>
                    <span id="lf-progress-pct">0%</span>
                </div>
                <div id="lf-sub-status" style="font-size:11px;color:#6b7280;margin-bottom:6px;min-height:15px;"></div>
                <div class="wnq-progressbar-wrap"><div class="wnq-progressbar" id="lf-bar"></div></div>
                <div class="wnq-live-stats">
                    <span>Total: <b id="ls-found">0</b></span>
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
            let _stopped = false;
            window.wnqStop = function() { _stopped = true; };

            window.wnqStartSearch = function() {
                _stopped = false;
                const urls     = document.getElementById('lf-urls').value.trim();
                const minSeo   = document.getElementById('lf-min-seo').value;
                const industry = document.getElementById('lf-industry').value.trim();
                if (!urls) { alert('Enter at least one website URL.'); return; }

                document.getElementById('lf-start-btn').disabled = true;
                document.getElementById('lf-stop-btn').style.display = '';
                document.getElementById('lf-progress-area').style.display = '';
                document.getElementById('lf-result').innerHTML = '';
                wnqResetStats();

                (async function() {
                    // Phase 1: queue URLs (fast — parse and store in transient)
                    let queueResp;
                    try {
                        const fd = new FormData();
                        fd.append('action',   'wnq_lead_queue_manual');
                        fd.append('nonce',    '<?php echo esc_js($nonce); ?>');
                        fd.append('urls',     urls);
                        fd.append('industry', industry);
                        wnqSetProgressLabel('Queuing URLs…');
                        const r = await fetch(ajaxurl, { method: 'POST', body: fd });
                        const rawQ = await r.text();
                        try { queueResp = JSON.parse(rawQ); }
                        catch(je) {
                            const preview = rawQ.replace(/<[^>]+>/g,'').trim().substring(0, 120);
                            wnqShowError('Queue failed: ' + (preview || '(empty response)'));
                            document.getElementById('lf-start-btn').disabled = false;
                            document.getElementById('lf-stop-btn').style.display = 'none';
                            return;
                        }
                    } catch(e) {
                        wnqShowError('Network error: ' + e.message);
                        document.getElementById('lf-start-btn').disabled = false;
                        document.getElementById('lf-stop-btn').style.display = 'none';
                        return;
                    }

                    if (!queueResp.success) {
                        wnqShowError(queueResp.data?.message || 'Failed to queue URLs');
                        document.getElementById('lf-start-btn').disabled = false;
                        document.getElementById('lf-stop-btn').style.display = 'none';
                        return;
                    }

                    const { batch_id, total } = queueResp.data;
                    document.getElementById('ls-found').textContent = total || 0;
                    if (!batch_id || total === 0) {
                        wnqShowError('No valid URLs found in the input.');
                        document.getElementById('lf-start-btn').disabled = false;
                        document.getElementById('lf-stop-btn').style.display = 'none';
                        return;
                    }

                    // Phase 2: process one URL at a time
                    let progress = 0, consecutiveErrors = 0, lastStats = null;
                    while (progress < total && !_stopped) {
                        wnqSetProgressLabel('Processing ' + progress + '/' + total + '…');
                        wnqSetSubStatus('Crawling URL ' + (progress + 1) + ' of ' + total + '…');

                        let procResp = null;
                        try {
                            const ctrl = new AbortController();
                            const tid  = setTimeout(() => ctrl.abort(), 60000);
                            const fd2 = new FormData();
                            fd2.append('action',   'wnq_lead_process_next_manual');
                            fd2.append('nonce',    '<?php echo esc_js($nonce); ?>');
                            fd2.append('batch_id', batch_id);
                            fd2.append('min_seo',  minSeo);
                            const r2 = await fetch(ajaxurl, { method: 'POST', body: fd2, signal: ctrl.signal });
                            clearTimeout(tid);
                            const rawText = await r2.text();
                            try { procResp = JSON.parse(rawText); }
                            catch(je) {
                                const preview = rawText.replace(/<[^>]+>/g,'').trim().substring(0, 120);
                                wnqShowError('URL ' + (progress+1) + ' PHP error: ' + (preview || '(empty)'));
                                consecutiveErrors++; progress++;
                                wnqSetProgress(progress, total);
                                if (consecutiveErrors >= 5) _stopped = true;
                                continue;
                            }
                        } catch(e) {
                            wnqSetSubStatus('URL ' + (progress+1) + ' timed out — skipping.');
                            consecutiveErrors++; progress++;
                            wnqSetProgress(progress, total);
                            if (consecutiveErrors >= 5) {
                                wnqShowError('5 consecutive timeouts — stopping. Try again.');
                                _stopped = true;
                            }
                            continue;
                        }

                        if (!procResp || !procResp.success) {
                            const errMsg = procResp?.data?.message || 'Unknown server error';
                            wnqSetSubStatus('URL ' + (progress+1) + ': ' + errMsg);
                            consecutiveErrors++; progress++;
                            wnqSetProgress(progress, total);
                            if (consecutiveErrors >= 5) {
                                wnqShowError('5 server errors — stopping. Last: ' + errMsg);
                                _stopped = true;
                            }
                            continue;
                        }

                        consecutiveErrors = 0;
                        const d = procResp.data;
                        progress  = d.progress;
                        lastStats = d.stats;
                        wnqSetSubStatus('URL ' + progress + ' done.');
                        wnqUpdateLiveStats(d.stats);
                        wnqSetProgress(d.progress, d.total);
                        if (d.done) break;
                    }

                    document.getElementById('lf-start-btn').disabled = false;
                    document.getElementById('lf-stop-btn').style.display = 'none';
                    const saved = lastStats ? lastStats.saved : 0;
                    if (!_stopped) {
                        wnqSetProgressLabel('Complete');
                        wnqSetSubStatus('');
                        wnqSetProgress(1, 1);
                        document.getElementById('lf-result').innerHTML =
                            '<div class="wnq-result wnq-result-ok"><strong>Done</strong><p><b>' + saved + '</b> new lead' + (saved !== 1 ? 's' : '') + ' saved.'
                            + (saved > 0 ? ' <a href="admin.php?page=wnq-lead-finder&tab=leads" style="color:#15803d;font-weight:600">View leads &rarr;</a>' : '') + '</p></div>';
                    } else {
                        wnqSetProgressLabel('Stopped');
                        document.getElementById('lf-result').innerHTML =
                            '<div class="wnq-result" style="background:#fef9c3;border:1px solid #fde68a;color:#92400e;"><strong>Search stopped</strong><p>' + saved + ' lead' + (saved !== 1 ? 's' : '') + ' saved so far.</p></div>';
                    }
                })();
            };

            // ── Helpers ──────────────────────────────────────────────────────

            function wnqResetStats() {
                ['ls-found','ls-saved','ls-franchise','ls-duplicate','ls-noweb','ls-lowseo'].forEach(function(id) {
                    document.getElementById(id).textContent = '0';
                });
                wnqSetProgress(0, 1);
            }

            function wnqUpdateLiveStats(s) {
                document.getElementById('ls-saved').textContent     = s.saved;
                document.getElementById('ls-franchise').textContent = s.franchise;
                document.getElementById('ls-duplicate').textContent = s.duplicate;
                document.getElementById('ls-noweb').textContent     = s.no_website;
                document.getElementById('ls-lowseo').textContent    = s.low_seo;
            }

            function wnqSetProgress(current, total) {
                const pct = total > 0 ? Math.round((current / total) * 100) : 0;
                document.getElementById('lf-bar').style.width = pct + '%';
                document.getElementById('lf-progress-pct').textContent = pct + '%';
            }

            function wnqSetProgressLabel(msg) {
                document.getElementById('lf-progress-label').textContent = msg;
            }

            function wnqSetSubStatus(msg) {
                document.getElementById('lf-sub-status').textContent = msg;
            }

            function wnqShowError(msg) {
                document.getElementById('lf-result').innerHTML +=
                    '<div class="wnq-result wnq-result-err" style="margin-bottom:6px;"><strong>Error</strong> — ' + msg + '</div>';
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
        $f_email        = !empty($_GET['has_email']);
        $f_not_exported = !empty($_GET['not_exported']);
        $page       = max(1, (int)($_GET['paged'] ?? 1));
        $per_page   = 50;

        $filter_args = array_filter([
            'industry'      => $f_industry,
            'city'          => $f_city,
            'state'         => $f_state,
            'status'        => $f_status,
            'min_seo_score' => $f_min_seo,
            'has_email'     => $f_email        ?: null,
            'not_exported'  => $f_not_exported ?: null,
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
            $f_industry     ? ['industry'     => $f_industry] : [],
            $f_city         ? ['city'         => $f_city]     : [],
            $f_state        ? ['state'        => $f_state]    : [],
            $f_status       ? ['status'       => $f_status]   : [],
            $f_email        ? ['has_email'    => '1']         : [],
            $f_not_exported ? ['not_exported' => '1']         : []
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
                <input type="checkbox" name="not_exported" value="1" <?php checked($f_not_exported); ?>> Not Exported
            </label>
            <button type="submit" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Filter</button>
            <a href="<?php echo esc_url($base_url); ?>" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Reset</a>
            <a href="<?php echo esc_url($export_url); ?>" class="wnq-btn wnq-btn-primary wnq-btn-sm" style="margin-left:auto;">
                Export GHL CSV (<?php echo esc_html($total); ?>)
            </a>
        </form>

        <div class="wnq-bulk-bar" id="wnq-bulk-bar">
            <span class="wnq-bulk-count"><span id="wnq-bulk-cnt">0</span> selected</span>
            <select id="wnq-bulk-status">
                <option value="">— Set Status —</option>
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="qualified">Qualified</option>
                <option value="closed">Closed</option>
            </select>
            <button class="wnq-btn wnq-btn-primary wnq-btn-sm" onclick="wnqBulkApply()">Apply</button>
            <button class="wnq-btn wnq-btn-danger wnq-btn-sm" onclick="wnqBulkDelete()">Delete Selected</button>
            <button class="wnq-btn wnq-btn-secondary wnq-btn-sm" onclick="wnqBulkClear()">Clear</button>
        </div>

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
                        <th><input type="checkbox" id="wnq-sel-all" title="Select all on this page"></th>
                        <th>Priority</th>
                        <th>Company</th>
                        <th>Industry</th>
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
                    $location = trim($lead['city'] . ($lead['state'] ? ', ' . $lead['state'] : ''));
                    // Priority: higher SEO issues + has email + established reviews = hotter lead
                    $prio_score = ($score * 2)
                                + (!empty($lead['email']) ? 4 : 0)
                                + min((int)($lead['review_count'] / 20), 2);
                    if ($prio_score >= 12)      { $prio_label = 'Hot';  $prio_cls = 'prio-hot'; }
                    elseif ($prio_score >= 8)   { $prio_label = 'Warm'; $prio_cls = 'prio-warm'; }
                    elseif ($prio_score >= 4)   { $prio_label = 'Mild'; $prio_cls = 'prio-mild'; }
                    else                        { $prio_label = 'Cold'; $prio_cls = 'prio-cold'; }
                ?>
                    <tr id="lr-<?php echo (int)$lead['id']; ?>">
                        <td style="padding-top:10px;"><input type="checkbox" class="wnq-sel" value="<?php echo (int)$lead['id']; ?>"></td>
                        <td><span class="wnq-prio <?php echo $prio_cls; ?>" title="SEO issues: <?php echo $score; ?>/7 | Email: <?php echo !empty($lead['email']) ? 'yes' : 'no'; ?>"><?php echo $prio_label; ?></span></td>
                        <td>
                            <strong><?php echo esc_html($lead['business_name']); ?></strong>
                            <?php if (!empty($lead['exported_at'])): ?>
                                <br><span style="font-size:9px;color:#6b7280;font-weight:400;">Exported <?php echo esc_html(date('M j', strtotime($lead['exported_at']))); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($lead['industry']); ?></td>
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
                                <button class="wnq-copy" onclick="wnqCopy('<?php echo esc_js($lead['phone']); ?>',this)" title="Copy phone">⎘</button>
                            <?php else: ?><span style="color:#9ca3af">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($lead['email']): ?>
                                <a href="mailto:<?php echo esc_attr($lead['email']); ?>" style="font-size:11px;color:#2563eb;"><?php echo esc_html($lead['email']); ?></a>
                                <?php if (!empty($lead['email_source']) && $lead['email_source'] !== $lead['website']): ?>
                                    <span style="font-size:9px;color:#9ca3af;display:block;">from /<?php echo esc_html(trim(parse_url($lead['email_source'], PHP_URL_PATH) ?: '', '/')); ?></span>
                                <?php endif; ?>
                                <button class="wnq-copy" onclick="wnqCopy('<?php echo esc_js($lead['email']); ?>',this)" title="Copy email">⎘</button>
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

            // ── Copy to clipboard ────────────────────────────────────────────
            window.wnqCopy = function(text, btn) {
                navigator.clipboard.writeText(text).then(function() {
                    const orig = btn.textContent;
                    btn.textContent = '✓';
                    btn.style.color = '#16a34a';
                    setTimeout(function() { btn.textContent = orig; btn.style.color = ''; }, 1500);
                });
            };

            // ── Bulk selection ────────────────────────────────────────────────
            const selAll   = document.getElementById('wnq-sel-all');
            const bulkBar  = document.getElementById('wnq-bulk-bar');
            const bulkCnt  = document.getElementById('wnq-bulk-cnt');

            function updateBulkBar() {
                const checked = document.querySelectorAll('.wnq-sel:checked');
                bulkCnt.textContent = checked.length;
                bulkBar.classList.toggle('show', checked.length > 0);
                document.querySelectorAll('.wnq-sel').forEach(cb => {
                    cb.closest('tr').classList.toggle('wnq-selected', cb.checked);
                });
            }

            selAll && selAll.addEventListener('change', function() {
                document.querySelectorAll('.wnq-sel').forEach(cb => { cb.checked = this.checked; });
                updateBulkBar();
            });

            document.querySelectorAll('.wnq-sel').forEach(cb => {
                cb.addEventListener('change', function() {
                    if (!this.checked && selAll) selAll.checked = false;
                    updateBulkBar();
                });
            });

            window.wnqBulkClear = function() {
                document.querySelectorAll('.wnq-sel').forEach(cb => { cb.checked = false; });
                if (selAll) selAll.checked = false;
                updateBulkBar();
            };

            window.wnqBulkApply = function() {
                const status = document.getElementById('wnq-bulk-status').value;
                if (!status) { alert('Select a status to apply.'); return; }
                const ids = [...document.querySelectorAll('.wnq-sel:checked')].map(cb => cb.value);
                if (!ids.length) return;
                const fd = new FormData();
                fd.append('action',      'wnq_lead_bulk_action');
                fd.append('nonce',       nonce);
                fd.append('bulk_action', status);
                ids.forEach(id => fd.append('ids[]', id));
                fetch(ajaxurl, { method:'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.success) { alert('Error: ' + (d.data?.message || 'Unknown')); return; }
                        document.querySelectorAll('.wnq-sel:checked').forEach(cb => {
                            const sel = cb.closest('tr').querySelector('.wnq-status-sel');
                            if (sel) sel.value = status;
                        });
                        wnqBulkClear();
                    });
            };

            window.wnqBulkDelete = function() {
                const ids = [...document.querySelectorAll('.wnq-sel:checked')].map(cb => cb.value);
                if (!ids.length) return;
                if (!confirm('Delete ' + ids.length + ' lead(s)? This cannot be undone.')) return;
                const fd = new FormData();
                fd.append('action',      'wnq_lead_bulk_action');
                fd.append('nonce',       nonce);
                fd.append('bulk_action', 'delete');
                ids.forEach(id => fd.append('ids[]', id));
                fetch(ajaxurl, { method:'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.success) { alert('Error: ' + (d.data?.message || 'Unknown')); return; }
                        document.querySelectorAll('.wnq-sel:checked').forEach(cb => {
                            const row = cb.closest('tr');
                            if (row) row.remove();
                        });
                        wnqBulkClear();
                    });
            };
        })();
        </script>
        <?php
    }

    // ── Tab: Settings ────────────────────────────────────────────────────────

    private static function renderSettingsTab(array $settings): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wnq_lead_save_settings', 'wnq_nonce'); ?>
            <input type="hidden" name="action" value="wnq_lead_save_settings">

            <div class="wnq-card">
                <h3>Default Qualification Filters</h3>
                <div class="wnq-row2">
                    <div class="wnq-field">
                        <label>Min SEO Score</label>
                        <input type="number" name="min_seo_score" value="<?php echo esc_attr($settings['min_seo_score'] ?? 2); ?>" min="0" max="7">
                        <small>Issues found (0–7). 2+ means real SEO problems worth pitching.</small>
                    </div>
                </div>
            </div>

            <button type="submit" class="wnq-btn wnq-btn-primary">Save Settings</button>
        </form>

        <?php if (!empty($_GET['settings_saved'])): ?>
            <div class="notice notice-success is-dismissible" style="margin-top:14px;"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php
    }

    // ── AJAX Handlers ────────────────────────────────────────────────────────

    /**
     * Phase 1 — Queue manual URL search: parses the URL list and stores
     * it in a transient, returns immediately with a batch_id.
     */
    public static function ajaxQueueManual(): void
    {
        if (!check_ajax_referer('wnq_lead_manual', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed — refresh the page and try again.']);
        }
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $result = LeadFinderEngine::queueManualSearch([
            'urls'     => sanitize_textarea_field($_POST['urls']     ?? ''),
            'industry' => sanitize_text_field($_POST['industry']     ?? ''),
        ]);

        $result['ok']
            ? wp_send_json_success(['batch_id' => $result['batch_id'] ?? '', 'total' => $result['total'] ?? 0])
            : wp_send_json_error(['message' => $result['error'] ?? 'Queue failed']);
    }

    /**
     * Phase 2 — Process next manual URL: crawls exactly one URL from the
     * queued batch. Called in a loop by the browser until done=true.
     */
    public static function ajaxProcessNextManual(): void
    {
        if (!check_ajax_referer('wnq_lead_manual', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed — refresh the page and try again.']);
        }
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        if (!$batch_id) {
            wp_send_json_error(['message' => 'Missing batch_id']);
        }

        $result = LeadFinderEngine::processNextManual($batch_id, [
            'min_seo_score' => (int)($_POST['min_seo'] ?? 2),
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

    public static function ajaxRunMigration(): void
    {
        check_ajax_referer('wnq_lead_migration', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json(['ok' => false, 'error' => 'Access denied']);
        }
        Lead::runMigration();
        wp_send_json(['ok' => true]);
    }

    public static function ajaxBulkAction(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied'], 403);
        }

        $bulk_action = sanitize_key($_POST['bulk_action'] ?? '');
        $ids         = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids         = array_filter($ids); // drop zeros

        if (empty($ids)) {
            wp_send_json_error(['message' => 'No leads selected']);
        }

        if ($bulk_action === 'delete') {
            Lead::bulkDelete($ids);
            wp_send_json_success(['deleted' => count($ids)]);
        } elseif (in_array($bulk_action, ['new', 'contacted', 'qualified', 'closed'], true)) {
            Lead::bulkUpdateStatus($ids, $bulk_action);
            wp_send_json_success(['updated' => count($ids), 'status' => $bulk_action]);
        } else {
            wp_send_json_error(['message' => 'Invalid action']);
        }
    }

    // ── admin_post Handlers ──────────────────────────────────────────────────

    public static function handleExportCsv(): void
    {
        check_admin_referer('wnq_lead_export_csv');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        Lead::exportCsv(array_filter([
            'industry'     => sanitize_text_field($_GET['industry']     ?? ''),
            'city'         => sanitize_text_field($_GET['city']         ?? ''),
            'state'        => sanitize_text_field($_GET['state']        ?? ''),
            'status'       => sanitize_key($_GET['status']              ?? ''),
            'has_email'    => !empty($_GET['has_email'])    ? true : null,
            'not_exported' => !empty($_GET['not_exported']) ? true : null,
        ], fn($v) => $v !== null && $v !== ''));
    }

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wnq_lead_save_settings', 'wnq_nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        $existing = get_option('wnq_lead_finder_settings', []);
        update_option('wnq_lead_finder_settings', array_merge($existing, [
            'min_seo_score' => max(0, min(7, (int)($_POST['min_seo_score'] ?? 2))),
        ]));

        wp_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=settings&settings_saved=1'));
        exit;
    }
}
