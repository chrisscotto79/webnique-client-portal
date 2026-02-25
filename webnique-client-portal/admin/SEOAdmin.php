<?php
/**
 * SEO Admin - PRODUCTION READY - COMPLETE
 * 
 * Features:
 * - Full task lists from operations manual (200+ tasks)
 * - Bulk import buttons per service type
 * - Monthly Tasks tab with month selector
 * - Checkbox/uncheck functionality
 * - Add/delete custom tasks
 * - Task grouping
 * - Client website links
 * 
 * @package WebNique Portal
 */

namespace WNQ\Admin;

use WNQ\Models\SEO;
use WNQ\Models\Client;

if (!defined('ABSPATH')) {
    exit;
}

final class SEOAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSubmenu'], 25);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function addSubmenu(): void
    {
        $capability = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';

        add_submenu_page(
            'wnq-portal',
            'SEO Tracking',
            'SEO Tracking',
            $capability,
            'wnq-seo',
            [self::class, 'render']
        );
    }

    public static function enqueueAssets($hook): void
    {
        if ($hook !== 'webnique-portal_page_wnq-seo') {
            return;
        }
        wp_enqueue_script('jquery');
    }

    public static function render(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'overview';
        $client_id = isset($_GET['client']) ? sanitize_text_field($_GET['client']) : '';

        switch ($view) {
            case 'client':
                if ($client_id) {
                    self::renderClientView($client_id);
                } else {
                    self::renderOverview();
                }
                break;
            case 'report':
                self::renderReportView($client_id);
                break;
            default:
                self::renderOverview();
                break;
        }
    }

    private static function renderOverview(): void
    {
        $clients = Client::getAll();
        $all_stats = [];
        foreach ($clients as $client) {
            // Get progress for ONE-TIME tasks only (month_year IS NULL)
            $progress = SEO::getOverallProgress($client['client_id'], null);
            $all_stats[$client['client_id']] = $progress;
        }

        ?>
        <div class="wrap wnq-seo-premium">
            <div class="seo-header">
                <div class="header-content">
                    <h1>🚀 SEO Tracking Dashboard</h1>
                    <p class="subtitle">Monitor SEO progress across all clients</p>
                </div>
            </div>

            <div class="seo-stats-grid">
                <?php
                $total_tasks = 0;
                $total_completed = 0;
                $active_clients = 0;
                
                foreach ($all_stats as $stats) {
                    $total_tasks += $stats['total_tasks'];
                    $total_completed += $stats['completed_tasks'];
                    if ($stats['total_tasks'] > 0) $active_clients++;
                }
                ?>
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $active_clients; ?></div>
                        <div class="stat-label">Active Clients</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $total_tasks; ?></div>
                        <div class="stat-label">Total Tasks</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $total_completed; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $total_tasks > 0 ? round(($total_completed / $total_tasks) * 100) : 0; ?>%</div>
                        <div class="stat-label">Completion Rate</div>
                    </div>
                </div>
            </div>

            <div class="clients-seo-grid">
                <?php foreach ($clients as $client): 
                    $stats = $all_stats[$client['client_id']];
                    $progress_pct = $stats['completion_percentage'];
                    
                    $website_url = $client['website_url'] ?? '';
                    if (!empty($website_url) && !preg_match('/^https?:\/\//', $website_url)) {
                        $website_url = 'https://' . $website_url;
                    }
                    $admin_url = $website_url ? rtrim($website_url, '/') . '/wp-admin' : '';
                ?>
                    <div class="client-seo-card">
                        <div class="client-card-header">
                            <div class="client-info">
                                <h3>
                                    <?php if ($website_url): ?>
                                        <a href="<?php echo esc_url($website_url); ?>" target="_blank" class="client-link">
                                            <?php echo esc_html($client['name']); ?> 🔗
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($client['name']); ?>
                                    <?php endif; ?>
                                </h3>
                                <?php if ($admin_url): ?>
                                    <a href="<?php echo esc_url($admin_url); ?>" target="_blank" class="admin-link">
                                        🔧 WP Admin
                                    </a>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($client['client_id'])); ?>" 
                               class="btn-manage">
                                Manage SEO →
                            </a>
                        </div>

                        <div class="progress-section">
                            <div class="progress-label">
                                <span>Overall Progress</span>
                                <span class="progress-pct"><?php echo $progress_pct; ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progress_pct; ?>%"></div>
                            </div>
                        </div>

                        <div class="task-stats">
                            <div class="task-stat">
                                <span class="stat-count"><?php echo $stats['completed_tasks']; ?></span>
                                <span class="stat-name">Completed</span>
                            </div>
                            <div class="task-stat">
                                <span class="stat-count"><?php echo $stats['in_progress_tasks']; ?></span>
                                <span class="stat-name">In Progress</span>
                            </div>
                            <div class="task-stat">
                                <span class="stat-count"><?php echo $stats['pending_tasks']; ?></span>
                                <span class="stat-name">Pending</span>
                            </div>
                        </div>

                        <?php if ($stats['total_tasks'] === 0): ?>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="init-form">
                                <?php wp_nonce_field('wnq_init_seo_client'); ?>
                                <input type="hidden" name="action" value="wnq_init_seo_client">
                                <input type="hidden" name="client_id" value="<?php echo esc_attr($client['client_id']); ?>">
                                <button type="submit" class="btn-init">
                                    ✨ Initialize SEO Tasks
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php self::renderStyles(); ?>
        <?php
    }

    private static function renderClientView(string $client_id): void
    {
        $all_clients = Client::getAll();
        $client = null;
        foreach ($all_clients as $c) {
            if ($c['client_id'] === $client_id) {
                $client = $c;
                break;
            }
        }
        
        if (!$client) {
            wp_die('Client not found');
        }

        $website_url = $client['website_url'] ?? '';
        if (!empty($website_url) && !preg_match('/^https?:\/\//', $website_url)) {
            $website_url = 'https://' . $website_url;
        }
        $admin_url = $website_url ? rtrim($website_url, '/') . '/wp-admin' : '';

        $selected_service = isset($_GET['service']) ? sanitize_text_field($_GET['service']) : 'onpage';
        $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');

        // For monthly tasks, filter by month
        if ($selected_service === 'monthly') {
            $tasks = SEO::getTasksByClient($client_id, $selected_service, $selected_month);
        } else {
            $tasks = SEO::getTasksByClient($client_id, $selected_service, null);
        }

        $progress = SEO::getOverallProgress($client_id, $selected_service === 'monthly' ? $selected_month : null);
        $service_progress = SEO::getServiceTypeProgress($client_id, $selected_service === 'monthly' ? $selected_month : null);

        $tasks_by_group = [];
        foreach ($tasks as $task) {
            $group = $task['task_group'] ?: 'General';
            if (!isset($tasks_by_group[$group])) {
                $tasks_by_group[$group] = [];
            }
            $tasks_by_group[$group][] = $task;
        }

        $service_types = [
            'onpage' => ['name' => 'On-Page SEO', 'icon' => '📝', 'color' => '#667eea'],
            'technical' => ['name' => 'Technical SEO', 'icon' => '⚙️', 'color' => '#10b981'],
            'local' => ['name' => 'Local SEO', 'icon' => '📍', 'color' => '#f59e0b'],
            'offpage' => ['name' => 'Off-Page SEO', 'icon' => '🔗', 'color' => '#8b5cf6'],
            'monthly' => ['name' => 'Monthly Tasks', 'icon' => '📅', 'color' => '#ec4899'],
        ];

        $current_service = $service_types[$selected_service] ?? $service_types['onpage'];

        ?>
        <div class="wrap wnq-seo-premium">
            <div class="seo-header">
                <div class="header-content">
                    <a href="<?php echo admin_url('admin.php?page=wnq-seo'); ?>" class="back-link">← Back</a>
                    <h1>🚀 <?php echo esc_html($client['name']); ?> - SEO Tracking</h1>
                    <?php if ($website_url || $admin_url): ?>
                        <div class="client-links-header">
                            <?php if ($website_url): ?>
                                <a href="<?php echo esc_url($website_url); ?>" target="_blank" class="header-link">🔗 Visit Site</a>
                            <?php endif; ?>
                            <?php if ($admin_url): ?>
                                <a href="<?php echo esc_url($admin_url); ?>" target="_blank" class="header-link">🔧 WP Admin</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="progress-overview">
                <div class="progress-card">
                    <div class="progress-header">
                        <h3><?php echo $selected_service === 'monthly' ? 'Monthly Progress' : 'Overall Progress'; ?></h3>
                        <span class="progress-percentage"><?php echo $progress['completion_percentage']; ?>%</span>
                    </div>
                    <div class="progress-bar-large">
                        <div class="progress-fill-large" style="width: <?php echo $progress['completion_percentage']; ?>%"></div>
                    </div>
                    <div class="progress-stats">
                        <div class="stat-item">
                            <strong><?php echo $progress['completed_tasks']; ?></strong>
                            <span>Completed</span>
                        </div>
                        <div class="stat-item">
                            <strong><?php echo $progress['in_progress_tasks']; ?></strong>
                            <span>In Progress</span>
                        </div>
                        <div class="stat-item">
                            <strong><?php echo $progress['pending_tasks']; ?></strong>
                            <span>Pending</span>
                        </div>
                        <div class="stat-item">
                            <strong><?php echo $progress['total_tasks']; ?></strong>
                            <span>Total Tasks</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="service-tabs">
                <?php foreach ($service_types as $type => $info): 
                    $type_data = null;
                    foreach ($service_progress as $sp) {
                        if ($sp['service_type'] === $type) {
                            $type_data = $sp;
                            break;
                        }
                    }
                    $total = $type_data ? intval($type_data['total']) : 0;
                    $completed = $type_data ? intval($type_data['completed']) : 0;
                    $is_active = $selected_service === $type;
                    
                    $tab_url = admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($client_id) . '&service=' . $type);
                    if ($type === 'monthly') {
                        $tab_url .= '&month=' . $selected_month;
                    }
                ?>
                    <a href="<?php echo $tab_url; ?>" 
                       class="service-tab <?php echo $is_active ? 'active' : ''; ?>"
                       style="border-color: <?php echo $info['color']; ?>">
                        <span class="tab-icon"><?php echo $info['icon']; ?></span>
                        <span class="tab-name"><?php echo $info['name']; ?></span>
                        <span class="tab-count"><?php echo $completed; ?>/<?php echo $total; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($selected_service === 'monthly'): ?>
                <div class="monthly-controls">
                    <div class="month-selector-wrapper">
                        <label for="month-selector">Select Month:</label>
                        <select id="month-selector" class="month-selector-inline">
                            <?php for ($i = 0; $i < 12; $i++): 
                                $month = date('Y-m', strtotime("-$i months"));
                                $label = date('F Y', strtotime($month . '-01'));
                            ?>
                                <option value="<?php echo $month; ?>" <?php selected($selected_month, $month); ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <?php if (empty($tasks)): ?>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                            <?php wp_nonce_field('wnq_init_monthly_tasks'); ?>
                            <input type="hidden" name="action" value="wnq_init_monthly_tasks">
                            <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                            <input type="hidden" name="month_year" value="<?php echo esc_attr($selected_month); ?>">
                            <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                            <button type="submit" class="btn-init-month">
                                📅 Initialize <?php echo date('F Y', strtotime($selected_month . '-01')); ?> Tasks
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($tasks_by_group)): ?>
                <div class="task-section">
                    <div class="task-section-header" style="border-left-color: <?php echo $current_service['color']; ?>">
                        <div class="section-title">
                            <span class="section-icon"><?php echo $current_service['icon']; ?></span>
                            <h3><?php echo $current_service['name']; ?></h3>
                            <?php if ($selected_service === 'monthly'): ?>
                                <span class="month-badge"><?php echo date('F Y', strtotime($selected_month . '-01')); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="task-count"><?php echo count($tasks); ?> tasks</span>
                    </div>

                    <?php if (empty($tasks) && $selected_service !== 'monthly'): ?>
                        <div class="bulk-import-banner">
                            <div class="banner-content">
                                <div class="banner-icon">📦</div>
                                <div class="banner-text">
                                    <h4>No tasks yet for <?php echo $current_service['name']; ?></h4>
                                    <p>Import all standard <?php echo strtolower($current_service['name']); ?> tasks at once</p>
                                </div>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <?php wp_nonce_field('wnq_bulk_import_seo'); ?>
                                    <input type="hidden" name="action" value="wnq_bulk_import_seo">
                                    <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                                    <input type="hidden" name="service_type" value="<?php echo esc_attr($selected_service); ?>">
                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                                    <button type="submit" class="btn-bulk-import">
                                        📦 Bulk Import All Tasks
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($tasks_by_group as $group_name => $group_tasks): ?>
                        <div class="task-group">
                            <div class="task-group-header-wrapper">
                                <h4 class="task-group-header">
                                    📁 <?php echo esc_html($group_name); ?>
                                    <span class="group-task-count"><?php echo count($group_tasks); ?> tasks</span>
                                </h4>
                                <button 
                                    onclick="toggleAddTask('<?php echo esc_js($selected_service); ?>', '<?php echo esc_js($group_name); ?>')" 
                                    class="btn-add-task">
                                    + Add Task
                                </button>
                            </div>

                            <form 
                                method="post" 
                                action="<?php echo admin_url('admin-post.php'); ?>" 
                                class="quick-add-task-form" 
                                id="add-task-<?php echo esc_attr($selected_service . '-' . sanitize_title($group_name)); ?>"
                                style="display: none;">
                                <?php wp_nonce_field('wnq_add_seo_task'); ?>
                                <input type="hidden" name="action" value="wnq_add_seo_task">
                                <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                                <input type="hidden" name="service_type" value="<?php echo esc_attr($selected_service); ?>">
                                <input type="hidden" name="task_group" value="<?php echo esc_attr($group_name); ?>">
                                <input type="hidden" name="month_year" value="<?php echo $selected_service === 'monthly' ? esc_attr($selected_month) : ''; ?>">
                                <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                                
                                <div class="quick-add-inputs">
                                    <input 
                                        type="text" 
                                        name="task_name" 
                                        placeholder="Enter task name..." 
                                        class="quick-add-input"
                                        required>
                                    <button type="submit" class="btn-save-task">Save</button>
                                    <button 
                                        type="button" 
                                        onclick="toggleAddTask('<?php echo esc_js($selected_service); ?>', '<?php echo esc_js($group_name); ?>')" 
                                        class="btn-cancel-task">
                                        Cancel
                                    </button>
                                </div>
                            </form>

                            <div class="tasks-list">
                                <?php foreach ($group_tasks as $task): ?>
                                    <div class="task-item task-<?php echo esc_attr($task['status']); ?>">
                                        <div class="task-checkbox">
                                            <?php if ($task['status'] === 'completed'): ?>
                                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                                    <?php wp_nonce_field('wnq_uncomplete_seo_task'); ?>
                                                    <input type="hidden" name="action" value="wnq_uncomplete_seo_task">
                                                    <input type="hidden" name="task_id" value="<?php echo esc_attr($task['id']); ?>">
                                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                                                    <button type="submit" class="checkbox-btn completed" title="Click to uncheck">✅</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                                    <?php wp_nonce_field('wnq_complete_seo_task'); ?>
                                                    <input type="hidden" name="action" value="wnq_complete_seo_task">
                                                    <input type="hidden" name="task_id" value="<?php echo esc_attr($task['id']); ?>">
                                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                                                    <button type="submit" class="checkbox-btn">☐</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div class="task-content">
                                            <div class="task-name"><?php echo esc_html($task['task_name']); ?></div>
                                            <?php if (!empty($task['task_description'])): ?>
                                                <div class="task-description"><?php echo esc_html($task['task_description']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($task['notes'])): ?>
                                                <div class="task-notes">📝 <?php echo esc_html($task['notes']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($task['status'] === 'completed' && !empty($task['completed_date'])): ?>
                                                <div class="task-completed-date">
                                                    Completed: <?php echo date('M j, Y g:i A', strtotime($task['completed_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="task-actions">
                                            <?php if ($task['status'] !== 'completed'): ?>
                                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                                    <?php wp_nonce_field('wnq_update_seo_task'); ?>
                                                    <input type="hidden" name="action" value="wnq_update_seo_task">
                                                    <input type="hidden" name="task_id" value="<?php echo esc_attr($task['id']); ?>">
                                                    <input type="hidden" name="status" value="in_progress">
                                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                                                    <button type="submit" class="btn-action">Start</button>
                                                </form>
                                            <?php endif; ?>
                                            <button onclick="editTaskNotes(<?php echo $task['id']; ?>)" class="btn-action">Notes</button>
                                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                                <?php wp_nonce_field('wnq_delete_seo_task'); ?>
                                                <input type="hidden" name="action" value="wnq_delete_seo_task">
                                                <input type="hidden" name="task_id" value="<?php echo esc_attr($task['id']); ?>">
                                                <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                                                <button type="submit" class="btn-action btn-delete" onclick="return confirm('Delete this task?')">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($selected_service !== 'monthly'): ?>
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <h3>No <?php echo $current_service['name']; ?> Tasks Yet</h3>
                    <p>Use bulk import to add all standard tasks at once</p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('wnq_bulk_import_seo'); ?>
                        <input type="hidden" name="action" value="wnq_bulk_import_seo">
                        <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                        <input type="hidden" name="service_type" value="<?php echo esc_attr($selected_service); ?>">
                        <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                        <button type="submit" class="btn-primary btn-large">
                            📦 Bulk Import All <?php echo $current_service['name']; ?> Tasks
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php self::renderStyles(); ?>
        
        <script>
        jQuery(document).ready(function($) {
            $('#month-selector').on('change', function() {
                const url = new URL(window.location.href);
                url.searchParams.set('month', $(this).val());
                window.location.href = url.toString();
            });
        });

        function editTaskNotes(taskId) {
            const notes = prompt('Add notes for this task:');
            if (notes !== null) {
                const form = document.createElement('form');
                form.method = 'post';
                form.action = '<?php echo admin_url('admin-post.php'); ?>';
                
                const fields = {
                    'action': 'wnq_update_seo_task',
                    'task_id': taskId,
                    'notes': notes,
                    'redirect_url': window.location.href,
                    '_wpnonce': '<?php echo wp_create_nonce('wnq_update_seo_task'); ?>'
                };
                
                for (const [key, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function toggleAddTask(serviceType, groupName) {
            const formId = 'add-task-' + serviceType + '-' + groupName.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
            const form = document.getElementById(formId);
            
            if (form) {
                if (form.style.display === 'none') {
                    form.style.display = 'block';
                    form.querySelector('input[name="task_name"]').focus();
                } else {
                    form.style.display = 'none';
                    form.querySelector('input[name="task_name"]').value = '';
                }
            }
        }
        </script>
        <?php
    }

    private static function renderReportView(string $client_id): void
    {
        $all_clients = Client::getAll();
        $client = null;
        foreach ($all_clients as $c) {
            if ($c['client_id'] === $client_id) {
                $client = $c;
                break;
            }
        }
        
        if (!$client) {
            wp_die('Client not found');
        }

        $current_month = date('Y-m');
        $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : $current_month;
        $report = SEO::getReport($client_id, $selected_month);
        $progress = SEO::getOverallProgress($client_id, $selected_month);

        ?>
        <div class="wrap wnq-seo-premium">
            <div class="seo-header">
                <div class="header-content">
                    <a href="<?php echo admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($client_id)); ?>" class="back-link">← Back</a>
                    <h1>📊 Monthly SEO Report</h1>
                    <p class="subtitle"><?php echo esc_html($client['name']); ?> - <?php echo date('F Y', strtotime($selected_month . '-01')); ?></p>
                </div>
                <div class="header-actions">
                    <select id="month-selector" class="month-selector">
                        <?php for ($i = 0; $i < 12; $i++): 
                            $month = date('Y-m', strtotime("-$i months"));
                            $label = date('F Y', strtotime($month . '-01'));
                        ?>
                            <option value="<?php echo $month; ?>" <?php selected($selected_month, $month); ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="report-form">
                <?php wp_nonce_field('wnq_save_seo_report'); ?>
                <input type="hidden" name="action" value="wnq_save_seo_report">
                <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                <input type="hidden" name="month_year" value="<?php echo esc_attr($selected_month); ?>">

                <div class="report-section">
                    <h3>📈 Key Metrics</h3>
                    <div class="metrics-grid">
                        <div class="metric-input">
                            <label>Keywords Tracked</label>
                            <input type="number" name="keywords_tracked" value="<?php echo esc_attr($report['keywords_tracked'] ?? '0'); ?>" class="regular-text">
                        </div>
                        <div class="metric-input">
                            <label>Average Position</label>
                            <input type="number" step="0.01" name="avg_position" value="<?php echo esc_attr($report['avg_position'] ?? '0'); ?>" class="regular-text">
                        </div>
                        <div class="metric-input">
                            <label>Organic Traffic</label>
                            <input type="number" name="organic_traffic" value="<?php echo esc_attr($report['organic_traffic'] ?? '0'); ?>" class="regular-text">
                        </div>
                        <div class="metric-input">
                            <label>Backlinks Added</label>
                            <input type="number" name="backlinks_added" value="<?php echo esc_attr($report['backlinks_added'] ?? '0'); ?>" class="regular-text">
                        </div>
                    </div>
                </div>

                <div class="report-section">
                    <h3>🔗 Report URL</h3>
                    <input type="url" name="report_url" value="<?php echo esc_attr($report['report_url'] ?? ''); ?>" class="large-text" placeholder="https://docs.google.com/...">
                    <p class="description">Link to the full monthly SEO report</p>
                </div>

                <div class="report-section">
                    <h3>📝 Monthly Summary</h3>
                    <textarea name="summary" rows="6" class="large-text" placeholder="Summarize this month's SEO work..."><?php echo esc_textarea($report['summary'] ?? ''); ?></textarea>
                </div>

                <div class="report-section">
                    <h3>✅ Task Completion</h3>
                    <div class="completion-stats">
                        <div class="stat-box">
                            <div class="stat-number"><?php echo $progress['completed_tasks']; ?>/<?php echo $progress['total_tasks']; ?></div>
                            <div class="stat-label">Tasks Completed</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number"><?php echo $progress['completion_percentage']; ?>%</div>
                            <div class="stat-label">Completion Rate</div>
                        </div>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">💾 Save Report</button>
                    <a href="<?php echo admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($client_id)); ?>" class="button button-large">Cancel</a>
                </p>
            </form>
        </div>

        <?php self::renderStyles(); ?>
        
        <script>
        jQuery(document).ready(function($) {
            $('#month-selector').on('change', function() {
                const url = new URL(window.location.href);
                url.searchParams.set('month', $(this).val());
                window.location.href = url.toString();
            });
        });
        </script>
        <?php
    }

    private static function renderStyles(): void
    {
        ?>
        <style>
        .wnq-seo-premium { margin: 20px 20px 20px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .seo-header { display: flex; justify-content: space-between; align-items: center; padding: 24px 32px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 16px; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3); }
        .seo-header h1 { margin: 0 0 4px; font-size: 28px; font-weight: 800; color: white; }
        .subtitle { margin: 0; color: rgba(255, 255, 255, 0.9); font-size: 15px; }
        .back-link { display: inline-block; color: white; text-decoration: none; font-weight: 600; margin-bottom: 8px; opacity: 0.9; }
        .back-link:hover { opacity: 1; }
        .client-links-header { display: flex; gap: 12px; margin-top: 8px; }
        .header-link { padding: 6px 12px; background: rgba(255, 255, 255, 0.2); color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .header-link:hover { background: rgba(255, 255, 255, 0.3); }
        .month-selector { padding: 10px 16px; border: 2px solid rgba(255, 255, 255, 0.3); border-radius: 8px; background: rgba(255, 255, 255, 0.9); font-size: 14px; font-weight: 600; }
        .btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: white; color: #10b981; font-weight: 700; border-radius: 8px; text-decoration: none; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); border: none; cursor: pointer; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); }
        .btn-large { padding: 14px 28px; font-size: 16px; }
        .seo-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { display: flex; align-items: center; gap: 16px; padding: 20px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
        .stat-icon { font-size: 32px; }
        .stat-value { font-size: 28px; font-weight: 800; color: #1e293b; }
        .stat-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .clients-seo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
        .client-seo-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
        .client-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
        .client-info { display: flex; flex-direction: column; gap: 4px; }
        .client-card-header h3 { margin: 0; font-size: 18px; font-weight: 700; }
        .client-link { color: #1e293b; text-decoration: none; transition: color 0.2s; }
        .client-link:hover { color: #10b981; }
        .admin-link { font-size: 12px; color: #64748b; text-decoration: none; font-weight: 600; }
        .admin-link:hover { color: #10b981; }
        .btn-manage { padding: 8px 16px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 13px; }
        .btn-manage:hover { background: #059669; }
        .progress-section { margin-bottom: 20px; }
        .progress-label { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #64748b; }
        .progress-pct { color: #10b981; font-weight: 800; }
        .progress-bar { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; border: 2px solid #cbd5e1; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #10b981 0%, #059669 100%); transition: width 0.3s; min-width: 0; }
        .task-stats { display: flex; justify-content: space-around; padding-top: 16px; border-top: 1px solid #f1f5f9; }
        .task-stat { text-align: center; }
        .stat-count { display: block; font-size: 20px; font-weight: 800; color: #1e293b; }
        .stat-name { display: block; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .btn-init { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 16px; }
        .btn-init:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
        .progress-overview { margin-bottom: 24px; }
        .progress-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
        .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .progress-header h3 { margin: 0; font-size: 18px; }
        .progress-percentage { font-size: 24px; font-weight: 800; color: #10b981; }
        .progress-bar-large { height: 16px; background: #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 16px; border: 2px solid #cbd5e1; box-shadow: inset 0 2px 4px rgba(0,0,0,0.06); }
        .progress-fill-large { height: 100%; background: linear-gradient(90deg, #10b981 0%, #059669 100%); transition: width 0.3s; min-width: 0; }
        .progress-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .stat-item { text-align: center; }
        .stat-item strong { display: block; font-size: 24px; color: #1e293b; }
        .stat-item span { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .service-tabs { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
        .service-tab { display: flex; align-items: center; gap: 8px; padding: 12px 20px; background: white; border: 2px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #64748b; font-weight: 600; transition: all 0.2s; }
        .service-tab:hover { border-color: #cbd5e1; transform: translateY(-2px); }
        .service-tab.active { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-width: 3px; color: #1e293b; }
        .tab-icon { font-size: 20px; }
        .tab-name { font-size: 14px; }
        .tab-count { padding: 2px 8px; background: #f1f5f9; border-radius: 12px; font-size: 11px; }
        .service-tab.active .tab-count { background: white; }
        .monthly-controls { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
        .month-selector-wrapper { display: flex; align-items: center; gap: 12px; }
        .month-selector-wrapper label { font-weight: 600; color: #1e293b; }
        .month-selector-inline { padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 6px; font-weight: 600; }
        .btn-init-month { padding: 10px 20px; background: #ec4899; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-init-month:hover { background: #db2777; }
        .month-badge { padding: 4px 12px; background: #fce7f3; color: #ec4899; border-radius: 12px; font-size: 12px; font-weight: 700; margin-left: 12px; }
        .bulk-import-banner { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 32px; margin-bottom: 24px; }
        .banner-content { display: flex; align-items: center; gap: 24px; }
        .banner-icon { font-size: 48px; }
        .banner-text { flex: 1; color: white; }
        .banner-text h4 { margin: 0 0 8px; font-size: 18px; font-weight: 700; }
        .banner-text p { margin: 0; opacity: 0.9; }
        .btn-bulk-import { padding: 12px 24px; background: white; color: #667eea; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 14px; }
        .btn-bulk-import:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); }
        .task-section { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
        .task-section-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 16px; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; border-left: 4px solid; padding-left: 16px; }
        .section-title { display: flex; align-items: center; gap: 12px; }
        .section-icon { font-size: 24px; }
        .task-section-header h3 { margin: 0; font-size: 18px; font-weight: 700; }
        .task-count { padding: 4px 12px; background: #f1f5f9; border-radius: 12px; font-size: 12px; font-weight: 700; color: #64748b; }
        .task-group { margin-bottom: 32px; }
        .task-group-header-wrapper { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .task-group-header { margin: 0; padding: 12px 16px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-left: 4px solid #10b981; border-radius: 8px; font-size: 15px; font-weight: 700; color: #1e293b; flex: 1; display: flex; justify-content: space-between; align-items: center; }
        .group-task-count { font-size: 12px; font-weight: 600; color: #64748b; background: white; padding: 4px 12px; border-radius: 12px; }
        .btn-add-task { padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.2s; margin-left: 12px; }
        .btn-add-task:hover { background: #059669; transform: translateY(-1px); }
        .quick-add-task-form { background: #f0fdf4; border: 2px solid #10b981; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .quick-add-inputs { display: flex; gap: 8px; align-items: center; }
        .quick-add-input { flex: 1; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        .quick-add-input:focus { outline: none; border-color: #10b981; }
        .btn-save-task { padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-save-task:hover { background: #059669; }
        .btn-cancel-task { padding: 10px 20px; background: #e2e8f0; color: #64748b; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-cancel-task:hover { background: #cbd5e1; }
        .tasks-list { display: flex; flex-direction: column; gap: 12px; }
        .task-item { display: flex; align-items: flex-start; gap: 16px; padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
        .task-item.task-completed { background: #f0fdf4; border-color: #86efac; }
        .task-item.task-in_progress { background: #fef3c7; border-color: #fde68a; }
        .task-checkbox { flex-shrink: 0; }
        .checkbox-btn { width: 32px; height: 32px; border: 2px solid #cbd5e0; background: white; border-radius: 6px; cursor: pointer; font-size: 20px; display: flex; align-items: center; justify-content: center; padding: 0; }
        .checkbox-btn:hover { border-color: #10b981; background: #f0fdf4; }
        .checkbox-btn.completed { background: #f0fdf4; border-color: #10b981; }
        .task-content { flex: 1; }
        .task-name { font-size: 15px; font-weight: 600; color: #1e293b; margin-bottom: 4px; }
        .task-description { font-size: 13px; color: #64748b; margin-bottom: 4px; }
        .task-notes { font-size: 12px; color: #8b5cf6; margin-top: 8px; padding: 8px; background: #faf5ff; border-radius: 4px; }
        .task-completed-date { font-size: 11px; color: #059669; margin-top: 4px; }
        .task-actions { display: flex; gap: 8px; }
        .btn-action { padding: 6px 12px; border: 1px solid #e2e8f0; background: white; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .btn-action:hover { background: #f8fafc; border-color: #cbd5e0; }
        .btn-delete { color: #dc2626 !important; background: #fee2e2 !important; border: 1px solid #fecaca !important; }
        .btn-delete:hover { background: #fecaca !important; }
        .report-form { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
        .report-section { margin-bottom: 32px; }
        .report-section h3 { margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #1e293b; }
        .metrics-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .metric-input label { display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px; }
        .completion-stats { display: flex; gap: 24px; }
        .stat-box { flex: 1; padding: 24px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 12px; text-align: center; color: white; }
        .stat-number { font-size: 36px; font-weight: 800; margin-bottom: 8px; }
        .stat-label { font-size: 14px; font-weight: 600; opacity: 0.9; }
        .empty-state { text-align: center; padding: 80px 20px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
        .empty-icon { font-size: 64px; margin-bottom: 16px; }
        .empty-state h3 { margin: 0 0 8px; font-size: 20px; }
        .empty-state p { margin: 0 0 24px; color: #64748b; }
        @media (max-width: 1200px) { .seo-stats-grid { grid-template-columns: repeat(2, 1fr); } .metrics-grid { grid-template-columns: 1fr; } }
        </style>
        <?php
    }

    // FORM HANDLERS
    public static function handleInitClient(): void
    {
        check_admin_referer('wnq_init_seo_client');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $client_id = sanitize_text_field($_POST['client_id']);
        SEO::initializeClientTasks($client_id);

        wp_redirect(admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($client_id)));
        exit;
    }

    public static function handleInitMonthlyTasks(): void
    {
        check_admin_referer('wnq_init_monthly_tasks');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $client_id = sanitize_text_field($_POST['client_id']);
        $month_year = sanitize_text_field($_POST['month_year']);

        SEO::initializeMonthlyTasks($client_id, $month_year);

        $redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($client_id) . '&service=monthly&month=' . $month_year);
        wp_redirect($redirect_url);
        exit;
    }

    public static function handleBulkImport(): void
    {
        check_admin_referer('wnq_bulk_import_seo');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $client_id = sanitize_text_field($_POST['client_id']);
        $service_type = sanitize_text_field($_POST['service_type']);
        $month_year = isset($_POST['month_year']) ? sanitize_text_field($_POST['month_year']) : null;

        SEO::bulkImportTasks($client_id, $service_type, $month_year);

        $redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($client_id) . '&service=' . $service_type);
        wp_redirect($redirect_url);
        exit;
    }

    public static function handleCompleteTask(): void
    {
        check_admin_referer('wnq_complete_seo_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $task_id = intval($_POST['task_id']);
        SEO::completeTask($task_id);

        $redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : admin_url('admin.php?page=wnq-seo');
        wp_redirect($redirect_url);
        exit;
    }

    public static function handleUncompleteTask(): void
    {
        check_admin_referer('wnq_uncomplete_seo_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $task_id = intval($_POST['task_id']);
        SEO::uncompleteTask($task_id);

        $redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : admin_url('admin.php?page=wnq-seo');
        wp_redirect($redirect_url);
        exit;
    }

    public static function handleUpdateTask(): void
    {
        check_admin_referer('wnq_update_seo_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $task_id = intval($_POST['task_id']);
        $data = [];

        if (isset($_POST['status'])) {
            $data['status'] = sanitize_text_field($_POST['status']);
        }
        if (isset($_POST['notes'])) {
            $data['notes'] = sanitize_textarea_field($_POST['notes']);
        }

        SEO::updateTask($task_id, $data);

        $redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : admin_url('admin.php?page=wnq-seo');
        wp_redirect($redirect_url);
        exit;
    }

    public static function handleSaveReport(): void
    {
        check_admin_referer('wnq_save_seo_report');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $data = [
            'client_id' => sanitize_text_field($_POST['client_id']),
            'month_year' => sanitize_text_field($_POST['month_year']),
            'report_url' => esc_url_raw($_POST['report_url'] ?? ''),
            'keywords_tracked' => intval($_POST['keywords_tracked'] ?? 0),
            'avg_position' => floatval($_POST['avg_position'] ?? 0),
            'organic_traffic' => intval($_POST['organic_traffic'] ?? 0),
            'backlinks_added' => intval($_POST['backlinks_added'] ?? 0),
            'summary' => sanitize_textarea_field($_POST['summary'] ?? ''),
        ];

        SEO::saveReport($data);

        wp_redirect(admin_url('admin.php?page=wnq-seo&view=client&client=' . urlencode($data['client_id'])));
        exit;
    }

    public static function handleAddTask(): void
    {
        check_admin_referer('wnq_add_seo_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $client_id = sanitize_text_field($_POST['client_id']);
        $service_type = sanitize_text_field($_POST['service_type']);
        $task_group = sanitize_text_field($_POST['task_group']);
        $task_name = sanitize_text_field($_POST['task_name']);
        $month_year = isset($_POST['month_year']) && !empty($_POST['month_year']) ? sanitize_text_field($_POST['month_year']) : null;

        if (empty($task_name)) {
            wp_redirect($_POST['redirect_url']);
            exit;
        }

        SEO::createTask([
            'client_id' => $client_id,
            'service_type' => $service_type,
            'task_group' => $task_group,
            'task_name' => $task_name,
            'status' => 'pending',
            'month_year' => $month_year,
        ]);

        wp_redirect($_POST['redirect_url']);
        exit;
    }

    public static function handleDeleteTask(): void
    {
        check_admin_referer('wnq_delete_seo_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $task_id = intval($_POST['task_id']);
        SEO::deleteTask($task_id);

        $redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : admin_url('admin.php?page=wnq-seo');
        wp_redirect($redirect_url);
        exit;
    }
}