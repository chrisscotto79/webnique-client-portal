<?php
namespace WNQ\Admin;

use WNQ\Services\LeadBrowserIntake;
use WNQ\Models\Lead;

if (!defined('ABSPATH')) { exit; }

final class LeadBrowserAdmin
{
    public static function register(): void {
        \WNQ\Models\LeadSearchHistory::install();
        add_action('wp_ajax_wnq_browser_lead_save', [self::class, 'save']);
        add_action('wp_ajax_wnq_browser_search_history', [self::class, 'history']);
        add_action('admin_post_wnq_lead_qualify', [self::class, 'manage']);
    }
    public static function manage(): void
    {
        if (!current_user_can('manage_options') && !current_user_can('wnq_manage_portal')) { wp_die('Access denied'); }
        check_admin_referer('wnq_lead_qualify');
        global $wpdb;
        try {
            $op = sanitize_key($_POST['operation'] ?? '');
            if ($op === 'delete_all') {
                if (!current_user_can('manage_options') || ($_POST['confirmation'] ?? '') !== 'DELETE ALL') { throw new \RuntimeException('Administrator access and exact DELETE ALL confirmation required.'); }
                $message = Lead::deleteAll() . ' leads permanently deleted. Search history and GHL suppression/handoff records retained. Existing GHL contacts and workflows were not changed.';
            } elseif ($op === 'rules') {
                if (!current_user_can('manage_options')) { throw new \RuntimeException('Only administrators can change qualification rules.'); }
                update_option('wnq_lead_seo_min', max(0, min(7, (int)($_POST['seo_min'] ?? 0))), false);
                $message = 'Qualification filters saved. Hands-free GHL transfers use valid email and safety checks, not these filters.';
            } elseif ($op === 'review') {
                $fit = sanitize_key($_POST['company_fit'] ?? '');
                $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
                $lead = \WNQ\Services\LeadGhlSync::lead(absint($_POST['lead_id'] ?? 0));
                if (!$lead || !in_array($fit, ['unknown','independent','chain','large'], true) || !$reason) { throw new \RuntimeException('Choose a company classification and provide evidence/reason.'); }
                $notes = ($lead['notes'] ?? '') . "\nCompany review: " . $fit . ' by staff #' . get_current_user_id() . ' at ' . gmdate('Y-m-d H:i:s') . ' UTC. ' . $reason;
                if ($wpdb->update($wpdb->prefix . 'wnq_leads', ['company_fit'=>$fit, 'notes'=>$notes], ['id'=>(int)$lead['id']]) === false) { throw new \RuntimeException('Review could not be saved.'); }
                $message = 'Company review saved. No lead was sent; use Approve & Send when ready.';
            } else { throw new \RuntimeException('Unknown action.'); }
        } catch (\RuntimeException $e) { $message = $e->getMessage(); }
        set_transient('wnq_lead_list_notice_' . get_current_user_id(), $message, 120);
        wp_safe_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=leads')); exit;
    }
    public static function history(): void
    {
        if (!current_user_can('manage_options') && !current_user_can('wnq_manage_portal')) { wp_send_json_error(['message' => 'Access denied.'], 403); return; }
        if (!check_ajax_referer('wnq_browser_leads', 'nonce', false)) { wp_send_json_error(['message' => 'Session expired. Refresh WordPress.'], 403); return; }
        try {
            $op = sanitize_key($_POST['operation'] ?? '');
            $keyword = wp_unslash($_POST['keyword'] ?? '');
            $run = sanitize_text_field($_POST['run'] ?? '');
            if ($op === 'check') {
                $zips = \WNQ\Models\LeadSearchHistory::zips(wp_unslash($_POST['zips'] ?? ''));
                wp_send_json_success(['keyword' => \WNQ\Models\LeadSearchHistory::keyword($keyword), 'items' => \WNQ\Models\LeadSearchHistory::check($keyword, $zips)]);
            } elseif ($op === 'begin') {
                \WNQ\Models\LeadSearchHistory::begin($run, $keyword, sanitize_text_field($_POST['zip'] ?? ''));
                wp_send_json_success([]);
            } elseif ($op === 'finish') {
                \WNQ\Models\LeadSearchHistory::finish($run, (array)json_decode(wp_unslash($_POST['stats'] ?? '{}'), true));
                wp_send_json_success([]);
            } else { throw new \RuntimeException('Unknown history action.'); }
        } catch (\Throwable $e) { wp_send_json_error(['message' => $e instanceof \RuntimeException ? $e->getMessage() : 'History request failed safely.'], 400); }
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options') && !current_user_can('wnq_manage_portal')) { wp_send_json_error(['message' => 'Access denied.'], 403); return; }
        if (!check_ajax_referer('wnq_browser_leads', 'nonce', false)) { wp_send_json_error(['message' => 'Session expired. Refresh WordPress, then resume.'], 403); return; }
        $raw = wp_unslash($_POST['row'] ?? '');
        if (!is_string($raw) || strlen($raw) > 20000) { wp_send_json_error(['message' => 'Listing too large.'], 400); return; }
        $row = json_decode($raw, true);
        if (!is_array($row)) { wp_send_json_error(['message' => 'Invalid listing.'], 400); return; }
        try {
            wp_send_json_success(LeadBrowserIntake::accept($row, sanitize_text_field(wp_unslash($_POST['keyword'] ?? '')), sanitize_text_field($_POST['zip'] ?? '')));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Listing could not be processed. Retry safely.'], 400);
        }
    }

