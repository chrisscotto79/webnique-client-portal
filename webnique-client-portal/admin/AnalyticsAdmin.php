<?php
/**
 * Analytics Admin - Complete Implementation
 */

namespace WNQ\Admin;

use WNQ\Models\AnalyticsConfig;

if (!defined('ABSPATH')) exit;

final class AnalyticsAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSubmenu'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets'], 999);
    }

    public static function addSubmenu(): void
    {
        $capability = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page('wnq-portal', 'Analytics', 'Analytics', $capability, 'wnq-analytics', [self::class, 'render']);
    }

    public static function enqueueAssets($hook): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wnq-analytics') return;
        wp_enqueue_script('jquery');
    }

    public static function render(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.'));
        }

        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';

        switch ($view) {
            case 'settings':
                self::renderSettings();
                break;
            case 'clients':
                self::renderClientsManager();
                break;
            case 'edit-client':
                self::renderEditClient();
                break;
            default:
                self::renderDashboard();
                break;
        }
    }

    private static function renderDashboard(): void
    {
        $all_clients = AnalyticsConfig::getAllClients();
        $current_client_id = isset($_GET['client']) ? sanitize_text_field($_GET['client']) : '';

        if (empty($current_client_id) && !empty($all_clients)) {
            $current_client_id = $all_clients[0]['client_id'];
        }

        $config = $current_client_id ? AnalyticsConfig::getClientConfig($current_client_id) : null;
        $credentials = AnalyticsConfig::getCredentials();

        ?>
        <div class="wrap wnq-analytics-wrap">
            <h1 style="margin-bottom: 15px;">📊 Analytics Dashboard</h1>

            <?php if (empty($all_clients)): ?>
                <div class="notice notice-warning"><p><strong>No clients configured.</strong> <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=clients'); ?>">Add your first client →</a></p></div>
            <?php elseif (!$credentials): ?>
                <div class="notice notice-error"><p><strong>⚠️ Service account not configured.</strong> <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=settings'); ?>">Configure now →</a></p></div>
            <?php else: ?>

            <div class="wnq-top-bar">
                <div class="wnq-client-info">
                    <strong><?php echo esc_html($config['client_name'] ?? ''); ?></strong>
                    <span class="separator">|</span>
                    <span><?php echo esc_html($config['website_url'] ?? ''); ?></span>
                </div>
                <div class="wnq-controls">
                    <select id="client-selector">
                        <?php foreach ($all_clients as $client): ?>
                            <option value="<?php echo esc_attr($client['client_id']); ?>" <?php selected($client['client_id'], $current_client_id); ?>>
                                <?php echo esc_html($client['client_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="wnq-date-range">
                        <option value="7">Last 7 Days</option>
                        <option value="30" selected>Last 30 Days</option>
                        <option value="90">Last 3 Months</option>
                        <option value="180">Last 6 Months</option>
                        <option value="365">Last Year</option>
                        <option value="730">Last 2 Years</option>
                    </select>
                    <button type="button" id="wnq-refresh-data" class="button button-primary">🔄 Refresh</button>
                    <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=clients'); ?>" class="button">👥 Clients</a>
                    <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=settings'); ?>" class="button">⚙️ Settings</a>
                </div>
            </div>

            <div id="wnq-analytics-loading" class="wnq-loading"><p>⏳ Loading analytics data...</p></div>
            <div id="wnq-analytics-content" style="display: none;"></div>

            <?php endif; ?>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

        <script>
        window.wnqAnalytics = {
            ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('wnq_analytics_nonce'); ?>',
            clientId: '<?php echo esc_js($current_client_id); ?>'
        };

        (function($) {
            let currentChart = null;

            $('#client-selector').on('change', function() {
                window.location.href = '<?php echo admin_url('admin.php?page=wnq-analytics'); ?>&client=' + $(this).val();
            });

            function loadData(days) {
                $('#wnq-analytics-loading').show();
                $('#wnq-analytics-content').hide();
                $('#wnq-refresh-data').prop('disabled', true).text('⏳ Loading...');

                $.ajax({
                    url: wnqAnalytics.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wnq_get_analytics_data',
                        nonce: wnqAnalytics.nonce,
                        client_id: wnqAnalytics.clientId,
                        date_range: days
                    },
                    success: function(resp) {
                        console.log('Response:', resp);
                        if (resp.success) {
                            renderData(resp.data);
                        } else {
                            showError(resp.data?.message || 'Failed to load data');
                        }
                    },
                    error: function(xhr) {
                        console.error('Error:', xhr);
                        showError('Error loading data');
                    },
                    complete: function() {
                        $('#wnq-refresh-data').prop('disabled', false).text('🔄 Refresh');
                    }
                });
            }

            function renderData(data) {
                let html = '<div class="wnq-analytics-dashboard">';

                // Traffic Overview - Compact Grid
                html += '<div class="wnq-overview-grid">';
                html += '<div class="wnq-metric"><span class="metric-icon">👥</span><div class="metric-content"><span class="metric-label">Visitors</span><span class="metric-value">' + (data.overview.total_users || 0).toLocaleString() + '</span></div></div>';
                html += '<div class="wnq-metric"><span class="metric-icon">📄</span><div class="metric-content"><span class="metric-label">Page Views</span><span class="metric-value">' + (data.overview.page_views || 0).toLocaleString() + '</span></div></div>';
                html += '<div class="wnq-metric"><span class="metric-icon">🔄</span><div class="metric-content"><span class="metric-label">Sessions</span><span class="metric-value">' + (data.overview.sessions || 0).toLocaleString() + '</span></div></div>';
                html += '<div class="wnq-metric"><span class="metric-icon">📊</span><div class="metric-content"><span class="metric-label">Bounce Rate</span><span class="metric-value">' + (data.overview.bounce_rate || 0).toFixed(1) + '%</span></div></div>';
                html += '</div>';

                // KEY EVENTS - Compact Cards
                if (data.key_events && data.key_events.length > 0) {
                    html += '<div class="wnq-section-header"><h2>🎯 Key Events</h2></div>';
                    html += '<div class="wnq-events-grid">';

                    data.key_events.forEach(event => {
                        let icon = '📊';
                        let colorClass = 'default';

                        if (event.event_name === 'phone_click') {
                            icon = '📞'; colorClass = 'phone';
                        } else if (event.event_name === 'email_click') {
                            icon = '✉️'; colorClass = 'email';
                        } else if (event.event_name === 'social_click') {
                            icon = '🌐'; colorClass = 'social';
                        } else if (event.event_name === 'contact_page_visit') {
                            icon = '📝'; colorClass = 'contact';
                        } else if (event.event_name === 'generate_lead') {
                            icon = '📋'; colorClass = 'form';
                        } else if (event.event_name === 'purchase') {
                            icon = '💰'; colorClass = 'purchase';
                        }

                        html += '<div class="wnq-event-card ' + colorClass + '">';
                        html += '<span class="event-icon">' + icon + '</span>';
                        html += '<div class="event-content">';
                        html += '<span class="event-label">' + event.display_name + '</span>';
                        html += '<span class="event-count">' + event.count.toLocaleString() + '</span>';
                        html += '</div></div>';
                    });

                    html += '</div>';
                }

                // Visitors Chart
                if (data.visitors_over_time && data.visitors_over_time.length > 0) {
                    html += '<div class="wnq-chart-section">';
                    html += '<h3>📈 Visitors Over Time</h3>';
                    html += '<canvas id="visitors-chart"></canvas>';
                    html += '</div>';
                }

                // Traffic Sources & Top Pages - Side by Side
                html += '<div class="wnq-tables-grid">';

                if (data.traffic_sources && data.traffic_sources.length > 0) {
                    html += '<div class="wnq-table-section">';
                    html += '<h3>🚀 Traffic Sources</h3>';
                    html += '<table class="wnq-compact-table">';
                    html += '<thead><tr><th>Channel</th><th>Sessions</th><th>%</th></tr></thead>';
                    html += '<tbody>';
                    data.traffic_sources.slice(0, 5).forEach(s => {
                        html += '<tr><td>' + s.channel + '</td><td>' + s.sessions.toLocaleString() + '</td><td><strong>' + s.percentage + '%</strong></td></tr>';
                    });
                    html += '</tbody></table>';
                    html += '</div>';
                }

                if (data.top_pages && data.top_pages.length > 0) {
                    html += '<div class="wnq-table-section">';
                    html += '<h3>📄 Top Pages</h3>';
                    html += '<table class="wnq-compact-table">';
                    html += '<thead><tr><th>Page</th><th>Views</th></tr></thead>';
                    html += '<tbody>';
                    data.top_pages.slice(0, 5).forEach(p => {
                        const displayPath = p.path.length > 35 ? p.path.substring(0, 35) + '...' : p.path;
                        html += '<tr><td><code>' + displayPath + '</code></td><td><strong>' + p.views.toLocaleString() + '</strong></td></tr>';
                    });
                    html += '</tbody></table>';
                    html += '</div>';
                }

                html += '</div>';
                html += '</div>';

                $('#wnq-analytics-content').html(html).show();
                $('#wnq-analytics-loading').hide();

                // Render chart
                if (data.visitors_over_time && data.visitors_over_time.length > 0 && typeof Chart !== 'undefined') {
                    const ctx = document.getElementById('visitors-chart');
                    if (ctx) {
                        if (currentChart) currentChart.destroy();
                        currentChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: data.visitors_over_time.map(d => d.date),
                                datasets: [{
                                    label: 'Visitors',
                                    data: data.visitors_over_time.map(d => d.users),
                                    borderColor: '#667eea',
                                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                    tension: 0.4,
                                    fill: true,
                                    borderWidth: 2,
                                    pointRadius: 3,
                                    pointHoverRadius: 5,
                                    pointBackgroundColor: '#667eea',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                aspectRatio: 4,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        backgroundColor: 'rgba(0,0,0,0.8)',
                                        padding: 10,
                                        titleFont: { size: 13 },
                                        bodyFont: { size: 12 }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: { color: 'rgba(0,0,0,0.05)' },
                                        ticks: { font: { size: 11 } }
                                    },
                                    x: {
                                        grid: { display: false },
                                        ticks: { font: { size: 11 } }
                                    }
                                }
                            }
                        });
                    }
                }
            }

            function showError(msg) {
                $('#wnq-analytics-content').html('<div class="notice notice-error"><p>' + msg + '</p></div>').show();
                $('#wnq-analytics-loading').hide();
            }

            $('#wnq-refresh-data').on('click', function() {
                loadData(parseInt($('#wnq-date-range').val()));
            });

            $('#wnq-date-range').on('change', function() {
                loadData(parseInt($(this).val()));
            });

            $(document).ready(function() {
                if (wnqAnalytics.clientId) loadData(30);
            });
        })(jQuery);
        </script>

        <style>
        .wnq-analytics-wrap { max-width: 1400px; }
        .wnq-top-bar { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .wnq-client-info { font-size: 14px; color: #1a202c; }
        .wnq-client-info strong { font-size: 16px; }
        .wnq-client-info .separator { margin: 0 10px; color: #cbd5e0; }
        .wnq-controls { display: flex; gap: 8px; }
        .wnq-controls select { padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e0; font-size: 13px; }
        .wnq-loading { background: #fff; padding: 40px; text-align: center; border-radius: 8px; border: 1px solid #e2e8f0; }
        .wnq-overview-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .wnq-metric { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; display: flex; align-items: center; gap: 12px; }
        .wnq-metric:hover { border-color: #cbd5e0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .wnq-metric .metric-icon { font-size: 28px; }
        .wnq-metric .metric-content { display: flex; flex-direction: column; }
        .wnq-metric .metric-label { font-size: 11px; text-transform: uppercase; color: #718096; font-weight: 600; margin-bottom: 2px; }
        .wnq-metric .metric-value { font-size: 24px; font-weight: bold; color: #1a202c; }
        .wnq-section-header { margin: 25px 0 12px; }
        .wnq-section-header h2 { font-size: 18px; margin: 0; color: #1a202c; }
        .wnq-events-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 25px; }
        .wnq-event-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; display: flex; align-items: center; gap: 12px; transition: all 0.2s; }
        .wnq-event-card:hover { border-color: #cbd5e0; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .wnq-event-card .event-icon { font-size: 32px; }
        .wnq-event-card .event-content { display: flex; flex-direction: column; }
        .wnq-event-card .event-label { font-size: 12px; text-transform: uppercase; color: #718096; font-weight: 600; margin-bottom: 2px; }
        .wnq-event-card .event-count { font-size: 22px; font-weight: bold; color: #1a202c; }
        .wnq-event-card.phone { border-left: 3px solid #3182ce; }
        .wnq-event-card.email { border-left: 3px solid #805ad5; }
        .wnq-event-card.social { border-left: 3px solid #38b2ac; }
        .wnq-event-card.contact { border-left: 3px solid #ed8936; }
        .wnq-event-card.form { border-left: 3px solid #48bb78; }
        .wnq-event-card.purchase { border-left: 3px solid #f56565; }
        .wnq-chart-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .wnq-chart-section h3 { margin: 0 0 15px; font-size: 16px; color: #1a202c; }
        .wnq-tables-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .wnq-table-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; }
        .wnq-table-section h3 { margin: 0 0 15px; font-size: 16px; color: #1a202c; }
        .wnq-compact-table { width: 100%; border-collapse: collapse; }
        .wnq-compact-table thead th { padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; color: #718096; border-bottom: 2px solid #e2e8f0; font-weight: 600; }
        .wnq-compact-table tbody td { padding: 10px; font-size: 13px; color: #4a5568; border-bottom: 1px solid #f7fafc; }
        .wnq-compact-table tbody tr:hover { background: #f7fafc; }
        .wnq-compact-table code { background: #f7fafc; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
        @media (max-width: 1200px) {
            .wnq-overview-grid { grid-template-columns: repeat(2, 1fr); }
            .wnq-tables-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .wnq-overview-grid { grid-template-columns: 1fr; }
            .wnq-events-grid { grid-template-columns: 1fr; }
            .wnq-top-bar { flex-direction: column; gap: 12px; align-items: stretch; }
            .wnq-controls { flex-wrap: wrap; }
        }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // CLIENTS MANAGER
    // -------------------------------------------------------------------------

    private static function renderClientsManager(): void
    {
        // Get analytics-configured clients
        $analytics_clients = AnalyticsConfig::getAllClients();
        $analytics_by_id   = [];
        foreach ($analytics_clients as $ac) {
            $analytics_by_id[$ac['client_id']] = $ac;
        }

        // Also fetch regular portal clients for cross-reference
        global $wpdb;
        $portal_clients = [];
        $clients_table  = $wpdb->prefix . 'wnq_clients';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $clients_table)) === $clients_table) {
            $rows = $wpdb->get_results(
                "SELECT client_id, name, email, website, company FROM {$clients_table} WHERE status != 'deleted' ORDER BY name ASC",
                ARRAY_A
            );
            $portal_clients = $rows ?: [];
        }

        $message = '';
        if (isset($_GET['added'])) {
            $message = '<div class="notice notice-success is-dismissible"><p>Client analytics configured successfully.</p></div>';
        } elseif (isset($_GET['updated'])) {
            $message = '<div class="notice notice-success is-dismissible"><p>Client analytics updated successfully.</p></div>';
        } elseif (isset($_GET['deleted'])) {
            $message = '<div class="notice notice-success is-dismissible"><p>Client analytics configuration removed.</p></div>';
        } elseif (isset($_GET['error'])) {
            $message = '<div class="notice notice-error is-dismissible"><p>Error: ' . esc_html(urldecode($_GET['error'])) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1 style="margin-bottom: 15px;">👥 Analytics Clients</h1>
            <?php echo $message; ?>

            <div style="margin-bottom: 20px; display: flex; gap: 8px; align-items: center;">
                <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add'); ?>" class="button button-primary">+ Add Analytics Client</a>
                <a href="<?php echo admin_url('admin.php?page=wnq-analytics'); ?>" class="button">← Back to Dashboard</a>
                <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=settings'); ?>" class="button">⚙️ Settings</a>
            </div>

            <?php if (empty($analytics_clients) && empty($portal_clients)): ?>
                <div class="notice notice-info">
                    <p>No clients found. <a href="<?php echo admin_url('admin.php?page=wnq-clients'); ?>">Add clients first</a>, then configure analytics for them here.</p>
                </div>

            <?php elseif (!empty($analytics_clients)): ?>
                <h2 style="font-size:16px; margin-bottom:12px;">Configured Analytics Clients</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:180px;">Client Name</th>
                            <th style="width:140px;">Client ID</th>
                            <th>Website</th>
                            <th style="width:180px;">GA4 Property</th>
                            <th style="width:180px;">Search Console</th>
                            <th style="width:160px;">Timezone</th>
                            <th style="width:220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analytics_clients as $client): ?>
                        <tr>
                            <td><strong><?php echo esc_html($client['client_name']); ?></strong></td>
                            <td><code style="font-size:12px;"><?php echo esc_html($client['client_id']); ?></code></td>
                            <td>
                                <?php if (!empty($client['website_url'])): ?>
                                    <a href="<?php echo esc_url($client['website_url']); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html($client['website_url']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:12px;"><?php echo esc_html($client['ga4_property_id'] ?: '—'); ?></code></td>
                            <td style="font-size:12px;"><?php echo esc_html($client['search_console_url'] ?: '—'); ?></td>
                            <td style="font-size:12px;"><?php echo esc_html($client['timezone'] ?: 'America/New_York'); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=edit-client&action=edit&client_id=' . urlencode($client['client_id'])); ?>" class="button button-small">Edit</a>
                                <a href="<?php echo admin_url('admin.php?page=wnq-analytics&client=' . urlencode($client['client_id'])); ?>" class="button button-small">View</a>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline-block;" onsubmit="return confirm('Delete this analytics client? This cannot be undone.');">
                                    <?php wp_nonce_field('wnq_delete_analytics_client', 'wnq_nonce'); ?>
                                    <input type="hidden" name="action" value="wnq_delete_analytics_client">
                                    <input type="hidden" name="client_id" value="<?php echo esc_attr($client['client_id']); ?>">
                                    <button type="submit" class="button button-small" style="color:#a00; border-color:#a00;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#666; font-size:13px; margin-top:12px;">
                    <?php echo count($analytics_clients); ?> analytics client<?php echo count($analytics_clients) !== 1 ? 's' : ''; ?> configured.
                </p>

                <?php
                // Show portal clients that don't have analytics configured yet
                $unconfigured = array_values(array_filter($portal_clients, fn($c) => !isset($analytics_by_id[$c['client_id']])));
                if (!empty($unconfigured)):
                ?>
                <h2 style="font-size:16px; margin:24px 0 12px;">Clients Without Analytics</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th style="width:140px;">Client ID</th>
                            <th>Website</th>
                            <th style="width:180px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unconfigured as $client): ?>
                        <tr>
                            <td><strong><?php echo esc_html($client['name']); ?></strong></td>
                            <td><code style="font-size:12px;"><?php echo esc_html($client['client_id']); ?></code></td>
                            <td><?php echo esc_html($client['website'] ?: '—'); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add&prefill_client_id=' . urlencode($client['client_id']) . '&prefill_name=' . urlencode($client['name'])); ?>" class="button button-small button-primary">Configure Analytics</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

            <?php elseif (!empty($portal_clients)): ?>
                <div class="notice notice-warning">
                    <p><strong>No analytics configured yet.</strong> You have <?php echo count($portal_clients); ?> portal client<?php echo count($portal_clients) !== 1 ? 's' : ''; ?> that can be connected to Google Analytics. <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add'); ?>">Configure analytics →</a></p>
                </div>
                <h2 style="font-size:16px; margin:20px 0 12px;">Available Clients</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th style="width:140px;">Client ID</th>
                            <th>Website</th>
                            <th style="width:180px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($portal_clients as $client): ?>
                        <tr>
                            <td><strong><?php echo esc_html($client['name']); ?></strong></td>
                            <td><code style="font-size:12px;"><?php echo esc_html($client['client_id']); ?></code></td>
                            <td><?php echo esc_html($client['website'] ?: '—'); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add&prefill_client_id=' . urlencode($client['client_id']) . '&prefill_name=' . urlencode($client['name'])); ?>" class="button button-small button-primary">Configure Analytics</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php else: ?>
                <div class="notice notice-info">
                    <p>No analytics clients configured yet. <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add'); ?>">Add your first analytics client →</a></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // EDIT / ADD CLIENT FORM
    // -------------------------------------------------------------------------

    private static function renderEditClient(): void
    {
        $action     = isset($_GET['action'])    ? sanitize_text_field($_GET['action'])    : 'add';
        $client_id  = isset($_GET['client_id']) ? sanitize_text_field($_GET['client_id']) : '';
        $client     = null;

        if ($action === 'edit' && $client_id) {
            $client = AnalyticsConfig::getClientConfig($client_id);
            if (!$client) {
                echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> Client not found.</p></div></div>';
                return;
            }
        }

        // Support pre-filling fields when linking from the clients list
        $prefill_client_id = isset($_GET['prefill_client_id']) ? sanitize_text_field($_GET['prefill_client_id']) : '';
        $prefill_name      = isset($_GET['prefill_name'])      ? sanitize_text_field($_GET['prefill_name'])      : '';

        $form_action = ($action === 'edit') ? 'wnq_update_analytics_client' : 'wnq_add_analytics_client';
        $page_title  = ($action === 'edit') ? '✏️ Edit Analytics Client' : '➕ Add Analytics Client';

        $error_msg = '';
        if (isset($_GET['error'])) {
            $error_msg = '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode($_GET['error'])) . '</p></div>';
        }

        $timezones = \DateTimeZone::listIdentifiers();

        // Prepare phone/form_ids display values
        $phones_display = '';
        if (!empty($client['phone_numbers'])) {
            $phones_display = is_array($client['phone_numbers'])
                ? implode("\n", $client['phone_numbers'])
                : $client['phone_numbers'];
        }
        $form_ids_display = '';
        if (!empty($client['form_ids'])) {
            $form_ids_display = is_array($client['form_ids'])
                ? implode("\n", $client['form_ids'])
                : $client['form_ids'];
        }
        ?>
        <div class="wrap">
            <h1 style="margin-bottom: 15px;"><?php echo esc_html($page_title); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=clients'); ?>" class="button" style="margin-bottom: 20px; display: inline-block;">← Back to Clients</a>
            <?php echo $error_msg; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="max-width: 720px;">
                <?php wp_nonce_field($form_action, 'wnq_nonce'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>">
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="original_client_id" value="<?php echo esc_attr($client_id); ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th style="width:200px;"><label for="client_name">Client Name <span style="color:red;">*</span></label></th>
                        <td>
                            <input type="text" id="client_name" name="client_name" class="regular-text" required
                                value="<?php echo esc_attr($client['client_name'] ?? $prefill_name); ?>"
                                placeholder="e.g. Acme Corp">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="client_id">Client ID <span style="color:red;">*</span></label></th>
                        <td>
                            <input type="text" id="client_id" name="client_id" class="regular-text" required
                                value="<?php echo esc_attr($client['client_id'] ?? $prefill_client_id); ?>"
                                placeholder="e.g. acme-corp"
                                pattern="[a-z0-9\-]+"
                                <?php echo ($action === 'edit') ? 'readonly style="background:#f5f5f5; color:#666;"' : ''; ?>>
                            <p class="description">
                                Lowercase letters, numbers, and hyphens only.
                                <?php if ($action === 'add'): ?>
                                    Auto-fills from Client Name.
                                <?php else: ?>
                                    <strong>Cannot be changed after creation.</strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="website_url">Website URL</label></th>
                        <td>
                            <input type="url" id="website_url" name="website_url" class="regular-text"
                                value="<?php echo esc_attr($client['website_url'] ?? ''); ?>"
                                placeholder="https://example.com">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ga4_property_id">GA4 Property ID</label></th>
                        <td>
                            <input type="text" id="ga4_property_id" name="ga4_property_id" class="regular-text"
                                value="<?php echo esc_attr($client['ga4_property_id'] ?? ''); ?>"
                                placeholder="properties/123456789">
                            <p class="description">
                                Found in Google Analytics → Admin → Property Settings.<br>
                                Format: <code>properties/XXXXXXXXX</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="search_console_url">Search Console URL</label></th>
                        <td>
                            <input type="text" id="search_console_url" name="search_console_url" class="regular-text"
                                value="<?php echo esc_attr($client['search_console_url'] ?? ''); ?>"
                                placeholder="https://example.com/">
                            <p class="description">The exact URL as it appears in Google Search Console (include trailing slash).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="timezone">Timezone</label></th>
                        <td>
                            <select id="timezone" name="timezone" class="regular-text">
                                <?php foreach ($timezones as $tz): ?>
                                    <option value="<?php echo esc_attr($tz); ?>" <?php selected($client['timezone'] ?? 'America/New_York', $tz); ?>>
                                        <?php echo esc_html($tz); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="phone_numbers">Phone Numbers</label></th>
                        <td>
                            <textarea id="phone_numbers" name="phone_numbers" rows="3" class="regular-text"
                                placeholder="One phone number per line&#10;e.g. +15551234567"><?php echo esc_textarea($phones_display); ?></textarea>
                            <p class="description">Used to track phone click events in GA4. One per line.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_ids">Form IDs</label></th>
                        <td>
                            <textarea id="form_ids" name="form_ids" rows="3" class="regular-text"
                                placeholder="One form ID per line&#10;e.g. contact-form-7"><?php echo esc_textarea($form_ids_display); ?></textarea>
                            <p class="description">Used to track form submission events in GA4. One per line.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo ($action === 'edit') ? 'Update Client' : 'Add Client'; ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=clients'); ?>" class="button" style="margin-left: 8px;">Cancel</a>
                </p>
            </form>
        </div>

        <?php if ($action === 'add'): ?>
        <script>
        (function() {
            var nameField = document.getElementById('client_name');
            var idField   = document.getElementById('client_id');
            var autoGen   = true;

            nameField.addEventListener('input', function() {
                if (autoGen) {
                    idField.value = this.value
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+|-+$/g, '');
                }
            });
            idField.addEventListener('input', function() {
                autoGen = (this.value === '');
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // SETTINGS PAGE
    // -------------------------------------------------------------------------

    private static function renderSettings(): void
    {
        $credentials = AnalyticsConfig::getCredentials();

        $message = '';
        if (isset($_GET['saved'])) {
            $message = '<div class="notice notice-success is-dismissible"><p>Credentials saved successfully.</p></div>';
        } elseif (isset($_GET['error'])) {
            $message = '<div class="notice notice-error is-dismissible"><p>Error: ' . esc_html(urldecode($_GET['error'])) . '</p></div>';
        } elseif (isset($_GET['tested'])) {
            if ($_GET['tested'] === 'success') {
                $message = '<div class="notice notice-success is-dismissible"><p>✅ Connection test successful!</p></div>';
            } else {
                $message = '<div class="notice notice-error is-dismissible"><p>❌ Connection test failed. Check your credentials and make sure the service account has access to the GA4 property.</p></div>';
            }
        } elseif (isset($_GET['cache_cleared'])) {
            $message = '<div class="notice notice-success is-dismissible"><p>Analytics cache cleared successfully.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1 style="margin-bottom: 15px;">⚙️ Analytics Settings</h1>
            <a href="<?php echo admin_url('admin.php?page=wnq-analytics'); ?>" class="button" style="margin-bottom: 20px; display: inline-block;">← Back to Dashboard</a>
            <?php echo $message; ?>

            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:24px; max-width:720px; margin-bottom:24px;">
                <h2 style="margin-top:0;">🔑 Google Service Account Credentials</h2>

                <?php if ($credentials): ?>
                    <div style="background:#f0fff4; border:1px solid #9ae6b4; border-radius:6px; padding:16px; margin-bottom:20px;">
                        <strong style="color:#276749;">✅ Credentials Configured</strong><br>
                        <span style="color:#2f855a; font-size:13px;">Service Account: <?php echo esc_html($credentials['email']); ?></span><br>
                        <?php if ($credentials['project_id']): ?>
                            <span style="color:#2f855a; font-size:13px;">Project ID: <?php echo esc_html($credentials['project_id']); ?></span><br>
                        <?php endif; ?>
                        <?php if ($credentials['last_tested']): ?>
                            <span style="color:#4a5568; font-size:12px; margin-top:4px; display:block;">
                                Last tested: <?php echo esc_html($credentials['last_tested']); ?> —
                                Status: <?php echo $credentials['test_status'] === 'success'
                                    ? '<strong style="color:#276749;">Passed</strong>'
                                    : '<strong style="color:#c53030;">Failed</strong>'; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:24px;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('wnq_test_analytics_connection', 'wnq_nonce'); ?>
                            <input type="hidden" name="action" value="wnq_test_analytics_connection">
                            <button type="submit" class="button">🔬 Test Connection</button>
                        </form>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('wnq_clear_analytics_cache', 'wnq_nonce'); ?>
                            <input type="hidden" name="action" value="wnq_clear_analytics_cache">
                            <button type="submit" class="button">🗑️ Clear Cache</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="background:#fff5f5; border:1px solid #feb2b2; border-radius:6px; padding:16px; margin-bottom:20px;">
                        <strong style="color:#9b2c2c;">⚠️ No credentials configured</strong><br>
                        <span style="color:#742a2a; font-size:13px;">Upload your Google service account JSON file below to enable analytics.</span>
                    </div>
                <?php endif; ?>

                <h3 style="margin-top:0;">Upload Service Account JSON</h3>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('wnq_save_analytics_settings', 'wnq_nonce'); ?>
                    <input type="hidden" name="action" value="wnq_save_analytics_settings">
                    <input type="hidden" name="credentials_source" value="file">
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th style="width:160px;"><label for="credentials_file">JSON File</label></th>
                            <td>
                                <input type="file" id="credentials_file" name="credentials_file" accept=".json" required>
                                <p class="description">Download from Google Cloud Console → IAM &amp; Admin → Service Accounts → Keys → Add Key → JSON.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit" style="margin:16px 0 0;">
                        <button type="submit" class="button button-primary">💾 Save Credentials</button>
                    </p>
                </form>

                <details style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:16px;">
                    <summary style="cursor:pointer; color:#4a5568; font-size:13px; font-weight:600;">Or paste JSON directly</summary>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:12px;">
                        <?php wp_nonce_field('wnq_save_analytics_settings', 'wnq_nonce'); ?>
                        <input type="hidden" name="action" value="wnq_save_analytics_settings">
                        <input type="hidden" name="credentials_source" value="text">
                        <textarea name="credentials_json" rows="12"
                            style="width:100%; font-family:monospace; font-size:12px; border:1px solid #cbd5e0; border-radius:4px; padding:8px;"
                            placeholder='{"type":"service_account","project_id":"...","client_email":"...","private_key":"..."}'></textarea>
                        <p class="submit" style="margin:8px 0 0;">
                            <button type="submit" class="button button-primary">💾 Save Credentials</button>
                        </p>
                    </form>
                </details>
            </div>

            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:24px; max-width:720px;">
                <h2 style="margin-top:0;">ℹ️ Setup Instructions</h2>
                <ol style="line-height:1.8; color:#4a5568;">
                    <li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> and create a service account.</li>
                    <li>Enable the <strong>Google Analytics Data API</strong> and <strong>Google Search Console API</strong> for your project.</li>
                    <li>Download a JSON key for the service account and upload it above.</li>
                    <li>In <strong>Google Analytics</strong>, go to Admin → Property Access Management and add the service account email with <em>Viewer</em> role.</li>
                    <li>In <strong>Search Console</strong>, add the service account email as a user for each property.</li>
                    <li>Add your clients under <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=clients'); ?>">Clients</a> with the correct GA4 Property ID and Search Console URL.</li>
                </ol>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: GET ANALYTICS DATA (admin dashboard)
    // -------------------------------------------------------------------------

    public static function ajaxGetAnalyticsData(): void
    {
        try {
            check_ajax_referer('wnq_analytics_nonce', 'nonce');

            $is_admin  = current_user_can('manage_options') || current_user_can('wnq_manage_portal');
            $client_id = sanitize_text_field($_POST['client_id'] ?? '');

            if (!$is_admin) {
                if (!is_user_logged_in()) {
                    wp_send_json_error(['message' => 'Authentication required']);
                    return;
                }
                // Non-admin clients can only access their own analytics data
                $user_client_id = trim((string) get_user_meta(get_current_user_id(), 'wnq_client_id', true));
                if (empty($user_client_id)) {
                    wp_send_json_error(['message' => 'No client account linked to this user']);
                    return;
                }
                $client_id = $user_client_id; // Always use user's own client_id for security
            }

            $date_range = intval($_POST['date_range'] ?? 30);

            $config      = AnalyticsConfig::getClientConfig($client_id);
            $credentials = AnalyticsConfig::getCredentials();

            if (!$config || !$credentials) {
                wp_send_json_error(['message' => 'Analytics not configured for this client']);
                return;
            }

            $token      = self::getGoogleAccessToken($credentials['credentials']);
            $end_date   = date('Y-m-d');
            $start_date = date('Y-m-d', strtotime("-{$date_range} days"));

            $data = [];

            try {
                $data['overview']           = self::fetchOverviewStats($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['visitors_over_time'] = self::fetchVisitorsOverTime($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['traffic_sources']    = self::fetchTrafficSources($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['top_pages']          = self::fetchTopPages($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['key_events']         = self::fetchKeyEvents($token, $config['ga4_property_id'], $start_date, $end_date);
            } catch (\Exception $e) {
                error_log('[WNQ Analytics] Fetch error: ' . $e->getMessage());
                $data['overview']           = ['total_users' => 0, 'page_views' => 0, 'sessions' => 0, 'bounce_rate' => 0];
                $data['visitors_over_time'] = [];
                $data['traffic_sources']    = [];
                $data['top_pages']          = [];
                $data['key_events']         = [];
                $data['error']              = $e->getMessage();
            }

            wp_send_json_success($data);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: GET CLIENT ANALYTICS CONFIG (used by admin UI)
    // -------------------------------------------------------------------------

    public static function ajaxGetClientAnalytics(): void
    {
        check_ajax_referer('wnq_analytics_nonce', 'nonce');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');

        if (empty($client_id)) {
            wp_send_json_error(['message' => 'No client ID provided']);
            return;
        }

        $config = AnalyticsConfig::getClientConfig($client_id);

        if (!$config) {
            wp_send_json_error(['message' => 'Client not found']);
            return;
        }

        // Strip sensitive fields before returning
        unset($config['credentials_json']);

        wp_send_json_success(['config' => $config]);
    }

    // -------------------------------------------------------------------------
    // FORM HANDLERS
    // -------------------------------------------------------------------------

    public static function handleSaveSettings(): void
    {
        check_admin_referer('wnq_save_analytics_settings', 'wnq_nonce');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $credentials_data = null;
        $source = sanitize_text_field($_POST['credentials_source'] ?? 'file');

        if ($source === 'text' && !empty($_POST['credentials_json'])) {
            $json_string      = wp_unslash($_POST['credentials_json']);
            $credentials_data = json_decode($json_string, true);
        } elseif (
            $source === 'file'
            && isset($_FILES['credentials_file'])
            && $_FILES['credentials_file']['error'] === UPLOAD_ERR_OK
        ) {
            $file_content     = file_get_contents($_FILES['credentials_file']['tmp_name']);
            $credentials_data = json_decode($file_content, true);
        }

        if (!$credentials_data || !is_array($credentials_data)) {
            $error = urlencode('Invalid JSON or no file uploaded. Please provide a valid service account JSON.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=settings&error=' . $error));
            exit;
        }

        if (empty($credentials_data['client_email']) || empty($credentials_data['private_key'])) {
            $error = urlencode('Invalid service account JSON: missing client_email or private_key fields.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=settings&error=' . $error));
            exit;
        }

        $result = AnalyticsConfig::saveCredentials($credentials_data);

        if ($result) {
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=settings&saved=1'));
        } else {
            $error = urlencode('Failed to save credentials to the database. Please try again.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=settings&error=' . $error));
        }
        exit;
    }

    public static function handleAddClient(): void
    {
        check_admin_referer('wnq_add_analytics_client', 'wnq_nonce');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $client_id   = sanitize_text_field($_POST['client_id'] ?? '');
        $client_name = sanitize_text_field($_POST['client_name'] ?? '');

        if (empty($client_id) || empty($client_name)) {
            $error = urlencode('Client ID and Client Name are required.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add&error=' . $error));
            exit;
        }

        // Validate client_id format
        if (!preg_match('/^[a-z0-9\-]+$/', $client_id)) {
            $error = urlencode('Client ID may only contain lowercase letters, numbers, and hyphens.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add&error=' . $error));
            exit;
        }

        $phone_lines   = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['phone_numbers'] ?? '')))));
        $form_id_lines = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['form_ids'] ?? '')))));

        $config = [
            'client_id'          => $client_id,
            'client_name'        => $client_name,
            'ga4_property_id'    => sanitize_text_field($_POST['ga4_property_id'] ?? ''),
            'search_console_url' => sanitize_text_field($_POST['search_console_url'] ?? ''),
            'website_url'        => esc_url_raw($_POST['website_url'] ?? ''),
            'timezone'           => sanitize_text_field($_POST['timezone'] ?? 'America/New_York'),
            'phone_numbers'      => $phone_lines,
            'form_ids'           => $form_id_lines,
        ];

        $result = AnalyticsConfig::saveClientConfig($config);

        if ($result !== false) {
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=clients&added=1'));
        } else {
            $error = urlencode('Failed to save client. The Client ID may already exist.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=edit-client&action=add&error=' . $error));
        }
        exit;
    }

    public static function handleUpdateClient(): void
    {
        check_admin_referer('wnq_update_analytics_client', 'wnq_nonce');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $original_client_id = sanitize_text_field($_POST['original_client_id'] ?? '');

        if (empty($original_client_id)) {
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=clients'));
            exit;
        }

        $client_name = sanitize_text_field($_POST['client_name'] ?? '');
        if (empty($client_name)) {
            $error = urlencode('Client Name is required.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=edit-client&action=edit&client_id=' . urlencode($original_client_id) . '&error=' . $error));
            exit;
        }

        $phone_lines   = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['phone_numbers'] ?? '')))));
        $form_id_lines = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['form_ids'] ?? '')))));

        $config = [
            'client_id'          => $original_client_id,
            'client_name'        => $client_name,
            'ga4_property_id'    => sanitize_text_field($_POST['ga4_property_id'] ?? ''),
            'search_console_url' => sanitize_text_field($_POST['search_console_url'] ?? ''),
            'website_url'        => esc_url_raw($_POST['website_url'] ?? ''),
            'timezone'           => sanitize_text_field($_POST['timezone'] ?? 'America/New_York'),
            'phone_numbers'      => $phone_lines,
            'form_ids'           => $form_id_lines,
        ];

        $result = AnalyticsConfig::saveClientConfig($config);

        if ($result !== false) {
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=clients&updated=1'));
        } else {
            $error = urlencode('Failed to update client. Please try again.');
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=edit-client&action=edit&client_id=' . urlencode($original_client_id) . '&error=' . $error));
        }
        exit;
    }

    public static function handleDeleteClient(): void
    {
        check_admin_referer('wnq_delete_analytics_client', 'wnq_nonce');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');

        if (empty($client_id)) {
            wp_redirect(admin_url('admin.php?page=wnq-analytics&view=clients'));
            exit;
        }

        AnalyticsConfig::deleteClient($client_id);

        wp_redirect(admin_url('admin.php?page=wnq-analytics&view=clients&deleted=1'));
        exit;
    }

    public static function handleTestConnection(): void
    {
        check_admin_referer('wnq_test_analytics_connection', 'wnq_nonce');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        // Use the first configured client for the test
        $all_clients = AnalyticsConfig::getAllClients();
        $client_id   = !empty($all_clients) ? $all_clients[0]['client_id'] : '';

        $result = AnalyticsConfig::testConnection($client_id);
        $status = $result['success'] ? 'success' : 'failed';

        wp_redirect(admin_url('admin.php?page=wnq-analytics&view=settings&tested=' . $status));
        exit;
    }

    public static function handleClearCache(): void
    {
        check_admin_referer('wnq_clear_analytics_cache', 'wnq_nonce');

        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        // Clear the shared GA access token
        delete_transient('wnq_ga_access_token');

        // Clear all analytics data transients
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_wnq_analytics_%'
                OR option_name LIKE '_transient_timeout_wnq_analytics_%'"
        );

        wp_redirect(admin_url('admin.php?page=wnq-analytics&view=settings&cache_cleared=1'));
        exit;
    }

    // -------------------------------------------------------------------------
    // GOOGLE API HELPERS
    // -------------------------------------------------------------------------

    private static function getGoogleAccessToken(array $credentials): string
    {
        $cached = get_transient('wnq_ga_access_token');
        if ($cached) return $cached;

        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim  = [
            'iss'   => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $segments      = [
            self::base64UrlEncode(wp_json_encode($header)),
            self::base64UrlEncode(wp_json_encode($claim)),
        ];
        $signing_input = implode('.', $segments);

        $signature = '';
        if (!openssl_sign($signing_input, $signature, $credentials['private_key'], 'SHA256')) {
            throw new \Exception('JWT signing failed. Check the private_key in your service account JSON.');
        }

        $segments[] = self::base64UrlEncode($signature);
        $jwt        = implode('.', $segments);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body'    => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Token request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['access_token'])) {
            throw new \Exception('Token failed: ' . ($body['error_description'] ?? $body['error'] ?? 'Unknown'));
        }

        $token = $body['access_token'];
        set_transient('wnq_ga_access_token', $token, 3000);
        return $token;
    }

    private static function fetchKeyEvents(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'eventName']],
            'metrics'    => [['name' => 'eventCount']],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName'    => 'eventName',
                    'inListFilter' => [
                        'values' => [
                            'phone_click',
                            'email_click',
                            'social_click',
                            'contact_page_visit',
                            'generate_lead',
                            'purchase',
                        ],
                    ],
                ],
            ],
            'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]],
        ]);

        $displayNames = [
            'phone_click'         => 'Phone Clicks',
            'email_click'         => 'Email Clicks',
            'social_click'        => 'Social Clicks',
            'contact_page_visit'  => 'Contact Page',
            'generate_lead'       => 'Form Submissions',
            'purchase'            => 'Purchases',
        ];

        $events = [];
        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $eventName = $row['dimensionValues'][0]['value'];
                $events[]  = [
                    'event_name'   => $eventName,
                    'display_name' => $displayNames[$eventName] ?? ucwords(str_replace('_', ' ', $eventName)),
                    'count'        => intval($row['metricValues'][0]['value']),
                ];
            }
        }
        return $events;
    }

    private static function fetchOverviewStats(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'metrics'    => [
                ['name' => 'totalUsers'],
                ['name' => 'screenPageViews'],
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
            ],
        ]);

        $m = $data['rows'][0]['metricValues'] ?? [];
        return [
            'total_users' => isset($m[0]) ? intval($m[0]['value'])   : 0,
            'page_views'  => isset($m[1]) ? intval($m[1]['value'])   : 0,
            'sessions'    => isset($m[2]) ? intval($m[2]['value'])   : 0,
            'bounce_rate' => isset($m[3]) ? floatval($m[3]['value']) * 100 : 0,
        ];
    }

    private static function fetchVisitorsOverTime(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'date']],
            'metrics'    => [['name' => 'totalUsers'], ['name' => 'sessions']],
            'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
        ]);

        $trends = [];
        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $date     = $row['dimensionValues'][0]['value'];
                $trends[] = [
                    'date'     => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2),
                    'users'    => intval($row['metricValues'][0]['value']),
                    'sessions' => intval($row['metricValues'][1]['value']),
                ];
            }
        }
        return $trends;
    }

    private static function fetchTrafficSources(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'totalUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => 10,
        ]);

        $sources = [];
        $total   = 0;

        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $total += intval($row['metricValues'][0]['value']);
            }
            foreach ($data['rows'] as $row) {
                $sessions  = intval($row['metricValues'][0]['value']);
                $sources[] = [
                    'channel'    => $row['dimensionValues'][0]['value'],
                    'sessions'   => $sessions,
                    'users'      => intval($row['metricValues'][1]['value']),
                    'percentage' => $total > 0 ? round(($sessions / $total) * 100, 1) : 0,
                ];
            }
        }
        return $sources;
    }

    private static function fetchTopPages(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics'    => [['name' => 'screenPageViews'], ['name' => 'bounceRate']],
            'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit'      => 10,
        ]);

        $pages = [];
        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $pages[] = [
                    'path'        => $row['dimensionValues'][0]['value'],
                    'title'       => $row['dimensionValues'][1]['value'] ?? 'Untitled',
                    'views'       => intval($row['metricValues'][0]['value']),
                    'bounce_rate' => floatval($row['metricValues'][1]['value'] ?? 0) * 100,
                ];
            }
        }
        return $pages;
    }

    private static function makeGARequest(string $token, string $property_id, array $body): array
    {
        $url = "https://analyticsdata.googleapis.com/v1beta/{$property_id}:runReport";

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('GA API request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            throw new \Exception('GA API error ' . $code . ': ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
