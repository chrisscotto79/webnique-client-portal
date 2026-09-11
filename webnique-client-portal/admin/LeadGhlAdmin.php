<?php
namespace WNQ\Admin;

use WNQ\Services\LeadGhlSync;

if (!defined('ABSPATH')) { exit; }

final class LeadGhlAdmin
{
    public static function register(): void
    {
        add_action('admin_post_wnq_lead_ghl', [self::class, 'handle']);
    }

    private static function allowed(): bool
    {
        return current_user_can('manage_options') || current_user_can('wnq_manage_portal');
    }

    public static function handle(): void
    {
        if (!self::allowed()) { wp_die('Access denied', '', ['response' => 403]); }
        check_admin_referer('wnq_lead_ghl');
        $action = sanitize_key($_POST['operation'] ?? '');
        try {
            if ($action === 'settings') {
                if (!current_user_can('manage_options')) { wp_die('Only administrators can change integration settings.'); }
                LeadGhlSync::saveSettings(trim(wp_unslash($_POST['token'] ?? '')), !empty($_POST['automatic']), !empty($_POST['clear_token']));
                $message = 'Settings saved. Automatic sync applies only to newly saved eligible leads; no existing leads were enrolled.';
            } elseif ($action === 'test') {
                LeadGhlSync::test();
                $message = 'Location access and campaign tag verified. No contacts changed; no emails triggered.';
            } elseif ($action === 'approve') {
                LeadGhlSync::test();
                $ok = LeadGhlSync::enqueue(absint($_POST['lead_id'] ?? 0));
                $message = $ok ? 'Approved and queued. The background worker will apply the campaign tag.' : 'Not queued: check token, email, lead status, suppression, or existing handoff.';
            } elseif ($action === 'approve_bulk') {
                $result = self::approveList($_POST['lead_ids'] ?? []);
                $message = $result['queued'] . ' leads approved and queued; ' . $result['skipped'] . ' skipped (ineligible, suppressed, or already queued/sent). Background processing applies the campaign tag; queued does not mean delivered.';
            } elseif ($action === 'suppress') {
                LeadGhlSync::suppress(absint($_POST['lead_id'] ?? 0));
                $message = 'Email suppressed for future plugin handoffs. This does not cancel a workflow already running in GHL.';
            } else { throw new \RuntimeException('Unknown action.'); }
        } catch (\RuntimeException $e) { $message = $e->getMessage(); }
        set_transient('wnq_ghl_notice_' . get_current_user_id(), $message, 120);
        wp_safe_redirect(admin_url('admin.php?page=wnq-lead-finder&tab=ghl'));
        exit;
    }

    private static function fields(string $operation, int $id = 0): void
    {
        echo '<input type="hidden" name="action" value="wnq_lead_ghl"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="lead_id" value="' . (int)$id . '">';
        wp_nonce_field('wnq_lead_ghl');
    }

