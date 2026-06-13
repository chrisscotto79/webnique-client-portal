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
    }

    public static function addPage(): void
    {
        add_submenu_page(
            'wnq-portal',
            'Client Portal Dashboard',
            'Client Portal Dashboard',
            self::capability(),
            'wnq-client-portal-dashboard',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        self::checkCapability();
        ClientPortal::ensureSchema();
        $selected_id = sanitize_text_field(wp_unslash($_GET['client_id'] ?? ''));
        $clients = Client::getAll();
        $selected = $selected_id !== '' ? Client::getByClientId($selected_id) : null;
        ?>
        <div class="wrap wnq-cp-admin">
            <h1>Client Portal Dashboard</h1>
            <p class="description">Account health, client activity, CRM totals, work, reports, and messages in one place.</p>
            <div class="wnq-cp-stats">
                <div><strong><?php echo count($clients); ?></strong><span>Clients</span></div>
                <div><strong><?php echo count(array_filter($clients, static fn($c) => ($c['status'] ?? '') === 'active')); ?></strong><span>Active Accounts</span></div>
                <div><strong><?php echo esc_html(number_format(array_sum(array_map(static fn($c) => (float)($c['monthly_rate'] ?? 0), $clients)), 0)); ?></strong><span>Monthly Managed Revenue</span></div>
            </div>
            <div class="wnq-cp-panel">
                <h2>All Clients</h2>
                <table class="widefat striped">
                    <thead><tr><th>Client</th><th>Account</th><th>Billing</th><th>Customers</th><th>Jobs</th><th>Recorded Revenue</th><th>Open Work</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($clients as $client):
                        $overview = ClientPortal::overview((string)$client['client_id']);
                        $health = $overview['health'];
                        $crm = $overview['customers'];
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($client['company'] ?: $client['name']); ?></strong><br><small><?php echo esc_html($client['client_id']); ?></small></td>
                            <td><?php self::status($health['overall'], ucfirst($health['overall'])); ?></td>
                            <td><?php self::status($health['billing']['tone'], $health['billing']['label']); ?></td>
                            <td><?php echo (int)$crm['total']; ?></td>
                            <td><?php echo (int)$crm['job_count']; ?></td>
                            <td>$<?php echo esc_html(number_format((float)$crm['revenue'], 2)); ?></td>
                            <td><?php echo (int)$overview['open_tasks']; ?></td>
                            <td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode((string)$client['client_id']))); ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$clients): ?><tr><td colspan="8">No clients found.</td></tr><?php endif; ?>
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
            <?php self::table('Recent Customers', $customers, ['name' => 'Customer', 'service' => 'Service', 'status' => 'Status', 'job_count' => 'Jobs', 'final_value' => 'Recorded Revenue']); ?>
            <?php self::table('Work', $tasks, ['title' => 'Item', 'status' => 'Status', 'priority' => 'Priority', 'due_date' => 'Due']); ?>
            <?php self::table('Reports', $reports, ['report_type' => 'Type', 'period_start' => 'Start', 'period_end' => 'End', 'status' => 'Status', 'generated_at' => 'Generated']); ?>
            <?php self::table('Messages', $messages, ['sender_role' => 'From', 'subject' => 'Subject', 'message' => 'Message', 'created_at' => 'Sent']); ?>
        </div>
        <?php
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
                if ($key === 'final_value') $value = '$' . number_format((float)$value, 2);
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
        .wnq-cp-admin{max-width:1500px}.wnq-cp-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:24px 0}
        .wnq-cp-stats div,.wnq-cp-panel{background:#fff;border:1px solid #dcdcde;padding:20px;margin-bottom:20px}.wnq-cp-stats strong{display:block;font-size:28px}.wnq-cp-stats span{color:#646970}
        .wnq-cp-status{display:inline-block;padding:5px 9px;border-radius:4px;font-weight:700}.wnq-cp-status.is-green{background:#dcfce7;color:#166534}.wnq-cp-status.is-yellow{background:#fef3c7;color:#92400e}.wnq-cp-status.is-red{background:#fee2e2;color:#991b1b}
        .wnq-cp-panel textarea{display:block;width:100%;max-width:700px;margin:10px 0}.wnq-cp-detail{margin-top:30px}@media(max-width:782px){.wnq-cp-stats{grid-template-columns:1fr}.wnq-cp-panel{overflow:auto}}
        </style>';
    }
}
