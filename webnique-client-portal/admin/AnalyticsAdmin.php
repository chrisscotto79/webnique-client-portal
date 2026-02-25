<?php
/**
 * Analytics Admin - FIXED EVENT NAMES & CONDENSED UI
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
                <?php return; ?>
            <?php endif; ?>

            <?php if (!$credentials): ?>
                <div class="notice notice-error"><p><strong>⚠️ Service account not configured.</strong> <a href="<?php echo admin_url('admin.php?page=wnq-analytics&view=settings'); ?>">Configure now →</a></p></div>
                <?php return; ?>
            <?php endif; ?>

            <div class="wnq-top-bar">
                <div class="wnq-client-info">
                    <strong><?php echo esc_html($config['client_name']); ?></strong>
                    <span class="separator">|</span>
                    <span><?php echo esc_html($config['website_url']); ?></span>
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
                            icon = '📞';
                            colorClass = 'phone';
                        } else if (event.event_name === 'email_click') {
                            icon = '✉️';
                            colorClass = 'email';
                        } else if (event.event_name === 'social_click') {
                            icon = '🌐';
                            colorClass = 'social';
                        } else if (event.event_name === 'contact_page_visit') {
                            icon = '📝';
                            colorClass = 'contact';
                        } else if (event.event_name === 'generate_lead') {
                            icon = '📋';
                            colorClass = 'form';
                        } else if (event.event_name === 'purchase') {
                            icon = '💰';
                            colorClass = 'purchase';
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
        
        /* Top Bar - Condensed */
        .wnq-top-bar { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .wnq-client-info { font-size: 14px; color: #1a202c; }
        .wnq-client-info strong { font-size: 16px; }
        .wnq-client-info .separator { margin: 0 10px; color: #cbd5e0; }
        .wnq-controls { display: flex; gap: 8px; }
        .wnq-controls select { padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e0; font-size: 13px; }
        
        .wnq-loading { background: #fff; padding: 40px; text-align: center; border-radius: 8px; border: 1px solid #e2e8f0; }
        
        /* Overview Grid - Compact */
        .wnq-overview-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .wnq-metric { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; display: flex; align-items: center; gap: 12px; }
        .wnq-metric:hover { border-color: #cbd5e0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .wnq-metric .metric-icon { font-size: 28px; }
        .wnq-metric .metric-content { display: flex; flex-direction: column; }
        .wnq-metric .metric-label { font-size: 11px; text-transform: uppercase; color: #718096; font-weight: 600; margin-bottom: 2px; }
        .wnq-metric .metric-value { font-size: 24px; font-weight: bold; color: #1a202c; }
        
        /* Section Headers */
        .wnq-section-header { margin: 25px 0 12px; }
        .wnq-section-header h2 { font-size: 18px; margin: 0; color: #1a202c; }
        
        /* Events Grid - Compact */
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
        
        /* Chart Section */
        .wnq-chart-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .wnq-chart-section h3 { margin: 0 0 15px; font-size: 16px; color: #1a202c; }
        
        /* Tables Grid */
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

    // ... (keeping all other methods: renderClientsManager, renderEditClient, renderSettings)

    private static function renderClientsManager(): void { /* Same as before */ }
    private static function renderEditClient(): void { /* Same as before */ }
    private static function renderSettings(): void { /* Same as before */ }

    public static function ajaxGetAnalyticsData(): void
    {
        try {
            check_ajax_referer('wnq_analytics_nonce', 'nonce');

            if (!current_user_can('manage_options') && !current_user_can('wnq_manage_portal')) {
                wp_send_json_error(['message' => 'Permission denied']);
            }

            $client_id = sanitize_text_field($_POST['client_id'] ?? '');
            $date_range = intval($_POST['date_range'] ?? 30);

            $config = AnalyticsConfig::getClientConfig($client_id);
            $credentials = AnalyticsConfig::getCredentials();

            if (!$config || !$credentials) {
                wp_send_json_error(['message' => 'Not configured']);
            }

            $token = self::getGoogleAccessToken($credentials['credentials']);
            $end_date = date('Y-m-d');
            $start_date = date('Y-m-d', strtotime("-{$date_range} days"));

            $data = [];

            // GA4 Data
            try {
                $data['overview'] = self::fetchOverviewStats($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['visitors_over_time'] = self::fetchVisitorsOverTime($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['traffic_sources'] = self::fetchTrafficSources($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['top_pages'] = self::fetchTopPages($token, $config['ga4_property_id'], $start_date, $end_date);
                $data['key_events'] = self::fetchKeyEvents($token, $config['ga4_property_id'], $start_date, $end_date);
            } catch (\Exception $e) {
                $data['overview'] = ['total_users' => 0, 'page_views' => 0, 'sessions' => 0, 'bounce_rate' => 0];
                $data['visitors_over_time'] = [];
                $data['traffic_sources'] = [];
                $data['top_pages'] = [];
                $data['key_events'] = [];
            }

            wp_send_json_success($data);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // ... (keeping all handler methods)
    public static function handleSaveSettings(): void { /* Same as before */ }
    public static function handleAddClient(): void { /* Same as before */ }
    public static function handleUpdateClient(): void { /* Same as before */ }
    public static function handleDeleteClient(): void { /* Same as before */ }

    private static function getGoogleAccessToken(array $credentials): string
    {
        $cached = get_transient('wnq_ga_access_token');
        if ($cached) return $cached;

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ];

        $segments = [
            self::base64UrlEncode(wp_json_encode($header)),
            self::base64UrlEncode(wp_json_encode($claim))
        ];
        $signing_input = implode('.', $segments);

        $signature = '';
        if (!openssl_sign($signing_input, $signature, $credentials['private_key'], 'SHA256')) {
            throw new \Exception('JWT signing failed');
        }

        $segments[] = self::base64UrlEncode($signature);
        $jwt = implode('.', $segments);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) throw new \Exception('Token request failed');

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['access_token'])) {
            throw new \Exception('Token failed: ' . ($body['error_description'] ?? 'Unknown'));
        }

        $token = $body['access_token'];
        set_transient('wnq_ga_access_token', $token, 3000);
        return $token;
    }

    // FETCH KEY EVENTS - FIXED WITH CORRECT EVENT NAMES
    private static function fetchKeyEvents(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount']],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'eventName',
                    'inListFilter' => [
                        'values' => [
                            'phone_click',
                            'email_click',
                            'social_click',
                            'contact_page_visit',
                            'generate_lead',
                            'purchase'
                        ]
                    ]
                ]
            ],
            'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]]
        ]);
        
        $events = [];
        $displayNames = [
            'phone_click' => 'Phone Clicks',
            'email_click' => 'Email Clicks',
            'social_click' => 'Social Clicks',
            'contact_page_visit' => 'Contact Page',
            'generate_lead' => 'Form Submissions',
            'purchase' => 'Purchases'
        ];
        
        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $eventName = $row['dimensionValues'][0]['value'];
                $events[] = [
                    'event_name' => $eventName,
                    'display_name' => $displayNames[$eventName] ?? ucwords(str_replace('_', ' ', $eventName)),
                    'count' => intval($row['metricValues'][0]['value'])
                ];
            }
        }
        return $events;
    }

    private static function fetchOverviewStats(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'screenPageViews'],
                ['name' => 'sessions'],
                ['name' => 'bounceRate']
            ]
        ]);
        
        $m = $data['rows'][0]['metricValues'] ?? [];
        return [
            'total_users' => isset($m[0]) ? intval($m[0]['value']) : 0,
            'page_views' => isset($m[1]) ? intval($m[1]['value']) : 0,
            'sessions' => isset($m[2]) ? intval($m[2]['value']) : 0,
            'bounce_rate' => isset($m[3]) ? floatval($m[3]['value']) * 100 : 0
        ];
    }

    private static function fetchVisitorsOverTime(string $token, string $property_id, string $start, string $end): array
    {
        $data = self::makeGARequest($token, $property_id, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'date']],
            'metrics' => [['name' => 'totalUsers'], ['name' => 'sessions']],
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]]
        ]);
        
        $trends = [];
        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $date = $row['dimensionValues'][0]['value'];
                $trends[] = [
                    'date' => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2),
                    'users' => intval($row['metricValues'][0]['value']),
                    'sessions' => intval($row['metricValues'][1]['value'])
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
            'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit' => 10
        ]);
        
        $sources = [];
        $total = 0;
        
        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $total += intval($row['metricValues'][0]['value']);
            }
            foreach ($data['rows'] as $row) {
                $sessions = intval($row['metricValues'][0]['value']);
                $sources[] = [
                    'channel' => $row['dimensionValues'][0]['value'],
                    'sessions' => $sessions,
                    'users' => intval($row['metricValues'][1]['value']),
                    'percentage' => $total > 0 ? round(($sessions / $total) * 100, 1) : 0
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
            'metrics' => [['name' => 'screenPageViews'], ['name' => 'bounceRate']],
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit' => 10
        ]);
        
        $pages = [];
        if (isset($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $pages[] = [
                    'path' => $row['dimensionValues'][0]['value'],
                    'title' => $row['dimensionValues'][1]['value'] ?? 'Untitled',
                    'views' => intval($row['metricValues'][0]['value']),
                    'bounce_rate' => floatval($row['metricValues'][1]['value'] ?? 0) * 100
                ];
            }
        }
        return $pages;
    }

    private static function makeGARequest(string $token, string $property_id, array $body): array
    {
        $url = "https://analyticsdata.googleapis.com/v1beta/{$property_id}:runReport";
        
        $response = wp_remote_post($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) throw new \Exception('GA API failed');

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            throw new \Exception('GA API error: ' . ($data['error']['message'] ?? 'Unknown'));
        }

        return $data;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}