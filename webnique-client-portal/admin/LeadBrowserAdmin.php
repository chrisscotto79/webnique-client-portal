<?php
namespace WNQ\Admin;

use WNQ\Services\LeadBrowserIntake;
use WNQ\Models\Lead;

if (!defined('ABSPATH')) { exit; }

final class LeadBrowserAdmin
{
    public static function register(): void { add_action('wp_ajax_wnq_browser_lead_save', [self::class, 'save']); }

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
        <link rel="stylesheet" href="<?php echo esc_url($base . 'css/lead-browser.css?v=' . WNQ_PORTAL_VERSION); ?>">
        <section class="lf-hero"><span>GOOGLE MAPS → YOUR LEAD LIST</span><h2>Find the right businesses. Build your list.</h2><p>Search a niche and ZIP. Chrome reads listings; WordPress checks their websites for public emails.</p></section>
        <div id="lf-browser-app" data-nonce="<?php echo esc_attr(wp_create_nonce('wnq_browser_leads')); ?>">
            <section class="wnq-card lf-search"><form id="lf-search-form">
                <div class="wnq-field"><label for="lf-niche">Niche keyword</label><input id="lf-niche" name="keyword" maxlength="100" placeholder="Plumbers" required></div>
                <div class="wnq-field"><label for="lf-postcode">ZIP code</label><input id="lf-postcode" name="zip" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" placeholder="32825" required></div>
                <button class="wnq-btn wnq-btn-primary" id="lf-start">Find leads</button>
            </form><p>Searches the area around your ZIP; Google may include nearby businesses. No review-count, website or franchise exclusions.</p>
            <p id="lf-extension-status" role="status">Checking Chrome companion…</p>
            <button id="lf-reconnect" class="wnq-btn wnq-btn-secondary" type="button">Reconnect companion</button>
            <details id="lf-setup"><summary>One-time Chrome setup</summary><ol>
                <li>In your local plugin folder, find <strong>browser-companion</strong>.</li>
                <li>Open <strong>chrome://extensions</strong>, enable Developer mode, select <strong>Load unpacked</strong>, and choose that folder.</li>
                <li>Refresh this WordPress page. Keep this page and Chrome open during collection.</li>
            </ol><p>The companion is restricted to this agency’s WordPress admin and Google Maps. It has no GHL token and does not send emails. No Node server or paid Maps API is required.</p></details></section>
            <section class="wnq-card"><div class="lf-progress-head"><h3>Current search</h3><div><button id="lf-resume" class="wnq-btn wnq-btn-secondary" type="button">Resume / retry</button> <button id="lf-pause" class="wnq-btn wnq-btn-secondary" type="button" disabled>Pause</button></div></div>
                <p id="lf-progress" role="status" aria-live="polite">Enter a keyword and ZIP to begin.</p>
                <div class="lf-counts"><div><strong id="lf-count-found">0</strong><span>Listings collected</span></div><div><strong id="lf-count-saved">0</strong><span>New leads saved</span></div><div><strong id="lf-count-email">0</strong><span>New leads with email</span></div><div><strong id="lf-count-duplicate">0</strong><span>Already in your list</span></div></div>
                <p class="lf-note">Up to 100 listings per search. Missing emails and names stay blank. Email found does not mean mailbox-verified. Pause stops after the current request; close neither tab while a request is saving.</p>
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
        $count = Lead::count($args);
        $rows = Lead::getAll(array_merge($args, ['limit' => 50, 'offset' => ($page - 1) * 50]));
        ?>
        <link rel="stylesheet" href="<?php echo esc_url(plugins_url('../assets/css/lead-browser.css', __FILE__) . '?v=' . WNQ_PORTAL_VERSION); ?>">
        <section class="wnq-card"><h2>Your combined lead list</h2><p>All saved searches in one place. Review email sources before outreach; businesses without email can still be called. Existing GHL approval and automatic-sync settings are unchanged.</p>
        <form method="get" class="lf-list-tools"><input type="hidden" name="page" value="wnq-lead-finder"><input type="hidden" name="tab" value="leads"><label>Show <select name="contact_filter"><option value="all">All businesses</option><option value="email" <?php selected($filter, 'email'); ?>>Has email</option></select></label><button class="wnq-btn wnq-btn-secondary">Filter</button>
        <a class="wnq-btn wnq-btn-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wnq_lead_export_csv' . ($filter === 'email' ? '&has_email=1' : '')), 'wnq_lead_export_csv')); ?>">Export list for calling / CRM</a></form>
        <div class="wnq-tbl-wrap"><table class="wnq-tbl lf-leads"><thead><tr><th>Business</th><th>Phone</th><th>Email &amp; source</th><th>Outreach</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
        <tr><td><strong><?php echo esc_html($row['business_name']); ?></strong><div><?php echo esc_html($row['industry']); ?></div><small>Status: <?php echo esc_html(ucfirst($row['status'])); ?></small><br>
        <?php if ($row['website']): ?><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($row['website']); ?>">Website ↗</a><?php endif; ?>
        <details><summary>Listing details</summary><p><?php echo esc_html($row['address']); ?></p><p>Contact name: <?php echo esc_html(trim(($row['owner_first'] ?? '') . ' ' . ($row['owner_last'] ?? '')) ?: 'Not found'); ?></p><p><?php echo esc_html($row['rating'] . ' stars · ' . $row['review_count'] . ' reviews'); ?></p><p class="lf-source-notes"><?php echo esc_html($row['notes'] ?? ''); ?></p></details></td>
        <td><?php if ($row['phone']): ?><a href="<?php echo esc_url('tel:' . preg_replace('/[^+0-9]/', '', $row['phone'])); ?>"><?php echo esc_html($row['phone']); ?></a><?php else: ?>Not found<?php endif; ?></td>
        <td><?php if ($row['email']): ?><a href="<?php echo esc_url('mailto:' . $row['email']); ?>"><?php echo esc_html($row['email']); ?></a><div><small>Found, not mailbox-verified</small></div><?php if (filter_var($row['email_source'] ?? '', FILTER_VALIDATE_URL)): ?><a href="<?php echo esc_url($row['email_source']); ?>" target="_blank" rel="noopener noreferrer">Email source ↗</a><?php endif; ?><?php else: ?><span>No email found</span><div><small>Keep for cold calling</small></div><?php endif; ?></td>
        <td class="wnq-ghl-cell"><?php LeadGhlAdmin::row($row); ?></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="4">No saved leads match this filter. Start with Find Leads.</td></tr><?php endif; ?>
        </tbody></table></div><p><?php echo esc_html($count); ?> businesses · Page <?php echo (int)$page; ?></p>
        <?php foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $target): if ($target < 1 || ($target - 1) * 50 >= $count) { continue; } ?><a class="wnq-btn wnq-btn-secondary" href="<?php echo esc_url(add_query_arg(['page' => 'wnq-lead-finder', 'tab' => 'leads', 'paged' => $target, 'contact_filter' => $filter], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a> <?php endforeach; ?></section>
        <?php
    }
}
