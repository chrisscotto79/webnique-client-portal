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
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Priority 22 — must run AFTER SEOHubAdmin::addMenuPages() (priority 20)
        add_action('admin_menu', [self::class, 'addMenuPage'], 22);

        // ZIP sweep (Google Maps scraping, no API key)
        add_action('wp_ajax_wnq_zip_start',   [self::class, 'ajaxZipStart']);
        add_action('wp_ajax_wnq_zip_process', [self::class, 'ajaxZipProcess']);

        // Manual URL queue
        add_action('wp_ajax_wnq_lead_queue_manual',        [self::class, 'ajaxQueueManual']);
        add_action('wp_ajax_wnq_lead_process_next_manual', [self::class, 'ajaxProcessNextManual']);

        // Bulk Google Maps import
        add_action('wp_ajax_wnq_bulk_maps_queue',   [self::class, 'ajaxBulkMapsQueue']);
        add_action('wp_ajax_wnq_bulk_maps_process', [self::class, 'ajaxBulkMapsProcess']);

        // CSV import
        add_action('wp_ajax_wnq_csv_import_queue',   [self::class, 'ajaxCsvImportQueue']);
        add_action('wp_ajax_wnq_csv_import_process', [self::class, 'ajaxCsvImportProcess']);

        // Lead management
        add_action('wp_ajax_wnq_lead_update_status', [self::class, 'ajaxUpdateStatus']);
        add_action('wp_ajax_wnq_lead_update_notes',  [self::class, 'ajaxUpdateNotes']);
        add_action('wp_ajax_wnq_lead_delete',        [self::class, 'ajaxDelete']);
        add_action('wp_ajax_wnq_lead_bulk_action',   [self::class, 'ajaxBulkAction']);
        add_action('wp_ajax_wnq_lead_run_migration', [self::class, 'ajaxRunMigration']);
        add_action('wp_ajax_wnq_lead_delete_all',    [self::class, 'ajaxDeleteAll']);

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
            <?php foreach (['search'=>'ZIP Sweep','manual'=>'Manual URLs','csv_import'=>'CSV Import','leads'=>'All Leads','settings'=>'Settings'] as $t=>$label): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab='.$t)); ?>" class="wnq-lf-tab <?php echo $tab===$t?'active':''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </div>

        <?php
        match ($tab) {
            'leads'      => self::renderLeadsTab(),
            'settings'   => self::renderSettingsTab($settings),
            'manual'     => self::renderManualTab($settings),
            'csv_import' => self::renderCsvImportTab($settings),
            default      => self::renderZipSweepTab($settings),
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
                Saves leads with <strong>&lt; 50 reviews</strong> and a website, then scrapes for phone, email, and social links.
                Scrapes homepage for email + social links. <strong>Zero API cost.</strong>
            </p>
            <div class="wnq-row2" style="max-width:600px">
                <div class="wnq-field">
                    <label for="lf-keyword">Industry / Keyword</label>
                    <input type="text" id="lf-keyword" placeholder="e.g. pressure washing" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    <small>Used as: <code id="lf-used-as"><?php echo esc_html(($settings['default_keyword'] ?? 'plumbing') . ' 34211'); ?></code></small>
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
            const keywordInput=document.getElementById('lf-keyword'),usedAs=document.getElementById('lf-used-as');
            if(keywordInput&&usedAs){const sync=()=>{usedAs.textContent=(keywordInput.value.trim()||'plumbing')+' 34211';};keywordInput.addEventListener('input',sync);sync();}
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
                    } else if(d.action==='backend_status'){
                        wnqSetStatus('Backend job '+(d.state||'running')+' — '+zipIdx+' of '+total+' ZIPs');
                        wnqLog('Backend: '+zipIdx+'/'+total+' ZIPs | found '+(d.found||0)+' | saved '+((d.stats&&d.stats.saved)||0)+(d.imported?' | imported '+d.imported:''),'#60a5fa');
                        if(!d.done&&!stopFlag)await delay(3000);
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

        <?php /* ── Bulk Google Maps Import ─────────────────────────────── */ ?>
        <div class="wnq-card" style="margin-top:20px">
            <h3>Bulk Google Maps Import</h3>
            <p style="color:#6b7280;margin:-6px 0 10px;font-size:12px;">
                Paste tab-separated rows exported from your Google Maps scraper.
                Expected columns (with or without a header row):<br>
                <code style="font-size:11px">Title &nbsp;·&nbsp; Rating &nbsp;·&nbsp; Reviews &nbsp;·&nbsp; Phone &nbsp;·&nbsp; Email &nbsp;·&nbsp; Industry &nbsp;·&nbsp; Address &nbsp;·&nbsp; Website &nbsp;·&nbsp; Google Maps Link</code><br>
                Each website is fetched to fill in any missing email and collect social media links.
            </p>
            <div class="wnq-field" style="margin-bottom:14px">
                <label>Paste TSV data</label>
                <textarea id="bm-tsv" style="width:100%;height:180px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:monospace;box-sizing:border-box;"
                    placeholder="Roto-Rooter Plumbing&#9;4.8&#9;1102&#9;(407) 949-9004&#9;&#9;Plumber&#9;1535 W Broadway St&#9;https://www.rotorooter.com/&#9;https://www.google.com/maps/place/..."></textarea>
            </div>
            <div style="max-width:320px;margin-bottom:14px">
                <div class="wnq-field">
                    <label>Fallback Industry (used when column is blank)</label>
                    <input type="text" id="bm-industry" placeholder="e.g. plumbing" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center">
                <button class="wnq-btn wnq-btn-primary" id="bm-start-btn" onclick="wnqBulkMapsStart()">Import &amp; Save Leads</button>
                <button class="wnq-btn wnq-btn-secondary" id="bm-stop-btn" onclick="wnqBulkMapsStop()" style="display:none">Stop</button>
            </div>
            <div id="bm-progress" style="display:none;margin-top:16px">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#374151;margin-bottom:4px">
                    <span id="bm-label">Starting…</span><span id="bm-pct">0%</span>
                </div>
                <div class="wnq-progressbar-wrap"><div class="wnq-progressbar" id="bm-bar"></div></div>
                <div class="wnq-live-stats" style="margin:8px 0">
                    <span>Total: <b id="bm-found">0</b></span>
                    <span>Saved: <b id="bm-saved" style="color:#16a34a">0</b></span>
                    <span>Dupe: <b id="bm-dup">0</b></span>
                    <span>No Site: <b id="bm-noweb">0</b></span>
                    <span>Errors: <b id="bm-err" style="color:#dc2626">0</b></span>
                </div>
                <div id="bm-log" style="background:#0f172a;border-radius:6px;padding:10px 12px;font-family:monospace;font-size:11px;color:#94a3b8;max-height:220px;overflow-y:auto;line-height:1.7"></div>
            </div>
            <div id="bm-result" style="margin-top:12px"></div>
        </div>
        <script>
        (function(){
            let _bmStopped=false;
            function bmLog(msg,color){const el=document.getElementById('bm-log');if(!el)return;const d=document.createElement('div');d.style.color=color||'#94a3b8';d.textContent=msg;el.appendChild(d);el.scrollTop=el.scrollHeight;}
            function bmProg(c,t){const p=t>0?Math.round(c/t*100):0;document.getElementById('bm-bar').style.width=p+'%';document.getElementById('bm-pct').textContent=p+'%';}
            function bmLabel(m){document.getElementById('bm-label').textContent=m;}
            function bmErr(m){document.getElementById('bm-result').innerHTML+='<div class="wnq-result wnq-result-err" style="margin-bottom:6px"><strong>Error</strong> — '+m+'</div>';document.getElementById('bm-start-btn').disabled=false;document.getElementById('bm-stop-btn').style.display='none';}
            window.wnqBulkMapsStop=function(){_bmStopped=true;};
            window.wnqBulkMapsStart=async function(){
                _bmStopped=false;
                const tsv=document.getElementById('bm-tsv').value.trim();
                const industry=document.getElementById('bm-industry').value.trim();
                if(!tsv){alert('Paste your scraper data first.');return;}
                const startBtn=document.getElementById('bm-start-btn'),stopBtn=document.getElementById('bm-stop-btn');
                startBtn.disabled=true;stopBtn.style.display='';
                document.getElementById('bm-progress').style.display='';
                document.getElementById('bm-result').innerHTML='';
                document.getElementById('bm-log').innerHTML='';
                ['bm-found','bm-saved','bm-dup','bm-noweb','bm-err'].forEach(id=>document.getElementById(id).textContent='0');
                bmProg(0,1);
                // Phase 1: queue
                let qr;
                try{
                    const fd=new FormData();
                    fd.append('action','wnq_bulk_maps_queue');
                    fd.append('nonce',<?php echo wp_json_encode($nonce); ?>);
                    fd.append('tsv',tsv);
                    fd.append('industry',industry);
                    bmLabel('Parsing rows…');
                    const r=await fetch(ajaxurl,{method:'POST',body:fd}),raw=await r.text();
                    try{qr=JSON.parse(raw);}catch(je){bmErr('Parse failed: '+raw.replace(/<[^>]+>/g,'').trim().substring(0,160));startBtn.disabled=false;stopBtn.style.display='none';return;}
                }catch(e){bmErr('Network error: '+e.message);startBtn.disabled=false;stopBtn.style.display='none';return;}
                if(!qr.success){bmErr(qr.data?.message||'Failed to queue');startBtn.disabled=false;stopBtn.style.display='none';return;}
                const{batch_id,total}=qr.data;
                document.getElementById('bm-found').textContent=total||0;
                if(!batch_id||!total){bmErr('No valid rows found. Check the format.');startBtn.disabled=false;stopBtn.style.display='none';return;}
                bmLog('Queued '+total+' row(s). Fetching websites…','#60a5fa');
                // Phase 2: process one row at a time
                let progress=0,consec=0,lastStats=null;
                while(progress<total&&!_bmStopped){
                    bmLabel('Processing '+progress+'/'+total+'…');
                    let pr=null;
                    try{
                        const ctrl=new AbortController(),tid=setTimeout(()=>ctrl.abort(),75000);
                        const fd2=new FormData();
                        fd2.append('action','wnq_bulk_maps_process');
                        fd2.append('nonce',<?php echo wp_json_encode($nonce); ?>);
                        fd2.append('batch_id',batch_id);
                        const r2=await fetch(ajaxurl,{method:'POST',body:fd2,signal:ctrl.signal});
                        clearTimeout(tid);
                        const raw2=await r2.text();
                        try{pr=JSON.parse(raw2);}catch(je){
                            bmLog('Row '+(progress+1)+' server error: '+raw2.replace(/<[^>]+>/g,'').trim().substring(0,120),'#f87171');
                            if(++consec>=5)_bmStopped=true;
                            progress++;bmProg(progress,total);continue;
                        }
                    }catch(e){
                        bmLog('Row '+(progress+1)+' timed out.','#fb923c');
                        if(++consec>=5){bmErr('5 timeouts — stopping.');_bmStopped=true;}
                        progress++;bmProg(progress,total);continue;
                    }
                    if(!pr?.success){
                        bmLog('Row '+(progress+1)+': '+(pr?.data?.message||'Error'),'#f87171');
                        if(++consec>=5){bmErr('5 errors — stopping.');_bmStopped=true;}
                        progress++;bmProg(progress,total);continue;
                    }
                    consec=0;
                    const d=pr.data;
                    progress=d.progress;
                    lastStats=d.stats;
                    const name=d.name||('Row '+progress);
                    if(d.outcome==='saved')         bmLog('✓ Saved: '+name,'#4ade80');
                    else if(d.outcome==='duplicate') bmLog('⟳ Dupe: '+name,'#94a3b8');
                    else if(d.outcome==='no_website')bmLog('✗ No site: '+name,'#fb923c');
                    else                             bmLog('✗ '+d.outcome+': '+name,'#f87171');
                    if(d.stats){
                        document.getElementById('bm-saved').textContent=d.stats.saved||0;
                        document.getElementById('bm-dup').textContent=d.stats.duplicate||0;
                        document.getElementById('bm-noweb').textContent=d.stats.no_website||0;
                        document.getElementById('bm-err').textContent=d.stats.error||0;
                    }
                    bmProg(d.progress,d.total);
                    if(d.done)break;
                }
                startBtn.disabled=false;stopBtn.style.display='none';
                const saved=lastStats?lastStats.saved:0;
                if(!_bmStopped){
                    bmLabel('Complete');
                    document.getElementById('bm-result').innerHTML='<div class="wnq-result wnq-result-ok"><strong>Done</strong><p><b>'+saved+'</b> new lead'+(saved!==1?'s':'')+' saved.'+(saved>0?' <a href="admin.php?page=wnq-lead-finder&tab=leads" style="color:#15803d;font-weight:600">View leads &rarr;</a>':'')+' </p></div>';
                }else{
                    bmLabel('Stopped');
                    document.getElementById('bm-result').innerHTML='<div class="wnq-result" style="background:#fef9c3;border:1px solid #fde68a;color:#92400e;"><strong>Stopped</strong><p>'+saved+' lead'+(saved!==1?'s':'')+' saved.</p></div>';
                }
            };
        })();
        </script>
        <?php
    }

    // ── Tab: CSV Import ──────────────────────────────────────────────────────

    private static function renderCsvImportTab(array $settings): void
    {
        $nonce = wp_create_nonce('wnq_csv_import');
        ?>
        <div class="wnq-card">
            <h3>Bulk CSV Import</h3>
            <p style="color:#6b7280;margin:-6px 0 14px;font-size:12px;">
                Upload up to <strong>10,000 scraped leads</strong>. Rows are filtered, then each qualifying lead's
                website is scraped for email + social links before saving.
            </p>

            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:12px;color:#15803d;line-height:1.9">
                <strong>Pipeline (in order):</strong><br>
                1. <strong>Reviews &lt; 100</strong> — filters out established businesses unlikely to need SEO help<br>
                2. <strong>Has phone number</strong> — must have a phone in the CSV<br>
                3. <strong>Not a franchise / chain</strong> — name check + website HTML check<br>
                4. <strong>Not a duplicate</strong> — name + city match against existing leads<br>
                5. <strong>Website scraped</strong> — email extracted, social links collected
            </div>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:12px;color:#1d4ed8;line-height:1.7">
                <strong>Auto-detected columns (case-insensitive):</strong>
                Business Name / Company Name · Email · Phone · Website · Address · City · State · Zip / Postal Code ·
                Industry / Category · Rating / Stars · Review Count / Reviews ·
                Facebook · Instagram · LinkedIn · Twitter · YouTube · TikTok · Status · Notes
            </div>

            <div class="wnq-row2" style="max-width:680px;margin-bottom:14px">
                <div class="wnq-field">
                    <label for="ci-file">CSV File</label>
                    <input type="file" id="ci-file" accept=".csv,text/csv" style="padding:6px 0">
                    <small>Max 10,000 rows · Max 20 MB · UTF-8 or Windows-1252. First row = header.</small>
                </div>
                <div class="wnq-field">
                    <label>Fallback Industry</label>
                    <input type="text" id="ci-industry" placeholder="e.g. pressure washing" value="<?php echo esc_attr($settings['default_keyword'] ?? ''); ?>">
                    <small>Used for rows where the Industry column is blank.</small>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center">
                <button class="wnq-btn wnq-btn-primary" id="ci-start-btn" onclick="wnqCsvStart()">Upload &amp; Run Pipeline</button>
                <button class="wnq-btn wnq-btn-secondary" id="ci-stop-btn" onclick="wnqCsvStop()" style="display:none">Stop</button>
            </div>

            <!-- Filter summary (shown after queue phase) -->
            <div id="ci-filter-summary" style="display:none;margin-top:14px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:12px">
                <strong style="display:block;margin-bottom:8px;color:#374151">Filter Results</strong>
                <div style="display:flex;gap:20px;flex-wrap:wrap">
                    <span>Total rows: <b id="ci-f-total">0</b></span>
                    <span style="color:#dc2626">Too many reviews (≥100): <b id="ci-f-reviews">0</b></span>
                    <span style="color:#dc2626">No phone: <b id="ci-f-nophone">0</b></span>
                    <span style="color:#dc2626">Franchise/chain: <b id="ci-f-franchise">0</b></span>
                    <span style="color:#dc2626">Multi-location: <b id="ci-f-multi">0</b></span>
                    <span style="color:#dc2626">Already in DB: <b id="ci-f-dup">0</b></span>
                    <span style="color:#16a34a;font-weight:600">Qualified to scrape: <b id="ci-f-qualified">0</b></span>
                </div>
            </div>

            <!-- Scraping progress -->
            <div id="ci-progress" style="display:none;margin-top:14px">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#374151;margin-bottom:4px">
                    <span id="ci-label">Scraping…</span><span id="ci-pct">0%</span>
                </div>
                <div class="wnq-progressbar-wrap"><div class="wnq-progressbar" id="ci-bar"></div></div>
                <div class="wnq-live-stats" style="margin:8px 0">
                    <span>Qualified: <b id="ci-total">0</b></span>
                    <span>Saved: <b id="ci-saved" style="color:#16a34a">0</b></span>
                    <span>Franchise (HTML): <b id="ci-franchise">0</b></span>
                    <span>No site: <b id="ci-noweb">0</b></span>
                    <span>Errors: <b id="ci-err" style="color:#dc2626">0</b></span>
                </div>
                <div id="ci-log" style="background:#0f172a;border-radius:6px;padding:10px 12px;font-family:monospace;font-size:11px;color:#94a3b8;max-height:220px;overflow-y:auto;line-height:1.7"></div>
            </div>

            <div id="ci-result" style="margin-top:12px"></div>
        </div>
        <script>
        (function(){
            let _ciStopped = false;
            function ciLog(msg,color){const el=document.getElementById('ci-log');if(!el)return;const d=document.createElement('div');d.style.color=color||'#94a3b8';d.textContent=msg;el.appendChild(d);el.scrollTop=el.scrollHeight;}
            function ciProg(c,t){const p=t>0?Math.round(c/t*100):0;document.getElementById('ci-bar').style.width=p+'%';document.getElementById('ci-pct').textContent=p+'%';}
            function ciLabel(m){document.getElementById('ci-label').textContent=m;}
            function ciErr(m){document.getElementById('ci-result').innerHTML+='<div class="wnq-result wnq-result-err" style="margin-bottom:6px"><strong>Error</strong> — '+m+'</div>';document.getElementById('ci-start-btn').disabled=false;document.getElementById('ci-stop-btn').style.display='none';}
            window.wnqCsvStop = function(){_ciStopped=true;ciLabel('Stopping…');};
            window.wnqCsvStart = async function(){
                _ciStopped = false;
                const fileEl=document.getElementById('ci-file');
                const industry=document.getElementById('ci-industry').value.trim();
                if(!fileEl.files.length){alert('Please select a CSV file.');return;}
                const file=fileEl.files[0];
                if(file.size>20*1024*1024){alert('File too large. Max 20 MB.');return;}
                const startBtn=document.getElementById('ci-start-btn'),stopBtn=document.getElementById('ci-stop-btn');
                startBtn.disabled=true;stopBtn.style.display='';
                document.getElementById('ci-filter-summary').style.display='none';
                document.getElementById('ci-progress').style.display='none';
                document.getElementById('ci-result').innerHTML='';
                document.getElementById('ci-log').innerHTML='';
                ['ci-total','ci-saved','ci-franchise','ci-noweb','ci-err'].forEach(id=>document.getElementById(id).textContent='0');

                // ── Phase 1: upload + filter ─────────────────────────────
                let qr;
                try{
                    const fd=new FormData();
                    fd.append('action','wnq_csv_import_queue');
                    fd.append('nonce',<?php echo wp_json_encode($nonce); ?>);
                    fd.append('csv_file',file);
                    fd.append('industry',industry);
                    ciLabel('Uploading & filtering CSV…');
                    document.getElementById('ci-progress').style.display='';
                    ciProg(0,1);
                    const r=await fetch(ajaxurl,{method:'POST',body:fd});
                    const raw=await r.text();
                    try{qr=JSON.parse(raw);}catch(je){ciErr('Parse failed: '+raw.replace(/<[^>]+>/g,'').trim().substring(0,200));startBtn.disabled=false;stopBtn.style.display='none';return;}
                }catch(e){ciErr('Upload error: '+e.message);startBtn.disabled=false;stopBtn.style.display='none';return;}
                if(!qr.success){ciErr(qr.data?.message||'Upload failed');startBtn.disabled=false;stopBtn.style.display='none';return;}

                const{batch_id,total,filtered}=qr.data;

                // Show filter summary
                const fs=document.getElementById('ci-filter-summary');
                fs.style.display='';
                document.getElementById('ci-f-total').textContent=filtered.total_rows||0;
                document.getElementById('ci-f-reviews').textContent=filtered.too_many_reviews||0;
                document.getElementById('ci-f-nophone').textContent=filtered.no_phone||0;
                document.getElementById('ci-f-franchise').textContent=filtered.franchise||0;
                document.getElementById('ci-f-multi').textContent=filtered.multi_location||0;
                document.getElementById('ci-f-dup').textContent=filtered.duplicate||0;
                document.getElementById('ci-f-qualified').textContent=total||0;
                document.getElementById('ci-total').textContent=total||0;

                if(!batch_id||!total){
                    startBtn.disabled=false;stopBtn.style.display='none';
                    document.getElementById('ci-result').innerHTML='<div class="wnq-result" style="background:#fef9c3;border:1px solid #fde68a;color:#92400e;"><strong>No qualifying leads</strong><p>All '+( filtered.total_rows||0 )+' rows were filtered out. Check your data.</p></div>';
                    ciProg(0,1);ciLabel('Done — 0 qualified');
                    return;
                }

                ciLog('Filter complete — '+total+' of '+(filtered.total_rows||0)+' rows passed. Starting website scrape…','#60a5fa');

                // ── Phase 2: scrape one lead per request ─────────────────
                // Auto-stop is intentionally removed — bad sites (SSL errors,
                // timeouts, PHP notices) should never kill a 200-lead run.
                // The Stop button is there if the user wants to halt manually.
                let processed=0,lastStats=null,networkFail=0;
                while(processed<total&&!_ciStopped){
                    ciLabel('Scraping '+(processed+1)+' / '+total+'…');
                    let pr=null;
                    try{
                        const ctrl=new AbortController(),tid=setTimeout(()=>ctrl.abort(),45000);
                        const fd2=new FormData();
                        fd2.append('action','wnq_csv_import_process');
                        fd2.append('nonce',<?php echo wp_json_encode($nonce); ?>);
                        fd2.append('batch_id',batch_id);
                        const r2=await fetch(ajaxurl,{method:'POST',body:fd2,signal:ctrl.signal});
                        clearTimeout(tid);networkFail=0;
                        const raw2=await r2.text();
                        try{pr=JSON.parse(raw2);}catch(je){
                            // PHP produced non-JSON (notice/warning before output) — skip row, keep going
                            ciLog('Row '+(processed+1)+' skipped (server output issue)','#fb923c');
                            processed++;ciProg(processed,total);continue;
                        }
                    }catch(e){
                        // True network failure (not an abort from our own timeout)
                        if(++networkFail>=10){ciErr('10 consecutive network failures — the server may be unreachable.');_ciStopped=true;}
                        ciLog('Row '+(processed+1)+' network error: '+e.message,'#fb923c');
                        processed++;ciProg(processed,total);continue;
                    }
                    // Session expired — only hard-stop worth doing
                    if(!pr?.success){
                        if(pr?.data?.message?.includes('expired')){ciErr(pr.data.message);_ciStopped=true;}
                        else{ciLog('Row '+(processed+1)+' skipped: '+(pr?.data?.message||'unknown error'),'#f87171');}
                        processed++;ciProg(processed,total);continue;
                    }
                    const d=pr.data;
                    processed=d.processed;lastStats=d.stats;
                    const name=d.name||('Row '+processed);
                    if(d.outcome==='saved')            ciLog('✓ Saved: '+name+(d.email?' ('+d.email+')':''),'#4ade80');
                    else if(d.outcome==='franchise')   ciLog('✗ Franchise (HTML): '+name,'#c084fc');
                    else if(d.outcome==='no_website')  ciLog('✗ No site: '+name,'#fb923c');
                    else if(d.outcome==='error')       ciLog('✗ Error: '+name,'#f87171');
                    else                               ciLog('⟳ '+d.outcome+': '+name,'#94a3b8');
                    if(d.stats){
                        document.getElementById('ci-saved').textContent=d.stats.saved||0;
                        document.getElementById('ci-franchise').textContent=d.stats.franchise||0;
                        document.getElementById('ci-noweb').textContent=d.stats.no_website||0;
                        document.getElementById('ci-err').textContent=d.stats.error||0;
                    }
                    ciProg(d.processed,d.total);
                    if(d.done)break;
                }
                startBtn.disabled=false;stopBtn.style.display='none';
                const saved=lastStats?lastStats.saved:0;
                if(!_ciStopped){
                    ciLabel('Complete');
                    document.getElementById('ci-result').innerHTML='<div class="wnq-result wnq-result-ok"><strong>Done</strong><p><b>'+saved+'</b> lead'+(saved!==1?'s':'')+' saved.'+(saved>0?' <a href="admin.php?page=wnq-lead-finder&tab=leads" style="color:#15803d;font-weight:600">View leads &rarr;</a>':'')+' </p></div>';
                }else{
                    ciLabel('Stopped');
                    document.getElementById('ci-result').innerHTML='<div class="wnq-result" style="background:#fef9c3;border:1px solid #fde68a;color:#92400e;"><strong>Stopped</strong><p>'+saved+' lead'+(saved!==1?'s':'')+' saved.</p></div>';
                }
            };
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
            <button type="button" class="wnq-btn wnq-btn-danger wnq-btn-sm" onclick="wnqDeleteAllLeads()">Delete All Leads</button>
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
            window.wnqDeleteAllLeads=function(){const typed=prompt('This permanently deletes every saved lead. Type DELETE ALL to confirm.');if(typed!=='DELETE ALL')return;const b=new FormData();b.append('action','wnq_lead_delete_all');b.append('nonce',nonce);fetch(ajaxurl,{method:'POST',body:b}).then(r=>r.json()).then(d=>{if(!d.success){alert('Error: '+(d.data?.message||'?'));return;}alert('Deleted '+(d.data?.deleted||0)+' lead(s).');window.location.href=<?php echo wp_json_encode($base_url);?>;});};
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
            <p style="color:#6b7280;font-size:13px;margin:0 0 16px">Configure the scalable Node backend for ZIP sweeps, or leave it blank to use the legacy local fallback.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                <?php wp_nonce_field('wnq_lead_save_settings','wnq_nonce');?>
                <input type="hidden" name="action" value="wnq_lead_save_settings">
                <div class="wnq-card" style="background:#f8fafc;margin:-4px 0 16px;padding:14px">
                    <h3 style="margin-bottom:10px">Scalable Backend</h3>
                    <div class="wnq-row2">
                        <div class="wnq-field">
                            <label>Backend API URL</label>
                            <input type="url" name="backend_api_url" value="<?php echo esc_attr($settings['backend_api_url']??'');?>" placeholder="https://lead-api.webnique.com">
                            <small>When set, ZIP Sweep runs in the Node backend instead of WordPress.</small>
                        </div>
                        <div class="wnq-field">
                            <label>Backend API Key</label>
                            <input type="password" name="backend_api_key" value="<?php echo esc_attr($settings['backend_api_key']??'');?>" placeholder="Bearer token">
                            <small>Stored in WordPress options and sent as Authorization: Bearer.</small>
                        </div>
                    </div>
                    <div class="wnq-row2">
                        <div class="wnq-field">
                            <label>Backend Source</label>
                            <select name="backend_source">
                                <option value="outscraper"<?php selected($settings['backend_source']??'outscraper','outscraper');?>>Outscraper</option>
                            </select>
                            <small>Phase 1 uses Outscraper for Maps discovery.</small>
                        </div>
                        <div class="wnq-field">
                            <label>Max Reviews</label>
                            <input type="number" name="max_reviews" value="<?php echo esc_attr($settings['max_reviews']??50);?>" min="0" max="500">
                            <small>Businesses above this review count are filtered out.</small>
                        </div>
                    </div>
                </div>
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
        ?>
        <div class="wnq-card" style="max-width:680px">
            <h3>Scraper Setup (Puppeteer / Node.js)</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 12px">
                The ZIP Sweep uses the bundled local Puppeteer server to scrape Google Maps results.
                Install dependencies during deploy or via SSH; WordPress admin no longer runs shell installs.
            </p>
            <p style="margin:0 0 14px">
                Status: <?php if($npm_installed):?>
                    <strong style="color:#16a34a">✓ Installed</strong>
                <?php else:?>
                    <strong style="color:#dc2626">✗ Not installed</strong> — ZIP Sweep will use fallback HTTP (fewer results)
                <?php endif;?>
            </p>
            <div style="margin-top:16px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:12px">
                <strong>Manual install (SSH):</strong><br>
                <code style="display:block;margin-top:6px;font-size:11px;word-break:break-all">cd <?php echo esc_html($scraper_dir); ?> &amp;&amp; npm install</code>
            </div>
        </div>
        <?php
    }

    // ── AJAX: ZIP Sweep ───────────────────────────────────────────────────────

    public static function ajaxZipStart(): void
    {
        if (!check_ajax_referer('wnq_lead_nonce', 'nonce', false)) { wp_send_json_error(['error' => 'Security check failed'], 403); return; }
        self::requireCap();
        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
        if (self::backendEnabled()) {
            $result = self::startBackendJob($keyword);
            $result['ok']
                ? wp_send_json_success($result)
                : wp_send_json_error(['error' => $result['error'] ?? 'Backend job failed']);
            return;
        }
        ob_start();
        try {
            $result = LeadFinderEngine::startSearch($keyword);
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
        $backend_batch = get_transient('wnq_backend_lead_batch_' . $batch_id);
        if ($backend_batch) {
            $result = self::pollBackendJob($batch_id, $backend_batch);
            $result['ok']
                ? wp_send_json_success($result)
                : wp_send_json_error(['error' => $result['error'] ?? 'Backend polling failed']);
            return;
        }
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

    // ── AJAX: Bulk Google Maps Import ─────────────────────────────────────────

    public static function ajaxBulkMapsQueue(): void
    {
        if (!check_ajax_referer('wnq_lead_manual', 'nonce', false)) { wp_send_json_error(['message' => 'Security check failed — refresh and try again.']); return; }
        self::requireCap();
        $result = LeadFinderEngine::queueBulkMaps(
            wp_unslash($_POST['tsv'] ?? ''),
            sanitize_text_field($_POST['industry'] ?? '')
        );
        $result['ok']
            ? wp_send_json_success(['batch_id' => $result['batch_id'], 'total' => $result['total']])
            : wp_send_json_error(['message' => $result['error'] ?? 'Queue failed']);
    }

    public static function ajaxBulkMapsProcess(): void
    {
        if (!check_ajax_referer('wnq_lead_manual', 'nonce', false)) { wp_send_json_error(['message' => 'Security check failed — refresh and try again.']); return; }
        self::requireCap();
        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        if (!$batch_id) { wp_send_json_error(['message' => 'Missing batch_id']); return; }
        @set_time_limit(0);
        ob_start();
        try {
            $result = LeadFinderEngine::processNextBulkMaps($batch_id);
        } catch (\Throwable $e) {
            ob_end_clean();
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }
        ob_end_clean();
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

    public static function ajaxDeleteAll(): void
    {
        check_ajax_referer('wnq_lead_actions', 'nonce');
        self::requireCap();
        $deleted = Lead::deleteAll();
        wp_send_json_success(['deleted' => $deleted]);
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
                'backend_api_url'  => esc_url_raw(untrailingslashit($_POST['backend_api_url'] ?? '')),
                'backend_api_key'  => sanitize_text_field($_POST['backend_api_key'] ?? ''),
                'backend_source'   => sanitize_key($_POST['backend_source'] ?? 'outscraper'),
                'max_reviews'      => max(0, min(500, (int)($_POST['max_reviews'] ?? 50))),
            ]
        ));
        wp_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=settings&settings_saved=1'));
        exit;
    }

    // ── AJAX: CSV Import ──────────────────────────────────────────────────────

    public static function ajaxCsvImportQueue(): void
    {
        if (!check_ajax_referer('wnq_csv_import', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed — refresh and try again.']);
            return;
        }
        self::requireCap();

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            wp_send_json_error(['message' => 'No file uploaded.']);
            return;
        }
        if ($_FILES['csv_file']['size'] > 20 * 1024 * 1024) {
            wp_send_json_error(['message' => 'File too large. Max 20 MB.']);
            return;
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error(['message' => 'Could not read uploaded file.']);
            return;
        }

        // Auto-detect delimiter (comma or tab)
        $first_line = fgets($handle);
        rewind($handle);
        $delimiter = substr_count($first_line, "\t") > substr_count($first_line, ',') ? "\t" : ',';

        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header || count($header) < 2) {
            fclose($handle);
            wp_send_json_error(['message' => 'Could not read CSV header. Ensure the first row contains column names.']);
            return;
        }

        $header  = array_map(fn($h) => strtolower(trim((string)$h)), $header);
        $col_map = self::buildCsvColumnMap($header);

        if (!isset($col_map['business_name'])) {
            fclose($handle);
            wp_send_json_error(['message' => 'Could not find a "Business Name" or "Company Name" column. Check your CSV headers.']);
            return;
        }

        $fallback_industry = sanitize_text_field($_POST['industry'] ?? '');

        // Read all rows (cap at 10k)
        $all_rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false && count($all_rows) < 10000) {
            if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) > 0) {
                $all_rows[] = $row;
            }
        }
        fclose($handle);

        if (empty($all_rows)) {
            wp_send_json_error(['message' => 'CSV has no data rows after the header.']);
            return;
        }

        // ── Filtering pipeline ───────────────────────────────────────────────
        $filtered = [
            'total_rows'       => count($all_rows),
            'too_many_reviews' => 0,
            'no_phone'         => 0,
            'franchise'        => 0,
            'multi_location'   => 0,
            'duplicate'        => 0,
        ];

        // Pass 1: build name frequency map to detect multi-location chains
        // Normalise: lowercase + strip common location suffixes (of X, at X, - X)
        $name_freq = [];
        foreach ($all_rows as $row) {
            $name = strtolower(trim((string)($row[$col_map['business_name']] ?? '')));
            $name = preg_replace('/\s+(of|at|in|–|-)\s+\S.*$/', '', $name); // strip location suffix
            $name = preg_replace('/[^a-z0-9 ]/', '', $name);
            $name = preg_replace('/\s+/', ' ', trim($name));
            if ($name) {
                $name_freq[$name] = ($name_freq[$name] ?? 0) + 1;
            }
        }

        // Pass 2: apply filters, collect qualifying rows
        $qualified = [];
        foreach ($all_rows as $row) {
            $get = fn(int $idx): string => isset($row[$idx]) ? trim((string)$row[$idx]) : '';

            $business_name = sanitize_text_field($get($col_map['business_name']));
            if (!$business_name) {
                continue; // silently skip rows with no business name (not counted in any filter bucket)
            }

            // 1. Review count < 100
            $review_count = isset($col_map['review_count']) ? (int)$get($col_map['review_count']) : 0;
            if ($review_count >= 100) {
                $filtered['too_many_reviews']++;
                continue;
            }

            // 2. Must have a phone number
            $phone = isset($col_map['phone']) ? $get($col_map['phone']) : '';
            if (empty($phone) || preg_replace('/[^0-9]/', '', $phone) === '') {
                $filtered['no_phone']++;
                continue;
            }

            // 3. Franchise check (name only — HTML check done during scrape phase)
            if (\WNQ\Services\LeadEnrichmentService::isFranchise($business_name)) {
                $filtered['franchise']++;
                continue;
            }

            // 4. Multi-location chain: same normalised name appears 3+ times
            $norm = preg_replace('/[^a-z0-9 ]/', '', strtolower($business_name));
            $norm = preg_replace('/\s+(of|at|in|–|-)\s+\S.*$/', '', $norm);
            $norm = preg_replace('/\s+/', ' ', trim($norm));
            if (($name_freq[$norm] ?? 0) >= 3) {
                $filtered['multi_location']++;
                continue;
            }

            // 5. Already in database
            $city = isset($col_map['city']) ? sanitize_text_field($get($col_map['city'])) : '';
            if (Lead::existsByNameAndCity($business_name, $city)) {
                $filtered['duplicate']++;
                continue;
            }

            $qualified[] = $row;
        }

        if (empty($qualified)) {
            // Return success with 0 total so UI shows the filter summary
            wp_send_json_success([
                'batch_id' => '',
                'total'    => 0,
                'filtered' => $filtered,
            ]);
            return;
        }

        $batch_id = 'ci_' . bin2hex(random_bytes(8));
        set_transient('wnq_csv_' . $batch_id, [
            'rows'              => $qualified,
            'col_map'           => $col_map,
            'fallback_industry' => $fallback_industry,
            'total'             => count($qualified),
            'offset'            => 0,
            'stats'             => ['saved' => 0, 'franchise' => 0, 'no_website' => 0, 'error' => 0],
        ], 2 * HOUR_IN_SECONDS);

        wp_send_json_success([
            'batch_id' => $batch_id,
            'total'    => count($qualified),
            'filtered' => $filtered,
        ]);
    }

    public static function ajaxCsvImportProcess(): void
    {
        if (!check_ajax_referer('wnq_csv_import', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed — refresh and try again.']);
            return;
        }
        self::requireCap();

        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        if (!$batch_id) {
            wp_send_json_error(['message' => 'Missing batch_id']);
            return;
        }

        $state = get_transient('wnq_csv_' . $batch_id);
        if (!$state) {
            wp_send_json_error(['message' => 'Import session expired or not found. Please re-upload the file.']);
            return;
        }

        // Buffer all output so stray PHP notices/warnings never corrupt the JSON response
        ob_start();
        @set_time_limit(0);

        $rows     = $state['rows'];
        $col_map  = $state['col_map'];
        $offset   = $state['offset'];
        $total    = $state['total'];
        $stats    = $state['stats'];
        $fallback = $state['fallback_industry'];

        if ($offset >= $total) {
            ob_end_clean();
            delete_transient('wnq_csv_' . $batch_id);
            wp_send_json_success(['processed' => $total, 'total' => $total, 'stats' => $stats, 'done' => true]);
            return;
        }

        // Process one lead per request (web fetch involved)
        $row     = $rows[$offset];
        $data    = self::mapCsvRow($row, $col_map, $fallback);
        $name    = $data['business_name'];
        $website = $data['website'];
        $outcome = 'error';
        $email   = '';

        try {
            $homepage_html = '';

            // Fetch homepage if a website URL is present
            if ($website) {
                $resp = wp_remote_get($website, [
                    'timeout'             => 8,
                    'user-agent'          => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
                    'sslverify'           => false,
                    'redirection'         => 3,
                    'limit_response_size' => 300000,
                ]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 400) {
                    $homepage_html = wp_remote_retrieve_body($resp);
                }
            }

            // Re-check franchise via HTML (catches sites that disclose it on the page)
            if ($homepage_html && \WNQ\Services\LeadEnrichmentService::isFranchise($name, $homepage_html)) {
                $stats['franchise']++;
                $outcome = 'franchise';
            } elseif (!$website) {
                $stats['no_website']++;
                $outcome = 'no_website';
                // Still save the lead if it had enough info (phone + name already confirmed)
                $id = Lead::insert($data);
                if ($id > 0) {
                    $stats['saved']++;
                    $outcome = 'saved'; // saved without email
                } else {
                    $stats['error']++;
                    $outcome = 'error';
                }
            } else {
                // Extract email from homepage (or /contact fallback)
                $email_result = \WNQ\Services\LeadEmailExtractor::extractEmail($website, $homepage_html);
                if ($email_result['email']) {
                    $data['email']        = $email_result['email'];
                    $data['email_source'] = $email_result['source'];
                    $email                = $email_result['email'];
                }

                // Extract social media if not already in CSV
                $social = \WNQ\Services\LeadEnrichmentService::extractSocialMedia($website, $homepage_html);
                foreach (['facebook','instagram','linkedin','twitter','youtube','tiktok'] as $sn) {
                    if (empty($data['social_' . $sn]) && !empty($social[$sn])) {
                        $data['social_' . $sn] = $social[$sn];
                    }
                }

                $id = Lead::insert($data);
                if ($id > 0) {
                    $stats['saved']++;
                    $outcome = 'saved';
                } else {
                    $stats['error']++;
                    $outcome = 'error';
                }
            }
        } catch (\Throwable $e) {
            $stats['error']++;
            $outcome = 'error';
        }

        $new_offset = $offset + 1;
        $done       = $new_offset >= $total;

        $state['offset'] = $new_offset;
        $state['stats']  = $stats;

        if ($done) {
            delete_transient('wnq_csv_' . $batch_id);
        } else {
            set_transient('wnq_csv_' . $batch_id, $state, 2 * HOUR_IN_SECONDS);
        }

        ob_end_clean(); // discard any stray PHP output before sending JSON

        wp_send_json_success([
            'processed' => $new_offset,
            'total'     => $total,
            'stats'     => $stats,
            'done'      => $done,
            'outcome'   => $outcome,
            'name'      => $name,
            'email'     => $email,
        ]);
    }

    /**
     * Build a map of lead field => CSV column index from the header row.
     */
    private static function buildCsvColumnMap(array $headers): array
    {
        $aliases = [
            'business_name'    => ['business name','company name','company','name','business','title'],
            'email'            => ['email','email address','e-mail'],
            'phone'            => ['phone','phone number','telephone','tel'],
            'website'          => ['website','website url','url','web','site'],
            'address'          => ['address','street','street address','full address'],
            'city'             => ['city','town'],
            'state'            => ['state','province','region'],
            'zip'              => ['zip','zip code','postal code','postcode','postal'],
            'industry'         => ['industry','category','type','niche','keyword'],
            'rating'           => ['rating','stars','star rating','score'],
            'review_count'     => ['review count','reviews','review_count','num reviews','number of reviews','# reviews'],
            'owner_first'      => ['owner first','first name','owner first name','firstname'],
            'owner_last'       => ['owner last','last name','owner last name','lastname'],
            'social_facebook'  => ['facebook','fb','facebook url','social_facebook'],
            'social_instagram' => ['instagram','ig','instagram url','social_instagram'],
            'social_linkedin'  => ['linkedin','linkedin url','social_linkedin'],
            'social_twitter'   => ['twitter','x','twitter url','social_twitter','x (twitter)'],
            'social_youtube'   => ['youtube','yt','youtube url','social_youtube'],
            'social_tiktok'    => ['tiktok','tt','tiktok url','social_tiktok'],
            'status'           => ['status','lead status'],
            'notes'            => ['notes','note','comments','comment'],
        ];

        $map = [];
        foreach ($aliases as $field => $names) {
            foreach ($headers as $i => $h) {
                if (in_array($h, $names, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    /**
     * Map a single CSV row to a lead data array ready for Lead::insert().
     */
    private static function mapCsvRow(array $row, array $col_map, string $fallback_industry): array
    {
        $get = fn(string $field): string =>
            isset($col_map[$field], $row[$col_map[$field]])
                ? trim((string)$row[$col_map[$field]])
                : '';

        $status = $get('status');
        if (!in_array($status, ['new', 'contacted', 'qualified', 'closed'], true)) {
            $status = 'new';
        }

        $business_name = sanitize_text_field($get('business_name'));
        $city          = sanitize_text_field($get('city'));
        $phone         = sanitize_text_field($get('phone'));

        // Stable, unique place_id so the UNIQUE KEY constraint is satisfied
        $place_id = 'csv_' . md5($business_name . '|' . strtolower($city) . '|' . $phone);

        return [
            'place_id'         => $place_id,
            'business_name'    => $business_name,
            'industry'         => sanitize_text_field($get('industry') ?: $fallback_industry),
            'owner_first'      => sanitize_text_field($get('owner_first')),
            'owner_last'       => sanitize_text_field($get('owner_last')),
            'website'          => esc_url_raw($get('website')),
            'address'          => sanitize_text_field($get('address')),
            'city'             => $city,
            'state'            => sanitize_text_field($get('state')),
            'zip'              => sanitize_text_field($get('zip')),
            'phone'            => $phone,
            'email'            => sanitize_email($get('email')), // may be overwritten by scraper
            'rating'           => (float)$get('rating'),
            'review_count'     => (int)$get('review_count'),
            'social_facebook'  => esc_url_raw($get('social_facebook')),
            'social_instagram' => esc_url_raw($get('social_instagram')),
            'social_linkedin'  => esc_url_raw($get('social_linkedin')),
            'social_twitter'   => esc_url_raw($get('social_twitter')),
            'social_youtube'   => esc_url_raw($get('social_youtube')),
            'social_tiktok'    => esc_url_raw($get('social_tiktok')),
            'status'           => $status,
            'notes'            => sanitize_textarea_field($get('notes')),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function requireCap(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Access denied'], 403);
            exit;
        }
    }

    private static function backendEnabled(): bool
    {
        $settings = get_option('wnq_lead_finder_settings', []);
        return !empty($settings['backend_api_url']) && !empty($settings['backend_api_key']);
    }

    private static function startBackendJob(string $keyword): array
    {
        if (!$keyword) {
            return ['ok' => false, 'error' => 'Keyword is required'];
        }

        $settings = get_option('wnq_lead_finder_settings', []);
        $zips = array_values(array_unique(\WNQ\Data\FloridaZips::getAll()));
        $response = self::backendRequest('POST', '/v1/jobs', [
            'keyword' => $keyword,
            'zips' => $zips,
            'source' => $settings['backend_source'] ?? 'outscraper',
            'createdBy' => wp_get_current_user()->user_login ?: 'wordpress',
            'filters' => [
                'maxReviews' => (int)($settings['max_reviews'] ?? 50),
                'requireWebsite' => true,
                'requirePhone' => false,
            ],
        ]);

        if (empty($response['ok'])) {
            return $response;
        }

        $batch_id = wp_generate_uuid4();
        set_transient('wnq_backend_lead_batch_' . $batch_id, [
            'job_id' => $response['data']['jobId'] ?? '',
            'imported' => false,
            'keyword' => $keyword,
            'stats' => self::emptyBackendStats(),
        ], DAY_IN_SECONDS);

        return [
            'ok' => true,
            'mode' => 'backend',
            'batch_id' => $batch_id,
            'backend_job_id' => $response['data']['jobId'] ?? '',
            'total_zips' => count($zips),
        ];
    }

    private static function pollBackendJob(string $batch_id, array $batch): array
    {
        $job_id = sanitize_text_field($batch['job_id'] ?? '');
        if (!$job_id) {
            delete_transient('wnq_backend_lead_batch_' . $batch_id);
            return ['ok' => false, 'error' => 'Backend job id missing'];
        }

        $response = self::backendRequest('GET', '/v1/jobs/' . rawurlencode($job_id));
        if (empty($response['ok'])) {
            return $response;
        }

        $job = $response['data']['job'] ?? [];
        $stats = [
            'zips_searched' => (int)($job['completed_zips'] ?? 0),
            'saved' => (int)($job['total_saved'] ?? 0),
            'duplicate' => 0,
            'no_phone' => 0,
            'no_website' => 0,
            'errors' => (int)($job['error_count'] ?? 0),
        ];

        $done = in_array($job['state'] ?? '', ['completed', 'completed_with_errors', 'failed', 'canceled'], true);
        $imported = 0;

        if ($done && empty($batch['imported']) && in_array($job['state'] ?? '', ['completed', 'completed_with_errors'], true)) {
            $imported = self::importBackendLeads($job_id);
            $batch['imported'] = true;
            $batch['stats'] = $stats;
            set_transient('wnq_backend_lead_batch_' . $batch_id, $batch, DAY_IN_SECONDS);
        }

        if ($done) {
            delete_transient('wnq_backend_lead_batch_' . $batch_id);
        }

        return [
            'ok' => true,
            'done' => $done,
            'action' => 'backend_status',
            'zip_index' => (int)($job['completed_zips'] ?? 0),
            'total_zips' => (int)($job['total_zips'] ?? 0),
            'stats' => $stats,
            'state' => sanitize_text_field($job['state'] ?? 'queued'),
            'found' => (int)($job['total_found'] ?? 0),
            'imported' => $imported,
        ];
    }

    private static function importBackendLeads(string $job_id): int
    {
        $response = self::backendRequest('GET', '/v1/jobs/' . rawurlencode($job_id) . '/leads');
        if (empty($response['ok'])) {
            return 0;
        }

        $count = 0;
        foreach (($response['data']['leads'] ?? []) as $lead) {
            $place_id = sanitize_text_field($lead['source_place_id'] ?? '');
            if (!$place_id) {
                $place_id = 'backend_' . md5(($lead['website'] ?? '') . '|' . ($lead['business_name'] ?? ''));
            }
            if (Lead::findByPlaceId($place_id)) {
                continue;
            }
            $id = Lead::insert([
                'place_id' => $place_id,
                'business_name' => sanitize_text_field($lead['business_name'] ?? ''),
                'industry' => sanitize_text_field($lead['industry'] ?? ''),
                'website' => esc_url_raw($lead['website'] ?? ''),
                'address' => sanitize_text_field($lead['address'] ?? ''),
                'city' => sanitize_text_field($lead['city'] ?? ''),
                'state' => sanitize_text_field($lead['state'] ?? ''),
                'zip' => sanitize_text_field($lead['zip'] ?? ''),
                'phone' => sanitize_text_field($lead['phone'] ?? ''),
                'email' => sanitize_email($lead['email'] ?? ''),
                'email_source' => esc_url_raw($lead['email_source'] ?? ''),
                'rating' => (float)($lead['rating'] ?? 0),
                'review_count' => (int)($lead['review_count'] ?? 0),
                'social_facebook' => esc_url_raw($lead['facebook'] ?? ''),
                'social_instagram' => esc_url_raw($lead['instagram'] ?? ''),
                'social_linkedin' => esc_url_raw($lead['linkedin'] ?? ''),
                'social_youtube' => esc_url_raw($lead['youtube'] ?? ''),
                'social_tiktok' => esc_url_raw($lead['tiktok'] ?? ''),
                'seo_score' => (int)($lead['lead_score'] ?? 0),
                'seo_issues' => [],
                'status' => 'new',
                'notes' => 'Imported from Lead Backend job ' . $job_id,
            ]);
            if ($id > 0) {
                $count++;
            }
        }

        return $count;
    }

    private static function backendRequest(string $method, string $path, array $body = []): array
    {
        $settings = get_option('wnq_lead_finder_settings', []);
        $base = untrailingslashit($settings['backend_api_url'] ?? '');
        $key = $settings['backend_api_key'] ?? '';
        if (!$base || !$key) {
            return ['ok' => false, 'error' => 'Lead backend is not configured'];
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
        ];
        if ($method !== 'GET') {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($base . $path, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => $data['error'] ?? ('Backend HTTP ' . $code)];
        }

        return ['ok' => true, 'data' => is_array($data) ? $data : []];
    }

    private static function emptyBackendStats(): array
    {
        return ['zips_searched' => 0, 'saved' => 0, 'duplicate' => 0, 'no_phone' => 0, 'no_website' => 0, 'errors' => 0];
    }
}
