<?php
/**
 * Internal client portal overview.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Admin;

use WNQ\Models\Client;
use WNQ\Models\ClientPortal;

if (!defined('ABSPATH')) {
    exit;
}

final class ClientPortalAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPage'], 25);
        add_action('admin_post_wnq_portal_admin_message', [self::class, 'handleMessage']);
        add_action('admin_post_wnq_portal_export_report', [self::class, 'handleReportExport']);
        add_action('admin_notices', [self::class, 'messageNotice']);
    }

    public static function addPage(): void
    {
        $unread = ClientPortal::getUnreadMessageCount();
        $label = 'Client Portal Dashboard' . ($unread > 0 ? ' <span class="awaiting-mod count-' . $unread . '"><span class="pending-count">' . $unread . '</span></span>' : '');
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
        $count = ClientPortal::getUnreadMessageCount();
        if ($count < 1 || sanitize_key((string)($_GET['page'] ?? '')) === 'wnq-client-portal-dashboard') {
            return;
        }
        echo '<div class="notice notice-info"><p><strong>' . esc_html($count . ' unread client portal message' . ($count === 1 ? '' : 's')) . '.</strong> <a href="' . esc_url(admin_url('admin.php?page=wnq-client-portal-dashboard')) . '">Review messages</a></p></div>';
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
        foreach ($clients as $client) {
            $performance = ClientPortal::getMonthlyPerformance((string)$client['client_id']);
            $current = $performance ? end($performance) : [];
            $month_jobs += (int)($current['jobs'] ?? 0);
            $month_profit += (float)($current['profit'] ?? 0);
        }
        ?>
        <div class="wrap wnq-cp-admin">
            <h1>Client Portal Dashboard</h1>
            <p class="description">Client activity, CRM performance, reports, billing health, and unread messages in one place.</p>
            <div class="wnq-cp-stats">
                <div><strong><?php echo count($clients); ?></strong><span>Clients</span></div>
                <div><strong><?php echo count(array_filter($clients, static fn($c) => ($c['status'] ?? '') === 'active')); ?></strong><span>Active Accounts</span></div>
                <div><strong><?php echo esc_html(number_format(array_sum(array_map(static fn($c) => (float)($c['monthly_rate'] ?? 0), $clients)), 0)); ?></strong><span>Monthly Managed Revenue</span></div>
                <div><strong><?php echo (int)$month_jobs; ?></strong><span>Client Jobs This Month</span></div>
                <div><strong class="<?php echo $month_profit >= 0 ? 'is-positive' : 'is-negative'; ?>">$<?php echo esc_html(number_format($month_profit, 0)); ?></strong><span>Client Profit This Month</span></div>
                <div><strong><?php echo (int)ClientPortal::getUnreadMessageCount(); ?></strong><span>Unread Client Messages</span></div>
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
                <table class="widefat striped">
                    <thead><tr><th>Client</th><th>Account</th><th>Billing</th><th>Customers</th><th>Jobs This Month</th><th>Revenue This Month</th><th>Profit This Month</th><th>Messages</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($clients as $client):
                        $overview = ClientPortal::overview((string)$client['client_id']);
                        $health = $overview['health'];
                        $crm = $overview['customers'];
                        $performance = $overview['performance'];
                        $current = $performance ? end($performance) : [];
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($client['company'] ?: $client['name']); ?></strong><br><small><?php echo esc_html($client['client_id']); ?></small></td>
                            <td><?php self::status($health['overall'], ucfirst($health['overall'])); ?></td>
                            <td><?php self::status($health['billing']['tone'], $health['billing']['label']); ?></td>
                            <td><?php echo (int)$crm['total']; ?></td>
                            <td><?php echo (int)($current['jobs'] ?? 0); ?></td>
                            <td>$<?php echo esc_html(number_format((float)($current['revenue'] ?? 0), 2)); ?></td>
                            <td class="<?php echo (float)($current['profit'] ?? 0) >= 0 ? 'is-positive' : 'is-negative'; ?>">$<?php echo esc_html(number_format((float)($current['profit'] ?? 0), 2)); ?></td>
                            <td><?php echo (int)ClientPortal::getUnreadMessageCount((string)$client['client_id']); ?></td>
                            <td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode((string)$client['client_id']))); ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$clients): ?><tr><td colspan="9">No clients found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
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
        $messages = ClientPortal::getMessages($client_id, 30);
        ClientPortal::markMessagesRead($client_id, 'client');
        $tasks = ClientPortal::getTasks($client_id, 30);
        $reports = ClientPortal::getReports($client_id, 20);
        ?>
        <div class="wnq-cp-detail">
            <div class="wnq-cp-panel">
                <h2><?php echo esc_html($client['company'] ?: $client['name']); ?></h2>
                <p><?php echo esc_html(implode(' · ', array_filter([$client['phone'] ?? '', $client['email'] ?? '', $client['website'] ?? '']))); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wnq_portal_admin_message'); ?>
                    <input type="hidden" name="action" value="wnq_portal_admin_message">
                    <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                    <input type="text" name="subject" placeholder="Subject" class="regular-text">
                    <textarea name="message" rows="3" placeholder="Send the client a message" required></textarea>
                    <button class="button button-primary">Send Message</button>
                </form>
            </div>
            <?php self::performanceChart(ClientPortal::getMonthlyPerformance($client_id)); ?>
            <?php self::table('CRM & Job History', $customers, ['name' => 'Customer', 'service' => 'Service', 'lead_source' => 'Source', 'status' => 'Status', 'job_date' => 'Job Date', 'job_count' => 'Jobs', 'final_value' => 'Revenue', 'job_cost' => 'Cost']); ?>
            <?php self::table('Marketing Work History', $tasks, ['title' => 'Item', 'status' => 'Status', 'priority' => 'Priority', 'due_date' => 'Due']); ?>
            <?php self::reportsTable($reports); ?>
            <?php self::table('Messages', $messages, ['sender_role' => 'From', 'subject' => 'Subject', 'message' => 'Message', 'created_at' => 'Sent']); ?>
        </div>
        <?php
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
        ClientPortal::createMessage($client_id, [
            'subject' => wp_unslash($_POST['subject'] ?? ''),
            'message' => wp_unslash($_POST['message'] ?? ''),
        ], 'admin');
        wp_safe_redirect(admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode($client_id)));
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
        .is-positive{color:#166534;font-weight:700}.is-negative{color:#991b1b;font-weight:700}.wnq-cp-chart{height:230px;display:grid;grid-template-columns:repeat(6,minmax(70px,1fr));gap:18px;align-items:end;border-bottom:1px solid #dcdcde;padding:20px 10px 0}.wnq-cp-chart>div{height:100%;display:flex;flex-direction:column;justify-content:flex-end;text-align:center;gap:5px}.wnq-cp-bar{display:block;min-height:3px;background:#16a34a}.wnq-cp-bar.is-negative{background:#dc2626}.wnq-cp-chart small{color:#646970}.wnq-cp-panel textarea{display:block;width:100%;max-width:700px;margin:10px 0}.wnq-cp-detail{margin-top:30px}@media(max-width:782px){.wnq-cp-stats{grid-template-columns:1fr}.wnq-cp-panel{overflow:auto}}
        </style>';
    }
}