    public static function render(): void
    {
        $base = plugins_url('../assets/', __FILE__);
        ?>
        <div class="wnq-card" id="lf-ghl-auto" data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wnq_ghl_drain')); ?>">
            <strong>Automatic GHL handoff</strong>
            <p><?php echo !empty(\WNQ\Services\LeadGhlSync::settings()['automatic']) && \WNQ\Services\LeadGhlSync::configured() ? 'ON — new valid-email leads are queued automatically. No individual approval. Outreach score/review filters do not block this mode. Suppression and identity checks still apply.' : 'OFF or not configured — check GoHighLevel settings before starting.'; ?></p>
            <p data-progress role="status">Keep this page and Chrome open. Email deduplication is shared across all keywords and ZIP codes.</p>
            <p id="lf-bulk-totals" role="status">This bulk search: 0 new leads · 0 new leads with email</p>
            <p data-backlog role="status">Start a search to also queue valid unsent leads already in your list.</p>
        </div>
        <script src="<?php echo esc_url($base . 'js/lead-ghl-auto.js?v=' . WNQ_PORTAL_VERSION); ?>"></script>
        <link rel="stylesheet" href="<?php echo esc_url($base . 'css/lead-browser.css?v=' . WNQ_PORTAL_VERSION); ?>">
        <section class="lf-hero"><span>GOOGLE MAPS → YOUR LEAD LIST</span><h2>Find the right businesses. Build your list.</h2><p>Search a niche and ZIP. Chrome reads listings; WordPress checks their websites for public emails.</p></section>
        <div id="lf-browser-app" data-user="<?php echo (int)get_current_user_id(); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wnq_browser_leads')); ?>">
            <section class="wnq-card lf-search"><form id="lf-search-form">
                <div class="wnq-field"><label for="lf-niche">Niche keyword</label><input id="lf-niche" name="keyword" maxlength="100" placeholder="Plumbers" required></div>
                <div class="wnq-field"><label for="lf-postcode">ZIP codes (up to 250)</label><textarea id="lf-postcode" name="zip" maxlength="4000" placeholder="32825, 32826, 32828" required style="min-height:70px"></textarea></div>
                <button class="wnq-btn wnq-btn-primary" id="lf-start">Check ZIPs first</button>
            </form><p>Searches the area around your ZIP; Google may include nearby businesses. All listings are saved. When automatic GHL sync is on, new valid-email leads transfer without approval. Email deduplication spans all keywords and ZIPs.</p>
            <div id="lf-zip-review" hidden><h3>Review ZIP history before starting</h3><p>Previously searched ZIPs are unchecked. Select one to intentionally rerun it. Times are UTC; old imported leads may only establish that a search happened, not that it finished.</p><div id="lf-zip-items"></div><button id="lf-bulk-start" class="wnq-btn wnq-btn-primary" type="button">Start selected ZIPs</button></div>
            <p id="lf-extension-status" role="status">Checking Chrome companion…</p>
            <button id="lf-reconnect" class="wnq-btn wnq-btn-secondary" type="button">Reconnect companion</button>
            <details id="lf-setup"><summary>One-time Chrome setup</summary><ol>
                <li>In your local plugin folder, find <strong>browser-companion</strong>.</li>
                <li>Open <strong>chrome://extensions</strong>, enable Developer mode, select <strong>Load unpacked</strong>, and choose that folder.</li>
                <li>Refresh this WordPress page. Keep this page and Chrome open during collection.</li>
            </ol><p>The companion is restricted to this agency’s WordPress admin and Google Maps. It has no GHL token and does not send emails. No Node server or paid Maps API is required.</p></details></section>
            <section class="wnq-card"><div class="lf-progress-head"><h3>Current search</h3><div><button id="lf-resume" class="wnq-btn wnq-btn-secondary" type="button">Resume / retry</button> <button id="lf-pause" class="wnq-btn wnq-btn-secondary" type="button" disabled>Pause</button></div></div>
                <p id="lf-progress" role="status" aria-live="polite">Enter a keyword and ZIP to begin.</p>
                <p id="lf-bulk-progress" role="status"></p><small>Counts below are for the current ZIP. Search history is saved in WordPress; the remaining bulk queue stays in this Chrome tab.</small>
                <div class="lf-counts"><div><strong id="lf-count-found">0</strong><span>Listings collected</span></div><div><strong id="lf-count-saved">0</strong><span>New leads saved</span></div><div><strong id="lf-count-email">0</strong><span>New leads with email</span></div><div><strong id="lf-count-duplicate">0</strong><span>Already in your list</span></div></div>
                <p class="lf-note">Up to three reusable Maps tabs load listing details ahead; leads are saved one at a time. All collection tabs close when the ZIP finishes. Temporary Chrome errors retry with a cooldown until you Pause. Keep Chrome, this WordPress tab and your computer awake. Google verification or expired login may still need your attention. Up to 100 listings per ZIP; found emails are not mailbox-verified.</p>
                <ul id="lf-activity" aria-label="Recent collection activity"></ul>
                <a class="wnq-btn wnq-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab=leads')); ?>">View combined lead list</a>
            </section>
        </div>
        <script src="<?php echo esc_url($base . 'js/lead-browser.js?v=' . WNQ_PORTAL_VERSION); ?>" defer></script>
        <?php
    }

