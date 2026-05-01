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
use WNQ\Models\FinanceEntry;

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
        add_action('admin_post_wnq_save_finance_entry', [self::class, 'handleSaveFinanceEntry']);
        add_action('admin_post_wnq_delete_finance_entry', [self::class, 'handleDeleteFinanceEntry']);
    }

    public static function addSubmenu(): void
    {
        $capability = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page('wnq-portal', 'Clients', 'Clients', $capability, 'wnq-clients', [self::class, 'render']);
    }

    private static function ensureFinanceModel(): void
    {
        if (!class_exists('WNQ\\Models\\FinanceEntry') && defined('WNQ_PORTAL_PATH')) {
            $finance_model = WNQ_PORTAL_PATH . 'includes/Models/FinanceEntry.php';
            if (file_exists($finance_model)) {
                require_once $finance_model;
            }
        }
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
        self::ensureFinanceModel();

        $clients = Client::getAll();
        $total_count = Client::getCount();
        $active_count = Client::getCountByStatus('active');
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        
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

            <nav class="nav-tab-wrapper wnq-subtabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-clients')); ?>" class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-clients&tab=finance')); ?>" class="nav-tab <?php echo $active_tab === 'finance' ? 'nav-tab-active' : ''; ?>">Income & Expenses</a>
            </nav>

            <?php if ($active_tab === 'finance'): ?>
                <?php self::renderFinanceTab($clients); ?>
            <?php else: ?>

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
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('revenueChart');
            if (!canvas) return;
            const data = <?php echo json_encode($graph_data); ?>;
            const ctx = canvas.getContext('2d');
            const ratio = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * ratio;
            canvas.height = 320 * ratio;
            ctx.scale(ratio, ratio);

            const width = rect.width;
            const height = 320;
            const padding = { top: 24, right: 24, bottom: 48, left: 76 };
            const series = [
                { label: 'Income', values: data.income || data.revenue || [], color: '#059669' },
                { label: 'Expenses', values: data.expenses || [], color: '#dc2626' },
                { label: 'Net', values: data.net || data.afterFees || [], color: '#0d539e' }
            ];
            const values = series.flatMap(item => item.values);
            const maxValue = Math.max(100, ...values);
            const minValue = Math.min(0, ...values);
            const range = Math.max(1, maxValue - minValue);
            const chartWidth = width - padding.left - padding.right;
            const chartHeight = height - padding.top - padding.bottom;
            const labels = data.labels || [];

            ctx.clearRect(0, 0, width, height);
            ctx.font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.textBaseline = 'middle';

            for (let i = 0; i <= 4; i++) {
                const y = padding.top + (chartHeight / 4) * i;
                const value = maxValue - (range / 4) * i;
                ctx.strokeStyle = '#e5e7eb';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(padding.left, y);
                ctx.lineTo(width - padding.right, y);
                ctx.stroke();
                ctx.fillStyle = '#6b7280';
                ctx.textAlign = 'right';
                ctx.fillText('$' + Math.round(value).toLocaleString(), padding.left - 12, y);
            }

            function xFor(index) {
                return padding.left + (labels.length <= 1 ? chartWidth : (chartWidth / (labels.length - 1)) * index);
            }

            function yFor(value) {
                return padding.top + chartHeight - (((value - minValue) / range) * chartHeight);
            }

            series.forEach(item => {
                if (!item.values.length) return;
                ctx.strokeStyle = item.color;
                ctx.lineWidth = 3;
                ctx.beginPath();
                item.values.forEach((value, index) => {
                    const x = xFor(index);
                    const y = yFor(value);
                    if (index === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        ctx.lineTo(x, y);
                    }
                });
                ctx.stroke();

                ctx.fillStyle = item.color;
                item.values.forEach((value, index) => {
                    ctx.beginPath();
                    ctx.arc(xFor(index), yFor(value), 3.5, 0, Math.PI * 2);
                    ctx.fill();
                });
            });

            ctx.fillStyle = '#6b7280';
            ctx.textAlign = 'center';
            labels.forEach((label, index) => {
                if (index % 2 !== labels.length % 2 && labels.length > 8) return;
                ctx.fillText(label, xFor(index), height - 24);
            });

            let legendX = padding.left;
            series.forEach(item => {
                ctx.fillStyle = item.color;
                ctx.fillRect(legendX, 8, 10, 10);
                ctx.fillStyle = '#374151';
                ctx.textAlign = 'left';
                ctx.fillText(item.label, legendX + 16, 13);
                legendX += 98;
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
        .wnq-subtabs {
            margin-bottom: 20px;
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
        #revenueChart {
            display: block;
            width: 100%;
            height: 320px;
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
        .finance-grid {
            display: grid;
            grid-template-columns: minmax(320px, 440px) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }
        .finance-panel {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
        }
        .finance-panel h2 {
            margin-top: 0;
        }
        .finance-form {
            display: grid;
            gap: 16px;
        }
        .finance-field {
            display: grid;
            gap: 6px;
        }
        .finance-field label {
            font-weight: 700;
            color: #1f2937;
        }
        .finance-field input,
        .finance-field select,
        .finance-field textarea {
            box-sizing: border-box;
            width: 100%;
            max-width: 100%;
        }
        .finance-field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .amount-input {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .amount-input span {
            flex: 0 0 auto;
            font-weight: 700;
        }
        .amount-input input {
            min-width: 0;
        }
        .finance-table .income {
            color: #059669;
            font-weight: 700;
        }
        .finance-table .expense {
            color: #dc2626;
            font-weight: 700;
        }
        .frequency-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 11px;
            font-weight: 700;
        }
        .finance-table-wrap {
            overflow-x: auto;
        }
        @media (max-width: 1100px) {
            .finance-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .finance-field-row { grid-template-columns: 1fr; }
        }
        </style>
        <?php
    }

    private static function renderFinanceTab(array $clients): void
    {
        self::ensureFinanceModel();

        $summary = class_exists('WNQ\\Models\\FinanceEntry') ? FinanceEntry::getSummary() : [];
        $entries = class_exists('WNQ\\Models\\FinanceEntry') ? FinanceEntry::getAll(150) : [];
        $message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';

        ?>
        <?php if ($message === 'finance_saved'): ?>
            <div class="notice notice-success is-dismissible"><p>Finance entry saved.</p></div>
        <?php elseif ($message === 'finance_deleted'): ?>
            <div class="notice notice-success is-dismissible"><p>Finance entry deleted.</p></div>
        <?php elseif ($message === 'finance_error'): ?>
            <div class="notice notice-error is-dismissible"><p>Could not save the finance entry. Add an amount greater than $0.</p></div>
        <?php endif; ?>

        <div class="wnq-stats-grid">
            <div class="stat-card revenue">
                <div class="stat-label">Income This Month</div>
                <div class="stat-value">$<?php echo number_format($summary['month_income'] ?? 0, 2); ?></div>
                <div class="stat-subtitle">Recorded payments</div>
            </div>
            <div class="stat-card fees">
                <div class="stat-label">Expenses This Month</div>
                <div class="stat-value">$<?php echo number_format($summary['month_expense'] ?? 0, 2); ?></div>
                <div class="stat-subtitle">Recorded costs</div>
            </div>
            <div class="stat-card profit">
                <div class="stat-label">Net This Month</div>
                <div class="stat-value">$<?php echo number_format($summary['month_net'] ?? 0, 2); ?></div>
                <div class="stat-subtitle">Income minus expenses</div>
            </div>
            <div class="stat-card collected">
                <div class="stat-label">All-Time Net</div>
                <div class="stat-value">$<?php echo number_format($summary['net'] ?? 0, 2); ?></div>
                <div class="stat-subtitle">Tracked ledger total</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Entries</div>
                <div class="stat-value"><?php echo count($entries); ?></div>
                <div class="stat-subtitle">Latest 150 shown</div>
            </div>
        </div>

        <div class="finance-grid">
            <div class="finance-panel">
                <h2>Add Income or Expense</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="finance-form">
                    <?php wp_nonce_field('wnq_save_finance_entry'); ?>
                    <input type="hidden" name="action" value="wnq_save_finance_entry">
                    <div class="finance-field-row">
                        <div class="finance-field">
                            <label for="finance_type">Type</label>
                            <select name="type" id="finance_type">
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                        <div class="finance-field">
                            <label for="finance_recurrence">Frequency</label>
                            <select name="recurrence" id="finance_recurrence">
                                <option value="one_time">One time</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                    </div>
                    <div class="finance-field">
                        <label for="finance_amount">Amount</label>
                        <div class="amount-input">
                            <span>$</span>
                            <input type="number" name="amount" id="finance_amount" min="0.01" step="0.01" required>
                        </div>
                    </div>
                    <div class="finance-field">
                        <label for="finance_date">Start Date</label>
                        <input type="date" name="entry_date" id="finance_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required>
                    </div>
                    <div class="finance-field">
                        <label for="finance_category">Category</label>
                        <input type="text" name="category" id="finance_category" value="" placeholder="Hosting, PPC, Client Payment">
                    </div>
                    <div class="finance-field">
                        <label for="finance_client">Client</label>
                        <select name="client_id" id="finance_client">
                            <option value="">No client</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo esc_attr($client['id']); ?>"><?php echo esc_html($client['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="finance-field">
                        <label for="finance_method">Method</label>
                        <input type="text" name="payment_method" id="finance_method" placeholder="Stripe, ACH, Card, Cash">
                    </div>
                    <div class="finance-field">
                        <label for="finance_description">Notes</label>
                        <textarea name="description" id="finance_description" rows="3"></textarea>
                    </div>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Save Entry</button>
                    </p>
                </form>
            </div>

            <div class="finance-panel">
                <h2>Ledger</h2>
                <?php if (empty($entries)): ?>
                    <p class="text-muted">No income or expenses have been tracked yet.</p>
                <?php else: ?>
                    <div class="finance-table-wrap">
                    <table class="wp-list-table widefat fixed striped finance-table">
                        <thead>
                            <tr>
                                <th style="width: 110px;">Date</th>
                                <th style="width: 90px;">Type</th>
                                <th style="width: 110px;">Frequency</th>
                                <th style="width: 130px;">Amount</th>
                                <th>Category</th>
                                <th>Client</th>
                                <th>Notes</th>
                                <th style="width: 80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html(date('M j, Y', strtotime($entry['entry_date']))); ?></td>
                                    <td><?php echo esc_html(ucfirst($entry['type'])); ?></td>
                                    <td><span class="frequency-badge"><?php echo ($entry['recurrence'] ?? 'one_time') === 'monthly' ? 'Monthly' : 'One time'; ?></span></td>
                                    <td class="<?php echo esc_attr($entry['type']); ?>">
                                        <?php echo $entry['type'] === 'expense' ? '-' : '+'; ?>$<?php echo number_format(floatval($entry['amount']), 2); ?>
                                    </td>
                                    <td><?php echo esc_html($entry['category'] ?: 'Uncategorized'); ?></td>
                                    <td><?php echo esc_html($entry['client_name'] ?: ''); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($entry['description'] ?? '', 12)); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inline-form">
                                            <?php wp_nonce_field('wnq_delete_finance_entry'); ?>
                                            <input type="hidden" name="action" value="wnq_delete_finance_entry">
                                            <input type="hidden" name="id" value="<?php echo esc_attr($entry['id']); ?>">
                                            <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Delete this finance entry?');">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function getGraphData(array $clients): array
    {
        self::ensureFinanceModel();

        if (class_exists('WNQ\\Models\\FinanceEntry')) {
            $finance_data = FinanceEntry::getMonthlyTotals(12);
            $has_entries = array_sum($finance_data['income']) + array_sum($finance_data['expenses']);
            if ($has_entries > 0) {
                return $finance_data;
            }
        }

        $labels = [];
        $income = [];
        $expenses = [];
        $net = [];

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
            
            $income[] = $monthRevenue;
            $expenses[] = max(0, $monthRevenue - $monthAfterFees);
            $net[] = $monthAfterFees;
        }

        return [
            'labels' => $labels,
            'income' => $income,
            'expenses' => $expenses,
            'net' => $net,
        ];
    }

    public static function handleMarkPaid(): void
    {
        check_admin_referer('wnq_mark_paid');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
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

        self::ensureFinanceModel();
        if (class_exists('WNQ\\Models\\FinanceEntry')) {
            FinanceEntry::create([
                'type' => 'income',
                'category' => 'Client Payment',
                'amount' => floatval($client['after_fees'] ?? $client['monthly_rate'] ?? 0),
                'entry_date' => current_time('Y-m-d'),
                'recurrence' => 'one_time',
                'client_id' => intval($client['id']),
                'payment_method' => 'Marked Paid',
                'description' => 'Payment marked paid for ' . ($client['name'] ?? 'client'),
            ]);
        }

        wp_redirect(admin_url('admin.php?page=wnq-clients'));
        exit;
    }

    public static function handleSaveFinanceEntry(): void
    {
        check_admin_referer('wnq_save_finance_entry');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        self::ensureFinanceModel();
        $success = false;

        if (class_exists('WNQ\\Models\\FinanceEntry')) {
            $success = FinanceEntry::create([
                'type' => sanitize_key($_POST['type'] ?? 'income'),
                'amount' => floatval($_POST['amount'] ?? 0),
                'entry_date' => sanitize_text_field($_POST['entry_date'] ?? current_time('Y-m-d')),
                'recurrence' => sanitize_key($_POST['recurrence'] ?? 'one_time'),
                'category' => sanitize_text_field($_POST['category'] ?? ''),
                'client_id' => intval($_POST['client_id'] ?? 0),
                'payment_method' => sanitize_text_field($_POST['payment_method'] ?? ''),
                'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            ]);
        }

        wp_redirect(add_query_arg([
            'page' => 'wnq-clients',
            'tab' => 'finance',
            'message' => $success ? 'finance_saved' : 'finance_error',
        ], admin_url('admin.php')));
        exit;
    }

    public static function handleDeleteFinanceEntry(): void
    {
        check_admin_referer('wnq_delete_finance_entry');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        self::ensureFinanceModel();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if (class_exists('WNQ\\Models\\FinanceEntry') && $id > 0) {
            FinanceEntry::delete($id);
        }

        wp_redirect(add_query_arg([
            'page' => 'wnq-clients',
            'tab' => 'finance',
            'message' => 'finance_deleted',
        ], admin_url('admin.php')));
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
                                <?php $selected_cycle = $client['billing_cycle'] ?? get_option('wnq_default_billing_cycle', 'monthly'); ?>
                                <option value="monthly" <?php selected($selected_cycle, 'monthly'); ?>>Monthly</option>
                                <option value="quarterly" <?php selected($selected_cycle, 'quarterly'); ?>>Quarterly</option>
                                <option value="annually" <?php selected($selected_cycle, 'annually'); ?>>Annually</option>
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

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
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
            'billing_cycle' => sanitize_text_field($_POST['billing_cycle'] ?? get_option('wnq_default_billing_cycle', 'monthly')),
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

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
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