    public static function approveList($ids): array
    {
        if (!self::allowed()) { throw new \RuntimeException('Access denied'); }
        if (!is_array($ids) || !$ids || count($ids) > 50) { throw new \RuntimeException('Select between 1 and 50 leads from the current page.'); }
        foreach ($ids as $id) {
            if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)) { throw new \RuntimeException('Invalid lead selection.'); }
        }
        $ids = array_unique(array_map('intval', $ids));
        LeadGhlSync::test(); // Read-only preflight before any jobs are queued.
        $queued = 0;
        foreach ($ids as $id) { if (LeadGhlSync::enqueue($id)) { $queued++; } }
        return ['queued' => $queued, 'skipped' => count($ids) - $queued];
    }

    public static function listForm(): void
    {
        echo '<form id="lf-ghl-list" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Approve selected leads for Land Clearing Cold Email? Applying this tag can start live emails.\')">';
        self::fields('approve_bulk');
        echo '<p>Select reviewed leads below, then <button class="wnq-btn wnq-btn-primary" type="submit">Approve &amp; queue selected for GHL</button></p><p>Uses the saved private token and Land Clearing Cold Email tag. Only selected eligible leads are queued; existing suppression and duplicate checks still apply.</p></form>';
    }

    public static function row(array $lead): void
    {
        $state = LeadGhlSync::state($lead);
        echo '<div><strong>' . esc_html(ucfirst($state['status'] ?? 'Not sent')) . '</strong></div>';
        if (!empty($state['message'])) { echo '<small>' . esc_html($state['message']) . '</small>'; }
        if (LeadGhlSync::eligible($lead) && (!$state || in_array($state['status'], ['failed', 'held', 'review'], true))) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Apply the campaign tag? This can immediately start live emails in GoHighLevel.\')">';
            self::fields('approve', (int)$lead['id']);
            echo '<button class="wnq-btn wnq-btn-primary wnq-btn-sm" type="submit">' . ($state ? 'Approve retry / reconcile' : 'Approve &amp; Send') . '</button></form>';
        }
        if (!empty($lead['email']) && ($state['status'] ?? '') !== 'suppressed') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Block future plugin handoffs for this email? Existing GHL workflows must be stopped in GHL.\')">';
            self::fields('suppress', (int)$lead['id']);
            echo '<button class="wnq-btn wnq-btn-secondary wnq-btn-sm" type="submit">Suppress</button></form>';
        }
    }

    public static function render(): void
    {
        if (!self::allowed()) { return; }
        global $wpdb;
        $settings = LeadGhlSync::settings();
        $notice = get_transient('wnq_ghl_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('wnq_ghl_notice_' . get_current_user_id());
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }
        ?>
        <style>
        .wnq-ghl-hero{background:linear-gradient(115deg,#091c29,#124f96);color:white;border-radius:12px;padding:24px;margin-bottom:16px}
        .wnq-ghl-hero h2{color:white;margin-top:0}.wnq-ghl-toggle{display:flex;align-items:center;gap:10px;font-weight:600;margin:16px 0}
        .wnq-ghl-toggle input{appearance:none;position:relative;width:44px;height:24px;border-radius:20px;background:#64748b;border:0;flex-shrink:0;cursor:pointer}
        .wnq-ghl-toggle input:before{content:'';position:absolute;width:18px;height:18px;top:3px;left:3px;background:white;border-radius:50%;margin:0;transition:transform .15s}
        .wnq-ghl-toggle input:checked{background:#2563eb}.wnq-ghl-toggle input:checked:before{transform:translateX(20px)}
        .wnq-ghl-toggle input:focus-visible{outline:3px solid #f5ce48;outline-offset:3px}.wnq-ghl-copy{max-width:850px;line-height:1.65}
        .wnq-ghl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
        .wnq-ghl-grid div{padding:14px;background:#f5f7fb;border-radius:8px;overflow-wrap:anywhere}
        .wnq-lf-tabs{flex-wrap:wrap}.wnq-ghl-cell{min-width:180px;max-width:270px}.wnq-ghl-cell form{margin-top:6px}
        </style>
        <section class="wnq-ghl-hero"><h2>GoHighLevel lead handoff</h2><p>Review first. Automate when you’re ready.</p><strong><?php echo empty($settings['automatic']) ? 'Manual approval mode — automatic sync OFF' : 'Automatic sync ON — newly saved eligible leads only'; ?></strong></section>
        <div class="wnq-card wnq-ghl-copy">
            <div class="wnq-ghl-grid"><div><small>Destination location</small><br><strong><?php echo esc_html(LeadGhlSync::LOCATION); ?></strong></div><div><small>Workflow trigger tag</small><br><strong><?php echo esc_html(LeadGhlSync::TAG); ?></strong></div><div><small>Private token</small><br><strong><?php echo LeadGhlSync::configured() ? 'Saved securely' : 'Not configured'; ?></strong></div></div>
            <p><strong>Testing uses real contacts and real emails.</strong> Keep automatic sync off and use <strong>Approve &amp; Send</strong> in All Leads for an address you control. Applying the tag can start your existing workflow immediately. The connection test below only checks location/tag access.</p>
            <p>Qualification defaults: fewer than 50 confirmed reviews and staff-reviewed small independent business. Unknown company types, franchises/chains, large companies and unconfirmed review counts are held. An optional minimum SEO-issue threshold can be set in Lead List. Reviewing a company does not automatically send it: use Approve &amp; Send afterward.</p>
            <p>Email-ready also requires a valid email format and a New or Qualified lead. It does not prove mailbox deliverability, consent, or business ownership. Review sourced emails before enabling automation. Contacted, Closed, locally suppressed and GHL email-DND/unsubscribed contacts are excluded.</p>
            <?php if (current_user_can('manage_options')): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php self::fields('settings'); ?>
                <div class="wnq-field"><label for="wnq-ghl-token">Private integration token</label><input id="wnq-ghl-token" type="password" name="token" value="" autocomplete="new-password" placeholder="Leave blank to keep saved token"><small>Encrypted server-side. Never included in page HTML, exports or API error messages.</small></div>
                <label class="wnq-ghl-toggle"><input type="checkbox" role="switch" name="automatic" value="1" <?php checked(!empty($settings['automatic'])); ?>>Automatic GHL Sync</label><small>Save settings to apply a switch change.</small>
                <p>OFF requires approval. ON sends newly saved eligible leads. Turning OFF holds pending automatic jobs for manual approval; it cannot undo an in-flight request or an already-triggered workflow.</p>
                <label><input type="checkbox" name="clear_token" value="1">Clear saved token and turn automatic sync off</label>
                <p><button type="submit" class="wnq-btn wnq-btn-primary">Save settings</button></p>
            </form>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php self::fields('test'); ?><button class="wnq-btn wnq-btn-secondary">Test connection &amp; tag (read-only)</button></form>
        </div>
        <div class="wnq-card"><h3>Recent handoffs</h3><p>Sent means the campaign tag is present, not that email was delivered. Times below are UTC. Background processing depends on WordPress cron; configure a server cron for reliable processing on a quiet site.</p>
        <div class="wnq-tbl-wrap"><table class="wnq-tbl"><thead><tr><th>Lead</th><th>Status</th><th>Mode / approver</th><th>Attempts</th><th>Updated (UTC)</th><th>Result</th></tr></thead><tbody>
        <?php
        $rows = $wpdb->get_results('SELECT q.*, l.business_name FROM ' . LeadGhlSync::table() . " q LEFT JOIN {$wpdb->prefix}wnq_leads l ON q.lead_id=l.id ORDER BY q.updated_at DESC, q.id DESC LIMIT 100", ARRAY_A) ?: [];
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['business_name'] ?: 'Deleted lead #' . $row['lead_id']) . '</td><td>' . esc_html($row['status']) . '</td><td>' . esc_html($row['mode'] . ' / ' . $row['approved_by']) . '</td><td>' . (int)$row['attempts'] . '</td><td>' . esc_html($row['updated_at']) . '</td><td>' . esc_html($row['message']) . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="6">No handoffs yet. Review a lead in All Leads to begin.</td></tr>'; }
        ?>
        </tbody></table></div><p><a class="wnq-btn wnq-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab=leads')); ?>">Review All Leads</a> <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-lead-finder&tab=ghl')); ?>">Refresh status</a></p></div>
        <?php
    }
}
