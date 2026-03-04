<?php
/**
 * Backlink Manager Admin UI
 *
 * Three-tab interface for building and tracking backlinks for every client:
 *
 *   Citations    — Pre-loaded 30-site local directory checklist.
 *                  One-click status updates (Pending → Submitted → Live).
 *                  NAP copy-card pre-filled from client profile.
 *
 *   Opportunities — AI-generates 8 keyword-targeted link-building ideas.
 *                   One-click outreach email generator per opportunity.
 *                   Manually add custom targets.
 *
 *   All Links     — Full tracker table with batch live-verification,
 *                   editable notes, status dropdown, and delete.
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\BacklinkManager;
use WNQ\Models\Client;
use WNQ\Models\SEOHub;
use WNQ\Services\AIEngine;
use WNQ\Services\BacklinkVerifier;

final class BacklinkAdmin
{
    public static function register(): void
    {
        add_action('admin_menu',              [self::class, 'addMenuPage'], 30);
        add_action('wp_ajax_wnq_backlink',    [self::class, 'handleAjax']);
    }

    public static function addMenuPage(): void
    {
        $cap = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page(
            'wnq-seo-os',
            'Backlink Manager',
            'Backlinks',
            $cap,
            'wnq-backlinks',
            [self::class, 'renderPage']
        );
    }

    // ── Page Renderer ────────────────────────────────────────────────────────

    public static function renderPage(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        $tab       = sanitize_key($_GET['tab'] ?? 'citations');
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();
        $nonce     = wp_create_nonce('wnq_backlink_nonce');

        // Auto-select first client if none chosen
        if (!$client_id && !empty($clients)) {
            $client_id = $clients[0]['client_id'];
        }

        $stats    = $client_id ? BacklinkManager::getStats($client_id) : [];
        $profile  = $client_id ? SEOHub::getProfile($client_id) : null;
        $client   = $client_id ? Client::getByClientId($client_id) : null;
        $base_url = admin_url('admin.php?page=wnq-backlinks');
        ?>
        <div class="wrap wnq-bl">
        <style>
        .wnq-bl { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .wnq-bl-header { display:flex;align-items:center;gap:12px;margin-bottom:18px; }
        .wnq-bl-header h1 { margin:0;font-size:24px;font-weight:700; }
        .wnq-bl-badge { background:#7c3aed;color:#fff;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:600; }
        /* Client selector */
        .wnq-client-bar { display:flex;gap:10px;align-items:center;margin-bottom:18px;flex-wrap:wrap; }
        .wnq-client-bar select { padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px; }
        /* Stats */
        .wnq-bl-stats { display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px; }
        .wnq-bl-stat { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:11px 16px;text-align:center;min-width:90px; }
        .wnq-bl-stat .num { font-size:24px;font-weight:700;color:#1e293b;line-height:1; }
        .wnq-bl-stat .lbl { font-size:10px;color:#6b7280;margin-top:3px;text-transform:uppercase;letter-spacing:.5px; }
        .wnq-bl-stat.live  .num { color:#16a34a; }
        .wnq-bl-stat.lost  .num { color:#dc2626; }
        .wnq-bl-stat.sub   .num { color:#2563eb; }
        /* Tabs */
        .wnq-bl-tabs { display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:18px; }
        .wnq-bl-tab { padding:9px 18px;cursor:pointer;font-size:14px;font-weight:500;color:#6b7280;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none; }
        .wnq-bl-tab.active { color:#7c3aed;border-bottom-color:#7c3aed; }
        /* Cards */
        .wnq-bl-card { background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:14px; }
        .wnq-bl-card h3 { margin:0 0 12px;font-size:15px;font-weight:600; }
        /* Buttons */
        .wnq-bl-btn { padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none; }
        .wnq-bl-btn-primary { background:#7c3aed;color:#fff; }
        .wnq-bl-btn-primary:hover { background:#6d28d9; }
        .wnq-bl-btn-secondary { background:#f3f4f6;color:#374151;border:1px solid #d1d5db; }
        .wnq-bl-btn-secondary:hover { background:#e5e7eb; }
        .wnq-bl-btn-sm { padding:4px 10px;font-size:11px; }
        .wnq-bl-btn-danger { background:#dc2626;color:#fff; }
        /* NAP Card */
        .wnq-nap-card { background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px;margin-bottom:14px;font-size:12px; }
        .wnq-nap-card h4 { margin:0 0 8px;color:#15803d;font-size:13px; }
        .wnq-nap-row { display:flex;align-items:flex-start;gap:8px;margin-bottom:4px; }
        .wnq-nap-row strong { min-width:70px;color:#374151; }
        .wnq-nap-row span { flex:1;color:#111827; }
        .wnq-nap-copy-btn { background:#16a34a;color:#fff;border:none;border-radius:5px;padding:3px 8px;font-size:10px;cursor:pointer;font-weight:600; }
        /* Progress bar */
        .wnq-cit-progress { margin-bottom:12px; }
        .wnq-cit-bar-wrap { background:#e5e7eb;border-radius:6px;height:8px;overflow:hidden;margin-top:4px; }
        .wnq-cit-bar { height:100%;background:#7c3aed;border-radius:6px;transition:width .4s; }
        /* Citation table */
        table.wnq-bl-tbl { width:100%;border-collapse:collapse;font-size:12px; }
        table.wnq-bl-tbl th { background:#f9fafb;padding:9px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;white-space:nowrap; }
        table.wnq-bl-tbl td { padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle; }
        table.wnq-bl-tbl tr:hover td { background:#fafafa; }
        .wnq-bl-da { display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700; }
        .da-high { background:#dcfce7;color:#15803d; }
        .da-med  { background:#fef9c3;color:#92400e; }
        .da-low  { background:#f3f4f6;color:#6b7280; }
        .wnq-bl-status { display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;text-transform:capitalize; }
        .st-pending   { background:#f3f4f6;color:#6b7280; }
        .st-submitted { background:#dbeafe;color:#1d4ed8; }
        .st-live      { background:#dcfce7;color:#15803d; }
        .st-rejected  { background:#fee2e2;color:#dc2626; }
        .st-lost      { background:#fef9c3;color:#92400e; }
        /* Opportunity cards */
        .wnq-opp-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-bottom:10px; }
        .wnq-opp-card h4 { margin:0 0 6px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px; }
        .wnq-opp-type { background:#ede9fe;color:#6d28d9;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:700;text-transform:uppercase; }
        .wnq-opp-card p { margin:0 0 8px;font-size:12px;color:#374151; }
        .wnq-opp-card .search-q { background:#f9fafb;border:1px solid #e5e7eb;border-radius:5px;padding:5px 8px;font-size:11px;font-family:monospace;color:#374151;margin-bottom:8px;word-break:break-all; }
        /* Email modal */
        .wnq-email-box { background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:12px;font-size:12px;line-height:1.6;white-space:pre-wrap;font-family:inherit;margin-top:8px;color:#1e293b;min-height:120px; }
        /* Add link form */
        .wnq-add-form { display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:14px; }
        .wnq-add-form select,.wnq-add-form input { padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;width:100%;box-sizing:border-box; }
        .wnq-add-form label { font-size:11px;font-weight:600;color:#374151;display:block;margin-bottom:3px; }
        /* Spinner */
        .wnq-bl-spin { display:inline-block;width:14px;height:14px;border:2px solid #ddd6fe;border-top-color:#7c3aed;border-radius:50%;animation:blspin .7s linear infinite;vertical-align:middle;margin-left:5px; }
        @keyframes blspin { to { transform:rotate(360deg); } }
        /* Verify badge */
        .wnq-verify-ok  { color:#16a34a;font-size:10px;font-weight:700; }
        .wnq-verify-bad { color:#dc2626;font-size:10px;font-weight:700; }
        /* Outreach send */
        .wnq-sent-badge { color:#16a34a;font-size:10px;font-weight:700; }
        .wnq-contact-inp { padding:4px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:11px;width:160px; }
        .wnq-bl-btn-send { background:#0ea5e9;color:#fff; }
        .wnq-bl-btn-send:hover { background:#0284c7; }
        </style>

        <div class="wnq-bl-header">
            <h1>Backlink Manager</h1>
            <span class="wnq-bl-badge">Link Building</span>
        </div>

        <?php /* ── Client selector ── */ ?>
        <form method="get" class="wnq-client-bar">
            <input type="hidden" name="page" value="wnq-backlinks">
            <input type="hidden" name="tab"  value="<?php echo esc_attr($tab); ?>">
            <label style="font-size:12px;font-weight:600;color:#374151;">Client:</label>
            <select name="client_id" onchange="this.form.submit()">
                <?php foreach ($clients as $c): ?>
                    <option value="<?php echo esc_attr($c['client_id']); ?>"
                        <?php selected($client_id, $c['client_id']); ?>>
                        <?php echo esc_html($c['company'] ?: $c['client_id']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($client_id): ?>

        <?php /* ── Stat cards ── */ ?>
        <div class="wnq-bl-stats">
            <?php
            $st_cards = [
                ['cls' => '', 'num' => $stats['total'],     'lbl' => 'Total'],
                ['cls' => 'live', 'num' => $stats['live'],  'lbl' => 'Live'],
                ['cls' => 'sub',  'num' => $stats['submitted'], 'lbl' => 'Submitted'],
                ['cls' => '',     'num' => $stats['pending'],   'lbl' => 'Pending'],
                ['cls' => 'lost', 'num' => $stats['lost'],      'lbl' => 'Lost'],
                ['cls' => '',     'num' => $stats['rejected'],   'lbl' => 'Rejected'],
            ];
            foreach ($st_cards as $sc):
            ?>
                <div class="wnq-bl-stat <?php echo $sc['cls']; ?>">
                    <div class="num"><?php echo (int)$sc['num']; ?></div>
                    <div class="lbl"><?php echo esc_html($sc['lbl']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php /* ── Tabs ── */ ?>
        <div class="wnq-bl-tabs">
            <?php foreach (['citations' => 'Citations (Directories)', 'opportunities' => 'Opportunities', 'links' => 'All Links'] as $t => $label): ?>
                <a href="<?php echo esc_url(add_query_arg(['tab' => $t, 'client_id' => $client_id], $base_url)); ?>"
                   class="wnq-bl-tab <?php echo $tab === $t ? 'active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php
        match ($tab) {
            'opportunities' => self::renderOpportunitiesTab($client_id, $profile, $client, $nonce),
            'links'         => self::renderAllLinksTab($client_id, $nonce),
            default         => self::renderCitationsTab($client_id, $profile, $client, $nonce),
        };
        ?>

        <?php else: ?>
            <div class="wnq-bl-card" style="text-align:center;padding:40px;color:#6b7280;">
                Select a client above to manage their backlinks.
            </div>
        <?php endif; ?>

        </div><!-- .wnq-bl -->

        <script>
        (function() {
            const nonce     = '<?php echo esc_js($nonce); ?>';
            const clientId  = '<?php echo esc_js($client_id); ?>';

            // Generic AJAX helper
            window.blAjax = function(data, onSuccess, btn) {
                if (btn) { btn.disabled = true; }
                data.action    = 'wnq_backlink';
                data.nonce     = nonce;
                data.client_id = clientId;
                fetch(ajaxurl, { method:'POST', body: new URLSearchParams(data) })
                    .then(r => r.json())
                    .then(function(d) {
                        if (btn) btn.disabled = false;
                        if (d.success) onSuccess(d.data);
                        else alert('Error: ' + (d.data?.message || 'Unknown error'));
                    })
                    .catch(function() { if (btn) btn.disabled = false; alert('Network error'); });
            };

            // Status dropdown change
            document.querySelectorAll('.bl-status-sel').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    const id  = this.dataset.id;
                    const val = this.value;
                    const row = document.getElementById('bl-row-' + id);
                    blAjax({ sub_action: 'update_status', link_id: id, status: val }, function(d) {
                        // Update badge
                        const badge = row && row.querySelector('.wnq-bl-status');
                        if (badge) {
                            badge.className = 'wnq-bl-status st-' + val;
                            badge.textContent = val.charAt(0).toUpperCase() + val.slice(1);
                        }
                    });
                });
            });

            // Delete link
            window.blDelete = function(id) {
                if (!confirm('Delete this backlink? This cannot be undone.')) return;
                blAjax({ sub_action: 'delete', link_id: id }, function() {
                    const row = document.getElementById('bl-row-' + id);
                    if (row) row.remove();
                });
            };

            // Save notes on blur
            document.querySelectorAll('.bl-notes').forEach(function(inp) {
                let orig = inp.value;
                inp.addEventListener('blur', function() {
                    if (this.value === orig) return;
                    orig = this.value;
                    blAjax({ sub_action: 'save_notes', link_id: this.dataset.id, notes: this.value }, function() {});
                });
            });

            // Citation: seed
            const seedBtn = document.getElementById('bl-seed-btn');
            seedBtn && seedBtn.addEventListener('click', function() {
                blAjax({ sub_action: 'seed_citations' }, function(d) {
                    alert(d.message);
                    location.reload();
                }, seedBtn);
            });

            // Citation: quick status buttons
            document.querySelectorAll('.bl-quick-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const id  = this.dataset.id;
                    const st  = this.dataset.status;
                    const row = document.getElementById('bl-row-' + id);
                    blAjax({ sub_action: 'update_status', link_id: id, status: st }, function() {
                        location.reload();
                    }, btn);
                });
            });

            // Generate outreach email
            window.blGenEmail = function(id, domain, linkType, btn) {
                btn.disabled = true;
                btn.textContent = 'Generating…';
                blAjax({ sub_action: 'generate_email', link_id: id, target_domain: domain, link_type: linkType }, function(d) {
                    const box = document.getElementById('bl-email-' + id);
                    if (box) {
                        box.style.display = '';
                        box.querySelector('.wnq-email-box').textContent = d.email;
                    }
                    btn.textContent = 'Regenerate';
                    btn.disabled = false;
                }, null);
                btn.disabled = false; // re-enable immediately (AJAX is async)
            };

            // Generate opportunities
            const oppBtn = document.getElementById('bl-gen-opps');
            oppBtn && oppBtn.addEventListener('click', function() {
                const wrap = document.getElementById('bl-opps-wrap');
                wrap.innerHTML = '<div style="padding:20px;text-align:center;color:#7c3aed;">Generating opportunities with AI… <span class="wnq-bl-spin"></span></div>';
                blAjax({ sub_action: 'generate_opps' }, function(d) {
                    if (!d.opportunities || !d.opportunities.length) {
                        wrap.innerHTML = '<p style="color:#dc2626;padding:20px;">No opportunities returned. Check your AI settings.</p>';
                        return;
                    }
                    wrap.innerHTML = '';
                    d.opportunities.forEach(function(opp, i) {
                        const div = document.createElement('div');
                        div.className = 'wnq-opp-card';
                        div.innerHTML =
                            '<h4><span class="wnq-opp-type">' + opp.type + '</span> ' + esc(opp.description) + '</h4>' +
                            '<p>' + esc(opp.value) + '</p>' +
                            '<div class="search-q">🔍 ' + esc(opp.search_query) + '</div>' +
                            '<button class="wnq-bl-btn wnq-bl-btn-secondary wnq-bl-btn-sm" onclick="blGenEmailForOpp(this,' + i + ',\'' + esc(opp.type) + '\',\'' + esc(opp.description) + '\')">Generate Outreach Email</button>' +
                            '<div id="opp-email-' + i + '" style="display:none;margin-top:8px;"><div class="wnq-email-box"></div><button class="wnq-bl-btn wnq-bl-btn-secondary wnq-bl-btn-sm" style="margin-top:4px;" onclick="blCopyEmail(' + i + ')">Copy Email</button></div>';
                        wrap.appendChild(div);
                    });
                }, oppBtn);
            });

            window.blGenEmailForOpp = function(btn, idx, linkType, domain) {
                btn.disabled = true;
                btn.textContent = 'Generating…';
                blAjax({ sub_action: 'generate_email', link_id: 0, target_domain: domain, link_type: linkType }, function(d) {
                    const box = document.getElementById('opp-email-' + idx);
                    if (box) {
                        box.style.display = '';
                        box.querySelector('.wnq-email-box').textContent = d.email;
                    }
                    btn.textContent = 'Regenerate';
                    btn.disabled = false;
                }, null);
                btn.disabled = false;
            };

            window.blCopyEmail = function(idx) {
                const box = document.querySelector('#opp-email-' + idx + ' .wnq-email-box');
                if (box) navigator.clipboard.writeText(box.textContent);
            };

            // Save contact email on blur
            document.querySelectorAll('.bl-contact-email').forEach(function(inp) {
                let orig = inp.value;
                inp.addEventListener('blur', function() {
                    if (this.value === orig) return;
                    orig = this.value;
                    blAjax({ sub_action: 'save_contact_email', link_id: this.dataset.id, contact_email: this.value }, function() {});
                });
            });

            // Send outreach email via wp_mail
            window.blSendEmail = function(id, btn) {
                const inp = document.querySelector('.bl-contact-email[data-id="' + id + '"]');
                const to  = inp ? inp.value.trim() : '';
                if (!to) { alert('Enter a recipient email address first.'); return; }
                if (!confirm('Send outreach email to ' + to + '?')) return;
                btn.textContent = 'Sending\u2026';
                blAjax({ sub_action: 'send_email', link_id: id, contact_email: to }, function() {
                    btn.textContent = 'Resend';
                    const badge = document.getElementById('bl-sent-' + id);
                    if (badge) { badge.textContent = '\u2713 Sent'; badge.style.display = ''; }
                }, btn);
            };

            // Verify all links
            const verBtn = document.getElementById('bl-verify-all');
            verBtn && verBtn.addEventListener('click', function() {
                this.disabled = true;
                this.textContent = 'Verifying…';
                blAjax({ sub_action: 'verify_all' }, function(d) {
                    alert('Verified ' + d.checked + ' links: ' + d.live + ' live, ' + d.lost + ' lost/unreachable.');
                    location.reload();
                }, verBtn);
            });

            // Add custom link
            const addForm = document.getElementById('bl-add-form');
            addForm && addForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const fd = new FormData(addForm);
                blAjax({
                    sub_action:     'add_link',
                    target_domain:  fd.get('target_domain'),
                    submission_url: fd.get('submission_url'),
                    link_type:      fd.get('link_type'),
                    site_name:      fd.get('site_name'),
                }, function(d) {
                    alert('Link added (ID #' + d.link_id + '). Reload to see it.');
                    addForm.reset();
                });
            });

            // Utility: escape HTML for dynamic insertion
            function esc(s) {
                if (typeof s !== 'string') return '';
                return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
            }
        })();
        </script>
        <?php
    }

    // ── Tab: Citations ───────────────────────────────────────────────────────

    private static function renderCitationsTab(string $client_id, ?array $profile, ?array $client, string $nonce): void
    {
        $citations = BacklinkManager::getAll($client_id, ['link_type' => 'citation', 'limit' => 100]);
        $total     = count(BacklinkManager::getCitationSites());
        $live      = count(array_filter($citations, fn($r) => $r['status'] === 'live'));
        $submitted = count(array_filter($citations, fn($r) => $r['status'] === 'submitted'));
        $seeded    = count($citations);

        // NAP data from profile / client
        $services_raw = $profile ? json_decode($profile['primary_services'] ?? '[]', true) : [];
        $locs_raw     = $profile ? json_decode($profile['service_locations'] ?? '[]', true) : [];
        $nap = [
            'Business Name' => $client['company'] ?? '',
            'Website'       => $client['website'] ?? '',
            'Phone'         => $client['phone']   ?? '',
            'City'          => $locs_raw[0]        ?? '',
            'Services'      => implode(', ', array_slice((array)$services_raw, 0, 3)),
        ];
        ?>
        <?php /* ── NAP Copy Card ── */ ?>
        <div class="wnq-nap-card">
            <h4>NAP Data — Copy this when submitting each listing</h4>
            <?php foreach ($nap as $label => $val): ?>
                <div class="wnq-nap-row">
                    <strong><?php echo esc_html($label); ?></strong>
                    <span><?php echo esc_html($val ?: '—'); ?></span>
                    <?php if ($val): ?>
                        <button class="wnq-nap-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js($val); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500);">Copy</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php /* ── Progress ── */ ?>
        <div class="wnq-bl-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <div>
                    <strong>Citation Progress</strong>
                    <span style="color:#6b7280;font-size:12px;margin-left:8px;"><?php echo $live; ?> live / <?php echo $submitted; ?> submitted / <?php echo $total; ?> total</span>
                </div>
                <div style="display:flex;gap:8px;">
                    <?php if ($seeded < $total): ?>
                        <button class="wnq-bl-btn wnq-bl-btn-primary wnq-bl-btn-sm" id="bl-seed-btn">
                            + Load Citation Sites (<?php echo $total - $seeded; ?> new)
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($seeded > 0): ?>
            <div class="wnq-cit-progress">
                <div style="font-size:11px;color:#6b7280;margin-bottom:3px;"><?php echo round($live / $total * 100); ?>% live</div>
                <div class="wnq-cit-bar-wrap"><div class="wnq-cit-bar" style="width:<?php echo round($live / $total * 100); ?>%"></div></div>
            </div>
            <div class="wnq-cit-progress">
                <div style="font-size:11px;color:#6b7280;margin-bottom:3px;"><?php echo round(($live + $submitted) / $total * 100); ?>% submitted or live</div>
                <div class="wnq-cit-bar-wrap"><div class="wnq-cit-bar" style="width:<?php echo round(($live + $submitted) / $total * 100); ?>%;background:#2563eb;"></div></div>
            </div>
            <?php endif; ?>
        </div>

        <?php /* ── Citation Table ── */ ?>
        <div class="wnq-bl-card" style="padding:0;overflow:hidden;">
            <?php if (empty($citations)): ?>
                <div style="padding:30px;text-align:center;color:#6b7280;">
                    No citation sites loaded yet. Click "Load Citation Sites" to get started.
                </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="wnq-bl-tbl">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>DA</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Verified</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($citations as $row):
                    $da    = (int)$row['da_estimate'];
                    $dacls = $da >= 70 ? 'da-high' : ($da >= 50 ? 'da-med' : 'da-low');
                    $st    = $row['status'];
                ?>
                    <tr id="bl-row-<?php echo (int)$row['id']; ?>">
                        <td>
                            <strong style="font-size:12px;"><?php echo esc_html($row['site_name'] ?: $row['target_domain']); ?></strong>
                            <br>
                            <a href="<?php echo esc_url($row['submission_url'] ?: 'https://' . $row['target_domain']); ?>"
                               target="_blank" rel="noopener"
                               style="color:#7c3aed;font-size:10px;">
                                <?php echo esc_html($row['target_domain']); ?> ↗
                            </a>
                        </td>
                        <td><span class="wnq-bl-da <?php echo $dacls; ?>"><?php echo $da; ?></span></td>
                        <td><span class="wnq-bl-status st-<?php echo esc_attr($st); ?>"><?php echo esc_html(ucfirst($st)); ?></span></td>
                        <td style="font-size:10px;color:#6b7280;"><?php echo $row['submitted_at'] ? esc_html(date('M j, Y', strtotime($row['submitted_at']))) : '—'; ?></td>
                        <td style="font-size:10px;">
                            <?php if ($row['verified_live']): ?>
                                <span class="wnq-verify-ok">✓ Live</span>
                            <?php elseif ($st === 'submitted'): ?>
                                <span style="color:#9ca3af;font-size:10px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($st === 'pending'): ?>
                                <button class="wnq-bl-btn wnq-bl-btn-secondary wnq-bl-btn-sm bl-quick-btn" data-id="<?php echo (int)$row['id']; ?>" data-status="submitted">Mark Submitted</button>
                            <?php elseif ($st === 'submitted'): ?>
                                <button class="wnq-bl-btn wnq-bl-btn-sm bl-quick-btn" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;" data-id="<?php echo (int)$row['id']; ?>" data-status="live">Mark Live ✓</button>
                                <button class="wnq-bl-btn wnq-bl-btn-sm wnq-bl-btn-secondary bl-quick-btn" data-id="<?php echo (int)$row['id']; ?>" data-status="rejected" style="margin-left:3px;">Rejected</button>
                            <?php elseif ($st === 'live'): ?>
                                <span style="color:#16a34a;font-size:11px;font-weight:600;">✓ Live</span>
                            <?php elseif ($st === 'lost'): ?>
                                <button class="wnq-bl-btn wnq-bl-btn-sm bl-quick-btn" style="background:#fee2e2;color:#dc2626;" data-id="<?php echo (int)$row['id']; ?>" data-status="submitted">Re-submit</button>
                            <?php endif; ?>
                            <button class="wnq-bl-btn wnq-bl-btn-danger wnq-bl-btn-sm" onclick="blDelete(<?php echo (int)$row['id']; ?>)" style="margin-left:3px;">✕</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Tab: Opportunities ───────────────────────────────────────────────────

    private static function renderOpportunitiesTab(string $client_id, ?array $profile, ?array $client, string $nonce): void
    {
        $opportunities = BacklinkManager::getAll($client_id, [
            'limit' => 100,
        ]);
        $non_citations = array_filter($opportunities, fn($r) => $r['link_type'] !== 'citation');
        ?>
        <div class="wnq-bl-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3 style="margin:0 0 4px;">AI-Generated Opportunities</h3>
                    <p style="margin:0;font-size:12px;color:#6b7280;">
                        AI analyzes the client's industry, services, and location to generate 8 targeted link-building ideas — each with a Google search query and an outreach email.
                    </p>
                </div>
                <button class="wnq-bl-btn wnq-bl-btn-primary" id="bl-gen-opps">Generate Opportunities</button>
            </div>
            <div id="bl-opps-wrap" style="margin-top:16px;"></div>
        </div>

        <?php /* ── Add Custom Opportunity ── */ ?>
        <div class="wnq-bl-card">
            <h3>Add Custom Opportunity</h3>
            <form id="bl-add-form">
                <div class="wnq-add-form">
                    <div>
                        <label>Domain / Site</label>
                        <input type="text" name="target_domain" placeholder="e.g. homeimprovementblog.com" required>
                    </div>
                    <div>
                        <label>Submission URL</label>
                        <input type="url" name="submission_url" placeholder="https://…">
                    </div>
                    <div>
                        <label>Site Name</label>
                        <input type="text" name="site_name" placeholder="e.g. Home Improvement Hub">
                    </div>
                    <div>
                        <label>Type</label>
                        <select name="link_type">
                            <option value="guest_post">Guest Post</option>
                            <option value="resource_page">Resource Page</option>
                            <option value="directory">Directory</option>
                            <option value="sponsor">Sponsor</option>
                            <option value="press">Press / PR</option>
                            <option value="partnership">Partnership</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="wnq-bl-btn wnq-bl-btn-secondary wnq-bl-btn-sm">Add Link</button>
            </form>
        </div>

        <?php /* ── Saved Opportunities ── */ ?>
        <?php if (!empty($non_citations)): ?>
        <div class="wnq-bl-card">
            <h3>Saved Opportunities (<?php echo count($non_citations); ?>)</h3>
            <div style="overflow-x:auto;">
            <table class="wnq-bl-tbl">
                <thead><tr>
                    <th>Site / Type</th><th>Status</th><th>Outreach Email</th><th>Notes</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($non_citations as $row): ?>
                    <tr id="bl-row-<?php echo (int)$row['id']; ?>">
                        <td>
                            <strong><?php echo esc_html($row['site_name'] ?: $row['target_domain']); ?></strong>
                            <br><span class="wnq-opp-type" style="font-size:9px;"><?php echo esc_html(str_replace('_', ' ', $row['link_type'])); ?></span>
                            <?php if ($row['submission_url']): ?>
                                <br><a href="<?php echo esc_url($row['submission_url']); ?>" target="_blank" rel="noopener" style="font-size:10px;color:#7c3aed;"><?php echo esc_html($row['target_domain']); ?> ↗</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select class="bl-status-sel" data-id="<?php echo (int)$row['id']; ?>" style="font-size:11px;padding:3px 6px;border-radius:5px;border:1px solid #d1d5db;">
                                <?php foreach (['pending','submitted','live','rejected','lost'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php selected($row['status'], $s); ?>><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <button class="wnq-bl-btn wnq-bl-btn-secondary wnq-bl-btn-sm"
                                    onclick="blGenEmail(<?php echo (int)$row['id']; ?>,'<?php echo esc_js($row['target_domain']); ?>','<?php echo esc_js($row['link_type']); ?>',this)">
                                <?php echo $row['outreach_email'] ? 'View / Regen' : 'Generate Email'; ?>
                            </button>
                            <div id="bl-email-<?php echo (int)$row['id']; ?>" style="display:<?php echo $row['outreach_email'] ? '' : 'none'; ?>;margin-top:6px;">
                                <div class="wnq-email-box"><?php echo esc_html($row['outreach_email'] ?? ''); ?></div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:5px;flex-wrap:wrap;">
                                    <button class="wnq-bl-btn wnq-bl-btn-secondary wnq-bl-btn-sm" onclick="navigator.clipboard.writeText(document.querySelector('#bl-email-<?php echo (int)$row['id']; ?> .wnq-email-box').textContent)">Copy</button>
                                    <input type="email" class="bl-contact-email wnq-contact-inp" data-id="<?php echo (int)$row['id']; ?>"
                                           value="<?php echo esc_attr($row['contact_email'] ?? ''); ?>" placeholder="Recipient email…">
                                    <button class="wnq-bl-btn wnq-bl-btn-send wnq-bl-btn-sm" onclick="blSendEmail(<?php echo (int)$row['id']; ?>, this)">
                                        <?php echo $row['outreach_sent_at'] ? 'Resend' : 'Send'; ?>
                                    </button>
                                    <?php if ($row['outreach_sent_at']): ?>
                                        <span class="wnq-sent-badge" id="bl-sent-<?php echo (int)$row['id']; ?>">&#10003; Sent <?php echo esc_html(date('M j', strtotime($row['outreach_sent_at']))); ?></span>
                                    <?php else: ?>
                                        <span class="wnq-sent-badge" id="bl-sent-<?php echo (int)$row['id']; ?>" style="display:none;"></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <input type="text" class="bl-notes" data-id="<?php echo (int)$row['id']; ?>"
                                   value="<?php echo esc_attr($row['notes'] ?? ''); ?>"
                                   placeholder="Add note…"
                                   style="width:120px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11px;">
                        </td>
                        <td><button class="wnq-bl-btn wnq-bl-btn-danger wnq-bl-btn-sm" onclick="blDelete(<?php echo (int)$row['id']; ?>)">✕</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    // ── Tab: All Links ───────────────────────────────────────────────────────

    private static function renderAllLinksTab(string $client_id, string $nonce): void
    {
        $links = BacklinkManager::getAll($client_id, ['limit' => 200]);
        ?>
        <div class="wnq-bl-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div>
                    <h3 style="margin:0 0 2px;">All Links (<?php echo count($links); ?>)</h3>
                    <p style="margin:0;font-size:12px;color:#6b7280;">
                        Verify Live sends a HEAD request to each submitted/live link to confirm it still resolves.
                    </p>
                </div>
                <button class="wnq-bl-btn wnq-bl-btn-secondary" id="bl-verify-all">Verify Live Links</button>
            </div>
        </div>
        <div class="wnq-bl-card" style="padding:0;overflow:hidden;">
            <?php if (empty($links)): ?>
                <div style="padding:30px;text-align:center;color:#6b7280;">No links tracked yet. Load citations or add opportunities.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="wnq-bl-tbl">
                <thead><tr>
                    <th>Site</th><th>Type</th><th>DA</th><th>Status</th><th>Submitted</th><th>Last Verified</th><th>Outreach</th><th>Notes</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($links as $row):
                    $da    = (int)$row['da_estimate'];
                    $dacls = $da >= 70 ? 'da-high' : ($da >= 50 ? 'da-med' : 'da-low');
                ?>
                    <tr id="bl-row-<?php echo (int)$row['id']; ?>">
                        <td>
                            <strong style="font-size:12px;"><?php echo esc_html($row['site_name'] ?: $row['target_domain']); ?></strong>
                            <br>
                            <a href="<?php echo esc_url($row['submission_url'] ?: 'https://' . $row['target_domain']); ?>"
                               target="_blank" rel="noopener" style="color:#7c3aed;font-size:10px;">
                                <?php echo esc_html($row['target_domain']); ?> ↗
                            </a>
                        </td>
                        <td style="font-size:11px;"><?php echo esc_html(str_replace('_', ' ', $row['link_type'])); ?></td>
                        <td><?php echo $da ? '<span class="wnq-bl-da ' . $dacls . '">' . $da . '</span>' : '—'; ?></td>
                        <td>
                            <select class="bl-status-sel" data-id="<?php echo (int)$row['id']; ?>" style="font-size:11px;padding:3px 6px;border-radius:5px;border:1px solid #d1d5db;">
                                <?php foreach (['pending','submitted','live','rejected','lost'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php selected($row['status'], $s); ?>><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="font-size:10px;color:#6b7280;white-space:nowrap;"><?php echo $row['submitted_at'] ? esc_html(date('M j, Y', strtotime($row['submitted_at']))) : '—'; ?></td>
                        <td style="font-size:10px;white-space:nowrap;">
                            <?php if ($row['verified_at']): ?>
                                <?php if ($row['verified_live']): ?>
                                    <span class="wnq-verify-ok">✓ <?php echo esc_html(date('M j', strtotime($row['verified_at']))); ?></span>
                                <?php else: ?>
                                    <span class="wnq-verify-bad">✗ <?php echo esc_html(date('M j', strtotime($row['verified_at']))); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['outreach_email']): ?>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <input type="email" class="bl-contact-email wnq-contact-inp" data-id="<?php echo (int)$row['id']; ?>"
                                           value="<?php echo esc_attr($row['contact_email'] ?? ''); ?>" placeholder="Recipient email…">
                                    <div style="display:flex;align-items:center;gap:5px;">
                                        <button class="wnq-bl-btn wnq-bl-btn-send wnq-bl-btn-sm" onclick="blSendEmail(<?php echo (int)$row['id']; ?>, this)">
                                            <?php echo $row['outreach_sent_at'] ? 'Resend' : 'Send'; ?>
                                        </button>
                                        <?php if ($row['outreach_sent_at']): ?>
                                            <span class="wnq-sent-badge" id="bl-sent-<?php echo (int)$row['id']; ?>">&#10003; <?php echo esc_html(date('M j', strtotime($row['outreach_sent_at']))); ?></span>
                                        <?php else: ?>
                                            <span class="wnq-sent-badge" id="bl-sent-<?php echo (int)$row['id']; ?>" style="display:none;"></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-size:10px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" class="bl-notes" data-id="<?php echo (int)$row['id']; ?>"
                                   value="<?php echo esc_attr($row['notes'] ?? ''); ?>"
                                   placeholder="Notes…"
                                   style="width:120px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11px;">
                        </td>
                        <td><button class="wnq-bl-btn wnq-bl-btn-danger wnq-bl-btn-sm" onclick="blDelete(<?php echo (int)$row['id']; ?>)">✕</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── AJAX Handler ────────────────────────────────────────────────────────

    public static function handleAjax(): void
    {
        if (!check_ajax_referer('wnq_backlink_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed — refresh and try again.']);
        }
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied'], 403);
        }

        $client_id  = sanitize_text_field($_POST['client_id']  ?? '');
        $sub_action = sanitize_key($_POST['sub_action']        ?? '');
        $link_id    = (int)($_POST['link_id']                  ?? 0);

        switch ($sub_action) {

            case 'seed_citations':
                if (!$client_id) { wp_send_json_error(['message' => 'No client']); }
                $added = BacklinkManager::seedCitations($client_id);
                wp_send_json_success(['message' => $added > 0
                    ? "{$added} citation sites added."
                    : 'All citation sites already loaded.'
                ]);

            case 'update_status':
                $status = sanitize_key($_POST['status'] ?? '');
                if (!in_array($status, ['pending','submitted','live','rejected','lost'], true)) {
                    wp_send_json_error(['message' => 'Invalid status']);
                }
                BacklinkManager::updateStatus($link_id, $status);
                wp_send_json_success(['status' => $status]);

            case 'delete':
                BacklinkManager::delete($link_id);
                wp_send_json_success();

            case 'save_notes':
                $notes = sanitize_textarea_field($_POST['notes'] ?? '');
                BacklinkManager::update($link_id, ['notes' => $notes]);
                wp_send_json_success();

            case 'add_link':
                if (!$client_id) { wp_send_json_error(['message' => 'No client']); }
                $domain = sanitize_text_field($_POST['target_domain'] ?? '');
                if (!$domain) { wp_send_json_error(['message' => 'Domain required']); }
                $new_id = BacklinkManager::insert([
                    'client_id'      => $client_id,
                    'link_type'      => sanitize_key($_POST['link_type'] ?? 'guest_post'),
                    'target_domain'  => $domain,
                    'site_name'      => sanitize_text_field($_POST['site_name'] ?? ''),
                    'submission_url' => esc_url_raw($_POST['submission_url'] ?? ''),
                    'status'         => 'pending',
                ]);
                wp_send_json_success(['link_id' => $new_id]);

            case 'generate_email':
                if (!$client_id) { wp_send_json_error(['message' => 'No client']); }
                $profile  = SEOHub::getProfile($client_id);
                $client   = Client::getByClientId($client_id);
                $services = json_decode($profile['primary_services'] ?? '[]', true);
                $locs     = json_decode($profile['service_locations'] ?? '[]', true);

                $result = AIEngine::generate('backlink_outreach_email', [
                    'business_name' => $client['company'] ?? $client_id,
                    'website'       => $client['website'] ?? '',
                    'services'      => implode(', ', array_slice((array)$services, 0, 3)),
                    'location'      => $locs[0] ?? 'local area',
                    'link_type'     => sanitize_text_field($_POST['link_type'] ?? 'guest_post'),
                    'target_domain' => sanitize_text_field($_POST['target_domain'] ?? ''),
                ], $client_id, ['max_tokens' => 350, 'cache_ttl' => 0]);

                if (!$result['success']) {
                    wp_send_json_error(['message' => $result['error'] ?? 'AI generation failed']);
                }

                $email = $result['content'];
                // Persist against the link record if a valid link_id was passed
                if ($link_id) {
                    BacklinkManager::update($link_id, ['outreach_email' => $email]);
                }
                wp_send_json_success(['email' => $email]);

            case 'generate_opps':
                if (!$client_id) { wp_send_json_error(['message' => 'No client']); }
                @set_time_limit(60);
                $profile  = SEOHub::getProfile($client_id);
                $client   = Client::getByClientId($client_id);
                $services = json_decode($profile['primary_services'] ?? '[]', true);
                $locs     = json_decode($profile['service_locations'] ?? '[]', true);

                $result = AIEngine::generate('backlink_opportunities', [
                    'business_name' => $client['company'] ?? $client_id,
                    'website'       => $client['website'] ?? '',
                    'services'      => implode(', ', array_slice((array)$services, 0, 3)),
                    'location'      => $locs[0] ?? 'local area',
                ], $client_id, ['max_tokens' => 800, 'cache_ttl' => 0]);

                if (!$result['success']) {
                    wp_send_json_error(['message' => $result['error'] ?? 'AI generation failed']);
                }

                // Parse JSON from AI response
                $content = $result['content'];
                if (preg_match('/\[.*\]/s', $content, $m)) {
                    $opps = json_decode($m[0], true);
                } else {
                    $opps = json_decode($content, true);
                }

                wp_send_json_success(['opportunities' => is_array($opps) ? $opps : []]);

            case 'save_contact_email':
                $contact_email = sanitize_email($_POST['contact_email'] ?? '');
                BacklinkManager::update($link_id, ['contact_email' => $contact_email]);
                wp_send_json_success();

            case 'send_email':
                if (!$client_id) { wp_send_json_error(['message' => 'No client']); }
                $row = BacklinkManager::getById($link_id);
                if (!$row) { wp_send_json_error(['message' => 'Link not found']); }
                if (empty($row['outreach_email'])) {
                    wp_send_json_error(['message' => 'No email draft — generate one first.']);
                }
                $to = sanitize_email($_POST['contact_email'] ?? '');
                if (!$to || !is_email($to)) {
                    wp_send_json_error(['message' => 'Enter a valid recipient email address.']);
                }
                // Persist contact email if new
                if (($row['contact_email'] ?? '') !== $to) {
                    BacklinkManager::update($link_id, ['contact_email' => $to]);
                }
                $sender_client = Client::getByClientId($client_id);
                $from_name     = $sender_client['company'] ?? get_bloginfo('name') ?: 'WebNique';
                $headers       = [
                    'Content-Type: text/plain; charset=UTF-8',
                    'From: ' . $from_name . ' <chris@web-nique.com>',
                    'Reply-To: chris@web-nique.com',
                ];
                $subject = 'Link Building Opportunity — ' . $from_name;
                $sent    = wp_mail($to, $subject, $row['outreach_email'], $headers);
                if (!$sent) {
                    wp_send_json_error(['message' => 'Email failed to send. Check WordPress mail settings.']);
                }
                BacklinkManager::update($link_id, ['outreach_sent_at' => current_time('mysql')]);
                wp_send_json_success(['sent' => true]);

            case 'verify_all':
                if (!$client_id) { wp_send_json_error(['message' => 'No client']); }
                @set_time_limit(120);
                $results = BacklinkVerifier::verifyClientLinks($client_id);
                wp_send_json_success($results);

            default:
                wp_send_json_error(['message' => 'Unknown action: ' . $sub_action]);
        }
    }
}
