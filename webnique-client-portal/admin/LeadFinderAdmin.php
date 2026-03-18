<?php
/**
 * Lead Finder Admin UI
 *
 * Tabs:
 *   ZIP Sweep  — bulk Florida ZIP code sweep via Google Maps scraping (no API key)
 *   Manual     — paste URLs manually for SEO scoring + enrichment
 *   All Leads  — browse, filter, manage and export saved leads
 *   Settings   — default keyword and filter settings
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
        // Priority 22 — must run AFTER SEOHubAdmin::addMenuPages() (priority 20)
        add_action('admin_menu', [self::class, 'addMenuPage'], 22);

        // ZIP sweep (Google Maps scraping, no API key)
        add_action('wp_ajax_wnq_zip_start',   [self::class, 'ajaxZipStart']);
        add_action('wp_ajax_wnq_zip_process', [self::class, 'ajaxZipProcess']);

        // Manual URL queue
        add_action('wp_ajax_wnq_lead_queue_manual',        [self::class, 'ajaxQueueManual']);
        add_action('wp_ajax_wnq_lead_process_next_manual', [self::class, 'ajaxProcessNextManual']);

        // Lead management
        add_action('wp_ajax_wnq_lead_update_status', [self::class, 'ajaxUpdateStatus']);
        add_action('wp_ajax_wnq_lead_update_notes',  [self::class, 'ajaxUpdateNotes']);
        add_action('wp_ajax_wnq_lead_delete',        [self::class, 'ajaxDelete']);
        add_action('wp_ajax_wnq_lead_bulk_action',   [self::class, 'ajaxBulkAction']);
        add_action('wp_ajax_wnq_lead_run_migration', [self::class, 'ajaxRunMigration']);
        add_action('wp_ajax_wnq_npm_install',        [self::class, 'ajaxNpmInstall']);

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
        .wnq-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px}
        .wnq-field{display:flex;flex-direction:column;gap:4px}
        .wnq-field label{font-size:12px;font-weight:600;color:#374151}
        .wnq-field input,.wnq-field select,.wnq-field textarea{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:100%;box-sizing:border-box}
        .wnq-field textarea{resize:vertical;min-height:90px}
        .wnq-field small{color:#6b7280;font-size:11px}
        .wnq-btn{padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none}
        .wnq-btn-primary{background:#2563eb;color:#fff}
        .wnq-btn-primary:hover{background:#1d4ed8}
        .wnq-btn-secondary{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}
        .wnq-btn-secondary:hover{background:#e5e7eb}
        .wnq-btn-danger{background:#dc2626;color:#fff}
        .wnq-btn-sm{padding:4px 10px;font-size:11px}
        .wnq-progress{display:none;flex-direction:column;gap:8px;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-top:14px;color:#1d4ed8;font-weight:500}
        .wnq-progress.show{display:flex}
        .wnq-progress-row{display:flex;align-items:center;gap:10px}
        .wnq-pbar-wrap{background:#dbeafe;border-radius:4px;height:10px;flex:1;overflow:hidden}
        .wnq-pbar{background:#2563eb;height:10px;width:0;border-radius:4px;transition:width .3s}
        .wnq-sub-status{font-size:11px;color:#3b82f6;font-weight:400}
        .wnq-spinner{width:18px;height:18px;border:3px solid #bfdbfe;border-top-color:#2563eb;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0}
        @keyframes spin{to{transform:rotate(360deg)}}
        .wnq-progressbar-wrap{background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;margin:8px 0}
        .wnq-progressbar{height:100%;background:#2563eb;border-radius:6px;transition:width .3s ease;width:0%}
        .wnq-live-stats{display:flex;gap:16px;flex-wrap:wrap;padding:10px 0;font-size:12px}
        .wnq-ls-item{display:flex;align-items:center;gap:5px}
        .wnq-ls-num{font-size:18px;font-weight:700;color:#1e293b}
        .wnq-ls-lbl{color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.4px}
        .wnq-live-stats span{display:flex;align-items:center;gap:4px}
        .wnq-live-stats b{font-size:15px;font-weight:700}
        .wnq-result{padding:14px;border-radius:8px;margin-top:14px}
        .wnq-result-ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
        .wnq-result-err{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626}
        .wnq-result strong{display:block;font-size:14px;margin-bottom:6px}
        .wnq-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
        .wnq-filters select,.wnq-filters input{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px}
        .wnq-tbl-wrap{overflow-x:auto}
        table.wnq-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:1100px}
        table.wnq-tbl th{background:#f9fafb;padding:9px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;white-space:nowrap}
        table.wnq-tbl td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top}
        table.wnq-tbl tr:hover td{background:#fafafa}
        .st-new{background:#dbeafe;color:#1d4ed8}.st-contacted{background:#fef3c7;color:#b45309}
        .st-qualified{background:#d1fae5;color:#065f46}.st-closed{background:#f3f4f6;color:#6b7280}
        .wnq-social{display:flex;gap:4px;flex-wrap:wrap}
        .wnq-social a{display:inline-block;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;text-decoration:none}
        .s-fb{background:#1877f2;color:#fff}.s-ig{background:#e1306c;color:#fff}
        .s-li{background:#0a66c2;color:#fff}.s-tw{background:#1da1f2;color:#fff}
        .s-yt{background:#ff0000;color:#fff}.s-tt{background:#010101;color:#fff}
        .wnq-paginate{display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:12px;color:#6b7280}
        .wnq-paginate .pages{display:flex;gap:3px}
        .wnq-paginate a{padding:3px 8px;border:1px solid #e5e7eb;border-radius:4px;text-decoration:none;color:#374151}
        .wnq-paginate a.cur{background:#2563eb;color:#fff;border-color:#2563eb}
        .wnq-notes-edit{width:140px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11px}
        .wnq-status-sel{font-size:11px;padding:3px 6px;border-radius:5px;border:1px solid #d1d5db}
        .wnq-bulk-bar{display:none;gap:10px;align-items:center;padding:10px 14px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;margin-bottom:12px;font-size:12px}
        .wnq-bulk-bar.show{display:flex}
        .wnq-bulk-count{font-weight:600}
        </style>

        <div class="wnq-lf-header">
            <h1>Lead Finder</h1>
            <span class="wnq-lf-badge">No API Key Required</span>
        </div>

        <div class="wnq-lf-stats">
            <?php foreach (['Total Leads'=>$stats['total']??0,'New'=>$stats['new']??0,'Contacted'=>$stats['contacted']??0,'Qualified'=>$stats['qualified']??0,'With Email'=>$stats['with_email']??0] as $label=>$num): ?>
                <div class="wnq-stat"><div class="num"><?php echo esc_html(number_format($num)); ?></div><div class="lbl"><?php echo esc_html($label); ?></div></div>
            <?php endforeach; ?>
        </div>

        <div class="wnq-lf-tabs">
            <?php foreach (['search'=>'ZIP Sweep','manual'=>'Manual URLs','leads'=>'All Leads','settings'=>'Settings'] as $t=>$label): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab='.$t)); ?>" class="wnq-lf-tab <?php echo $tab===$t?'active':''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </div>

        <?php
        match ($tab) {
            'leads'    => self::renderLeadsTab(),
            'settings' => self::renderSettingsTab($settings),
            'manual'   => self::renderManualTab($settings),
            default    => self::renderZipSweepTab($settings),
        };
        ?>
        </div>
        <?php
    }

    // ── Tab: ZIP Sweep ───────────────────────────────────────────────────────

    private static function renderZipSweepTab(array $settings): void
    {
        $nonce      = wp_create_nonce('wnq_lead_nonce');
        $total_zips = count(\WNQ\Data\FloridaZips::getAll());
        ?>
        <div class="wnq-card">
            <h3>Florida ZIP Code Sweep</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 16px">
                Searches all <?php echo esc_html(number_format($total_zips)); ?> Florida ZIP codes on Google Maps.
                Saves leads with <strong>&lt; 50 reviews</strong> that have a phone number.
                Scrapes homepage for email + social links. <strong>Zero API cost.</strong>
            </p>
            <div class="wnq-row2" style="max-width:600px">
                <div class="wnq-field">
                    <label for="lf-keyword">Industry / Keyword</label>
                    <input type="text" id="lf-keyword" placeholder="e.g. pressure washing" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    <small>Used as: <code>pressure washing 34211</code></small>
                </div>
                <div class="wnq-field">
                    <label for="lf-delay">Delay between ZIPs (seconds)</label>
                    <input type="number" id="lf-delay" value="3" min="1" max="30" step="1">
                    <small>Recommended 3–5 s to avoid rate limits. <?php echo esc_html(number_format($total_zips)); ?> ZIPs × 3 s ≈ <?php echo esc_html(round($total_zips * 3 / 60)); ?> min.</small>
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;margin-top:4px">
                <button class="wnq-btn wnq-btn-primary" id="lf-start-btn" onclick="wnqZipStart()">&#9654; Start ZIP Sweep</button>
                <button class="wnq-btn wnq-btn-secondary" id="lf-stop-btn" style="display:none" onclick="wnqZipStop()">&#9646;&#9646; Stop</button>
            </div>
            <div class="wnq-progress" id="lf-progress">
                <div class="wnq-progress-row"><div class="wnq-spinner"></div><span id="lf-status-text">Starting…</span></div>
                <div class="wnq-progress-row"><div class="wnq-pbar-wrap"><div class="wnq-pbar" id="lf-pbar"></div></div><span id="lf-pct" style="font-size:12px;white-space:nowrap">0%</span></div>
                <div class="wnq-live-stats" style="margin:6px 0">
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-saved">0</span>&nbsp;<span class="wnq-ls-lbl">Saved</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-duplicate">0</span>&nbsp;<span class="wnq-ls-lbl">Dupe</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-no_phone">0</span>&nbsp;<span class="wnq-ls-lbl">No Phone</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-no_website">0</span>&nbsp;<span class="wnq-ls-lbl">No Website</span></div>
                    <div class="wnq-ls-item"><span class="wnq-ls-num" id="ls-zips">0</span>&nbsp;<span class="wnq-ls-lbl">ZIPs Done</span></div>
                </div>
                <div id="lf-log" style="background:#0f172a;border-radius:6px;padding:10px 12px;font-family:monospace;font-size:11px;color:#94a3b8;max-height:200px;overflow-y:auto;line-height:1.7"></div>
            </div>
            <div class="wnq-result" id="lf-result" style="display:none"></div>
        </div>
        <script>
        (function(){
            'use strict';
            const NONCE=<?php echo wp_json_encode($nonce); ?>,AJAX_URL=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,ABORT_MS=60000;
            const delay=ms=>new Promise(r=>setTimeout(r,ms));
            let batchId='',totalZips=0,running=false,stopFlag=false,consecFail=0,zipDelayMs=3000;
            const MAX_FAIL=5;
            function wnqLog(msg,color){
                const el=document.getElementById('lf-log');if(!el)return;
                const line=document.createElement('div');line.style.color=color||'#94a3b8';line.textContent=msg;
                el.appendChild(line);el.scrollTop=el.scrollHeight;
            }
            window.wnqZipStart=async function(){
                const keyword=document.getElementById('lf-keyword').value.trim();
                if(!keyword){alert('Please enter a keyword.');return;}
                zipDelayMs=Math.max(1000,(parseInt(document.getElementById('lf-delay').value,10)||3)*1000);
                running=true;stopFlag=false;consecFail=0;
                document.getElementById('lf-start-btn').style.display='none';
                document.getElementById('lf-stop-btn').style.display='';
                document.getElementById('lf-result').style.display='none';
                document.getElementById('lf-log').innerHTML='';
                wnqShowProgress(true);wnqSetStatus('Initialising search…');
                wnqLog('Starting ZIP sweep for "'+keyword+'"…','#60a5fa');
                try{
                    const sr=await wnqPost({action:'wnq_zip_start',nonce:NONCE,keyword});
                    if(!sr.success){wnqShowResult(false,sr.data?.error||'Failed to start.');wnqDone();return;}
                    batchId=sr.data.batch_id;totalZips=sr.data.total_zips||0;
                    wnqSetStatus('Searching ZIP 0 of '+totalZips+'…');
                    await wnqLoop();
                }catch(err){wnqShowResult(false,'Fatal: '+err.message);wnqDone();}
            };
            window.wnqZipStop=function(){stopFlag=true;wnqSetStatus('Stopping…');};
            async function wnqLoop(){
                while(running&&!stopFlag){
                    let resp;
                    try{resp=await wnqPost({action:'wnq_zip_process',nonce:NONCE,batch_id:batchId});}
                    catch(err){if(++consecFail>=MAX_FAIL){wnqShowResult(false,'Stopped after '+MAX_FAIL+' failures.');wnqDone();return;}wnqLog('Network error: '+err.message,'#f87171');continue;}
                    if(!resp.success){wnqShowResult(false,resp.data?.error||'Server error.');wnqDone();return;}
                    consecFail=0;const d=resp.data||{};
                    const zipIdx=typeof d.zip_index==='number'?d.zip_index:0,total=typeof d.total_zips==='number'?d.total_zips:(totalZips||1);
                    wnqSetProgress(Math.min(100,Math.round(zipIdx/total*100)));
                    if(d.action==='zip_searched'){
                        wnqSetStatus('ZIP '+zipIdx+' of '+total+' ('+d.zip+')');
                        wnqLog('🗺  Google Maps ZIP '+d.zip+' → '+(d.found||0)+' businesses found','#60a5fa');
                        if(!d.done&&!stopFlag)await delay(zipDelayMs);
                    } else if(d.action==='candidate'){
                        wnqSetStatus('ZIP '+zipIdx+' of '+total+' — scraping website…');
                        const name=d.name||'unknown';
                        const outcome=d.outcome||'?';
                        if(outcome==='saved'){wnqLog('  ✓ Saved: '+name,'#4ade80');}
                        else if(outcome==='duplicate'){wnqLog('  ⟳ Dupe: '+name,'#94a3b8');}
                        else if(outcome==='no_phone'){wnqLog('  ✗ No phone found: '+name,'#fb923c');}
                        else if(outcome==='no_website'){wnqLog('  ✗ No website: '+name,'#fb923c');}
                        else{wnqLog('  — Skipped: '+name+' ('+outcome+')','#94a3b8');}
                    } else if(d.action==='complete'){wnqSetProgress(100);wnqSetStatus('Complete!');}
                    const s=d.stats||{};
                    ['saved','duplicate','no_phone','no_website'].forEach(k=>{try{document.getElementById('ls-'+k).textContent=s[k]||0;}catch(_){}});
                    try{document.getElementById('ls-zips').textContent=s.zips_searched||0;}catch(_){}
                    if(d.done){wnqLog('Sweep complete — '+(s.saved||0)+' leads saved.','#4ade80');wnqShowResult(true,'Sweep complete! <strong>'+(s.saved||0)+' leads saved</strong>. ZIPs: '+(s.zips_searched||0)+' | Dupes: '+(s.duplicate||0)+' | No Phone: '+(s.no_phone||0)+' | No Site: '+(s.no_website||0));wnqDone();return;}
                }
                if(stopFlag){wnqLog('Stopped manually.','#fbbf24');wnqShowResult(false,'Search stopped manually.');wnqDone();}
            }
            async function wnqPost(data){
                const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),ABORT_MS);
                try{const r=await fetch(AJAX_URL,{method:'POST',body:new URLSearchParams(data),signal:ctrl.signal,headers:{'Content-Type':'application/x-www-form-urlencoded'}});clearTimeout(timer);const t=await r.text();try{return JSON.parse(t);}catch(_){throw new Error('Non-JSON: '+t.substring(0,200));}}
                catch(e){clearTimeout(timer);throw e;}
            }
            function wnqShowProgress(s){document.getElementById('lf-progress').classList.toggle('show',s);}
            function wnqSetProgress(p){try{document.getElementById('lf-pbar').style.width=p+'%';document.getElementById('lf-pct').textContent=p+'%';}catch(_){}}
            function wnqSetStatus(m){try{document.getElementById('lf-status-text').textContent=m;}catch(_){}}
            function wnqShowResult(ok,html){const el=document.getElementById('lf-result');el.className='wnq-result '+(ok?'wnq-result-ok':'wnq-result-err');el.innerHTML=html;el.style.display='';}
            function wnqDone(){running=false;document.getElementById('lf-start-btn').style.display='';document.getElementById('lf-stop-btn').style.display='none';wnqShowProgress(false);}
        }());
        </script>
        <?php
    }

    // ── Tab: Manual URLs ─────────────────────────────────────────────────────

    private static function renderManualTab(array $settings): void
    {
        $nonce = wp_create_nonce('wnq_lead_manual');
        ?>
        <div class="wnq-card">
            <h3>Add Prospects Manually</h3>
            <p style="color:#6b7280;margin:-6px 0 14px;font-size:12px;">
                Paste one website URL per line. Optionally use <code>Business Name | URL</code> format.
                Each site's source is scraped for email &amp; phone — processed one at a time.
                Franchises and duplicates are automatically filtered.
            </p>
            <div class="wnq-field" style="margin-bottom:14px">
                <label>Website URLs (one per line)</label>
                <textarea id="lf-urls" style="width:100%;height:160px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace;box-sizing:border-box;"
                    placeholder="https://example-plumber.com&#10;Acme Roofing | https://acmeroofing.com"></textarea>
            </div>
            <div style="max-width:320px;margin-bottom:14px">
                <div class="wnq-field">
                    <label>Industry / Label</label>
                    <input type="text" id="lf-industry" placeholder="e.g. roofing contractor" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    <small>Tags all leads in this batch</small>
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center">
                <button class="wnq-btn wnq-btn-primary" id="lf-manual-start-btn" onclick="wnqStartManual()">Scrape &amp; Save Leads</button>
                <button class="wnq-btn wnq-btn-secondary" id="lf-manual-stop-btn" onclick="wnqStopManual()" style="display:none">Stop</button>
            </div>
            <div id="lf-manual-progress" style="display:none;margin-top:16px">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#374151;margin-bottom:4px">
                    <span id="lf-manual-label">Starting…</span><span id="lf-manual-pct">0%</span>
                </div>
                <div class="wnq-progressbar-wrap"><div class="wnq-progressbar" id="lf-manual-bar"></div></div>
                <div class="wnq-live-stats" style="margin:8px 0">
                    <span>Total: <b id="lm-found">0</b></span>
                    <span>Saved: <b id="lm-saved" style="color:#16a34a">0</b></span>
                    <span>Franchise: <b id="lm-franchise">0</b></span>
                    <span>Dupe: <b id="lm-dup">0</b></span>
                    <span>No Site: <b id="lm-noweb">0</b></span>
                    <span>Errors: <b id="lm-error" style="color:#dc2626">0</b></span>
                </div>
                <div id="lf-manual-log" style="background:#0f172a;border-radius:6px;padding:10px 12px;font-family:monospace;font-size:11px;color:#94a3b8;max-height:200px;overflow-y:auto;line-height:1.7"></div>
            </div>
            <div id="lf-manual-result" style="margin-top:12px"></div>
        </div>
        <script>
        (function(){
            let _stopped=false;
            function mLog(msg,color){const el=document.getElementById('lf-manual-log');if(!el)return;const line=document.createElement('div');line.style.color=color||'#94a3b8';line.textContent=msg;el.appendChild(line);el.scrollTop=el.scrollHeight;}
            window.wnqStopManual=function(){_stopped=true;};
            window.wnqStartManual=async function(){
                _stopped=false;
                const urls=document.getElementById('lf-urls').value.trim(),industry=document.getElementById('lf-industry').value.trim();
                if(!urls){alert('Enter at least one URL.');return;}
                const startBtn=document.getElementById('lf-manual-start-btn'),stopBtn=document.getElementById('lf-manual-stop-btn');
                startBtn.disabled=true;stopBtn.style.display='';
                document.getElementById('lf-manual-progress').style.display='';
                document.getElementById('lf-manual-result').innerHTML='';
                document.getElementById('lf-manual-log').innerHTML='';
                ['lm-found','lm-saved','lm-franchise','lm-dup','lm-noweb'].forEach(id=>document.getElementById(id).textContent='0');
                msetProg(0,1);
                let qr;
                try{const fd=new FormData();fd.append('action','wnq_lead_queue_manual');fd.append('nonce',<?php echo wp_json_encode($nonce); ?>);fd.append('urls',urls);fd.append('industry',industry);mSetLabel('Queuing URLs…');
                    const r=await fetch(ajaxurl,{method:'POST',body:fd}),raw=await r.text();
                    try{qr=JSON.parse(raw);}catch(je){mErr('Queue failed: '+raw.replace(/<[^>]+>/g,'').trim().substring(0,120));return;}
                }catch(e){mErr('Network error: '+e.message);return;}
                finally{startBtn.disabled=false;stopBtn.style.display='none';}
                if(!qr.success){mErr(qr.data?.message||'Failed to queue');return;}
                const{batch_id,total}=qr.data;
                document.getElementById('lm-found').textContent=total||0;
                if(!batch_id||!total){mErr('No valid URLs found.');return;}
                startBtn.disabled=true;stopBtn.style.display='';
                mLog('Queued '+total+' URL(s). Starting scrape…','#60a5fa');
                let progress=0,consec=0,lastStats=null;
                while(progress<total&&!_stopped){
                    mSetLabel('Processing '+progress+'/'+total+'…');
                    let pr=null;
                    try{const ctrl=new AbortController(),tid=setTimeout(()=>ctrl.abort(),60000);
                        const fd2=new FormData();fd2.append('action','wnq_lead_process_next_manual');fd2.append('nonce',<?php echo wp_json_encode($nonce); ?>);fd2.append('batch_id',batch_id);
                        const r2=await fetch(ajaxurl,{method:'POST',body:fd2,signal:ctrl.signal});clearTimeout(tid);
                        const raw2=await r2.text();try{pr=JSON.parse(raw2);}catch(je){mErr('URL '+(progress+1)+' PHP error: '+raw2.replace(/<[^>]+>/g,'').trim().substring(0,120));if(++consec>=5)_stopped=true;progress++;msetProg(progress,total);continue;}
                    }catch(e){mLog('URL '+(progress+1)+' timed out.','#fb923c');if(++consec>=5){mErr('5 timeouts — stopping.');_stopped=true;}progress++;msetProg(progress,total);continue;}
                    if(!pr?.success){mLog('URL '+(progress+1)+': '+(pr?.data?.message||'Error'),'#f87171');if(++consec>=5){mErr('5 errors — stopping.');_stopped=true;}progress++;msetProg(progress,total);continue;}
                    consec=0;const d=pr.data;progress=d.progress;lastStats=d.stats;
                    const label=d.name||(d.url||('URL '+progress));
                    const outcome=d.outcome||'?';
                    if(outcome==='saved'){mLog('✓ Saved: '+label+(d.url?' ('+d.url+')':''),'#4ade80');}
                    else if(outcome==='duplicate'){mLog('⟳ Dupe: '+label,'#94a3b8');}
                    else if(outcome==='franchise'){mLog('✗ Franchise filtered: '+label,'#c084fc');}
                    else if(outcome==='no_website'){mLog('✗ Could not fetch site: '+(d.url||label),'#fb923c');}
                    else if(outcome==='error'){mLog('✗ Error on '+(d.url||label)+': '+(d.error||'unknown'),'#f87171');}
                    else{mLog('— '+outcome+': '+label,'#94a3b8');}
                    if(d.stats){document.getElementById('lm-saved').textContent=d.stats.saved||0;document.getElementById('lm-franchise').textContent=d.stats.franchise||0;document.getElementById('lm-dup').textContent=d.stats.duplicate||0;document.getElementById('lm-noweb').textContent=d.stats.no_website||0;document.getElementById('lm-error').textContent=d.stats.error||0;}
                    msetProg(d.progress,d.total);if(d.done)break;
                }
                startBtn.disabled=false;stopBtn.style.display='none';
                const saved=lastStats?lastStats.saved:0;
                if(!_stopped){mSetLabel('Complete');document.getElementById('lf-manual-result').innerHTML='<div class="wnq-result wnq-result-ok"><strong>Done</strong><p><b>'+saved+'</b> new lead'+(saved!==1?'s':'')+' saved.'+(saved>0?' <a href="admin.php?page=wnq-lead-finder&tab=leads" style="color:#15803d;font-weight:600">View leads &rarr;</a>':'')+' </p></div>';}
                else{mSetLabel('Stopped');document.getElementById('lf-manual-result').innerHTML='<div class="wnq-result" style="background:#fef9c3;border:1px solid #fde68a;color:#92400e;"><strong>Stopped</strong><p>'+saved+' lead'+(saved!==1?'s':'')+' saved.</p></div>';}
            };
            function mSetLabel(m){document.getElementById('lf-manual-label').textContent=m;}
            function msetProg(c,t){const p=t>0?Math.round(c/t*100):0;document.getElementById('lf-manual-bar').style.width=p+'%';document.getElementById('lf-manual-pct').textContent=p+'%';}
            function mErr(m){document.getElementById('lf-manual-result').innerHTML+='<div class="wnq-result wnq-result-err" style="margin-bottom:6px"><strong>Error</strong> — '+m+'</div>';document.getElementById('lf-manual-start-btn').disabled=false;document.getElementById('lf-manual-stop-btn').style.display='none';}
        })();
        </script>
        <?php
    }

    // ── Tab: All Leads ───────────────────────────────────────────────────────

    private static function renderLeadsTab(): void
    {
        $f_industry = sanitize_text_field($_GET['industry'] ?? '');
        $f_city     = sanitize_text_field($_GET['city']     ?? '');
        $f_state    = sanitize_text_field($_GET['state']    ?? '');
        $f_status   = sanitize_key($_GET['status']          ?? '');
        $f_email    = !empty($_GET['has_email']);
        $page       = max(1, (int)($_GET['paged'] ?? 1));
        $per_page   = 50;

        $filter_args = array_filter([
            'industry'  => $f_industry,
            'city'      => $f_city,
            'state'     => $f_state,
            'status'    => $f_status,
            'has_email' => $f_email ?: null,
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
        $export_url = wp_nonce_url(admin_url('admin-post.php?'.http_build_query(array_filter(['action'=>'wnq_lead_export_csv','industry'=>$f_industry,'city'=>$f_city,'state'=>$f_state,'status'=>$f_status,'has_email'=>$f_email?'1':'']))), 'wnq_lead_export_csv');
        ?>
        <form method="get" class="wnq-filters">
            <input type="hidden" name="page" value="wnq-lead-finder"><input type="hidden" name="tab" value="leads">
            <select name="industry"><option value="">All Industries</option><?php foreach($industries as $v):?><option value="<?php echo esc_attr($v);?>"<?php selected($f_industry,$v);?>><?php echo esc_html($v);?></option><?php endforeach;?></select>
            <select name="city"><option value="">All Cities</option><?php foreach($cities as $v):?><option value="<?php echo esc_attr($v);?>"<?php selected($f_city,$v);?>><?php echo esc_html($v);?></option><?php endforeach;?></select>
            <select name="state"><option value="">All States</option><?php foreach($states as $v):?><option value="<?php echo esc_attr($v);?>"<?php selected($f_state,$v);?>><?php echo esc_html($v);?></option><?php endforeach;?></select>
            <select name="status"><option value="">All Statuses</option><?php foreach(['new','contacted','qualified','closed'] as $s):?><option value="<?php echo $s;?>"<?php selected($f_status,$s);?>><?php echo ucfirst($s);?></option><?php endforeach;?></select>
            <label style="font-size:12px;display:flex;align-items:center;gap:4px;white-space:nowrap"><input type="checkbox" name="has_email" value="1"<?php checked($f_email);?>> Email</label>
            <button type="submit" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Filter</button>
            <a href="<?php echo esc_url($base_url);?>" class="wnq-btn wnq-btn-secondary wnq-btn-sm">Reset</a>
            <a href="<?php echo esc_url($export_url);?>" class="wnq-btn wnq-btn-primary wnq-btn-sm" style="margin-left:auto">Export GHL CSV (<?php echo esc_html($total);?>)</a>
        </form>
        <div class="wnq-bulk-bar" id="wnq-bulk-bar">
            <span class="wnq-bulk-count"><span id="wnq-bulk-cnt">0</span> selected</span>
            <select id="wnq-bulk-status"><option value="">— Set Status —</option><?php foreach(['new','contacted','qualified','closed'] as $s):?><option value="<?php echo $s;?>"><?php echo ucfirst($s);?></option><?php endforeach;?></select>
            <button class="wnq-btn wnq-btn-primary wnq-btn-sm" onclick="wnqBulkApply()">Apply</button>
            <button class="wnq-btn wnq-btn-danger wnq-btn-sm" onclick="wnqBulkDelete()">Delete Selected</button>
            <button class="wnq-btn wnq-btn-secondary wnq-btn-sm" onclick="wnqBulkClear()">Clear</button>
        </div>
        <div class="wnq-card" style="padding:0;overflow:hidden">
            <?php if(empty($leads)):?>
                <div style="padding:40px;text-align:center;color:#6b7280">No leads match your filters.</div>
            <?php else:?>
            <div class="wnq-tbl-wrap">
            <table class="wnq-tbl">
                <thead><tr>
                    <th><input type="checkbox" id="wnq-sel-all"></th>
                    <th>Company</th><th>Industry</th><th>Website</th><th>City</th><th>State</th>
                    <th>Phone</th><th>Email</th><th>Stars/Reviews</th><th>Social</th>
                    <th>Status</th><th>Notes</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach($leads as $lead):?>
                    <tr id="lr-<?php echo(int)$lead['id'];?>">
                        <td style="padding-top:10px"><input type="checkbox" class="wnq-sel" value="<?php echo(int)$lead['id'];?>"></td>
                        <td><strong><?php echo esc_html($lead['business_name']);?></strong></td>
                        <td><?php echo esc_html($lead['industry']);?></td>
                        <td><?php if($lead['website']):?><a href="<?php echo esc_url($lead['website']);?>" target="_blank" style="color:#2563eb;font-size:11px"><?php echo esc_html(parse_url($lead['website'],PHP_URL_HOST)?:$lead['website']);?></a><?php else:?>—<?php endif;?></td>
                        <td><?php echo esc_html($lead['city']?:'—');?></td>
                        <td><?php echo esc_html($lead['state']?:'—');?></td>
                        <td style="white-space:nowrap"><?php echo esc_html($lead['phone']?:'—');?></td>
                        <td><?php if($lead['email']):?><a href="mailto:<?php echo esc_attr($lead['email']);?>" style="font-size:11px;color:#2563eb"><?php echo esc_html($lead['email']);?></a><?php else:?>—<?php endif;?></td>
                        <td style="white-space:nowrap"><span style="color:#f59e0b">★</span> <?php echo esc_html($lead['rating']);?> <span style="color:#6b7280;font-size:10px">(<?php echo esc_html(number_format((int)$lead['review_count']));?>)</span></td>
                        <td><div class="wnq-social">
                            <?php if($lead['social_facebook']):?><a href="<?php echo esc_url($lead['social_facebook']);?>" target="_blank" class="s-fb">FB</a><?php endif;?>
                            <?php if($lead['social_instagram']):?><a href="<?php echo esc_url($lead['social_instagram']);?>" target="_blank" class="s-ig">IG</a><?php endif;?>
                            <?php if($lead['social_linkedin']):?><a href="<?php echo esc_url($lead['social_linkedin']);?>" target="_blank" class="s-li">in</a><?php endif;?>
                            <?php if($lead['social_twitter']):?><a href="<?php echo esc_url($lead['social_twitter']);?>" target="_blank" class="s-tw">X</a><?php endif;?>
                            <?php if($lead['social_youtube']):?><a href="<?php echo esc_url($lead['social_youtube']);?>" target="_blank" class="s-yt">YT</a><?php endif;?>
                            <?php if($lead['social_tiktok']):?><a href="<?php echo esc_url($lead['social_tiktok']);?>" target="_blank" class="s-tt">TT</a><?php endif;?>
                        </div></td>
                        <td><select class="wnq-status-sel" data-id="<?php echo(int)$lead['id'];?>"><?php foreach(['new','contacted','qualified','closed'] as $s):?><option value="<?php echo $s;?>"<?php selected($lead['status'],$s);?>><?php echo ucfirst($s);?></option><?php endforeach;?></select></td>
                        <td><input type="text" class="wnq-notes-edit" data-id="<?php echo(int)$lead['id'];?>" value="<?php echo esc_attr($lead['notes']??'');?>" placeholder="Add note…"></td>
                        <td><button class="wnq-btn wnq-btn-danger wnq-btn-sm" onclick="wnqDel(<?php echo(int)$lead['id'];?>)">✕</button></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
            </div>
            <?php endif;?>
        </div>
        <?php if($total>$per_page):?>
        <div class="wnq-paginate">
            <span>Showing <?php echo esc_html(($page-1)*$per_page+1);?>–<?php echo esc_html(min($page*$per_page,$total));?> of <?php echo esc_html($total);?></span>
            <div class="pages"><?php for($p=1,$tp=(int)ceil($total/$per_page);$p<=$tp;$p++):?><a href="<?php echo esc_url(add_query_arg('paged',$p,$base_url));?>" class="<?php echo $p===$page?'cur':'';?>"><?php echo $p;?></a><?php endfor;?></div>
        </div>
        <?php endif;?>
        <script>
        (function(){
            const nonce=<?php echo wp_json_encode($nonce);?>;
            function lPost(d){const b=new FormData();Object.entries(Object.assign({nonce},d)).forEach(([k,v])=>b.append(k,v));return fetch(ajaxurl,{method:'POST',body:b}).then(r=>r.json());}
            document.querySelectorAll('.wnq-status-sel').forEach(s=>s.addEventListener('change',function(){lPost({action:'wnq_lead_update_status',id:this.dataset.id,status:this.value});}));
            document.querySelectorAll('.wnq-notes-edit').forEach(i=>{let o=i.value;i.addEventListener('blur',function(){if(this.value===o)return;o=this.value;lPost({action:'wnq_lead_update_notes',id:this.dataset.id,notes:this.value});});});
            window.wnqDel=function(id){if(!confirm('Delete this lead?'))return;lPost({action:'wnq_lead_delete',id}).then(()=>{const r=document.getElementById('lr-'+id);if(r)r.remove();});};
            const selAll=document.getElementById('wnq-sel-all'),bar=document.getElementById('wnq-bulk-bar');
            function upd(){const n=document.querySelectorAll('.wnq-sel:checked').length;document.getElementById('wnq-bulk-cnt').textContent=n;bar.classList.toggle('show',n>0);}
            selAll&&selAll.addEventListener('change',function(){document.querySelectorAll('.wnq-sel').forEach(c=>c.checked=this.checked);upd();});
            document.querySelectorAll('.wnq-sel').forEach(c=>c.addEventListener('change',function(){if(!this.checked&&selAll)selAll.checked=false;upd();}));
            window.wnqBulkClear=function(){document.querySelectorAll('.wnq-sel').forEach(c=>c.checked=false);if(selAll)selAll.checked=false;upd();};
            window.wnqBulkApply=function(){const status=document.getElementById('wnq-bulk-status').value;if(!status){alert('Select a status.');return;}const ids=[...document.querySelectorAll('.wnq-sel:checked')].map(c=>c.value);if(!ids.length)return;const b=new FormData();b.append('action','wnq_lead_bulk_action');b.append('nonce',nonce);b.append('bulk_action',status);ids.forEach(id=>b.append('ids[]',id));fetch(ajaxurl,{method:'POST',body:b}).then(r=>r.json()).then(d=>{if(!d.success){alert('Error: '+(d.data?.message||'?'));return;}document.querySelectorAll('.wnq-sel:checked').forEach(c=>{const s=c.closest('tr').querySelector('.wnq-status-sel');if(s)s.value=status;});wnqBulkClear();});};
            window.wnqBulkDelete=function(){const ids=[...document.querySelectorAll('.wnq-sel:checked')].map(c=>c.value);if(!ids.length||!confirm('Delete '+ids.length+' lead(s)?'))return;const b=new FormData();b.append('action','wnq_lead_bulk_action');b.append('nonce',nonce);b.append('bulk_action','delete');ids.forEach(id=>b.append('ids[]',id));fetch(ajaxurl,{method:'POST',body:b}).then(r=>r.json()).then(d=>{if(!d.success){alert('Error: '+(d.data?.message||'?'));return;}document.querySelectorAll('.wnq-sel:checked').forEach(c=>{const r=c.closest('tr');if(r)r.remove();});wnqBulkClear();});};
        })();
        </script>
        <?php
    }

    // ── Tab: Settings ────────────────────────────────────────────────────────

    private static function renderSettingsTab(array $settings): void
    {
        ?>
        <div class="wnq-card" style="max-width:680px">
            <h3>Lead Finder Settings</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 16px">No API key required — leads are discovered by scraping Google Maps.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                <?php wp_nonce_field('wnq_lead_save_settings','wnq_nonce');?>
                <input type="hidden" name="action" value="wnq_lead_save_settings">
                <div class="wnq-row2">
                    <div class="wnq-field">
                        <label>Default Keyword</label>
                        <input type="text" name="default_keyword" value="<?php echo esc_attr($settings['default_keyword']??'');?>" placeholder="e.g. pressure washing">
                        <small>Pre-filled in ZIP Sweep and Manual tabs.</small>
                    </div>
                    <div class="wnq-field">
                        <label>Default Min SEO Score</label>
                        <input type="number" name="min_seo_score" value="<?php echo esc_attr($settings['min_seo_score']??2);?>" min="0" max="7">
                        <small>Issues found (0–7). 2+ = worth pitching.</small>
                    </div>
                </div>
                <button type="submit" class="wnq-btn wnq-btn-primary">Save Settings</button>
                <?php if(!empty($_GET['settings_saved'])):?><span style="margin-left:10px;color:#16a34a;font-size:12px">✓ Saved</span><?php endif;?>
            </form>
        </div>

        <?php
        $scraper_dir   = WNQ_PORTAL_PATH . 'scraper';
        $npm_installed = is_dir($scraper_dir . '/node_modules/puppeteer-core')
                      || is_dir($scraper_dir . '/node_modules/puppeteer');
        $nonce         = wp_create_nonce('wnq_lead_nonce');
        ?>
        <div class="wnq-card" style="max-width:680px">
            <h3>Scraper Setup (Puppeteer / Node.js)</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 12px">
                The ZIP Sweep uses a local Puppeteer server to scrape Google Maps results.
                Run <strong>npm install</strong> once to install the dependencies.
            </p>
            <p style="margin:0 0 14px">
                Status: <?php if($npm_installed):?>
                    <strong style="color:#16a34a">✓ Installed</strong>
                <?php else:?>
                    <strong style="color:#dc2626">✗ Not installed</strong> — ZIP Sweep will use fallback HTTP (fewer results)
                <?php endif;?>
            </p>
            <button id="wnq-npm-btn" class="wnq-btn wnq-btn-primary" onclick="wnqNpmInstall()">
                <?php echo $npm_installed ? 'Re-run npm install' : 'Install Node Dependencies'; ?>
            </button>
            <div id="wnq-npm-out" style="display:none;margin-top:14px;background:#0f172a;color:#e2e8f0;padding:14px 16px;border-radius:8px;font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:260px;overflow-y:auto"></div>
            <div style="margin-top:16px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:12px">
                <strong>Manual install (SSH):</strong><br>
                <code style="display:block;margin-top:6px;font-size:11px;word-break:break-all">cd <?php echo esc_html($scraper_dir); ?> &amp;&amp; npm install</code>
            </div>
            <script>
            function wnqNpmInstall(){
                const btn=document.getElementById('wnq-npm-btn');
                const out=document.getElementById('wnq-npm-out');
                btn.disabled=true; btn.textContent='Running npm install…';
                out.style.display='block'; out.textContent='Starting…\n';
                const fd=new FormData();
                fd.append('action','wnq_npm_install');
                fd.append('nonce',<?php echo wp_json_encode($nonce);?>);
                fetch(ajaxurl,{method:'POST',body:fd})
                    .then(function(r){return r.text();})
                    .then(function(text){
                        var d;
                        try{ d=JSON.parse(text); }
                        catch(e){
                            out.textContent='Server returned an unexpected response:\n\n'+text.slice(0,800);
                            btn.textContent='<?php echo $npm_installed ? 'Re-run npm install' : 'Install Node Dependencies'; ?>';
                            btn.disabled=false;
                            return;
                        }
                        if(d.shell_exec_disabled){
                            out.textContent='Automated install is not available on this server (shell_exec is disabled).\n\nRun the command in the "Manual install" box below via SSH instead.';
                            btn.textContent='<?php echo $npm_installed ? 'Re-run npm install' : 'Install Node Dependencies'; ?>';
                            btn.disabled=false;
                            return;
                        }
                        out.textContent = d.data ? (d.data.output||'(no output)') : (d.output||text);
                        if(d.installed||(d.data&&d.data.installed)){
                            btn.textContent='✓ Installed — Re-run npm install';
                            btn.disabled=false;
                        } else if(!d.success&&d.data&&d.data.message){
                            out.textContent=d.data.message;
                            btn.textContent='Install failed — try again';
                            btn.disabled=false;
                        } else {
                            btn.textContent='Install failed — try again';
                            btn.disabled=false;
                        }
                    })
                    .catch(function(e){
                        out.textContent='Network error: '+e.message;
                        btn.textContent='<?php echo $npm_installed ? 'Re-run npm install' : 'Install Node Dependencies'; ?>';
                        btn.disabled=false;
                    });
            }
            </script>
        </div>
        <?php
    }

    // ── AJAX: ZIP Sweep ───────────────────────────────────────────────────────

    public static function ajaxZipStart(): void
    {
        if (!check_ajax_referer('wnq_lead_nonce', 'nonce', false)) { wp_send_json_error(['error' => 'Security check failed'], 403); return; }
        self::requireCap();
        ob_start();
        try {
            $result = LeadFinderEngine::startSearch(sanitize_text_field($_POST['keyword'] ?? ''));
        } catch (\Throwable $e) {
            ob_end_clean();
            wp_send_json_error(['error' => $e->getMessage()]);
            return;
        }
        ob_end_clean();
        $result['ok']
            ? wp_send_json_success(['batch_id' => $result['batch_id'], 'total_zips' => $result['total_zips']])
            : wp_send_json_error(['error' => $result['error'] ?? 'Unknown error']);
    }

    public static function ajaxZipProcess(): void
    {
        if (!check_ajax_referer('wnq_lead_nonce', 'nonce', false)) { wp_send_json_error(['error' => 'Security check failed'], 403); return; }
        self::requireCap();
        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        if (!$batch_id) { wp_send_json_error(['error' => 'Missing batch_id']); return; }
        @set_time_limit(0);
        ob_start();
        try {
            $result = LeadFinderEngine::processNext($batch_id);
        } catch (\Throwable $e) {
            ob_end_clean();
            wp_send_json_error(['error' => $e->getMessage()]);
            return;
        }
        ob_end_clean();
        $result['ok']
            ? wp_send_json_success($result)
            : wp_send_json_error(['error' => $result['error'] ?? 'Processing error']);
    }

    // ── AJAX: Manual Queue ────────────────────────────────────────────────────

    public static function ajaxQueueManual(): void
    {
        if (!check_ajax_referer('wnq_lead_manual', 'nonce', false)) { wp_send_json_error(['message' => 'Security check failed — refresh and try again.']); return; }
        self::requireCap();
        $result = LeadFinderEngine::queueManualSearch([
            'urls'     => sanitize_textarea_field($_POST['urls']     ?? ''),
            'industry' => sanitize_text_field($_POST['industry']     ?? ''),
        ]);
        $result['ok']
            ? wp_send_json_success(['batch_id' => $result['batch_id'] ?? '', 'total' => $result['total'] ?? 0])
            : wp_send_json_error(['message' => $result['error'] ?? 'Queue failed']);
    }

    public static function ajaxProcessNextManual(): void
    {
        if (!check_ajax_referer('wnq_lead_manual', 'nonce', false)) { wp_send_json_error(['message' => 'Security check failed — refresh and try again.']); return; }
        self::requireCap();
        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        if (!$batch_id) { wp_send_json_error(['message' => 'Missing batch_id']); return; }
        $result = LeadFinderEngine::processNextManual($batch_id, ['min_seo_score' => (int)($_POST['min_seo'] ?? 2)]);
        $result['ok']
            ? wp_send_json_success($result)
            : wp_send_json_error(['message' => $result['error'] ?? 'Processing failed']);
    }

    // ── AJAX: Lead Management ─────────────────────────────────────────────────

    public static function ajaxUpdateStatus(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        self::requireCap();
        $id = (int)($_POST['id'] ?? 0); $status = sanitize_key($_POST['status'] ?? '');
        if ($id && in_array($status, ['new','contacted','qualified','closed'], true)) Lead::updateStatus($id, $status);
        wp_send_json_success();
    }

    public static function ajaxUpdateNotes(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        self::requireCap();
        $id = (int)($_POST['id'] ?? 0);
        if ($id) Lead::updateNotes($id, sanitize_textarea_field($_POST['notes'] ?? ''));
        wp_send_json_success();
    }

    public static function ajaxDelete(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        self::requireCap();
        $id = (int)($_POST['id'] ?? 0);
        if ($id) Lead::delete($id);
        wp_send_json_success();
    }

    public static function ajaxBulkAction(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        self::requireCap();
        $bulk_action = sanitize_key($_POST['bulk_action'] ?? '');
        $ids         = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (empty($ids)) { wp_send_json_error(['message' => 'No leads selected']); }
        if ($bulk_action === 'delete') {
            Lead::bulkDelete($ids);
            wp_send_json_success(['deleted' => count($ids)]);
        } elseif (in_array($bulk_action, ['new','contacted','qualified','closed'], true)) {
            Lead::bulkUpdateStatus($ids, $bulk_action);
            wp_send_json_success(['updated' => count($ids), 'status' => $bulk_action]);
        } else {
            wp_send_json_error(['message' => 'Invalid action']);
        }
    }

    public static function ajaxRunMigration(): void
    {
        check_ajax_referer('wnq_lead_migration', 'nonce');
        self::requireCap();
        Lead::runMigration();
        wp_send_json(['ok' => true]);
    }

    // ── AJAX: npm install ─────────────────────────────────────────────────────

    public static function ajaxNpmInstall(): void
    {
        if (!check_ajax_referer('wnq_lead_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed — refresh and try again.']);
            return;
        }
        self::requireCap();

        // Capture any stray output (PHP notices, warnings) so nothing corrupts the JSON.
        ob_start();

        $scraper_dir = WNQ_PORTAL_PATH . 'scraper';
        if (!is_dir($scraper_dir)) {
            ob_end_clean();
            wp_send_json_error(['message' => 'Scraper directory not found at: ' . $scraper_dir]);
            return;
        }

        // Check whether shell_exec is actually usable on this server.
        $shell_exec_disabled = !function_exists('shell_exec')
            || in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);

        if ($shell_exec_disabled) {
            ob_end_clean();
            wp_send_json([
                'success'             => false,
                'installed'           => false,
                'shell_exec_disabled' => true,
                'output'              => 'shell_exec is disabled on this server. Run npm install manually via SSH.',
            ]);
            return;
        }

        // npm install can download ~300 MB — remove the PHP time and memory limits.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Find npm binary.
        $npm = trim((string)shell_exec('which npm 2>/dev/null'));
        if (!$npm) {
            foreach (['/opt/node22/bin/npm', '/usr/local/bin/npm', '/usr/bin/npm'] as $p) {
                if (file_exists($p) && is_executable($p)) { $npm = $p; break; }
            }
        }
        if (!$npm) {
            ob_end_clean();
            wp_send_json_error(['message' => 'npm not found. Please install Node.js on your server first.']);
            return;
        }

        $cmd    = 'cd ' . escapeshellarg($scraper_dir) . ' && ' . escapeshellarg($npm) . ' install 2>&1';
        $output = shell_exec($cmd);

        ob_end_clean();

        $installed = is_dir($scraper_dir . '/node_modules/puppeteer-core')
                  || is_dir($scraper_dir . '/node_modules/puppeteer');

        wp_send_json([
            'success'   => $installed,
            'installed' => $installed,
            'output'    => $output ?: '(no output)',
        ]);
    }

    // ── Admin POST Handlers ───────────────────────────────────────────────────

    public static function handleExportCsv(): void
    {
        check_admin_referer('wnq_lead_export_csv');
        self::requireCap();
        Lead::exportCsv(array_filter([
            'industry'  => sanitize_text_field($_GET['industry']  ?? ''),
            'city'      => sanitize_text_field($_GET['city']      ?? ''),
            'state'     => sanitize_text_field($_GET['state']     ?? ''),
            'status'    => sanitize_key($_GET['status']           ?? ''),
            'has_email' => !empty($_GET['has_email']) ? true : null,
        ], fn($v) => $v !== null && $v !== ''));
    }

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wnq_lead_save_settings', 'wnq_nonce');
        self::requireCap();
        update_option('wnq_lead_finder_settings', array_merge(
            get_option('wnq_lead_finder_settings', []),
            [
                'default_keyword' => sanitize_text_field($_POST['default_keyword'] ?? ''),
                'min_seo_score'   => max(0, min(7, (int)($_POST['min_seo_score'] ?? 2))),
            ]
        ));
        wp_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=settings&settings_saved=1'));
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
