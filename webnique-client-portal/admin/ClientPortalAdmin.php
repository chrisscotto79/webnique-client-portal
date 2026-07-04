<?php
/**
 * Internal client portal overview.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Admin;

use WNQ\Models\Client;
use WNQ\Models\ClientPortal;
use WNQ\Core\Permissions;

if (!defined('ABSPATH')) {
    exit;
}

final class ClientPortalAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPage'], 25);
        add_action('admin_post_wnq_portal_admin_message', [self::class, 'handleMessage']);
        add_action('admin_post_wnq_portal_request_status', [self::class, 'handleRequestStatus']);
        add_action('admin_post_wnq_portal_ads_threshold', [self::class, 'handleAdsThreshold']);
        add_action('admin_post_wnq_portal_export_report', [self::class, 'handleReportExport']);
        add_action('admin_post_wnq_portal_download_attachment', [self::class, 'handleAttachmentDownload']);
        add_action('admin_notices', [self::class, 'messageNotice']);
    }

    public static function addPage(): void
    {
        $pending = ClientPortal::getUnreadMessageCount() + ClientPortal::getOpenRequestCount();
        $label = 'Client Portal Dashboard' . ($pending > 0 ? ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . $pending . '</span></span>' : '');
        add_submenu_page(
            'wnq-portal',
            'Client Portal Dashboard',
            $label,
            self::capability(),
            'wnq-client-portal-dashboard',
            [self::class, 'render']
        );
    }

    public static function messageNotice(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            return;
        }
        $messages = ClientPortal::getUnreadMessageCount();
        $requests = ClientPortal::getOpenRequestCount();
        if (($messages + $requests) < 1 || sanitize_key((string)($_GET['page'] ?? '')) === 'wnq-client-portal-dashboard') {
            return;
        }
        $parts = [];
        if ($messages > 0) $parts[] = $messages . ' unread message' . ($messages === 1 ? '' : 's');
        if ($requests > 0) $parts[] = $requests . ' open request' . ($requests === 1 ? '' : 's');
        echo '<div class="notice notice-info"><p><strong>' . esc_html(implode(' and ', $parts)) . ' in the client portal.</strong> <a href="' . esc_url(admin_url('admin.php?page=wnq-client-portal-dashboard')) . '">Review client activity</a></p></div>';
    }

    public static function render(): void
    {
        self::checkCapability();
        ClientPortal::ensureSchema();
        $selected_id = sanitize_text_field(wp_unslash($_GET['client_id'] ?? ''));
        $clients = Client::getAll();
        $selected = $selected_id !== '' ? Client::getByClientId($selected_id) : null;
        $unread_messages = ClientPortal::getUnreadClientMessages();
        $month_jobs = 0;
        $month_profit = 0.0;
        $month_ads_spend = 0.0;
        $ads_snapshots = [];
        foreach ($clients as $client) {
            $client_id = (string)$client['client_id'];
            $performance = ClientPortal::getMonthlyPerformance((string)$client['client_id']);
            $current = $performance ? end($performance) : [];
            $month_jobs += (int)($current['jobs'] ?? 0);
            $month_profit += (float)($current['profit'] ?? 0);
            $ads_snapshots[$client_id] = ClientPortal::getAdsSpendSnapshot($client_id, false);
            $month_ads_spend += (float)($ads_snapshots[$client_id]['spend'] ?? 0);
        }
        ?>
        <div class="wrap wnq-cp-admin">
            <h1>Client Portal Dashboard</h1>
            <p class="description">Client activity, CRM performance, reports, billing health, and unread messages in one place.</p>
            <?php if (sanitize_key((string)($_GET['ads_threshold'] ?? '')) === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Client Ads spend threshold saved.</p></div>
            <?php endif; ?>
            <div class="wnq-cp-stats">
                <div><strong><?php echo count($clients); ?></strong><span>Clients</span></div>
                <div><strong><?php echo count(array_filter($clients, static fn($c) => ($c['status'] ?? '') === 'active')); ?></strong><span>Active Accounts</span></div>
                <div><strong><?php echo esc_html(number_format(array_sum(array_map(static fn($c) => (float)($c['monthly_rate'] ?? 0), $clients)), 0)); ?></strong><span>Monthly Managed Revenue</span></div>
                <div><strong><?php echo (int)$month_jobs; ?></strong><span>Client Jobs This Month</span></div>
                <div><strong class="<?php echo $month_profit >= 0 ? 'is-positive' : 'is-negative'; ?>">$<?php echo esc_html(number_format($month_profit, 0)); ?></strong><span>Client Profit This Month</span></div>
                <div><strong>$<?php echo esc_html(number_format($month_ads_spend, 2)); ?></strong><span>Google Ads Spend This Month</span></div>
                <div><strong><?php echo (int)ClientPortal::getUnreadMessageCount(); ?></strong><span>Unread Client Messages</span></div>
                <div><strong><?php echo (int)ClientPortal::getOpenRequestCount(); ?></strong><span>Open Client Requests</span></div>
            </div>
            <?php if ($unread_messages): ?>
                <div class="wnq-cp-panel wnq-cp-inbox">
                    <h2>Unread Client Messages</h2>
                    <?php foreach ($unread_messages as $message): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode((string)$message['client_id']))); ?>">
                            <strong><?php echo esc_html($message['client_name']); ?></strong>
                            <span><?php echo esc_html($message['subject'] ?: 'New message'); ?></span>
                            <small><?php echo esc_html(wp_trim_words((string)$message['message'], 18)); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="wnq-cp-panel">
                <h2>All Clients</h2>
                <div class="wnq-cp-table-scroll">
                <table class="widefat striped">
                    <thead><tr><th>Client</th><th>Account</th><th>Billing</th><th>Customers</th><th>Jobs This Month</th><th>Revenue This Month</th><th>Profit This Month</th><th>Ads This Month</th><th>Ads Spend Alert</th><th>Messages</th><th>Requests</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($clients as $client):
                        $overview = ClientPortal::overview((string)$client['client_id']);
                        $health = $overview['health'];
                        $crm = $overview['customers'];
                        $performance = $overview['performance'];
                        $current = $performance ? end($performance) : [];
                        $ads = $ads_snapshots[(string)$client['client_id']] ?? [];
                        $ads_linked = !empty($ads['has_linked_account']);
                        $ads_ready = !empty($ads['configured']);
                        $ads_threshold = (float)($ads['threshold'] ?? 0);
                        $ads_over_threshold = $ads_ready && $ads_threshold > 0 && (float)($ads['spend'] ?? 0) >= $ads_threshold;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($client['company'] ?: $client['name']); ?></strong><br><small><?php echo esc_html($client['client_id']); ?></small></td>
                            <td><?php self::status($health['overall'], ucfirst($health['overall'])); ?></td>
                            <td><?php self::status($health['billing']['tone'], $health['billing']['label']); ?></td>
                            <td><?php echo (int)$crm['total']; ?></td>
                            <td><?php echo (int)($current['jobs'] ?? 0); ?></td>
                            <td>$<?php echo esc_html(number_format((float)($current['revenue'] ?? 0), 2)); ?></td>
                            <td class="<?php echo (float)($current['profit'] ?? 0) >= 0 ? 'is-positive' : 'is-negative'; ?>">$<?php echo esc_html(number_format((float)($current['profit'] ?? 0), 2)); ?></td>
                            <td class="<?php echo $ads_over_threshold ? 'is-negative' : ''; ?>"><?php echo $ads_ready ? '$' . esc_html(number_format((float)($ads['spend'] ?? 0), 2)) : ($ads_linked ? '<span class="is-negative">Connection issue</span>' : '<span class="text-muted">Not linked</span>'); ?></td>
                            <td>
                                <?php if ($ads_linked): ?>
                                    <form class="wnq-cp-threshold-form <?php echo $ads_over_threshold ? 'is-reached' : ''; ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('wnq_portal_ads_threshold_' . (string)$client['client_id']); ?>
                                        <input type="hidden" name="action" value="wnq_portal_ads_threshold">
                                        <input type="hidden" name="client_id" value="<?php echo esc_attr((string)$client['client_id']); ?>">
                                        <label><span class="screen-reader-text">Monthly Ads spend alert for <?php echo esc_html($client['company'] ?: $client['name']); ?></span><b>$</b><input type="number" name="spend_alert_threshold" value="<?php echo esc_attr(number_format($ads_threshold, 2, '.', '')); ?>" min="0" step="0.01"></label>
                                        <button type="submit" class="button button-small">Save</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">Unavailable</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)ClientPortal::getUnreadMessageCount((string)$client['client_id']); ?></td>
                            <td><?php echo (int)ClientPortal::getOpenRequestCount((string)$client['client_id']); ?></td>
                            <td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode((string)$client['client_id']))); ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$clients): ?><tr><td colspan="12">No clients found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php if ($selected): self::renderClient($selected); endif; ?>
        </div>
        <?php self::styles(); ?>
        <?php
    }

    private static function renderClient(array $client): void
    {
        $client_id = (string)$client['client_id'];
        $customers = ClientPortal::getCustomers($client_id, 30);
        $tickets = ClientPortal::getTickets($client_id, 30);
        $learning_requests = ClientPortal::getLearningRequests($client_id);
        $service_requests = ClientPortal::getRequests($client_id);
        $tasks = ClientPortal::getTasks($client_id, 30);
        $reports = ClientPortal::getReports($client_id, 20);
        ?>
        <div class="wnq-cp-detail">
            <div class="wnq-cp-panel">
                <h2><?php echo esc_html($client['company'] ?: $client['name']); ?></h2>
                <p><?php echo esc_html(implode(' · ', array_filter([$client['phone'] ?? '', $client['email'] ?? '', $client['website'] ?? '']))); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('wnq_portal_admin_message'); ?>
                    <input type="hidden" name="action" value="wnq_portal_admin_message">
                    <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                    <input type="text" name="subject" placeholder="Subject" class="regular-text" required>
                    <select name="category">
                        <option value="general">General Support</option>
                        <option value="website">Website Update</option>
                        <option value="seo">SEO / Report</option>
                        <option value="billing">Billing</option>
                        <option value="training">Training</option>
                    </select>
                    <select name="priority">
                        <option value="normal">Normal Priority</option>
                        <option value="high">High Priority</option>
                        <option value="urgent">Urgent</option>
                        <option value="low">Low Priority</option>
                    </select>
                    <textarea name="message" rows="3" placeholder="Start a new support ticket" required></textarea>
                    <input type="file" name="attachments[]" multiple>
                    <button class="button button-primary">Create Ticket</button>
                </form>
            </div>
            <?php self::performanceChart(ClientPortal::getMonthlyPerformance($client_id)); ?>
            <?php self::table('CRM & Job History', $customers, ['name' => 'Customer', 'service' => 'Service', 'lead_source' => 'Source', 'status' => 'Status', 'job_date' => 'Job Date', 'job_count' => 'Jobs', 'final_value' => 'Revenue', 'job_cost' => 'Cost']); ?>
            <?php self::table('Marketing Work History', $tasks, ['title' => 'Item', 'status' => 'Status', 'priority' => 'Priority', 'due_date' => 'Due']); ?>
            <?php self::reportsTable($reports); ?>
            <?php self::tickets($client_id, $tickets); ?>
            <?php self::requests($client_id, $service_requests); ?>
            <?php self::table('Learning Requests', $learning_requests, ['request_type' => 'Type', 'title' => 'Request', 'details' => 'Details', 'status' => 'Status', 'created_at' => 'Submitted']); ?>
        </div>
        <?php
    }

    private static function tickets(string $client_id, array $tickets): void
    {
        echo '<div class="wnq-cp-panel"><h2>Support Tickets</h2><div class="wnq-cp-tickets">';
        foreach ($tickets as $ticket) {
            echo '<details class="wnq-cp-ticket"><summary><span><strong>' . esc_html((string)$ticket['subject']) . '</strong><small>' . esc_html(strtoupper((string)$ticket['ticket_key']) . ' · ' . (string)$ticket['category'] . ' · ' . (string)$ticket['priority']) . '</small></span>';
            self::status(in_array($ticket['ticket_status'], ['resolved', 'closed'], true) ? 'green' : 'yellow', str_replace('_', ' ', (string)$ticket['ticket_status']));
            echo '</summary><div class="wnq-cp-thread">';
            foreach ($ticket['messages'] as $message) {
                echo '<article class="' . (($message['sender_role'] ?? '') === 'admin' ? 'is-admin' : 'is-client') . '"><header><strong>' . (($message['sender_role'] ?? '') === 'admin' ? 'Golden Web Marketing' : 'Client') . '</strong><time>' . esc_html((string)$message['created_at']) . '</time></header><p>' . nl2br(esc_html((string)$message['message'])) . '</p>';
                if (!empty($message['attachments'])) {
                    echo '<div class="wnq-cp-attachments">';
                    foreach ($message['attachments'] as $attachment) echo '<a target="_blank" rel="noopener" href="' . esc_url((string)$attachment['url']) . '">' . esc_html((string)$attachment['name']) . '</a>';
                    echo '</div>';
                }
                echo '</article>';
            }
            echo '</div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wnq-cp-ticket-reply" enctype="multipart/form-data">';
            wp_nonce_field('wnq_portal_admin_message');
            echo '<input type="hidden" name="action" value="wnq_portal_admin_message"><input type="hidden" name="client_id" value="' . esc_attr($client_id) . '"><input type="hidden" name="ticket_key" value="' . esc_attr((string)$ticket['ticket_key']) . '"><input type="hidden" name="subject" value="' . esc_attr((string)$ticket['subject']) . '"><input type="hidden" name="category" value="' . esc_attr((string)$ticket['category']) . '"><input type="hidden" name="priority" value="' . esc_attr((string)$ticket['priority']) . '">';
            echo '<textarea name="message" rows="3" placeholder="Reply to this ticket" required></textarea><select name="ticket_status">';
            foreach (['open' => 'Open', 'in_progress' => 'In Progress', 'waiting' => 'Waiting on Client', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected($ticket['ticket_status'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><input type="file" name="attachments[]" multiple><button class="button button-primary">Send Reply</button></form></details>';
        }
        if (!$tickets) echo '<p>No support tickets yet.</p>';
        echo '</div></div>';
    }

    private static function requests(string $client_id, array $requests): void
    {
        echo '<div class="wnq-cp-panel"><h2>Client Requests</h2><div class="wnq-cp-requests">';
        foreach ($requests as $request) {
            echo '<article><div><strong>' . esc_html((string)$request['title']) . '</strong><small>' . esc_html(strtoupper((string)$request['request_key']) . ' · ' . str_replace('_', ' ', (string)$request['request_type']) . ' · ' . (string)$request['priority']) . '</small><p>' . nl2br(esc_html((string)$request['details'])) . '</p>';
            if (!empty($request['request_data'])) {
                echo '<dl>';
                foreach ($request['request_data'] as $label => $value) echo '<dt>' . esc_html(str_replace('_', ' ', (string)$label)) . '</dt><dd>' . esc_html((string)$value) . '</dd>';
                echo '</dl>';
            }
            if (!empty($request['attachments'])) {
                echo '<div class="wnq-cp-attachments">';
                foreach ($request['attachments'] as $attachment) echo '<a target="_blank" rel="noopener" href="' . esc_url((string)$attachment['url']) . '">' . esc_html((string)$attachment['name']) . '</a>';
                echo '</div>';
            }
            echo '</div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('wnq_portal_request_status');
            echo '<input type="hidden" name="action" value="wnq_portal_request_status"><input type="hidden" name="client_id" value="' . esc_attr($client_id) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><select name="status">';
            foreach (['submitted' => 'Submitted', 'reviewing' => 'Reviewing', 'scheduled' => 'Scheduled', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'declined' => 'Declined'] as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($request['status'], $value, false) . '>' . esc_html($label) . '</option>';
            echo '</select><button class="button">Update</button></form></article>';
        }
        if (!$requests) echo '<p>No client requests yet.</p>';
        echo '</div></div>';
    }

    private static function performanceChart(array $rows): void
    {
        $max = max(1, ...array_map(static fn($row) => max(abs((float)$row['profit']), (float)$row['revenue']), $rows));
        echo '<div class="wnq-cp-panel"><h2>Monthly Job Performance</h2><div class="wnq-cp-chart">';
        foreach ($rows as $row) {
            $height = max(3, (int)round((abs((float)$row['profit']) / $max) * 100));
            echo '<div><span class="wnq-cp-bar ' . ((float)$row['profit'] >= 0 ? 'is-positive' : 'is-negative') . '" style="height:' . $height . '%"></span><strong>' . esc_html($row['label']) . '</strong><small>' . (int)$row['jobs'] . ' jobs</small><small>$' . esc_html(number_format((float)$row['profit'], 0)) . ' profit</small></div>';
        }
        echo '</div></div>';
    }

    private static function reportsTable(array $reports): void
    {
        echo '<div class="wnq-cp-panel"><h2>SEO OS Reports</h2><table class="widefat striped"><thead><tr><th>Report</th><th>Period</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ($reports as $report) {
            echo '<tr><td>' . esc_html(ucfirst((string)$report['report_type']) . ' Report') . '</td><td>' . esc_html((string)$report['period_start'] . ' - ' . (string)$report['period_end']) . '</td><td>' . esc_html((string)$report['status']) . '</td><td><a class="button" target="_blank" rel="noopener" href="' . esc_url($report['view_url']) . '">View Full Report</a> <a class="button" href="' . esc_url($report['pdf_url']) . '">Download PDF</a></td></tr>';
        }
        if (!$reports) echo '<tr><td colspan="4">No SEO OS reports yet.</td></tr>';
        echo '</tbody></table></div>';
    }

    private static function table(string $title, array $rows, array $columns): void
    {
        echo '<div class="wnq-cp-panel"><h2>' . esc_html($title) . '</h2><table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) echo '<th>' . esc_html($label) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $key => $label) {
                $value = $row[$key] ?? '';
                if (in_array($key, ['final_value', 'job_cost'], true)) $value = '$' . number_format((float)$value, 2);
                echo '<td>' . esc_html(wp_trim_words((string)$value, 24)) . '</td>';
            }
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="' . count($columns) . '">Nothing here yet.</td></tr>';
        echo '</tbody></table></div>';
    }

    public static function handleMessage(): void
    {
        self::checkCapability();
        check_admin_referer('wnq_portal_admin_message');
        $client_id = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        $body = [
            'ticket_key'    => wp_unslash($_POST['ticket_key'] ?? ''),
            'category'      => wp_unslash($_POST['category'] ?? 'general'),
            'priority'      => wp_unslash($_POST['priority'] ?? 'normal'),
            'ticket_status' => wp_unslash($_POST['ticket_status'] ?? 'open'),
            'subject'       => wp_unslash($_POST['subject'] ?? ''),
            'message'       => wp_unslash($_POST['message'] ?? ''),
        ];
        if (ClientPortal::messageValidationError($client_id, $body, 'admin') === '') {
            if (sanitize_key((string)$body['ticket_key']) !== '') {
                ClientPortal::markTicketMessagesRead($client_id, sanitize_key((string)$body['ticket_key']), 'client');
            }
            $body['attachments'] = self::handleUploads();
            if (!ClientPortal::createMessage($client_id, $body, 'admin')) {
                ClientPortal::deletePrivateAttachments($body['attachments']);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode($client_id)));
        exit;
    }

    public static function handleRequestStatus(): void
    {
        self::checkCapability();
        check_admin_referer('wnq_portal_request_status');
        $client_id = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        ClientPortal::updateRequestStatus(absint($_POST['request_id'] ?? 0), $client_id, sanitize_key(wp_unslash($_POST['status'] ?? 'submitted')));
        wp_safe_redirect(admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode($client_id)));
        exit;
    }

    public static function handleAdsThreshold(): void
    {
        self::checkCapability();
        $client_id = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        check_admin_referer('wnq_portal_ads_threshold_' . $client_id);
        if ($client_id === '' || !Client::getByClientId($client_id)) {
            wp_die('Client not found.', 'Invalid client', ['response' => 404]);
        }
        $raw_threshold = preg_replace('/[^0-9.\-]/', '', (string)wp_unslash($_POST['spend_alert_threshold'] ?? '0'));
        ClientPortal::saveAdsSettings($client_id, [
            'spend_alert_threshold' => max(0, round((float)$raw_threshold, 2)),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=wnq-client-portal-dashboard&ads_threshold=saved'));
        exit;
    }

    private static function handleUploads(): array
    {
        if (empty($_FILES['attachments']['name'])) return [];
        $files = $_FILES['attachments'];
        $uploads = [];
        $names = is_array($files['name']) ? array_keys($files['name']) : [0];
        foreach (array_slice($names, 0, 5) as $index) {
            $file = is_array($files['name']) ? [
                'name' => $files['name'][$index] ?? '',
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ] : $files;
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > 10 * MB_IN_BYTES) continue;
            $uploaded = ClientPortal::storePrivateUpload($file);
            if ($uploaded) $uploads[] = $uploaded;
        }
        return $uploads;
    }

    public static function handleAttachmentDownload(): void
    {
        $client_id = sanitize_text_field(wp_unslash($_GET['client_id'] ?? ''));
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string)wp_unslash($_GET['token'] ?? '')));
        if (!is_user_logged_in() || (!Permissions::canAccessClient($client_id) && !Permissions::currentUserCanManagePortal())) {
            wp_die('You do not have access to this attachment.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('wnq_portal_attachment_' . $client_id . '_' . $token);
        $attachment = ClientPortal::findPrivateAttachment($client_id, $token);
        $path = $attachment ? ClientPortal::privateAttachmentPath($attachment) : '';
        if (!$attachment || $path === '' || !is_file($path) || !is_readable($path)) {
            wp_die('Attachment not found.', 'Not Found', ['response' => 404]);
        }
        nocache_headers();
        $type = (string)($attachment['type'] ?: 'application/octet-stream');
        $preview = !empty($_GET['preview']) && str_starts_with($type, 'image/');
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . sanitize_file_name((string)$attachment['name']) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    public static function handleReportExport(): void
    {
        $report_id = absint($_GET['report_id'] ?? 0);
        check_admin_referer('wnq_portal_export_report_' . $report_id);
        if (!is_user_logged_in() || !$report_id) {
            wp_die('Invalid report request.');
        }
        global $wpdb;
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_seo_reports WHERE id=%d", $report_id), ARRAY_A);
        if (!$report || !\WNQ\Core\Permissions::canAccessClient((string)$report['client_id'])) {
            wp_die('You do not have access to this report.');
        }
        require_once WNQ_PORTAL_PATH . 'includes/Models/SEOHub.php';
        require_once WNQ_PORTAL_PATH . 'includes/Services/ReportGenerator.php';
        $format = sanitize_key((string)($_GET['format'] ?? 'html'));
        $generated = !empty($report['generated_at']) ? strtotime((string)$report['generated_at']) : time();
        $filename = sanitize_file_name('seo-report-' . $report['client_id'] . '-' . date('Y-m-d-His', $generated) . '-id-' . $report_id);
        if ($format === 'pdf') {
            $content = \WNQ\Services\ReportGenerator::renderReportPDF($report_id);
        } else {
            $content = \WNQ\Services\ReportGenerator::renderReportHTML($report_id);
        }
        if ($content === '') {
            wp_die('Report could not be rendered.');
        }
        nocache_headers();
        if ($format === 'pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
            header('Content-Length: ' . strlen($content));
        } else {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $filename . '.html"');
        }
        echo $content;
        exit;
    }

    private static function status(string $tone, string $label): void
    {
        echo '<span class="wnq-cp-status is-' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
    }

    private static function capability(): string
    {
        return current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
    }

    private static function checkCapability(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
    }

    private static function styles(): void
    {
        echo '<style>
        .wnq-cp-admin{max-width:1500px}.wnq-cp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin:24px 0}
        .wnq-cp-stats div,.wnq-cp-panel{background:#fff;border:1px solid #dcdcde;padding:20px;margin-bottom:20px}.wnq-cp-stats strong{display:block;font-size:28px}.wnq-cp-stats span{color:#646970}.wnq-cp-inbox{border-left:4px solid #d7b846}.wnq-cp-inbox>a{display:grid;grid-template-columns:220px 220px 1fr;gap:15px;padding:12px 0;border-bottom:1px solid #eee;text-decoration:none;color:#1d2327}.wnq-cp-inbox small{color:#646970}
        .wnq-cp-status{display:inline-block;padding:5px 9px;border-radius:4px;font-weight:700}.wnq-cp-status.is-green{background:#dcfce7;color:#166534}.wnq-cp-status.is-yellow{background:#fef3c7;color:#92400e}.wnq-cp-status.is-red{background:#fee2e2;color:#991b1b}
        .wnq-cp-table-scroll{overflow-x:auto}.wnq-cp-table-scroll table{min-width:1450px}.wnq-cp-threshold-form{display:flex;align-items:center;gap:6px}.wnq-cp-threshold-form label{display:flex;align-items:center;border:1px solid #8c8f94;border-radius:4px;background:#fff;overflow:hidden}.wnq-cp-threshold-form.is-reached label{border-color:#dc2626;background:#fff5f5}.wnq-cp-threshold-form b{padding-left:7px}.wnq-cp-threshold-form input{width:82px;min-height:30px;border:0;box-shadow:none;background:transparent}.wnq-cp-threshold-form input:focus{box-shadow:none}.text-muted{color:#646970}
        .is-positive{color:#166534;font-weight:700}.is-negative{color:#991b1b;font-weight:700}.wnq-cp-chart{height:230px;display:grid;grid-template-columns:repeat(6,minmax(70px,1fr));gap:18px;align-items:end;border-bottom:1px solid #dcdcde;padding:20px 10px 0}.wnq-cp-chart>div{height:100%;display:flex;flex-direction:column;justify-content:flex-end;text-align:center;gap:5px}.wnq-cp-bar{display:block;min-height:3px;background:#16a34a}.wnq-cp-bar.is-negative{background:#dc2626}.wnq-cp-chart small{color:#646970}.wnq-cp-panel textarea{display:block;width:100%;max-width:700px;margin:10px 0}.wnq-cp-detail{margin-top:30px}
        .wnq-cp-tickets{display:grid;gap:12px}.wnq-cp-ticket{border:1px solid #dcdcde;border-radius:6px;background:#f9f9f9}.wnq-cp-ticket summary{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:16px;cursor:pointer}.wnq-cp-ticket summary span,.wnq-cp-ticket summary small{display:block}.wnq-cp-ticket summary small{color:#646970;margin-top:4px}.wnq-cp-thread{display:grid;gap:10px;padding:0 16px 16px}.wnq-cp-thread article{max-width:75%;padding:12px 14px;border-radius:6px;background:#fff;border:1px solid #dcdcde}.wnq-cp-thread article.is-admin{justify-self:end;background:#fff8db}.wnq-cp-thread header{display:flex;justify-content:space-between;gap:20px}.wnq-cp-thread time{color:#646970;font-size:11px}.wnq-cp-ticket-reply{display:flex;align-items:end;gap:10px;padding:16px;border-top:1px solid #dcdcde;background:#fff}.wnq-cp-ticket-reply textarea{flex:1;margin:0}.wnq-cp-panel>form select{margin:0 6px 8px 0}.wnq-cp-attachments{display:flex;gap:6px;flex-wrap:wrap}.wnq-cp-attachments a{background:#fff;padding:5px 8px;border:1px solid #dcdcde;text-decoration:none}.wnq-cp-requests{display:grid;gap:12px}.wnq-cp-requests>article{display:grid;grid-template-columns:1fr auto;gap:20px;padding:16px;border:1px solid #dcdcde;background:#f9f9f9}.wnq-cp-requests small{display:block;color:#646970;margin-top:5px}.wnq-cp-requests dl{display:grid;grid-template-columns:160px 1fr;gap:5px}.wnq-cp-requests dt{font-weight:700;text-transform:capitalize}.wnq-cp-requests dd{margin:0}
        @media(max-width:782px){.wnq-cp-stats{grid-template-columns:1fr}.wnq-cp-panel{overflow:auto}.wnq-cp-ticket-reply{display:block}.wnq-cp-thread article{max-width:100%}}
        </style>';
    }
}
