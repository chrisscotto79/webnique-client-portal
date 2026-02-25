<?php
/**
 * Enhanced Clients Admin Page - SYNCED WITH ANALYTICS
 * 
 * Features:
 * - Syncs with AnalyticsConfig for multi-client analytics
 * - Payment analytics dashboard with Chart.js graphs
 * - Quick "Mark as Paid" button for each client
 * - Link to analytics dashboard for each client
 * - Direct link to web requests/messages
 * - Financial overview with stats
 * - Complete client management
 * 
 * @package WebNique Portal
 */

namespace WNQ\Admin;

use WNQ\Models\Client;

if (!defined('ABSPATH')) {
    exit;
}

final class ClientsAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSubmenu'], 5);
        add_action('admin_post_wnq_save_client', [self::class, 'handleSaveClient']);
        add_action('admin_post_wnq_delete_client_from_clients', [self::class, 'handleDeleteClient']);
        add_action('admin_post_wnq_mark_paid', [self::class, 'handleMarkPaid']);
    }

    public static function addSubmenu(): void
    {
        $capability = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page('wnq-portal', 'Clients', 'Clients', $capability, 'wnq-clients', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $client_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($action === 'edit' && $client_id) {
            self::renderEditForm($client_id);
            return;
        }

        if ($action === 'add') {
            self::renderAddForm();
            return;
        }

        self::renderClientsList();
    }

    private static function renderClientsList(): void
    {
        $clients = Client::getAll();
        $total_count = Client::getCount();
        $active_count = Client::getCountByStatus('active');
        
        // Safely load AnalyticsConfig if it exists
        $analytics_clients = [];
        $analytics_client_ids = [];
        
        if (class_exists('WNQ\\Models\\AnalyticsConfig')) {
            try {
                $analytics_clients = \WNQ\Models\AnalyticsConfig::getAllClients();
                $analytics_client_ids = array_column($analytics_clients, 'client_id');
            } catch (\Exception $e) {
                error_log('Analytics sync error: ' . $e->getMessage());
            }
        }

        // Calculate metrics
        $monthly_revenue = 0;
        $after_fees_total = 0;
        $total_collected = 0;
        
        foreach ($clients as $client) {
            if ($client['status'] === 'active') {
                $monthly_revenue += floatval($client['monthly_rate'] ?? 0);
                $after_fees_total += floatval($client['after_fees'] ?? 0);
            }
            $total_collected += floatval($client['total_collected'] ?? 0);
        }

        $total_fees = $monthly_revenue - $after_fees_total;

        // Get last 12 months data for graph
        $graph_data = self::getGraphData($clients);

        ?>
        <div class="wrap wnq-clients-admin">
            <div class="wnq-header">
                <div>
                    <h1>Clients & Revenue</h1>
                    <p class="subtitle">Manage clients, track payments, and view analytics</p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-clients&action=add'); ?>" class="page-title-action">+ Add New Client</a>
            </div>

            <!-- Stats Cards -->
            <div class="wnq-stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Clients</div>
                    <div class="stat-value"><?php echo $total_count; ?></div>
                    <div class="stat-subtitle"><?php echo $active_count; ?> active</div>
                </div>
                <div class="stat-card revenue">
                    <div class="stat-label">Monthly Revenue</div>
                    <div class="stat-value">$<?php echo number_format($monthly_revenue, 2); ?></div>
                    <div class="stat-subtitle">Before fees</div>
                </div>
                <div class="stat-card profit">
                    <div class="stat-label">After Fees</div>
                    <div class="stat-value">$<?php echo number_format($after_fees_total, 2); ?></div>
                    <div class="stat-subtitle">Net monthly</div>
                </div>
                <div class="stat-card collected">
                    <div class="stat-label">Total Collected</div>
                    <div class="stat-value">$<?php echo number_format($total_collected, 0); ?></div>
                    <div class="stat-subtitle">All-time</div>
                </div>
                <div class="stat-card fees">
                    <div class="stat-label">Stripe Fees</div>
                    <div class="stat-value">$<?php echo number_format($total_fees, 2); ?></div>
                    <div class="stat-subtitle">Monthly cost</div>
                </div>
            </div>

            <!-- Revenue Graph -->
            <div class="chart-container">
                <h2>Revenue Over Time</h2>
                <canvas id="revenueChart"></canvas>
            </div>

            <!-- Clients Table -->
            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <div class="empty-icon">👥</div>
                    <h3>No Clients Yet</h3>
                    <p>Get started by adding your first client</p>
                    <a href="<?php echo admin_url('admin.php?page=wnq-clients&action=add'); ?>" class="button button-primary button-hero">Add Your First Client</a>
                </div>
            <?php else: ?>
                <div class="clients-table-wrap">
                    <h2>All Clients</h2>
                    <table class="wp-list-table widefat fixed striped clients-table">
                        <thead>
                            <tr>
                                <th style="width: 150px;">Client</th>
                                <th>Contact</th>
                                <th style="width: 120px;">Tier</th>
                                <th style="width: 100px;">Monthly</th>
                                <th style="width: 100px;">After Fees</th>
                                <th style="width: 120px;">Last Payment</th>
                                <th style="width: 80px;">Status</th>
                                <th style="width: 380px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): 
                                $has_analytics = in_array($client['client_id'], $analytics_client_ids);
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($client['name']); ?></strong>
                                        <?php if ($client['company']): ?>
                                            <br><small class="text-muted"><?php echo esc_html($client['company']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($has_analytics): ?>
                                            <br><span class="analytics-badge">📊 Analytics Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($client['email']): ?>
                                            <a href="mailto:<?php echo esc_attr($client['email']); ?>"><?php echo esc_html($client['email']); ?></a>
                                        <?php endif; ?>
                                        <?php if ($client['phone']): ?>
                                            <br><small class="text-muted"><?php echo esc_html($client['phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $tier_map = [
                                            'website' => 'Website',
                                            'website-seo' => 'Web + SEO',
                                            'website-ppc' => 'Web + PPC',
                                            'website-seo-ppc' => 'Full Stack',
                                        ];
                                        ?>
                                        <span class="tier-badge tier-<?php echo esc_attr($client['tier']); ?>">
                                            <?php echo esc_html($tier_map[$client['tier']] ?? ucfirst($client['tier'])); ?>
                                        </span>
                                    </td>
                                    <td class="money-col">
                                        <strong class="amount-revenue">$<?php echo number_format($client['monthly_rate'] ?? 0, 0); ?></strong>
                                    </td>
                                    <td class="money-col">
                                        <strong class="amount-profit">$<?php echo number_format($client['after_fees'] ?? 0, 0); ?></strong>
                                        <br><small class="text-muted">-$<?php echo number_format(($client['monthly_rate'] ?? 0) - ($client['after_fees'] ?? 0), 2); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($client['last_payment_date']): ?>
                                            <strong><?php echo date('M j, Y', strtotime($client['last_payment_date'])); ?></strong>
                                            <br><small class="text-muted"><?php echo intval($client['payment_count'] ?? 0); ?> payments</small>
                                        <?php else: ?>
                                            <span class="text-muted">No payments</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($client['status']); ?>">
                                            <?php echo esc_html(ucfirst($client['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="actions-col">
                                        <!-- Mark Paid -->
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="inline-form">
                                            <?php wp_nonce_field('wnq_mark_paid'); ?>
                                            <input type="hidden" name="action" value="wnq_mark_paid">
                                            <input type="hidden" name="id" value="<?php echo esc_attr($client['id']); ?>">
                                            <button type="submit" class="button button-small btn-paid" title="Mark as paid for this month">
                                                ✓ Paid
                                            </button>
                                        </form>

                                        <!-- Analytics Dashboard -->
                                        <?php if ($has_analytics): ?>
                                            <a href="<?php echo admin_url('admin.php?page=wnq-analytics&client=' . urlencode($client['client_id'])); ?>" class="button button-small btn-analytics" title="View Analytics">
                                                📊 Analytics
                                            </a>
                                        <?php endif; ?>

                                        <!-- Messages -->
                                        <a href="<?php echo admin_url('admin.php?page=wnq-web-requests'); ?>" class="button button-small" title="View messages">
                                            💬 Chat
                                        </a>

                                        <!-- Edit -->
                                        <a href="<?php echo admin_url('admin.php?page=wnq-clients&action=edit&id=' . $client['id']); ?>" class="button button-small">
                                            Edit
                                        </a>

                                        <!-- Delete -->
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="inline-form">
                                            <?php wp_nonce_field('wnq_delete_client_from_clients'); ?>
                                            <input type="hidden" name="action" value="wnq_delete_client_from_clients">
                                            <input type="hidden" name="id" value="<?php echo esc_attr($client['id']); ?>">
                                            <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Delete this client?');">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="totals-row">
                                <td colspan="3" class="label-col">MONTHLY TOTALS:</td>
                                <td class="money-col"><strong class="amount-revenue">$<?php echo number_format($monthly_revenue, 2); ?></strong></td>
                                <td class="money-col"><strong class="amount-profit">$<?php echo number_format($after_fees_total, 2); ?></strong></td>
                                <td><strong><?php echo array_sum(array_column($clients, 'payment_count')); ?> total</strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('revenueChart');
            if (!ctx) return;

            const data = <?php echo json_encode($graph_data); ?>;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Monthly Revenue',
                        data: data.revenue,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 3
                    }, {
                        label: 'After Fees',
                        data: data.afterFees,
                        borderColor: '#0d539e',
                        backgroundColor: 'rgba(13, 83, 158, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 3,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: { size: 13, weight: '600' }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 14, weight: '600' },
                            bodyFont: { size: 13 },
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': $' + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f0f0f0' },
                            ticks: {
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                },
                                font: { size: 12 }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 12 } }
                        }
                    }
                }
            });
        });
        </script>

        <style>
        .wnq-clients-admin .wnq-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .wnq-clients-admin .wnq-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 4px;
        }
        .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }

        /* Stats Grid */
        .wnq-stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-bottom: 30px;
        }
        @media (max-width: 1400px) {
            .wnq-stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 900px) {
            .wnq-stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #0d539e;
        }
        .stat-card.revenue { border-left-color: #059669; }
        .stat-card.profit { border-left-color: #0d539e; }
        .stat-card.collected { border-left-color: #7c3aed; }
        .stat-card.fees { border-left-color: #dc2626; }

        .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #111827;
            line-height: 1;
            margin-bottom: 4px;
        }
        .stat-subtitle {
            font-size: 11px;
            color: #9ca3af;
        }

        /* Chart */
        .chart-container {
            background: white;
            padding: 24px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            margin-bottom: 30px;
        }
        .chart-container h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 20px;
        }

        /* Table */
        .clients-table-wrap {
            background: white;
            padding: 24px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .clients-table-wrap h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 20px;
        }

        .clients-table th {
            background: #f9fafb;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .text-muted {
            color: #6b7280;
            font-size: 12px;
        }

        .analytics-badge {
            display: inline-block;
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .money-col {
            text-align: right;
        }
        .amount-revenue {
            color: #059669;
            font-size: 15px;
        }
        .amount-profit {
            color: #0d539e;
            font-size: 15px;
        }

        .tier-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .tier-website { background: #dbeafe; color: #1e40af; }
        .tier-website-seo { background: #dcfce7; color: #166534; }
        .tier-website-ppc { background: #fef3c7; color: #92400e; }
        .tier-website-seo-ppc { background: #f3e8ff; color: #6b21a8; }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .actions-col .button {
            margin: 2px;
        }
        .inline-form {
            display: inline;
        }
        .btn-paid {
            background: #10b981;
            border-color: #059669;
            color: white;
        }
        .btn-paid:hover {
            background: #059669;
            color: white;
        }
        .btn-analytics {
            background: #0d539e;
            border-color: #0a4380;
            color: white;
        }
        .btn-analytics:hover {
            background: #0a4380;
            color: white;
        }

        .totals-row {
            background: #f9fafb;
            font-weight: 700;
        }
        .totals-row .label-col {
            text-align: right;
            padding-right: 16px;
        }

        .empty-state {
            background: white;
            padding: 60px;
            text-align: center;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        .empty-state p {
            color: #6b7280;
            margin-bottom: 24px;
        }
        </style>
        <?php
    }

    private static function getGraphData(array $clients): array
    {
        $labels = [];
        $revenue = [];
        $afterFees = [];

        for ($i = 11; $i >= 0; $i--) {
            $labels[] = date('M Y', strtotime("-$i months"));
            
            $monthRevenue = 0;
            $monthAfterFees = 0;
            
            foreach ($clients as $client) {
                if ($client['status'] === 'active') {
                    $monthRevenue += floatval($client['monthly_rate'] ?? 0);
                    $monthAfterFees += floatval($client['after_fees'] ?? 0);
                }
            }
            
            $revenue[] = $monthRevenue;
            $afterFees[] = $monthAfterFees;
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'afterFees' => $afterFees,
        ];
    }

    public static function handleMarkPaid(): void
    {
        check_admin_referer('wnq_mark_paid');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $client = Client::getById($id);

        if (!$client) {
            wp_die('Client not found - ID: ' . $id);
        }

        // Calculate new values
        $new_payment_count = intval($client['payment_count'] ?? 0) + 1;
        $new_total_collected = floatval($client['total_collected'] ?? 0) + floatval($client['after_fees'] ?? 0);

        $data = [
            'last_payment_date' => date('Y-m-d'),
            'payment_count' => $new_payment_count,
            'total_collected' => $new_total_collected,
        ];

        $success = Client::update($id, $data);

        if (!$success) {
            wp_die('Failed to update client payment');
        }

        wp_redirect(admin_url('admin.php?page=wnq-clients'));
        exit;
    }

    // Form rendering methods

    private static function renderAddForm(): void
    {
        ?>
        <div class="wrap">
            <h1>Add New Client</h1>
            <?php self::renderClientForm(null); ?>
        </div>
        <?php
    }

    private static function renderEditForm(int $id): void
    {
        $client = Client::getById($id);
        if (!$client) {
            wp_die('Client not found.');
        }
        ?>
        <div class="wrap">
            <h1>Edit Client: <?php echo esc_html($client['name']); ?></h1>
            <?php self::renderClientForm($client); ?>
        </div>
        <?php
    }

    private static function renderClientForm(?array $client): void
    {
        $is_edit = !empty($client);
        
        // Check if this client has analytics configured
        $has_analytics = false;
        $analytics_config = null;
        
        if ($is_edit && class_exists('WNQ\\Models\\AnalyticsConfig')) {
            try {
                $analytics_config = \WNQ\Models\AnalyticsConfig::getClientConfig($client['client_id']);
                $has_analytics = !empty($analytics_config);
            } catch (\Exception $e) {
                // Analytics not available
            }
        }
        
        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="wnq-client-form">
            <?php wp_nonce_field('wnq_save_client'); ?>
            <input type="hidden" name="action" value="wnq_save_client">
            <?php if ($is_edit): ?>
                <input type="hidden" name="id" value="<?php echo esc_attr($client['id']); ?>">
            <?php endif; ?>

            <div class="form-section">
                <h2>General Information</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="client_id">Client ID *</label></th>
                        <td>
                            <?php if ($is_edit): ?>
                                <input type="text" name="client_id" id="client_id" value="<?php echo esc_attr($client['client_id']); ?>" class="regular-text" readonly>
                                <p class="description">Client ID cannot be changed after creation.</p>
                            <?php else: ?>
                                <input type="text" name="client_id" id="client_id" value="" class="regular-text" required placeholder="e.g., acme-corp">
                                <p class="description">Unique identifier for this client (lowercase, no spaces). This will be used for analytics.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="name">Name *</label></th>
                        <td>
                            <input type="text" name="name" id="name" value="<?php echo esc_attr($client['name'] ?? ''); ?>" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email">Email *</label></th>
                        <td>
                            <input type="email" name="email" id="email" value="<?php echo esc_attr($client['email'] ?? ''); ?>" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="phone">Phone</label></th>
                        <td>
                            <input type="text" name="phone" id="phone" value="<?php echo esc_attr($client['phone'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="company">Company</label></th>
                        <td>
                            <input type="text" name="company" id="company" value="<?php echo esc_attr($client['company'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="website">Website</label></th>
                        <td>
                            <input type="url" name="website" id="website" value="<?php echo esc_attr($client['website'] ?? ''); ?>" class="regular-text" placeholder="https://example.com">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="active" <?php selected($client['status'] ?? 'active', 'active'); ?>>Active</option>
                                <option value="inactive" <?php selected($client['status'] ?? '', 'inactive'); ?>>Inactive</option>
                                <option value="pending" <?php selected($client['status'] ?? '', 'pending'); ?>>Pending</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tier">Tier</label></th>
                        <td>
                            <select name="tier" id="tier">
                                <option value="website" <?php selected($client['tier'] ?? 'website', 'website'); ?>>Website</option>
                                <option value="website-seo" <?php selected($client['tier'] ?? '', 'website-seo'); ?>>Website + SEO</option>
                                <option value="website-ppc" <?php selected($client['tier'] ?? '', 'website-ppc'); ?>>Website + PPC</option>
                                <option value="website-seo-ppc" <?php selected($client['tier'] ?? '', 'website-seo-ppc'); ?>>Website + SEO + PPC</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if ($has_analytics): ?>
            <div class="form-section analytics-notice">
                <h2>📊 Analytics Integration</h2>
                <div class="notice notice-success inline">
                    <p>
                        <strong>Analytics Active!</strong> This client has analytics configured.<br>
                        <a href="<?php echo admin_url('admin.php?page=wnq-analytics&client=' . urlencode($client['client_id'])); ?>" class="button">View Analytics →</a>
                        <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=clients'); ?>" class="button">Manage Analytics Settings →</a>
                    </p>
                </div>
            </div>
            <?php elseif ($is_edit): ?>
            <div class="form-section analytics-notice">
                <h2>📊 Analytics Integration</h2>
                <div class="notice notice-info inline">
                    <p>
                        <strong>Setup Analytics:</strong> To track this client's website performance, add them to the analytics system.<br>
                        <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=clients'); ?>" class="button">Add to Analytics →</a>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-section">
                <h2>Billing & Payments</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="billing_email">Billing Email</label></th>
                        <td>
                            <input type="email" name="billing_email" id="billing_email" value="<?php echo esc_attr($client['billing_email'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="billing_cycle">Billing Cycle</label></th>
                        <td>
                            <select name="billing_cycle" id="billing_cycle">
                                <option value="monthly" <?php selected($client['billing_cycle'] ?? 'monthly', 'monthly'); ?>>Monthly</option>
                                <option value="quarterly" <?php selected($client['billing_cycle'] ?? '', 'quarterly'); ?>>Quarterly</option>
                                <option value="annually" <?php selected($client['billing_cycle'] ?? '', 'annually'); ?>>Annually</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="monthly_rate">Monthly Rate</label></th>
                        <td>
                            $<input type="number" name="monthly_rate" id="monthly_rate" value="<?php echo esc_attr($client['monthly_rate'] ?? '0.00'); ?>" step="0.01" min="0" class="small-text">
                            <p class="description">Amount you charge the client per month</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="stripe_fee_percent">Stripe Fee (%)</label></th>
                        <td>
                            <input type="number" name="stripe_fee_percent" id="stripe_fee_percent" value="<?php echo esc_attr($client['stripe_fee_percent'] ?? '2.90'); ?>" step="0.01" min="0" max="100" class="small-text">%
                            <p class="description">Stripe percentage fee (default: 2.90%)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="stripe_fee_flat">Stripe Flat Fee</label></th>
                        <td>
                            $<input type="number" name="stripe_fee_flat" id="stripe_fee_flat" value="<?php echo esc_attr($client['stripe_fee_flat'] ?? '0.30'); ?>" step="0.01" min="0" class="small-text">
                            <p class="description">Stripe flat fee per transaction (default: $0.30)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="after_fees">After Fees</label></th>
                        <td>
                            $<input type="number" name="after_fees" id="after_fees" value="<?php echo esc_attr($client['after_fees'] ?? '0.00'); ?>" step="0.01" min="0" class="small-text" readonly style="background: #f0f0f0;">
                            <p class="description">Auto-calculated: Monthly Rate - Fees</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="last_payment_date">Last Payment Date</label></th>
                        <td>
                            <input type="date" name="last_payment_date" id="last_payment_date" value="<?php echo esc_attr($client['last_payment_date'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="payment_count">Number of Payments</label></th>
                        <td>
                            <input type="number" name="payment_count" id="payment_count" value="<?php echo esc_attr($client['payment_count'] ?? '0'); ?>" min="0" class="small-text">
                            <p class="description">Total number of payments received</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="total_collected">Total Collected</label></th>
                        <td>
                            $<input type="number" name="total_collected" id="total_collected" value="<?php echo esc_attr($client['total_collected'] ?? '0.00'); ?>" step="0.01" min="0" class="regular-text">
                            <p class="description">Lifetime revenue from this client</p>
                        </td>
                    </tr>
                </table>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const monthlyRate = document.getElementById('monthly_rate');
                const feePercent = document.getElementById('stripe_fee_percent');
                const feeFlat = document.getElementById('stripe_fee_flat');
                const afterFees = document.getElementById('after_fees');
                
                function calculateAfterFees() {
                    const rate = parseFloat(monthlyRate.value) || 0;
                    const percent = parseFloat(feePercent.value) || 0;
                    const flat = parseFloat(feeFlat.value) || 0;
                    
                    const percentFee = rate * (percent / 100);
                    const totalFees = percentFee + flat;
                    const result = rate - totalFees;
                    
                    afterFees.value = result.toFixed(2);
                }
                
                monthlyRate.addEventListener('input', calculateAfterFees);
                feePercent.addEventListener('input', calculateAfterFees);
                feeFlat.addEventListener('input', calculateAfterFees);
                
                calculateAfterFees();
            });
            </script>

            <div class="form-section">
                <h2>Notes</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="notes">Internal Notes</label></th>
                        <td>
                            <textarea name="notes" id="notes" rows="5" class="large-text"><?php echo esc_textarea($client['notes'] ?? ''); ?></textarea>
                            <p class="description">Internal notes (not visible to client)</p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <?php echo $is_edit ? 'Update Client' : 'Create Client'; ?>
                </button>
                <a href="<?php echo admin_url('admin.php?page=wnq-clients'); ?>" class="button button-large">Cancel</a>
            </p>
        </form>

        <style>
        .wnq-client-form .form-section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        .wnq-client-form .form-section h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #dcdcde;
        }
        .wnq-client-form .form-section.analytics-notice {
            border-left: 4px solid #0d539e;
        }
        .wnq-client-form .notice.inline {
            margin: 0;
            padding: 12px;
        }
        </style>
        <?php
    }

    public static function handleSaveClient(): void
    {
        check_admin_referer('wnq_save_client');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        $data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'company' => sanitize_text_field($_POST['company'] ?? ''),
            'website' => esc_url_raw($_POST['website'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
            'tier' => sanitize_text_field($_POST['tier'] ?? 'website'),
            'billing_email' => sanitize_email($_POST['billing_email'] ?? ''),
            'billing_cycle' => sanitize_text_field($_POST['billing_cycle'] ?? 'monthly'),
            'monthly_rate' => floatval($_POST['monthly_rate'] ?? 0),
            'stripe_fee_percent' => floatval($_POST['stripe_fee_percent'] ?? 2.90),
            'stripe_fee_flat' => floatval($_POST['stripe_fee_flat'] ?? 0.30),
            'after_fees' => floatval($_POST['after_fees'] ?? 0),
            'last_payment_date' => sanitize_text_field($_POST['last_payment_date'] ?? ''),
            'payment_count' => intval($_POST['payment_count'] ?? 0),
            'total_collected' => floatval($_POST['total_collected'] ?? 0),
            'notes' => wp_kses_post($_POST['notes'] ?? ''),
        ];

        if ($id) {
            $success = Client::update($id, $data);
        } else {
            $data['client_id'] = sanitize_text_field($_POST['client_id'] ?? '');
            $success = Client::create($data);
        }

        wp_redirect(add_query_arg([
            'page' => 'wnq-clients',
            'message' => $success ? 'success' : 'error'
        ], admin_url('admin.php')));
        exit;
    }

    public static function handleDeleteClient(): void
    {
        check_admin_referer('wnq_delete_client_from_clients');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $client = Client::getById($id);
        
        // Delete from analytics if configured and class exists
        if ($client && !empty($client['client_id']) && class_exists('WNQ\\Models\\AnalyticsConfig')) {
            try {
                \WNQ\Models\AnalyticsConfig::deleteClient($client['client_id']);
            } catch (\Exception $e) {
                // Continue even if analytics delete fails
                error_log('Failed to delete from analytics: ' . $e->getMessage());
            }
        }

        $success = Client::delete($id);

        wp_redirect(add_query_arg([
            'page' => 'wnq-clients',
            'message' => $success ? 'deleted' : 'error'
        ], admin_url('admin.php')));
        exit;
    }
}