    public static function leads(): void
    {
        $page = max(1, (int)($_GET['paged'] ?? 1));
        $filter = sanitize_key($_GET['contact_filter'] ?? 'all');
        $args = $filter === 'email' ? ['has_email' => true] : [];
        if ($filter === 'no_email') { $args['no_email'] = true; }
        foreach (['search','zip','status','company_fit','city','industry'] as $key) { if (!empty($_GET[$key]) && is_string($_GET[$key])) { $args[$key] = sanitize_text_field(wp_unslash($_GET[$key])); } }
        foreach (['max_reviews','seo_issues_min'] as $key) { if (isset($_GET[$key]) && $_GET[$key] !== '') { $args[$key] = max(0, min($key === 'max_reviews' ? 1000000 : 7, (int)$_GET[$key])); } }
        foreach (['has_phone','no_website'] as $key) { if (!empty($_GET[$key])) { $args[$key] = true; } }
        $count = Lead::count($args);
        $rows = Lead::getAll(array_merge($args, ['limit' => 50, 'offset' => ($page - 1) * 50]));
        ?>
        <link rel="stylesheet" href="<?php echo esc_url(plugins_url('../assets/css/lead-browser.css', __FILE__) . '?v=' . WNQ_PORTAL_VERSION); ?>">
        <?php $notice = get_transient('wnq_lead_list_notice_' . get_current_user_id()); if ($notice) { delete_transient('wnq_lead_list_notice_' . get_current_user_id()); echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; } ?>
        <section class="wnq-card"><h2>Prospect filters</h2><p>These filters help review your list. Hands-free GHL transfers do not require review-count, company-type or SEO approval. Valid email, New/Qualified status, suppression and duplicate protections still apply. Old holds can be reviewed separately.</p>
        <p>SEO issues: 0–7 homepage checks, higher = more problems (not a ranking or traffic score). Missing assessments never pass an enabled SEO filter. Existing rows may need a fresh assessment.</p>
        <?php if (current_user_can('manage_options')): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wnq_lead_qualify"><input type="hidden" name="operation" value="rules"><?php wp_nonce_field('wnq_lead_qualify'); ?><label>Minimum SEO issues for GHL (0 = optional) <input name="seo_min" type="number" min="0" max="7" value="<?php echo (int)get_option('wnq_lead_seo_min',0); ?>"></label> <button class="wnq-btn wnq-btn-secondary">Save rule</button></form><?php endif; ?></section>
        <section class="wnq-card"><h2>Your combined lead list</h2><p>All saved searches in one place. Review email sources before outreach; businesses without email can still be called. Existing GHL approval and automatic-sync settings are unchanged.</p>
        <form method="get" class="lf-list-tools"><input type="hidden" name="page" value="wnq-lead-finder"><input type="hidden" name="tab" value="leads"><label>Show <select name="contact_filter"><option value="all">All businesses</option><option value="email" <?php selected($filter, 'email'); ?>>Has email</option><option value="no_email" <?php selected($filter, 'no_email'); ?>>No email / calling list</option></select></label><button class="wnq-btn wnq-btn-secondary">Apply filters</button>
        <?php foreach (['search'=>'Business name','zip'=>'Listing ZIP','city'=>'City','industry'=>'Niche/category','max_reviews'=>'Maximum reviews','seo_issues_min'=>'Minimum SEO issues'] as $key=>$label): ?><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($args[$key] ?? ''); ?>" <?php if (in_array($key,['max_reviews','seo_issues_min'],true)) { echo 'type="number" min="0"'; } ?>></label><?php endforeach; ?>
        <?php foreach (['status'=>[''=>'Any status','new'=>'New','qualified'=>'Qualified','contacted'=>'Contacted','closed'=>'Closed'],'company_fit'=>[''=>'Any company type','unknown'=>'Needs review','independent'=>'Small independent','chain'=>'Franchise / chain','large'=>'Large company']] as $key=>$options): ?><label><?php echo esc_html($key==='status'?'Status':'Company type'); ?><select name="<?php echo esc_attr($key); ?>"><?php foreach ($options as $value=>$label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($args[$key] ?? '',$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
        <label><input type="checkbox" name="has_phone" value="1" <?php checked(!empty($args['has_phone'])); ?>>Has phone</label><label><input type="checkbox" name="no_website" value="1" <?php checked(!empty($args['no_website'])); ?>>No website</label>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab=leads')); ?>">Reset filters</a>
        <a class="wnq-btn wnq-btn-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wnq_lead_export_csv' . ($filter === 'email' ? '&has_email=1' : '')), 'wnq_lead_export_csv')); ?>">Export <?php echo $filter === 'email' ? 'all leads with email' : 'all leads'; ?> (ignores other filters)</a></form>
        <?php LeadGhlAdmin::listForm(); ?>
        <div class="wnq-tbl-wrap"><table class="wnq-tbl lf-leads"><thead><tr><th>Business</th><th>Phone</th><th>Email &amp; source</th><th>Outreach</th></tr></thead><tbody>
        <?php foreach ($rows as $row):
            $location = \WNQ\Services\LeadBrowserIntake::addressParts((string)($row['address'] ?? ''));
            foreach ($location as $field => $value) { if (empty($row[$field])) { $row[$field] = $value; } }
        ?>
        <tr><td><label><input type="checkbox" form="lf-ghl-list" name="lead_ids[]" value="<?php echo (int)$row['id']; ?>"> <strong><?php echo esc_html($row['business_name']); ?></strong></label><div><?php echo esc_html($row['industry']); ?></div><small>Status: <?php echo esc_html(ucfirst($row['status'])); ?></small><br>
        <?php if ($row['website']): ?><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($row['website']); ?>">Website ↗</a><?php endif; ?>
        <div class="lf-contact-address"><strong>Address:</strong> <?php echo esc_html(($row['address'] ?? '') ?: 'Not publicly listed / not collected'); ?><br>
        <strong>City:</strong> <?php echo esc_html(($row['city'] ?? '') ?: 'Not collected'); ?> · <strong>State:</strong> <?php echo esc_html(($row['state'] ?? '') ?: '—'); ?> · <strong>ZIP:</strong> <?php echo esc_html(($row['zip'] ?? '') ?: '—'); ?></div>
        <details><summary>Listing details</summary><p><?php echo esc_html($row['address']); ?></p><p>Contact name: <?php echo esc_html(trim(($row['owner_first'] ?? '') . ' ' . ($row['owner_last'] ?? '')) ?: 'Not found'); ?></p><p><?php echo esc_html($row['rating'] . ' stars · ' . $row['review_count'] . ' reviews'); ?></p><p class="lf-source-notes"><?php echo esc_html($row['notes'] ?? ''); ?></p></details></td>
        <td><?php if ($row['phone']): ?><a href="<?php echo esc_url('tel:' . preg_replace('/[^+0-9]/', '', $row['phone'])); ?>"><?php echo esc_html($row['phone']); ?></a><?php else: ?>Not found<?php endif; ?></td>
        <td><?php if ($row['email']): ?><a href="<?php echo esc_url('mailto:' . $row['email']); ?>"><?php echo esc_html($row['email']); ?></a><div><small>Found, not mailbox-verified</small></div><?php if (filter_var($row['email_source'] ?? '', FILTER_VALIDATE_URL)): ?><a href="<?php echo esc_url($row['email_source']); ?>" target="_blank" rel="noopener noreferrer">Email source ↗</a><?php endif; ?><?php else: ?><span>No email found</span><div><small>Keep for cold calling</small></div><?php endif; ?></td>
        <td class="wnq-ghl-cell"><p><?php echo esc_html(\WNQ\Services\LeadGhlSync::qualification($row) ?: 'Prospect rules passed'); ?></p><p>SEO issues: <?php echo !empty($row['seo_checked']) ? (int)$row['seo_score'] . '/7' : 'Not assessed'; ?></p>
        <details><summary>Review company type</summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wnq_lead_qualify"><input type="hidden" name="operation" value="review"><input type="hidden" name="lead_id" value="<?php echo (int)$row['id']; ?>"><?php wp_nonce_field('wnq_lead_qualify'); ?><label>Classification<select name="company_fit"><?php foreach (['unknown'=>'Unknown','independent'=>'Small independent','chain'=>'Franchise / chain','large'=>'Large company'] as $v=>$label): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($row['company_fit'] ?? 'unknown',$v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>Evidence / source URL<textarea name="reason" required maxlength="1000"></textarea></label><button class="wnq-btn wnq-btn-secondary">Save review (does not send)</button></form></details>
        <?php LeadGhlAdmin::row($row); ?></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="4">No saved leads match this filter. Start with Find Leads.</td></tr><?php endif; ?>
        </tbody></table></div><p><?php echo esc_html($count); ?> businesses · Page <?php echo (int)$page; ?></p>
        <?php foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $target): if ($target < 1 || ($target - 1) * 50 >= $count) { continue; } ?><a class="wnq-btn wnq-btn-secondary" href="<?php echo esc_url(add_query_arg(array_merge($args, ['page' => 'wnq-lead-finder', 'tab' => 'leads', 'paged' => $target, 'contact_filter' => $filter]), admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a> <?php endforeach; ?></section>
        <?php if (current_user_can('manage_options')): ?><section class="wnq-card"><details><summary>Danger zone — delete all saved leads</summary><p>This permanently removes every lead, not just filtered results. Export a backup first and pause collection in every Chrome tab. New imports can create new leads afterward. Search history and GHL suppression/handoff records remain; existing GHL contacts or running workflows are not deleted or stopped.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Permanently delete ALL saved leads? This cannot be undone without a backup.')"><input type="hidden" name="action" value="wnq_lead_qualify"><input type="hidden" name="operation" value="delete_all"><?php wp_nonce_field('wnq_lead_qualify'); ?><label>Type DELETE ALL <input name="confirmation" required pattern="DELETE ALL" autocomplete="off"></label> <button class="wnq-btn wnq-btn-secondary">Permanently delete all leads</button></form></details></section><?php endif; ?>
        <?php
    }
}
