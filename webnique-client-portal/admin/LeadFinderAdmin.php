<?php
/**
 * Lead Finder Admin UI — ZIP Edition
 *
 * Provides the "Lead Finder" admin page with three tabs:
 *   Search   — start a ZIP-sweep search across all Florida ZIP codes
 *   Leads    — browse, filter, manage, and export all saved leads
 *   Settings — Google Places API key and cron configuration
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
        add_action('admin_menu', [self::class, 'addMenuPage']);

        // ZIP-sweep AJAX (Phase 1 + Phase 2)
        add_action('wp_ajax_wnq_zip_start',   [self::class, 'ajaxZipStart']);
        add_action('wp_ajax_wnq_zip_process', [self::class, 'ajaxZipProcess']);

        // Lead management
        add_action('wp_ajax_wnq_lead_update_status', [self::class, 'ajaxUpdateStatus']);
        add_action('wp_ajax_wnq_lead_update_notes',  [self::class, 'ajaxUpdateNotes']);
        add_action('wp_ajax_wnq_lead_delete',        [self::class, 'ajaxDelete']);

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
        .wnq-lf{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .wnq-lf-header{display:flex;align-items:center;gap:12px;margin-bottom:20px}
        .wnq-lf-header h1{margin:0;font-size:24px;font-weight:700}
        .wnq-lf-badge{background:#2563eb;color:#fff;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:600}
        .wnq-lf-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
        .wnq-stat{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 18px;text-align:center;min-width:100px}
        .wnq-stat .num{font-size:26px;font-weight:700;color:#1e293b;line-height:1}
        .wnq-stat .lbl{font-size:10px;color:#6b7280;margin-top:3px;text-transform:uppercase;letter-spacing:.5px}
        .wnq-lf-tabs{display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:20px}
        .wnq-lf-tab{padding:10px 20px;cursor:pointer;font-size:14px;font-weight:500;color:#6b7280;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none}
        .wnq-lf-tab.active{color:#2563eb;border-bottom-color:#2563eb}
        .wnq-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px;margin-bottom:16px}
        .wnq-card h3{margin:0 0 14px;font-size:15px;font-weight:600}
        .wnq-row2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
        .wnq-field{display:flex;flex-direction:column;gap:4px}
        .wnq-field label{font-size:12px;font-weight:600;color:#374151}
        .wnq-field input,.wnq-field select,.wnq-field textarea{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:100%;box-sizing:border-box}
        .wnq-field textarea{resize:vertical;min-height:80px}
        .wnq-field small{color:#6b7280;font-size:11px}
        .wnq-btn{padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none}
        .wnq-btn-primary{background:#2563eb;color:#fff}
        .wnq-btn-primary:hover{background:#1d4ed8}
        .wnq-btn-secondary{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}
        .wnq-btn-secondary:hover{background:#e5e7eb}
        .wnq-btn-danger{background:#dc2626;color:#fff}
        .wnq-btn-sm{padding:4px 10px;font-size:11px}
        /* Progress box */
        .wnq-progress{display:none;flex-direction:column;gap:8px;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-top:14px;color:#1d4ed8;font-weight:500}
        .wnq-progress.show{display:flex}
        .wnq-progress-row{display:flex;align-items:center;gap:10px}
        .wnq-pbar-wrap{background:#dbeafe;border-radius:4px;height:10px;flex:1;overflow:hidden}
        .wnq-pbar{background:#2563eb;height:10px;width:0;border-radius:4px;transition:width .3s}
        .wnq-sub-status{font-size:11px;color:#3b82f6;font-weight:400}
        .wnq-spinner{width:18px;height:18px;border:3px solid #bfdbfe;border-top-color:#2563eb;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0}
        @keyframes spin{to{transform:rotate(360deg)}}
        /* Live stats */
        .wnq-live-stats{display:flex;gap:16px;flex-wrap:wrap;padding:10px 0;font-size:12px}
        .wnq-ls-item{display:flex;align-items:center;gap:5px}
        .wnq-ls-num{font-size:18px;font-weight:700;color:#1e293b}
        .wnq-ls-lbl{color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.4px}
        /* Result box */
        .wnq-result{padding:14px;border-radius:8px;margin-top:14px}
        .wnq-result-ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
        .wnq-result-err{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626}
        .wnq-result strong{display:block;font-size:14px;margin-bottom:6px}
        /* Leads table */
        .wnq-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
        .wnq-filters select,.wnq-filters input{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px}
        .wnq-tbl-wrap{overflow-x:auto}
        table.wnq-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:1100px}
        table.wnq-tbl th{background:#f9fafb;padding:9px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;white-space:nowrap}
        table.wnq-tbl td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top}
        table.wnq-tbl tr:hover td{background:#fafafa}
        .wnq-status{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;text-transform:capitalize}
        .st-new{background:#dbeafe;color:#1d4ed8}
        .st-contacted{background:#fef3c7;color:#b45309}
        .st-qualified{background:#d1fae5;color:#065f46}
        .st-closed{background:#f3f4f6;color:#6b7280}
        .wnq-social{display:flex;gap:4px;flex-wrap:wrap}
        .wnq-social a{display:inline-block;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;text-decoration:none}
        .s-fb{background:#1877f2;color:#fff}
        .s-ig{background:#e1306c;color:#fff}
        .s-li{background:#0a66c2;color:#fff}
        .s-tw{background:#1da1f2;color:#fff}
        .s-yt{background:#ff0000;color:#fff}
        .s-tt{background:#010101;color:#fff}
        .wnq-paginate{display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:12px;color:#6b7280}
        .wnq-paginate .pages{display:flex;gap:3px}
        .wnq-paginate a{padding:3px 8px;border:1px solid #e5e7eb;border-radius:4px;text-decoration:none;color:#374151}
        .wnq-paginate a.cur{background:#2563eb;color:#fff;border-color:#2563eb}
        .wnq-notes-edit{width:140px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11px}
        .wnq-status-sel{font-size:11px;padding:3px 6px;border-radius:5px;border:1px solid #d1d5db}
        </style>

        <div class="wnq-lf-header">
            <h1>Lead Finder</h1>
            <span class="wnq-lf-badge">Florida ZIP Sweep</span>
        </div>

        <!-- Overview Stats -->
        <div class="wnq-lf-stats">
            <div class="wnq-stat"><div class="num"><?php echo esc_html($stats['total']); ?></div><div class="lbl">Total Leads</div></div>
            <div class="wnq-stat"><div class="num"><?php echo esc_html($stats['new']); ?></div><div class="lbl">New</div></div>
            <div class="wnq-stat"><div class="num"><?php echo esc_html($stats['contacted']); ?></div><div class="lbl">Contacted</div></div>
            <div class="wnq-stat"><div class="num"><?php echo esc_html($stats['qualified']); ?></div><div class="lbl">Qualified</div></div>
            <div class="wnq-stat"><div class="num"><?php echo esc_html($stats['with_email']); ?></div><div class="lbl">With Email</div></div>
        </div>

        <!-- Tabs -->
        <div class="wnq-lf-tabs">
            <a href="?page=wnq-lead-finder&tab=search"   class="wnq-lf-tab <?php echo $tab === 'search'   ? 'active' : ''; ?>">Search</a>
            <a href="?page=wnq-lead-finder&tab=leads"    class="wnq-lf-tab <?php echo $tab === 'leads'    ? 'active' : ''; ?>">Leads (<?php echo esc_html($stats['total']); ?>)</a>
            <a href="?page=wnq-lead-finder&tab=settings" class="wnq-lf-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Settings</a>
        </div>

        <?php
        match ($tab) {
            'leads'    => self::renderLeadsTab($settings),
            'settings' => self::renderSettingsTab($settings),
            default    => self::renderSearchTab($settings),
        };
        ?>
        </div><!-- .wrap.wnq-lf -->
        <?php
    }

    // ── Tab: Search ──────────────────────────────────────────────────────────

    private static function renderSearchTab(array $settings): void
    {
        $nonce = wp_create_nonce('wnq_lead_nonce');
        $total_zips = count(\WNQ\Data\FloridaZips::getAll());
        ?>
        <div class="wnq-card">
            <h3>Florida ZIP Code Sweep</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 16px">
                Searches all <?php echo esc_html(number_format($total_zips)); ?> Florida ZIP codes on Google Maps for businesses matching your keyword.
                Saves leads with <strong>&lt; 50 reviews</strong> that have a phone number.
                Scrapes homepage for email + social media links.
            </p>

            <div class="wnq-row2" style="max-width:600px">
                <div class="wnq-field">
                    <label for="lf-keyword">Industry / Keyword</label>
                    <input type="text" id="lf-keyword" placeholder="e.g. roofing contractor" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    <small>Appended to each ZIP code for Google Maps search</small>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:4px">
                <button class="wnq-btn wnq-btn-primary" id="lf-start-btn" onclick="wnqZipStart()">
                    &#9654; Start ZIP Sweep
                </button>
                <button class="wnq-btn wnq-btn-secondary" id="lf-stop-btn" style="display:none" onclick="wnqZipStop()">
                    &#9646;&#9646; Stop
                </button>
            </div>

            <!-- Progress -->
            <div class="wnq-progress" id="lf-progress">
                <div class="wnq-progress-row">
                    <div class="wnq-spinner"></div>
                    <span id="lf-status-text">Starting…</span>
                </div>
                <div class="wnq-progress-row">
                    <div class="wnq-pbar-wrap"><div class="wnq-pbar" id="lf-pbar"></div></div>
                    <span id="lf-pct" style="font-size:12px;white-space:nowrap">0%</span>
                </div>
                <div class="wnq-sub-status" id="lf-sub-status"></div>
                <!-- Live stats row -->
                <div class="wnq-live-stats">
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-saved">0</span>&nbsp;<span class="wnq-ls-lbl">Saved</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-duplicate">0</span>&nbsp;<span class="wnq-ls-lbl">Dupe</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-no_phone">0</span>&nbsp;<span class="wnq-ls-lbl">No Phone</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-no_website">0</span>&nbsp;<span class="wnq-ls-lbl">No Website</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-zips">0</span>&nbsp;<span class="wnq-ls-lbl">ZIPs Done</span></div>
                </div>
            </div>

            <!-- Result -->
            <div class="wnq-result" id="lf-result" style="display:none"></div>
        </div>

        <script>
        (function () {
            'use strict';

            const NONCE     = <?php echo wp_json_encode($nonce); ?>;
            const AJAX_URL  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const ABORT_MS  = 60000; // 60s browser-side timeout per AJAX call

            let batchId    = '';
            let totalZips  = 0;
            let running    = false;
            let stopFlag   = false;
            let consecFail = 0;
            const MAX_FAIL = 5;

            window.wnqZipStart = async function () {
                const keyword = document.getElementById('lf-keyword').value.trim();
                if (!keyword) { alert('Please enter a keyword.'); return; }

                running   = true;
                stopFlag  = false;
                consecFail = 0;

                document.getElementById('lf-start-btn').style.display = 'none';
                document.getElementById('lf-stop-btn').style.display  = '';
                document.getElementById('lf-result').style.display    = 'none';
                wnqShowProgress(true);
                wnqSetStatus('Initialising search…');
                wnqSetSub('');

                try {
                    // Phase 1 — start
                    const startResp = await wnqPost({
                        action:  'wnq_zip_start',
                        nonce:   NONCE,
                        keyword: keyword,
                    });

                    if (!startResp.success) {
                        wnqShowResult(false, startResp.data?.error || 'Failed to start search.');
                        wnqDone();
                        return;
                    }

                    batchId   = startResp.data.batch_id;
                    totalZips = startResp.data.total_zips || 0;
                    wnqSetStatus('Searching ZIP 0 of ' + totalZips + '…');

                    // Phase 2 — loop
                    await wnqLoop();

                } catch (err) {
                    wnqShowResult(false, 'Fatal error: ' + err.message);
                    wnqDone();
                }
            };

            window.wnqZipStop = function () {
                stopFlag = true;
                wnqSetStatus('Stopping after current step…');
            };

            async function wnqLoop() {
                while (running && !stopFlag) {
                    let resp;
                    try {
                        resp = await wnqPost({
                            action:   'wnq_zip_process',
                            nonce:    NONCE,
                            batch_id: batchId,
                        });
                    } catch (err) {
                        consecFail++;
                        wnqSetSub('Network error (' + consecFail + '/' + MAX_FAIL + '): ' + err.message);
                        if (consecFail >= MAX_FAIL) {
                            wnqShowResult(false, 'Stopped after ' + MAX_FAIL + ' consecutive failures.');
                            wnqDone();
                            return;
                        }
                        continue;
                    }

                    if (!resp.success) {
                        wnqShowResult(false, resp.data?.error || 'Server error during processing.');
                        wnqDone();
                        return;
                    }

                    consecFail = 0;
                    const d = resp.data || {};

                    // Update progress bar
                    const zipIdx = typeof d.zip_index  === 'number' ? d.zip_index  : 0;
                    const total  = typeof d.total_zips === 'number' ? d.total_zips : (totalZips || 1);
                    const pct    = Math.min(100, Math.round((zipIdx / total) * 100));
                    wnqSetProgress(pct);

                    // Update status text
                    if (d.action === 'zip_searched') {
                        wnqSetStatus('ZIP ' + zipIdx + ' of ' + total + ' — found ' + (d.found || 0) + ' candidates (' + d.zip + ')');
                    } else if (d.action === 'candidate') {
                        wnqSetStatus('ZIP ' + zipIdx + ' of ' + total + ' — processing candidate…');
                        wnqSetSub('Result: ' + (d.outcome || 'unknown'));
                    } else if (d.action === 'zip_error') {
                        wnqSetSub('Error on ZIP ' + (d.zip || '') + ', skipping…');
                    } else if (d.action === 'complete') {
                        wnqSetProgress(100);
                        wnqSetStatus('Complete!');
                    }

                    // Update live stats
                    const s = d.stats || {};
                    wnqSetStat('saved',      s.saved      || 0);
                    wnqSetStat('duplicate',  s.duplicate  || 0);
                    wnqSetStat('no_phone',   s.no_phone   || 0);
                    wnqSetStat('no_website', s.no_website || 0);
                    document.getElementById('ls-zips').textContent = s.zips_searched || 0;

                    if (d.done) {
                        const saved = s.saved || 0;
                        wnqShowResult(true,
                            'Sweep complete! <strong>' + saved + ' leads saved</strong>. ' +
                            'ZIPs: ' + (s.zips_searched || 0) +
                            ' | Dupes: ' + (s.duplicate || 0) +
                            ' | No Phone: ' + (s.no_phone || 0) +
                            ' | No Site: ' + (s.no_website || 0)
                        );
                        wnqDone();
                        return;
                    }
                }

                if (stopFlag) {
                    wnqShowResult(false, 'Search stopped manually.');
                    wnqDone();
                }
            }

            // ── Helpers ──────────────────────────────────────────────────────

            async function wnqPost(data) {
                const ctrl = new AbortController();
                const timer = setTimeout(() => ctrl.abort(), ABORT_MS);
                try {
                    const body = new URLSearchParams(data);
                    const r = await fetch(AJAX_URL, {
                        method: 'POST',
                        body,
                        signal: ctrl.signal,
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    });
                    clearTimeout(timer);
                    const text = await r.text();
                    try {
                        return JSON.parse(text);
                    } catch (_) {
                        // Show raw PHP output for debugging
                        console.error('Non-JSON response:', text.substring(0, 500));
                        throw new Error('Server returned non-JSON: ' + text.substring(0, 200));
                    }
                } catch (e) {
                    clearTimeout(timer);
                    throw e;
                }
            }

            function wnqShowProgress(show) {
                document.getElementById('lf-progress').classList.toggle('show', show);
            }

            function wnqSetProgress(pct) {
                try {
                    document.getElementById('lf-pbar').style.width = pct + '%';
                    document.getElementById('lf-pct').textContent  = pct + '%';
                } catch (_) {}
            }

            function wnqSetStatus(msg) {
                try { document.getElementById('lf-status-text').textContent = msg; } catch (_) {}
            }

            function wnqSetSub(msg) {
                try { document.getElementById('lf-sub-status').textContent = msg; } catch (_) {}
            }

            function wnqSetStat(key, val) {
                try { document.getElementById('ls-' + key).textContent = val; } catch (_) {}
            }

            function wnqShowResult(ok, html) {
                const el = document.getElementById('lf-result');
                el.className = 'wnq-result ' + (ok ? 'wnq-result-ok' : 'wnq-result-err');
                el.innerHTML = html;
                el.style.display = '';
            }

            function wnqDone() {
                running = false;
                document.getElementById('lf-start-btn').style.display = '';
                document.getElementById('lf-stop-btn').style.display  = 'none';
                wnqShowProgress(false);
            }
        }());
        </script>
        <?php
    }

    // ── Tab: Leads ───────────────────────────────────────────────────────────

    private static function renderLeadsTab(array $settings): void
    {
        $nonce       = wp_create_nonce('wnq_lead_nonce');
        $per_page    = 50;
        $paged       = max(1, (int)($_GET['paged'] ?? 1));
        $offset      = ($paged - 1) * $per_page;

        $filter_industry = sanitize_text_field($_GET['industry'] ?? '');
        $filter_city     = sanitize_text_field($_GET['city']     ?? '');
        $filter_status   = sanitize_text_field($_GET['status']   ?? '');
        $filter_email    = !empty($_GET['has_email']);

        $args = [
            'limit'     => $per_page,
            'offset'    => $offset,
            'orderby'   => 'scraped_at',
            'order'     => 'DESC',
        ];
        if ($filter_industry) $args['industry']  = $filter_industry;
        if ($filter_city)     $args['city']      = $filter_city;
        if ($filter_status)   $args['status']    = $filter_status;
        if ($filter_email)    $args['has_email'] = true;

        $leads     = Lead::getAll($args);
        $total     = Lead::count($args);
        $num_pages = ceil($total / $per_page);

        $industries = Lead::getDistinctValues('industry');
        $cities     = Lead::getDistinctValues('city');

        $base_url = admin_url('admin.php?page=wnq-lead-finder&tab=leads');
        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=wnq_lead_export_csv' .
                ($filter_industry ? '&industry=' . urlencode($filter_industry) : '') .
                ($filter_city     ? '&city='     . urlencode($filter_city)     : '') .
                ($filter_status   ? '&status='   . urlencode($filter_status)   : '') .
                ($filter_email    ? '&has_email=1'                             : '')
            ),
            'wnq_lead_export'
        );
        ?>
        <div class="wnq-card" style="padding:16px 22px">
            <div class="wnq-filters">
                <form method="get" style="display:contents">
                    <input type="hidden" name="page" value="wnq-lead-finder">
                    <input type="hidden" name="tab"  value="leads">
                    <select name="industry">
                        <option value="">All Industries</option>
                        <?php foreach ($industries as $ind): ?>
                            <option value="<?php echo esc_attr($ind); ?>" <?php selected($filter_industry, $ind); ?>><?php echo esc_html($ind); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="city">
                        <option value="">All Cities</option>
                        <?php foreach ($cities as $c): ?>
                            <option value="<?php echo esc_attr($c); ?>" <?php selected($filter_city, $c); ?>><?php echo esc_html($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (['new','contacted','qualified','closed'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php selected($filter_status, $s); ?>><?php echo ucfirst($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label style="font-size:12px;display:flex;align-items:center;gap:4px">
                        <input type="checkbox" name="has_email" value="1" <?php checked($filter_email); ?>> Has Email
                    </label>
                    <button type="submit" class="wnq-btn wnq-btn-primary wnq-btn-sm">Filter</button>
                    <a href="<?php echo esc_url($base_url); ?>" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Reset</a>
                    <a href="<?php echo esc_url($export_url); ?>" class="wnq-btn wnq-btn-secondary wnq-btn-sm">&#8595; Export CSV (GHL)</a>
                </form>
            </div>

            <p style="margin:0 0 10px;font-size:12px;color:#6b7280">
                <?php echo number_format($total); ?> lead<?php echo $total !== 1 ? 's' : ''; ?>
            </p>

            <div class="wnq-tbl-wrap">
            <table class="wnq-tbl">
                <thead>
                <tr>
                    <th>Company</th>
                    <th>Industry</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Website</th>
                    <th>City</th>
                    <th>St</th>
                    <th>Stars</th>
                    <th>Reviews</th>
                    <th>Social</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($leads)): ?>
                    <tr><td colspan="13" style="text-align:center;color:#9ca3af;padding:40px">No leads found.</td></tr>
                <?php else: foreach ($leads as $lead): ?>
                <tr>
                    <td><strong><?php echo esc_html($lead['business_name']); ?></strong></td>
                    <td><?php echo esc_html($lead['industry']); ?></td>
                    <td><?php echo esc_html($lead['phone']); ?></td>
                    <td><?php echo esc_html($lead['email']); ?></td>
                    <td><?php if ($lead['website']): ?><a href="<?php echo esc_url($lead['website']); ?>" target="_blank" style="color:#2563eb;font-size:11px"><?php echo esc_html(parse_url($lead['website'], PHP_URL_HOST) ?: $lead['website']); ?></a><?php endif; ?></td>
                    <td><?php echo esc_html($lead['city']); ?></td>
                    <td><?php echo esc_html($lead['state']); ?></td>
                    <td><?php echo esc_html(number_format((float)$lead['rating'], 1)); ?></td>
                    <td><?php echo esc_html($lead['review_count']); ?></td>
                    <td>
                        <div class="wnq-social">
                            <?php if ($lead['social_facebook']):  ?><a href="<?php echo esc_url($lead['social_facebook']); ?>"  target="_blank" class="s-fb">FB</a><?php endif; ?>
                            <?php if ($lead['social_instagram']): ?><a href="<?php echo esc_url($lead['social_instagram']); ?>" target="_blank" class="s-ig">IG</a><?php endif; ?>
                            <?php if ($lead['social_linkedin']):  ?><a href="<?php echo esc_url($lead['social_linkedin']); ?>"  target="_blank" class="s-li">LI</a><?php endif; ?>
                            <?php if ($lead['social_twitter']):   ?><a href="<?php echo esc_url($lead['social_twitter']); ?>"   target="_blank" class="s-tw">TW</a><?php endif; ?>
                            <?php if ($lead['social_youtube']):   ?><a href="<?php echo esc_url($lead['social_youtube']); ?>"   target="_blank" class="s-yt">YT</a><?php endif; ?>
                            <?php if ($lead['social_tiktok']):    ?><a href="<?php echo esc_url($lead['social_tiktok']); ?>"    target="_blank" class="s-tt">TT</a><?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <select class="wnq-status-sel" onchange="wnqUpdateStatus(<?php echo esc_js($lead['id']); ?>, this.value)">
                            <?php foreach (['new','contacted','qualified','closed'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php selected($lead['status'], $s); ?>><?php echo ucfirst($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input class="wnq-notes-edit" type="text" value="<?php echo esc_attr($lead['notes']); ?>"
                            onblur="wnqUpdateNotes(<?php echo esc_js($lead['id']); ?>, this.value)" placeholder="Add note…">
                    </td>
                    <td>
                        <button class="wnq-btn wnq-btn-danger wnq-btn-sm" onclick="wnqDeleteLead(<?php echo esc_js($lead['id']); ?>, this)">Del</button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <?php if ($num_pages > 1): ?>
            <div class="wnq-paginate">
                <span><?php echo number_format($total); ?> leads — page <?php echo $paged; ?> of <?php echo $num_pages; ?></span>
                <div class="pages">
                    <?php for ($p = 1; $p <= $num_pages; $p++): ?>
                        <a href="<?php echo esc_url(add_query_arg('paged', $p, $base_url . ($filter_industry ? '&industry=' . urlencode($filter_industry) : '') . ($filter_city ? '&city=' . urlencode($filter_city) : '') . ($filter_status ? '&status=' . urlencode($filter_status) : '') . ($filter_email ? '&has_email=1' : ''))); ?>"
                           class="<?php echo $p === $paged ? 'cur' : ''; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        const wnqLeadNonce = <?php echo wp_json_encode($nonce); ?>;
        const wnqAjax     = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

        function wnqPost(data) {
            const body = new URLSearchParams(Object.assign({ nonce: wnqLeadNonce }, data));
            return fetch(wnqAjax, { method: 'POST', body }).then(r => r.json());
        }

        function wnqUpdateStatus(id, status) {
            wnqPost({ action: 'wnq_lead_update_status', id, status });
        }

        function wnqUpdateNotes(id, notes) {
            wnqPost({ action: 'wnq_lead_update_notes', id, notes });
        }

        function wnqDeleteLead(id, btn) {
            if (!confirm('Delete this lead?')) return;
            wnqPost({ action: 'wnq_lead_delete', id }).then(() => {
                btn.closest('tr').remove();
            });
        }
        </script>
        <?php
    }

    // ── Tab: Settings ────────────────────────────────────────────────────────

    private static function renderSettingsTab(array $settings): void
    {
        ?>
        <div class="wnq-card" style="max-width:680px">
            <h3>Lead Finder Settings</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 16px">
                No API key required — the Lead Finder scrapes Google Maps directly.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wnq_lead_save_settings'); ?>
                <input type="hidden" name="action" value="wnq_lead_save_settings">

                <div class="wnq-field" style="margin-bottom:14px">
                    <label>Default Keyword</label>
                    <input type="text" name="default_keyword" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>" placeholder="e.g. roofing contractor">
                    <small>Pre-filled in the Search tab (you can still change it before each sweep).</small>
                </div>

                <button type="submit" class="wnq-btn wnq-btn-primary">Save Settings</button>
            </form>
        </div>
        <?php
    }

    // ── AJAX: Phase 1 — Start ZIP Sweep ──────────────────────────────────────

    public static function ajaxZipStart(): void
    {
        if (!check_ajax_referer('wnq_lead_nonce', 'nonce', false)) {
            wp_send_json_error(['error' => 'Security check failed'], 403);
            return;
        }
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Access denied'], 403);
            return;
        }

        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
        $result  = LeadFinderEngine::startSearch($keyword);

        if (!$result['ok']) {
            wp_send_json_error(['error' => $result['error'] ?? 'Unknown error']);
            return;
        }

        wp_send_json_success([
            'batch_id'   => $result['batch_id'],
            'total_zips' => $result['total_zips'],
        ]);
    }

    // ── AJAX: Phase 2 — Process Next Unit ────────────────────────────────────

    public static function ajaxZipProcess(): void
    {
        if (!check_ajax_referer('wnq_lead_nonce', 'nonce', false)) {
            wp_send_json_error(['error' => 'Security check failed'], 403);
            return;
        }
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Access denied'], 403);
            return;
        }

        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        if (!$batch_id) {
            wp_send_json_error(['error' => 'Missing batch_id']);
            return;
        }

        $result = LeadFinderEngine::processNext($batch_id);

        if (!$result['ok']) {
            wp_send_json_error(['error' => $result['error'] ?? 'Processing error']);
            return;
        }

        wp_send_json_success($result);
    }

    // ── AJAX: Lead Management ─────────────────────────────────────────────────

    public static function ajaxUpdateStatus(): void
    {
        check_ajax_referer('wnq_lead_nonce', 'nonce');
        self::requireCap();
        $id     = (int)($_POST['id']     ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        if ($id && in_array($status, ['new','contacted','qualified','closed'], true)) {
            Lead::updateStatus($id, $status);
        }
        wp_send_json_success();
    }

    public static function ajaxUpdateNotes(): void
    {
        check_ajax_referer('wnq_lead_nonce', 'nonce');
        self::requireCap();
        $id    = (int)($_POST['id']    ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        if ($id) Lead::updateNotes($id, $notes);
        wp_send_json_success();
    }

    public static function ajaxDelete(): void
    {
        check_ajax_referer('wnq_lead_nonce', 'nonce');
        self::requireCap();
        $id = (int)($_POST['id'] ?? 0);
        if ($id) Lead::delete($id);
        wp_send_json_success();
    }

    // ── Admin POST: Export CSV ────────────────────────────────────────────────

    public static function handleExportCsv(): void
    {
        check_admin_referer('wnq_lead_export');
        self::requireCap();

        Lead::exportCsv([
            'industry' => sanitize_text_field($_GET['industry'] ?? ''),
            'city'     => sanitize_text_field($_GET['city']     ?? ''),
            'status'   => sanitize_text_field($_GET['status']   ?? ''),
            'has_email'=> !empty($_GET['has_email']),
        ]);
    }

    // ── Admin POST: Save Settings ─────────────────────────────────────────────

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wnq_lead_save_settings');
        self::requireCap();

        $existing = get_option('wnq_lead_finder_settings', []);
        $existing['google_places_key'] = sanitize_text_field($_POST['google_places_key'] ?? '');
        $existing['default_keyword']   = sanitize_text_field($_POST['default_keyword']   ?? '');

        update_option('wnq_lead_finder_settings', $existing);

        wp_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=settings&saved=1'));
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function requireCap(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Access denied'], 403);
            exit;
        }
    }
}
