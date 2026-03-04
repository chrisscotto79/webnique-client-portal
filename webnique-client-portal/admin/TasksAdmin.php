<?php
/**
 * TasksAdmin - PREMIUM VERSION
 * 
 * Complete task management with:
 * - Working drag & drop with smooth animations
 * - User filtering system
 * - Beautiful modern UI
 * - Task categories (Client/WebNique/General)
 * - Countdown timers
 * - Archive system
 * - Enhanced stats
 * 
 * @package WebNique Portal
 */

namespace WNQ\Admin;

use WNQ\Models\Task;

if (!defined('ABSPATH')) {
    exit;
}

final class TasksAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSubmenu'], 15);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function addSubmenu(): void
    {
        $capability = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';

        add_submenu_page(
            'wnq-portal',
            'Tasks',
            'Tasks',
            $capability,
            'wnq-tasks',
            [self::class, 'render']
        );
    }

    public static function enqueueAssets($hook): void
    {
        if ($hook !== 'webnique-portal_page_wnq-tasks') {
            return;
        }

        wp_enqueue_script('jquery-ui-draggable');
        wp_enqueue_script('jquery-ui-droppable');
        wp_enqueue_script('jquery-ui-sortable');
    }

    public static function render(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'kanban';
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($action === 'edit' && $task_id) {
            self::renderEditForm($task_id);
            return;
        }

        if ($action === 'add') {
            self::renderAddForm();
            return;
        }

        switch ($view) {
            case 'schedule':
                self::renderScheduleView();
                break;
            case 'calendar':
                self::renderCalendarView();
                break;
            case 'recurring':
                self::renderRecurringView();
                break;
            case 'journal':
                self::renderJournalView();
                break;
            case 'archive':
                self::renderArchiveView();
                break;
            default:
                self::renderKanbanBoard();
                break;
        }
    }

    private static function renderKanbanBoard(): void
    {
        // Load clients
        $clients = [];
        if (file_exists(WNQ_PORTAL_PATH . 'includes/Models/Client.php')) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
            if (class_exists('WNQ\\Models\\Client')) {
                $clients = \WNQ\Models\Client::getAll();
            }
        }

        // Get filters
        $filter_type = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : '';
        $filter_user = isset($_GET['user']) ? sanitize_text_field($_GET['user']) : '';

        // Get tasks
        $filters = [];
        if ($filter_type) {
            $filters['task_type'] = $filter_type;
        }
        if ($filter_user) {
            $filters['assigned_to'] = $filter_user;
        }
        $tasks = Task::getAll($filters);
        
        $counts = Task::getCountsByStatus();
        $type_counts = Task::getCountsByType();
        $overdue = Task::getOverdue();
        $this_week = Task::getDueThisWeek();

        // Group tasks by status
        $tasksByStatus = [
            'todo' => [],
            'in_progress' => [],
            'review' => [],
            'done' => [],
        ];

        foreach ($tasks as $task) {
            $status = $task['status'] ?? 'todo';
            if (isset($tasksByStatus[$status])) {
                $tasksByStatus[$status][] = $task;
            }
        }

        $team_members = [
            'christopher-scotto' => ['name' => 'Christopher Scotto', 'initials' => 'CS', 'color' => '#667eea'],
            'christopher-sanders' => ['name' => 'Christopher Sanders', 'initials' => 'CS', 'color' => '#f093fb'],
        ];

        // Count tasks by user
        $user_counts = [];
        foreach ($team_members as $id => $member) {
            $user_counts[$id] = 0;
            foreach ($tasks as $task) {
                if (($task['assigned_to'] ?? '') === $id) {
                    $user_counts[$id]++;
                }
            }
        }

        ?>
        <div class="wrap wnq-tasks-premium">
            <!-- Header Bar -->
            <div class="tasks-top-bar">
                <div class="tasks-branding">
                    <h1>📋 Task Management</h1>
                    <p class="subtitle">Drag & drop to organize your workflow</p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&action=add'); ?>" class="btn-new-task">
                    <span class="btn-icon">✨</span> New Task
                </a>
            </div>

            <!-- Navigation Tabs -->
            <div class="premium-tabs">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=kanban'); ?>" class="tab-item active">
                    <span class="tab-icon">📊</span> Kanban
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar'); ?>" class="tab-item">
                    <span class="tab-icon">📅</span> Calendar
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=recurring'); ?>" class="tab-item">
                    <span class="tab-icon">🔁</span> Recurring
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=journal'); ?>" class="tab-item">
                    <span class="tab-icon">📓</span> Daily Journal
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=archive'); ?>" class="tab-item">
                    <span class="tab-icon">📦</span> Archive
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=schedule'); ?>" class="tab-item">
                    <span class="tab-icon">📋</span> Daily Schedule
                </a>
            </div>

            <!-- Stats Dashboard -->
            <div class="stats-dashboard">
                <div class="stat-box stat-todo">
                    <div class="stat-icon">📝</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $counts['todo']; ?></div>
                        <div class="stat-label">To Do</div>
                    </div>
                </div>
                <div class="stat-box stat-progress">
                    <div class="stat-icon">🚀</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $counts['in_progress']; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="stat-box stat-review">
                    <div class="stat-icon">👀</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $counts['review']; ?></div>
                        <div class="stat-label">Review</div>
                    </div>
                </div>
                <div class="stat-box stat-done">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $counts['done']; ?></div>
                        <div class="stat-label">Done</div>
                    </div>
                </div>
                <?php if (!empty($overdue)): ?>
                <div class="stat-box stat-overdue">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo count($overdue); ?></div>
                        <div class="stat-label">Overdue</div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="stat-box stat-week">
                    <div class="stat-icon">📆</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo count($this_week); ?></div>
                        <div class="stat-label">This Week</div>
                    </div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="filters-bar">
                <div class="filter-section">
                    <span class="filter-title">Task Type:</span>
                    <div class="filter-buttons">
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks' . ($filter_user ? '&user=' . $filter_user : '')); ?>" 
                           class="filter-btn <?php echo empty($filter_type) ? 'active' : ''; ?>">
                            All <span class="badge"><?php echo array_sum($type_counts); ?></span>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks&filter=client' . ($filter_user ? '&user=' . $filter_user : '')); ?>" 
                           class="filter-btn <?php echo $filter_type === 'client' ? 'active' : ''; ?>">
                            🏢 Client <span class="badge"><?php echo $type_counts['client']; ?></span>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks&filter=webnique' . ($filter_user ? '&user=' . $filter_user : '')); ?>" 
                           class="filter-btn <?php echo $filter_type === 'webnique' ? 'active' : ''; ?>">
                            🏪 WebNique <span class="badge"><?php echo $type_counts['webnique']; ?></span>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks&filter=general' . ($filter_user ? '&user=' . $filter_user : '')); ?>" 
                           class="filter-btn <?php echo $filter_type === 'general' ? 'active' : ''; ?>">
                            📝 General <span class="badge"><?php echo $type_counts['general']; ?></span>
                        </a>
                    </div>
                </div>

                <div class="filter-section">
                    <span class="filter-title">Team Member:</span>
                    <div class="filter-buttons">
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks' . ($filter_type ? '&filter=' . $filter_type : '')); ?>" 
                           class="filter-btn <?php echo empty($filter_user) ? 'active' : ''; ?>">
                            All <span class="badge"><?php echo count($tasks); ?></span>
                        </a>
                        <?php foreach ($team_members as $id => $member): ?>
                            <a href="<?php echo admin_url('admin.php?page=wnq-tasks&user=' . $id . ($filter_type ? '&filter=' . $filter_type : '')); ?>" 
                               class="filter-btn user-filter <?php echo $filter_user === $id ? 'active' : ''; ?>">
                                <span class="user-avatar" style="background: <?php echo $member['color']; ?>">
                                    <?php echo $member['initials']; ?>
                                </span>
                                <?php echo explode(' ', $member['name'])[0]; ?>
                                <span class="badge"><?php echo $user_counts[$id]; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Search & Filters -->
            <div class="quick-search-bar">
                <div class="search-input-wrapper">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="task-search" placeholder="Search tasks by title, description, or client..." class="search-input">
                    <button id="clear-search" class="clear-search" style="display: none;">✕</button>
                </div>
                <div class="quick-filters">
                    <select id="filter-priority" class="quick-filter-select">
                        <option value="">All Priorities</option>
                        <option value="high">🔴 High</option>
                        <option value="medium">🟡 Medium</option>
                        <option value="low">🔵 Low</option>
                    </select>
                    <select id="filter-assignee" class="quick-filter-select">
                        <option value="">All Assignees</option>
                        <option value="christopher-scotto">Christopher Scotto</option>
                        <option value="christopher-sanders">Christopher Sanders</option>
                        <option value="unassigned">Unassigned</option>
                    </select>
                    <button id="reset-filters" class="btn-reset-filters">Reset All</button>
                </div>
            </div>

            <!-- Kanban Board -->
            <div class="kanban-board-premium">
                <?php
                $columns = [
                    'todo' => ['icon' => '📝', 'title' => 'To Do', 'color' => '#94a3b8'],
                    'in_progress' => ['icon' => '🚀', 'title' => 'In Progress', 'color' => '#667eea'],
                    'review' => ['icon' => '👀', 'title' => 'Review', 'color' => '#f59e0b'],
                    'done' => ['icon' => '✅', 'title' => 'Done', 'color' => '#10b981'],
                ];

                foreach ($columns as $status => $col):
                ?>
                    <div class="kanban-column-premium" data-status="<?php echo esc_attr($status); ?>">
                        <div class="column-header-premium" style="border-bottom-color: <?php echo $col['color']; ?>">
                            <div class="column-title-group">
                                <span class="column-icon"><?php echo $col['icon']; ?></span>
                                <h3 class="column-title"><?php echo $col['title']; ?></h3>
                            </div>
                            <span class="column-badge" style="background: <?php echo $col['color']; ?>20; color: <?php echo $col['color']; ?>">
                                <?php echo count($tasksByStatus[$status]); ?>
                            </span>
                        </div>
                        <div class="column-tasks-premium" id="column-<?php echo esc_attr($status); ?>">
                            <?php if (empty($tasksByStatus[$status])): ?>
                                <div class="empty-column-state">
                                    <div class="empty-icon"><?php echo $col['icon']; ?></div>
                                    <p>No tasks yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($tasksByStatus[$status] as $task): ?>
                                    <?php self::renderPremiumTaskCard($task, $team_members, $clients, $status); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php self::renderPremiumStyles(); ?>
        <?php self::renderPremiumScript(); ?>
        <?php
    }

    private static function renderPremiumTaskCard(array $task, array $team_members, array $clients, string $column_status): void
    {
        $priority = $task['priority'] ?? 'medium';
        $task_type = $task['task_type'] ?? 'general';
        $due_date = $task['due_date'] ?? null;
        $assignee_id = $task['assigned_to'] ?? '';
        $assignee = !empty($assignee_id) && isset($team_members[$assignee_id]) ? $team_members[$assignee_id] : null;

        // Get client name
        $client_name = '';
        if (!empty($task['client_id'])) {
            foreach ($clients as $client) {
                if ($client['client_id'] === $task['client_id']) {
                    $client_name = $client['name'];
                    break;
                }
            }
        }

        // Calculate countdown
        $countdown_html = '';
        $countdown_class = '';
        if ($due_date) {
            $today = strtotime('today');
            $due_timestamp = strtotime($due_date);
            $days_diff = floor(($due_timestamp - $today) / (60 * 60 * 24));

            if ($days_diff < 0 && $task['status'] !== 'done') {
                $countdown_html = '<span class="countdown-icon">⚠️</span> Overdue ' . abs($days_diff) . 'd';
                $countdown_class = 'countdown-overdue';
            } elseif ($days_diff === 0) {
                $countdown_html = '<span class="countdown-icon">🔥</span> Due today';
                $countdown_class = 'countdown-today';
            } elseif ($days_diff === 1) {
                $countdown_html = '<span class="countdown-icon">📅</span> Tomorrow';
                $countdown_class = 'countdown-soon';
            } elseif ($days_diff <= 3) {
                $countdown_html = '<span class="countdown-icon">📅</span> ' . $days_diff . ' days';
                $countdown_class = 'countdown-soon';
            } elseif ($days_diff <= 7) {
                $countdown_html = '<span class="countdown-icon">📅</span> ' . $days_diff . ' days';
                $countdown_class = 'countdown-normal';
            } else {
                $countdown_html = '<span class="countdown-icon">📅</span> ' . date('M j', $due_timestamp);
                $countdown_class = 'countdown-normal';
            }
        }

        // Priority colors
        $priority_colors = [
            'high' => '#dc2626',
            'medium' => '#f59e0b',
            'low' => '#3b82f6',
        ];
        $priority_color = $priority_colors[$priority] ?? '#6b7280';

        ?>
        <div class="task-card-premium" data-task-id="<?php echo esc_attr($task['id']); ?>" 
             data-title="<?php echo esc_attr(strtolower($task['title'])); ?>"
             data-description="<?php echo esc_attr(strtolower($task['description'] ?? '')); ?>"
             data-priority="<?php echo esc_attr($priority); ?>"
             data-assignee="<?php echo esc_attr($assignee_id); ?>"
             data-client="<?php echo esc_attr(strtolower($client_name)); ?>"
             style="border-left-color: <?php echo $priority_color; ?>">
            <div class="task-card-header">
                <?php
                $type_badges = [
                    'client' => ['icon' => '🏢', 'label' => 'Client', 'bg' => '#dbeafe', 'color' => '#1e40af'],
                    'webnique' => ['icon' => '🏪', 'label' => 'WebNique', 'bg' => '#d1fae5', 'color' => '#065f46'],
                    'general' => ['icon' => '📝', 'label' => 'General', 'bg' => '#f3f4f6', 'color' => '#374151'],
                ];
                $type_info = $type_badges[$task_type] ?? $type_badges['general'];
                ?>
                <span class="task-type-tag" style="background: <?php echo $type_info['bg']; ?>; color: <?php echo $type_info['color']; ?>">
                    <?php echo $type_info['icon']; ?> <?php echo $type_info['label']; ?>
                </span>
                
                <div class="task-priority-indicator" style="background: <?php echo $priority_color; ?>" 
                     title="<?php echo ucfirst($priority); ?> priority"></div>
            </div>
            
            <h4 class="task-card-title"><?php echo esc_html($task['title']); ?></h4>
            
            <?php if (!empty($task['description'])): ?>
            <p class="task-card-description">
                <?php echo wp_trim_words(esc_html($task['description']), 12); ?>
            </p>
            <?php endif; ?>
            
            <?php if ($countdown_html): ?>
            <div class="task-countdown-badge <?php echo esc_attr($countdown_class); ?>">
                <?php echo $countdown_html; ?>
            </div>
            <?php endif; ?>

            <?php
            // Get time tracking data from task meta or custom field
            $time_logs = get_post_meta($task['id'], '_wnq_time_logs', true);
            if (!is_array($time_logs)) $time_logs = [];
            
            $total_seconds = 0;
            foreach ($time_logs as $log) {
                $total_seconds += intval($log['duration'] ?? 0);
            }
            
            $hours = floor($total_seconds / 3600);
            $minutes = floor(($total_seconds % 3600) / 60);
            
            if ($total_seconds > 0):
            ?>
            <div class="time-tracking-display">
                <span class="time-icon">⏱️</span>
                <span class="time-logged"><?php echo $hours; ?>h <?php echo $minutes; ?>m logged</span>
            </div>
            <?php endif; ?>

            <div class="task-card-footer">
                <div class="task-footer-left">
                    <?php if ($assignee): ?>
                        <div class="task-assignee-avatar" style="background: <?php echo $assignee['color']; ?>" 
                             title="<?php echo esc_attr($assignee['name']); ?>">
                            <?php echo $assignee['initials']; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($client_name): ?>
                        <span class="task-client-tag">
                            🏢 <?php echo esc_html($client_name); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="task-actions-dropdown">
                    <button class="task-menu-btn" onclick="toggleTaskMenu(<?php echo $task['id']; ?>)">⋮</button>
                    <div class="task-menu" id="task-menu-<?php echo $task['id']; ?>">
                        <button onclick="startTimer(<?php echo $task['id']; ?>, '<?php echo esc_js($task['title']); ?>')">
                            ▶️ Start Timer
                        </button>
                        <button onclick="logTime(<?php echo $task['id']; ?>)">
                            ⏱️ Log Time
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks&action=edit&id=' . $task['id']); ?>">
                            ✏️ Edit
                        </a>
                        <?php if ($column_status === 'done'): ?>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('wnq_archive_task'); ?>
                            <input type="hidden" name="action" value="wnq_archive_task">
                            <input type="hidden" name="id" value="<?php echo esc_attr($task['id']); ?>">
                            <button type="submit">📦 Archive</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('wnq_delete_task'); ?>
                            <input type="hidden" name="action" value="wnq_delete_task">
                            <input type="hidden" name="id" value="<?php echo esc_attr($task['id']); ?>">
                            <button type="submit" onclick="return confirm('Delete this task?');" class="delete-btn">
                                🗑️ Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function renderJournalView(): void
    {
        // Get date from URL or default to today
        $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
        $date_timestamp = strtotime($selected_date);
        
        // Get journal entry for this date
        $journal_entry = get_option('wnq_journal_' . $selected_date, [
            'mood' => '',
            'accomplishments' => '',
            'challenges' => '',
            'notes' => '',
            'gratitude' => '',
        ]);
        
        // Get tasks completed on this date
        $all_tasks = Task::getAll();
        $completed_today = [];
        foreach ($all_tasks as $task) {
            if ($task['status'] === 'done' && !empty($task['updated_at'])) {
                $completed_date = date('Y-m-d', strtotime($task['updated_at']));
                if ($completed_date === $selected_date) {
                    $completed_today[] = $task;
                }
            }
        }
        
        // Get time logged today
        $total_time_today = 0;
        foreach ($all_tasks as $task) {
            $time_logs = get_post_meta($task['id'], '_wnq_time_logs', true);
            if (is_array($time_logs)) {
                foreach ($time_logs as $log) {
                    $log_date = date('Y-m-d', strtotime($log['date']));
                    if ($log_date === $selected_date) {
                        $total_time_today += intval($log['duration'] ?? 0);
                    }
                }
            }
        }
        
        $hours_logged = floor($total_time_today / 3600);
        $minutes_logged = floor(($total_time_today % 3600) / 60);
        
        // Check if it's today
        $is_today = ($selected_date === date('Y-m-d'));
        $is_future = ($date_timestamp > strtotime('today'));

        ?>
        <div class="wrap wnq-tasks-premium">
            <div class="tasks-top-bar">
                <div class="tasks-branding">
                    <h1>📓 Daily Journal</h1>
                    <p class="subtitle">Reflect on your day and track progress</p>
                </div>
            </div>

            <div class="premium-tabs">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=kanban'); ?>" class="tab-item">
                    <span class="tab-icon">📊</span> Kanban
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar'); ?>" class="tab-item">
                    <span class="tab-icon">📅</span> Calendar
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=recurring'); ?>" class="tab-item">
                    <span class="tab-icon">🔁</span> Recurring
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=journal'); ?>" class="tab-item active">
                    <span class="tab-icon">📓</span> Daily Journal
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=archive'); ?>" class="tab-item">
                    <span class="tab-icon">📦</span> Archive
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=schedule'); ?>" class="tab-item">
                    <span class="tab-icon">📋</span> Daily Schedule
                </a>
            </div>

            <!-- Date Navigation -->
            <div class="journal-navigation">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=journal&date=' . date('Y-m-d', strtotime('-1 day', $date_timestamp))); ?>" class="btn-nav">
                    ← Previous Day
                </a>
                <div class="journal-date">
                    <h2><?php echo date('l, F j, Y', $date_timestamp); ?></h2>
                    <?php if ($is_today): ?>
                        <span class="today-badge">Today</span>
                    <?php endif; ?>
                    <?php if (!$is_today): ?>
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=journal'); ?>" class="btn-today">Jump to Today</a>
                    <?php endif; ?>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=journal&date=' . date('Y-m-d', strtotime('+1 day', $date_timestamp))); ?>" class="btn-nav">
                    Next Day →
                </a>
            </div>

            <?php if ($is_future): ?>
                <div class="empty-state-premium">
                    <div class="empty-icon-large">🔮</div>
                    <h2>Future Date</h2>
                    <p>You can't journal about the future... yet!</p>
                    <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=journal'); ?>" class="button button-primary">
                        Go to Today
                    </a>
                </div>
            <?php else: ?>
                <!-- Save Success Message -->
                <?php if (isset($_GET['saved'])): ?>
                    <div class="notice notice-success is-dismissible" style="margin: 0 0 24px;">
                        <p><strong>✅ Journal entry saved successfully!</strong></p>
                    </div>
                <?php endif; ?>

                <!-- Journal Stats -->
                <div class="journal-stats">
                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo count($completed_today); ?></div>
                            <div class="stat-label">Tasks Completed</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⏱️</div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $hours_logged; ?>h <?php echo $minutes_logged; ?>m</div>
                            <div class="stat-label">Time Logged</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo count($completed_today) > 0 ? round(($total_time_today / count($completed_today)) / 60) . 'm' : '—'; ?></div>
                            <div class="stat-label">Avg per Task</div>
                        </div>
                    </div>
                </div>

                <!-- Journal Form -->
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="journal-form">
                    <?php wp_nonce_field('wnq_save_journal'); ?>
                    <input type="hidden" name="action" value="wnq_save_journal">
                    <input type="hidden" name="date" value="<?php echo esc_attr($selected_date); ?>">

                    <div class="journal-grid">
                        <!-- Left Column -->
                        <div class="journal-column">
                            <!-- Mood Selector -->
                            <div class="journal-section">
                                <h3>😊 How was your day?</h3>
                                <div class="mood-selector">
                                    <?php
                                    $moods = [
                                        'amazing' => ['emoji' => '🤩', 'label' => 'Amazing', 'color' => '#10b981'],
                                        'good' => ['emoji' => '😊', 'label' => 'Good', 'color' => '#3b82f6'],
                                        'okay' => ['emoji' => '😐', 'label' => 'Okay', 'color' => '#f59e0b'],
                                        'rough' => ['emoji' => '😓', 'label' => 'Rough', 'color' => '#ef4444'],
                                        'terrible' => ['emoji' => '😫', 'label' => 'Terrible', 'color' => '#991b1b'],
                                    ];
                                    foreach ($moods as $value => $mood):
                                        $selected = ($journal_entry['mood'] === $value);
                                    ?>
                                        <label class="mood-option <?php echo $selected ? 'selected' : ''; ?>" style="--mood-color: <?php echo $mood['color']; ?>">
                                            <input type="radio" name="mood" value="<?php echo $value; ?>" <?php checked($selected); ?>>
                                            <span class="mood-emoji"><?php echo $mood['emoji']; ?></span>
                                            <span class="mood-label"><?php echo $mood['label']; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Accomplishments -->
                            <div class="journal-section">
                                <h3>🎯 What did you accomplish?</h3>
                                <textarea name="accomplishments" rows="6" class="journal-textarea" placeholder="List your wins, completed tasks, progress made..."><?php echo esc_textarea($journal_entry['accomplishments']); ?></textarea>
                                
                                <?php if (!empty($completed_today)): ?>
                                    <details class="completed-tasks-details">
                                        <summary>✅ <?php echo count($completed_today); ?> tasks completed today</summary>
                                        <ul class="completed-tasks-list">
                                            <?php foreach ($completed_today as $task): ?>
                                                <li><?php echo esc_html($task['title']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>

                            <!-- Gratitude -->
                            <div class="journal-section">
                                <h3>🙏 What are you grateful for?</h3>
                                <textarea name="gratitude" rows="4" class="journal-textarea" placeholder="Three things you're thankful for today..."><?php echo esc_textarea($journal_entry['gratitude']); ?></textarea>
                            </div>
                        </div>

                        <!-- Right Column -->
                        <div class="journal-column">
                            <!-- Challenges -->
                            <div class="journal-section">
                                <h3>⚡ What challenges did you face?</h3>
                                <textarea name="challenges" rows="6" class="journal-textarea" placeholder="Blockers, difficulties, things that didn't go as planned..."><?php echo esc_textarea($journal_entry['challenges']); ?></textarea>
                            </div>

                            <!-- Notes -->
                            <div class="journal-section">
                                <h3>📝 Additional Notes</h3>
                                <textarea name="notes" rows="8" class="journal-textarea" placeholder="Any other thoughts, ideas, or reflections..."><?php echo esc_textarea($journal_entry['notes']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="journal-actions">
                        <button type="submit" class="btn-save-journal">
                            💾 Save Journal Entry
                        </button>
                        <?php if (!empty($journal_entry['mood']) || !empty($journal_entry['accomplishments'])): ?>
                            <span class="last-saved">Last saved: <?php echo date('g:i A', strtotime($selected_date)); ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <?php self::renderJournalStyles(); ?>
        <?php
    }

    private static function renderArchiveView(): void
    {
        $archived_tasks = Task::getArchived();
        $archived_count = count($archived_tasks);

        ?>
        <div class="wrap wnq-tasks-premium">
            <div class="tasks-top-bar">
                <div class="tasks-branding">
                    <h1>📦 Archive</h1>
                    <p class="subtitle"><?php echo $archived_count; ?> archived tasks</p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks'); ?>" class="btn-new-task btn-secondary">
                    ← Back to Kanban
                </a>
            </div>

            <div class="premium-tabs">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=kanban'); ?>" class="tab-item">
                    <span class="tab-icon">📊</span> Kanban
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar'); ?>" class="tab-item">
                    <span class="tab-icon">📅</span> Calendar
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=recurring'); ?>" class="tab-item">
                    <span class="tab-icon">🔁</span> Recurring
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=archive'); ?>" class="tab-item active">
                    <span class="tab-icon">📦</span> Archive
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=schedule'); ?>" class="tab-item">
                    <span class="tab-icon">📋</span> Daily Schedule
                </a>
            </div>

            <?php if (empty($archived_tasks)): ?>
                <div class="empty-state-premium">
                    <div class="empty-icon-large">📦</div>
                    <h2>No Archived Tasks</h2>
                    <p>Completed tasks will appear here when archived</p>
                </div>
            <?php else: ?>
                <div class="archive-grid-premium">
                    <?php foreach ($archived_tasks as $task): ?>
                        <div class="archive-card-premium">
                            <div class="archive-card-header">
                                <?php
                                $types = [
                                    'client' => ['icon' => '🏢', 'label' => 'Client', 'bg' => '#dbeafe', 'color' => '#1e40af'],
                                    'webnique' => ['icon' => '🏪', 'label' => 'WebNique', 'bg' => '#d1fae5', 'color' => '#065f46'],
                                    'general' => ['icon' => '📝', 'label' => 'General', 'bg' => '#f3f4f6', 'color' => '#374151'],
                                ];
                                $type_info = $types[$task['task_type'] ?? 'general'];
                                ?>
                                <span class="task-type-tag" style="background: <?php echo $type_info['bg']; ?>; color: <?php echo $type_info['color']; ?>">
                                    <?php echo $type_info['icon']; ?> <?php echo $type_info['label']; ?>
                                </span>
                                <span class="archive-date-badge">
                                    📅 <?php echo date('M j, Y', strtotime($task['archived_at'])); ?>
                                </span>
                            </div>
                            
                            <h3 class="archive-card-title"><?php echo esc_html($task['title']); ?></h3>
                            
                            <?php if (!empty($task['description'])): ?>
                            <p class="archive-card-description">
                                <?php echo wp_trim_words(esc_html($task['description']), 20); ?>
                            </p>
                            <?php endif; ?>
                            
                            <div class="archive-card-meta">
                                <?php
                                $priority_badges = [
                                    'high' => ['icon' => '🔴', 'label' => 'High'],
                                    'medium' => ['icon' => '🟡', 'label' => 'Medium'],
                                    'low' => ['icon' => '🔵', 'label' => 'Low'],
                                ];
                                $priority_info = $priority_badges[$task['priority'] ?? 'medium'];
                                ?>
                                <span class="meta-badge">
                                    <?php echo $priority_info['icon']; ?> <?php echo $priority_info['label']; ?>
                                </span>
                                <?php if (!empty($task['assigned_to'])): ?>
                                <span class="meta-badge">
                                    👤 <?php echo esc_html(ucwords(str_replace('-', ' ', $task['assigned_to']))); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="archive-card-actions">
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                    <?php wp_nonce_field('wnq_restore_task'); ?>
                                    <input type="hidden" name="action" value="wnq_restore_task">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($task['id']); ?>">
                                    <button type="submit" class="btn-restore">
                                        ↩️ Restore
                                    </button>
                                </form>
                                
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                    <?php wp_nonce_field('wnq_delete_task'); ?>
                                    <input type="hidden" name="action" value="wnq_delete_task">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($task['id']); ?>">
                                    <button type="submit" onclick="return confirm('Permanently delete?');" class="btn-delete-archive">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php self::renderPremiumStyles(); ?>
        <?php
    }

    private static function renderCalendarView(): void
    {
        // Load clients for filtering
        $clients = [];
        if (file_exists(WNQ_PORTAL_PATH . 'includes/Models/Client.php')) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
            if (class_exists('WNQ\\Models\\Client')) {
                $clients = \WNQ\Models\Client::getAll();
            }
        }

        // Get week offset (default to current week)
        $week_offset = isset($_GET['week']) ? intval($_GET['week']) : 0;
        
        // Calculate week start (Monday)
        $week_start = strtotime('monday this week ' . ($week_offset > 0 ? '+' : '') . $week_offset . ' weeks');
        
        // Get all tasks with due dates
        $all_tasks = Task::getAll();
        
        // Group tasks by day
        $tasks_by_day = [];
        for ($i = 0; $i < 7; $i++) {
            $day_timestamp = strtotime('+' . $i . ' days', $week_start);
            $day_key = date('Y-m-d', $day_timestamp);
            $tasks_by_day[$day_key] = [];
        }
        
        foreach ($all_tasks as $task) {
            if (!empty($task['due_date'])) {
                $due_key = date('Y-m-d', strtotime($task['due_date']));
                if (isset($tasks_by_day[$due_key])) {
                    $tasks_by_day[$due_key][] = $task;
                }
            }
        }
        
        $team_members = [
            'christopher-scotto' => ['name' => 'Christopher Scotto', 'initials' => 'CS', 'color' => '#667eea'],
            'christopher-sanders' => ['name' => 'Christopher Sanders', 'initials' => 'CS', 'color' => '#f093fb'],
        ];

        ?>
        <div class="wrap wnq-tasks-premium">
            <div class="tasks-top-bar">
                <div class="tasks-branding">
                    <h1>📅 Calendar View</h1>
                    <p class="subtitle">Weekly task schedule</p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&action=add'); ?>" class="btn-new-task">
                    <span class="btn-icon">✨</span> New Task
                </a>
            </div>

            <div class="premium-tabs">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=kanban'); ?>" class="tab-item">
                    <span class="tab-icon">📊</span> Kanban
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar'); ?>" class="tab-item active">
                    <span class="tab-icon">📅</span> Calendar
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=recurring'); ?>" class="tab-item">
                    <span class="tab-icon">🔁</span> Recurring
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=archive'); ?>" class="tab-item">
                    <span class="tab-icon">📦</span> Archive
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=schedule'); ?>" class="tab-item">
                    <span class="tab-icon">📋</span> Daily Schedule
                </a>
            </div>

            <!-- Week Navigation -->
            <div class="calendar-navigation">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar&week=' . ($week_offset - 1)); ?>" class="btn-nav">
                    ← Previous Week
                </a>
                <div class="week-display">
                    <h2><?php echo date('F j', $week_start); ?> - <?php echo date('F j, Y', strtotime('+6 days', $week_start)); ?></h2>
                    <?php if ($week_offset !== 0): ?>
                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar'); ?>" class="btn-today">Today</a>
                    <?php endif; ?>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar&week=' . ($week_offset + 1)); ?>" class="btn-nav">
                    Next Week →
                </a>
            </div>

            <!-- Calendar Grid -->
            <div class="calendar-grid">
                <?php
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                $today = date('Y-m-d');
                
                for ($i = 0; $i < 7; $i++):
                    $day_timestamp = strtotime('+' . $i . ' days', $week_start);
                    $day_key = date('Y-m-d', $day_timestamp);
                    $day_tasks = $tasks_by_day[$day_key];
                    $is_today = ($day_key === $today);
                    $is_past = ($day_timestamp < strtotime('today'));
                ?>
                    <div class="calendar-day <?php echo $is_today ? 'is-today' : ''; ?> <?php echo $is_past ? 'is-past' : ''; ?>">
                        <div class="day-header">
                            <div class="day-name"><?php echo $days[$i]; ?></div>
                            <div class="day-number"><?php echo date('j', $day_timestamp); ?></div>
                            <?php if ($is_today): ?>
                                <span class="today-badge">Today</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="day-tasks">
                            <?php if (empty($day_tasks)): ?>
                                <div class="no-tasks">No tasks scheduled</div>
                            <?php else: ?>
                                <?php foreach ($day_tasks as $task): 
                                    $assignee_id = $task['assigned_to'] ?? '';
                                    $assignee = !empty($assignee_id) && isset($team_members[$assignee_id]) ? $team_members[$assignee_id] : null;
                                    
                                    $status_colors = [
                                        'todo' => '#94a3b8',
                                        'in_progress' => '#667eea',
                                        'review' => '#f59e0b',
                                        'done' => '#10b981',
                                    ];
                                    $status_color = $status_colors[$task['status']] ?? '#94a3b8';
                                ?>
                                    <div class="calendar-task" style="border-left-color: <?php echo $status_color; ?>">
                                        <div class="task-time-status">
                                            <?php if ($task['status'] === 'done'): ?>
                                                <span class="task-status-icon">✅</span>
                                            <?php else: ?>
                                                <span class="task-status-icon">⏰</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="task-info">
                                            <div class="task-title"><?php echo esc_html($task['title']); ?></div>
                                            <?php if ($assignee): ?>
                                                <div class="task-assignee">
                                                    <span class="mini-avatar" style="background: <?php echo $assignee['color']; ?>">
                                                        <?php echo $assignee['initials']; ?>
                                                    </span>
                                                    <?php echo explode(' ', $assignee['name'])[0]; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <a href="<?php echo admin_url('admin.php?page=wnq-tasks&action=edit&id=' . $task['id']); ?>" class="task-link">
                                            →
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <?php self::renderCalendarStyles(); ?>
        <?php
    }

    private static function renderRecurringView(): void
    {
        // Get or create recurring tasks
        $recurring_tasks = Task::getAll(['is_recurring' => 1]);
        
        // Get today's completions (stored in task notes as JSON)
        $today = date('Y-m-d');
        
        // Calculate streaks for each task
        foreach ($recurring_tasks as &$task) {
            $completion_data = !empty($task['notes']) ? json_decode($task['notes'], true) : [];
            if (!is_array($completion_data)) {
                $completion_data = [];
            }
            
            // Calculate current streak
            $streak = 0;
            $check_date = strtotime('today');
            while (isset($completion_data[date('Y-m-d', $check_date)])) {
                $streak++;
                $check_date = strtotime('-1 day', $check_date);
            }
            
            $task['current_streak'] = $streak;
            $task['completed_today'] = isset($completion_data[$today]);
            $task['completion_data'] = $completion_data;
            $task['total_completions'] = count($completion_data);
        }
        
        $team_members = [
            'christopher-scotto' => ['name' => 'Christopher Scotto', 'initials' => 'CS', 'color' => '#667eea'],
            'christopher-sanders' => ['name' => 'Christopher Sanders', 'initials' => 'CS', 'color' => '#f093fb'],
        ];

        ?>
        <div class="wrap wnq-tasks-premium">
            <div class="tasks-top-bar">
                <div class="tasks-branding">
                    <h1>🔁 Daily Habits</h1>
                    <p class="subtitle">Build streaks with recurring tasks</p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&action=add'); ?>" class="btn-new-task">
                    <span class="btn-icon">✨</span> New Habit
                </a>
            </div>

            <div class="premium-tabs">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=kanban'); ?>" class="tab-item">
                    <span class="tab-icon">📊</span> Kanban
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar'); ?>" class="tab-item">
                    <span class="tab-icon">📅</span> Calendar
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=recurring'); ?>" class="tab-item active">
                    <span class="tab-icon">🔁</span> Recurring
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=archive'); ?>" class="tab-item">
                    <span class="tab-icon">📦</span> Archive
                </a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=schedule'); ?>" class="tab-item">
                    <span class="tab-icon">📋</span> Daily Schedule
                </a>
            </div>

            <?php if (empty($recurring_tasks)): ?>
                <div class="empty-state-premium">
                    <div class="empty-icon-large">🔁</div>
                    <h2>No Daily Habits Yet</h2>
                    <p>Create recurring tasks to build consistent habits and track your streaks!</p>
                    <a href="<?php echo admin_url('admin.php?page=wnq-tasks&action=add'); ?>" class="button button-primary button-large">
                        ✨ Create Your First Habit
                    </a>
                </div>
            <?php else: ?>
                <!-- Today's Date -->
                <div class="recurring-header">
                    <h2><?php echo date('l, F j, Y'); ?></h2>
                    <div class="today-stats">
                        <?php 
                        $completed_today = array_filter($recurring_tasks, function($t) { return $t['completed_today']; });
                        $completion_rate = count($recurring_tasks) > 0 ? round((count($completed_today) / count($recurring_tasks)) * 100) : 0;
                        ?>
                        <div class="stat-badge">
                            <span class="stat-number"><?php echo count($completed_today); ?>/<?php echo count($recurring_tasks); ?></span>
                            <span class="stat-label">Completed Today</span>
                        </div>
                        <div class="progress-ring">
                            <svg width="60" height="60">
                                <circle cx="30" cy="30" r="25" fill="none" stroke="#e2e8f0" stroke-width="5"/>
                                <circle cx="30" cy="30" r="25" fill="none" stroke="#10b981" stroke-width="5"
                                        stroke-dasharray="<?php echo 2 * 3.14159 * 25; ?>"
                                        stroke-dashoffset="<?php echo 2 * 3.14159 * 25 * (1 - $completion_rate / 100); ?>"
                                        transform="rotate(-90 30 30)"/>
                                <text x="30" y="36" text-anchor="middle" font-size="14" font-weight="bold" fill="#10b981">
                                    <?php echo $completion_rate; ?>%
                                </text>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Recurring Tasks Grid -->
                <div class="recurring-tasks-grid">
                    <?php foreach ($recurring_tasks as $task): 
                        $assignee_id = $task['assigned_to'] ?? '';
                        $assignee = !empty($assignee_id) && isset($team_members[$assignee_id]) ? $team_members[$assignee_id] : null;
                    ?>
                        <div class="recurring-task-card <?php echo $task['completed_today'] ? 'completed' : ''; ?>">
                            <div class="task-card-top">
                                <div class="task-info">
                                    <h3><?php echo esc_html($task['title']); ?></h3>
                                    <?php if (!empty($task['description'])): ?>
                                        <p class="task-desc"><?php echo esc_html($task['description']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($assignee): ?>
                                        <div class="task-assignee-small">
                                            <span class="mini-avatar" style="background: <?php echo $assignee['color']; ?>">
                                                <?php echo $assignee['initials']; ?>
                                            </span>
                                            <?php echo explode(' ', $assignee['name'])[0]; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="check-form">
                                    <?php wp_nonce_field('wnq_toggle_recurring'); ?>
                                    <input type="hidden" name="action" value="wnq_toggle_recurring">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="date" value="<?php echo $today; ?>">
                                    <button type="submit" class="check-button <?php echo $task['completed_today'] ? 'checked' : ''; ?>">
                                        <?php echo $task['completed_today'] ? '✅' : '⭕'; ?>
                                    </button>
                                </form>
                            </div>

                            <!-- Streak Display -->
                            <div class="streak-display">
                                <div class="streak-stat">
                                    <span class="streak-icon">🔥</span>
                                    <span class="streak-number"><?php echo $task['current_streak']; ?></span>
                                    <span class="streak-label">day streak</span>
                                </div>
                                <div class="streak-stat">
                                    <span class="streak-icon">📊</span>
                                    <span class="streak-number"><?php echo $task['total_completions']; ?></span>
                                    <span class="streak-label">total</span>
                                </div>
                            </div>

                            <!-- Last 7 Days -->
                            <div class="completion-history">
                                <?php for ($i = 6; $i >= 0; $i--): 
                                    $check_date = date('Y-m-d', strtotime("-$i days"));
                                    $is_completed = isset($task['completion_data'][$check_date]);
                                    $is_today_check = ($check_date === $today);
                                ?>
                                    <div class="history-day <?php echo $is_completed ? 'completed' : ''; ?> <?php echo $is_today_check ? 'today' : ''; ?>" 
                                         title="<?php echo date('M j', strtotime($check_date)); ?>">
                                        <div class="day-label"><?php echo date('D', strtotime($check_date)); ?></div>
                                        <div class="day-dot"><?php echo $is_completed ? '●' : '○'; ?></div>
                                    </div>
                                <?php endfor; ?>
                            </div>

                            <!-- Actions -->
                            <div class="task-actions-row">
                                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&action=edit&id=' . $task['id']); ?>" class="btn-action-small">
                                    ✏️ Edit
                                </a>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                    <?php wp_nonce_field('wnq_delete_task'); ?>
                                    <input type="hidden" name="action" value="wnq_delete_task">
                                    <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="redirect" value="recurring">
                                    <button type="submit" onclick="return confirm('Delete this habit?');" class="btn-action-small btn-delete-small">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php self::renderRecurringStyles(); ?>
        <?php
    }

    private static function renderPremiumStyles(): void
    {
        ?>
        <style>
        /* Reset & Base */
        .wnq-tasks-premium { 
            margin: 20px 20px 20px 0; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }
        .wnq-tasks-premium * { box-sizing: border-box; }

        /* Top Bar */
        .tasks-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding: 24px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
        }
        .tasks-branding h1 {
            margin: 0 0 4px;
            font-size: 32px;
            font-weight: 800;
            color: white;
            letter-spacing: -0.5px;
        }
        .subtitle {
            margin: 0;
            font-size: 15px;
            color: rgba(255, 255, 255, 0.9);
        }
        .btn-new-task {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: white;
            color: #667eea;
            font-weight: 700;
            font-size: 15px;
            border-radius: 12px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.2s;
        }
        .btn-new-task:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }
        .btn-icon {
            font-size: 18px;
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            backdrop-filter: blur(10px);
        }

        /* Premium Tabs */
        .premium-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            padding: 8px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .tab-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: #64748b;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .tab-item:hover {
            background: #f8fafc;
            color: #334155;
        }
        .tab-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }
        .tab-icon {
            font-size: 16px;
        }

        /* Stats Dashboard */
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-box {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.2s;
        }
        .stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }
        .stat-icon {
            font-size: 32px;
            flex-shrink: 0;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
            line-height: 1;
        }
        .stat-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        /* Filters Bar */
        .filters-bar {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
        }

        /* Quick Search Bar */
        .quick-search-bar {
            display: flex;
            gap: 16px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
            align-items: center;
            flex-wrap: wrap;
        }
        .search-input-wrapper {
            flex: 1;
            min-width: 300px;
            position: relative;
        }
        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 12px 48px 12px 48px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .clear-search {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            border: none;
            background: #e2e8f0;
            border-radius: 50%;
            color: #64748b;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .clear-search:hover {
            background: #cbd5e1;
        }
        .quick-filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .quick-filter-select {
            padding: 10px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .quick-filter-select:hover {
            border-color: #cbd5e1;
        }
        .quick-filter-select:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn-reset-filters {
            padding: 10px 20px;
            background: #f1f5f9;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-reset-filters:hover {
            background: #e2e8f0;
        }
        .task-card-premium.filtered-out {
            display: none !important;
        }

        /* Time Tracking */
        .time-tracking-display {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #eff6ff;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        .time-icon {
            font-size: 14px;
        }
        .time-logged {
            font-size: 12px;
            font-weight: 700;
            color: #1e40af;
        }

        /* Timer Modal */
        .timer-modal {
            display: none;
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 320px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            overflow: hidden;
        }
        .timer-modal.active {
            display: block;
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .timer-header {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .timer-task-name {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .timer-display {
            font-size: 36px;
            font-weight: 800;
            font-family: 'Courier New', monospace;
            text-align: center;
            margin-bottom: 8px;
        }
        .timer-body {
            padding: 20px;
        }
        .timer-actions {
            display: flex;
            gap: 12px;
        }
        .btn-timer {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-stop-timer {
            background: #dc2626;
            color: white;
        }
        .btn-stop-timer:hover {
            background: #b91c1c;
        }
        .btn-pause-timer {
            background: #f59e0b;
            color: white;
        }
        .btn-pause-timer:hover {
            background: #d97706;
        }

        .filter-section {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .filter-title {
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: #475569;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .filter-btn:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }
        .filter-btn .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            padding: 0 8px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .filter-btn.active .badge {
            background: rgba(255, 255, 255, 0.25);
        }
        .user-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            color: white;
            font-size: 11px;
            font-weight: 700;
        }

        /* Kanban Board */
        .kanban-board-premium {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            min-height: 600px;
        }
        .kanban-column-premium {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            min-height: 500px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        .column-header-premium {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 3px solid;
        }
        .column-title-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .column-icon {
            font-size: 22px;
        }
        .column-title {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: #1e293b;
        }
        .column-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            padding: 0 10px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 800;
        }
        .column-tasks-premium {
            min-height: 400px;
        }
        .column-tasks-premium.drag-over {
            background: rgba(102, 126, 234, 0.05);
            border-radius: 8px;
        }
        .empty-column-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            text-align: center;
        }
        .empty-icon {
            font-size: 48px;
            opacity: 0.5;
            margin-bottom: 12px;
        }
        .empty-column-state p {
            margin: 0;
            color: #94a3b8;
            font-size: 14px;
            font-weight: 600;
        }

        /* Task Card */
        .task-card-premium {
            background: white;
            border-left: 4px solid;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            cursor: grab;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.2s;
        }
        .task-card-premium:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        .task-card-premium.ui-draggable-dragging {
            cursor: grabbing;
            opacity: 0.7;
            transform: rotate(3deg) scale(1.05);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }
        .task-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .task-type-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .task-priority-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .task-card-title {
            margin: 0 0 8px;
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.4;
        }
        .task-card-description {
            margin: 0 0 12px;
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
        }
        .task-countdown-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .countdown-overdue {
            background: #fee2e2;
            color: #991b1b;
        }
        .countdown-today {
            background: #fef3c7;
            color: #92400e;
        }
        .countdown-soon {
            background: #fef3c7;
            color: #92400e;
        }
        .countdown-normal {
            background: #e0f2fe;
            color: #075985;
        }
        .countdown-icon {
            font-size: 14px;
        }
        .task-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }
        .task-footer-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .task-assignee-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            color: white;
            font-size: 12px;
            font-weight: 700;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .task-client-tag {
            padding: 4px 8px;
            background: #f1f5f9;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
        }
        .task-actions-dropdown {
            position: relative;
        }
        .task-menu-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: #f1f5f9;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .task-menu-btn:hover {
            background: #e2e8f0;
            color: #334155;
        }
        .task-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 36px;
            min-width: 150px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            z-index: 100;
            overflow: hidden;
        }
        .task-menu.active {
            display: block;
            animation: slideDown 0.2s ease;
        }
        .task-menu a,
        .task-menu button {
            display: block;
            width: 100%;
            padding: 10px 16px;
            border: none;
            background: none;
            text-align: left;
            text-decoration: none;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .task-menu a:hover,
        .task-menu button:hover {
            background: #f8fafc;
        }
        .task-menu .delete-btn {
            color: #dc2626;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Archive */
        .archive-grid-premium {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
        }
        .archive-card-premium {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.2s;
        }
        .archive-card-premium:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        .archive-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .archive-date-badge {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
        }
        .archive-card-title {
            margin: 0 0 8px;
            font-size: 17px;
            font-weight: 700;
            color: #1e293b;
        }
        .archive-card-description {
            margin: 0 0 12px;
            font-size: 14px;
            color: #64748b;
            line-height: 1.5;
        }
        .archive-card-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .meta-badge {
            padding: 4px 10px;
            background: #f1f5f9;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .archive-card-actions {
            display: flex;
            gap: 8px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
        }
        .btn-restore,
        .btn-delete-archive {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-restore {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-restore:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .btn-delete-archive {
            background: #fee2e2;
            color: #991b1b;
        }
        .btn-delete-archive:hover {
            background: #fecaca;
        }

        /* Empty State */
        .empty-state-premium {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 100px 20px;
            text-align: center;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .empty-icon-large {
            font-size: 80px;
            opacity: 0.5;
            margin-bottom: 20px;
        }
        .empty-state-premium h2 {
            margin: 0 0 8px;
            font-size: 24px;
            color: #1e293b;
        }
        .empty-state-premium p {
            margin: 0;
            color: #64748b;
            font-size: 15px;
        }

        /* Responsive */
        @media (max-width: 1400px) {
            .kanban-board-premium {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 900px) {
            .kanban-board-premium {
                grid-template-columns: 1fr;
            }
            .stats-dashboard {
                grid-template-columns: repeat(2, 1fr);
            }
            .tasks-top-bar {
                flex-direction: column;
                gap: 16px;
                text-align: center;
            }
            .filter-section {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        </style>

        <script>
        function toggleTaskMenu(taskId) {
            const menu = document.getElementById('task-menu-' + taskId);
            const allMenus = document.querySelectorAll('.task-menu');
            allMenus.forEach(m => {
                if (m !== menu) m.classList.remove('active');
            });
            menu.classList.toggle('active');
        }

        // Close menus when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.task-actions-dropdown')) {
                document.querySelectorAll('.task-menu').forEach(m => m.classList.remove('active'));
            }
        });

        // Quick Search & Filter
        const searchInput = document.getElementById('task-search');
        const clearSearchBtn = document.getElementById('clear-search');
        const filterPriority = document.getElementById('filter-priority');
        const filterAssignee = document.getElementById('filter-assignee');
        const resetBtn = document.getElementById('reset-filters');

        function filterTasks() {
            const searchTerm = searchInput.value.toLowerCase();
            const priorityFilter = filterPriority.value;
            const assigneeFilter = filterAssignee.value;
            
            const allCards = document.querySelectorAll('.task-card-premium');
            
            allCards.forEach(card => {
                const title = card.dataset.title || '';
                const description = card.dataset.description || '';
                const client = card.dataset.client || '';
                const priority = card.dataset.priority || '';
                const assignee = card.dataset.assignee || '';
                
                // Search match
                const searchMatch = !searchTerm || 
                    title.includes(searchTerm) || 
                    description.includes(searchTerm) ||
                    client.includes(searchTerm);
                
                // Priority match
                const priorityMatch = !priorityFilter || priority === priorityFilter;
                
                // Assignee match
                const assigneeMatch = !assigneeFilter || 
                    (assigneeFilter === 'unassigned' && !assignee) ||
                    (assigneeFilter !== 'unassigned' && assignee === assigneeFilter);
                
                if (searchMatch && priorityMatch && assigneeMatch) {
                    card.classList.remove('filtered-out');
                } else {
                    card.classList.add('filtered-out');
                }
            });
            
            updateColumnCounts();
            
            // Show/hide clear button
            clearSearchBtn.style.display = searchTerm ? 'block' : 'none';
        }

        searchInput.addEventListener('input', filterTasks);
        filterPriority.addEventListener('change', filterTasks);
        filterAssignee.addEventListener('change', filterTasks);
        
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            filterTasks();
        });
        
        resetBtn.addEventListener('click', function() {
            searchInput.value = '';
            filterPriority.value = '';
            filterAssignee.value = '';
            filterTasks();
        });

        function updateColumnCounts() {
            document.querySelectorAll('.kanban-column-premium').forEach(column => {
                const visibleCards = column.querySelectorAll('.task-card-premium:not(.filtered-out)').length;
                column.querySelector('.column-badge').textContent = visibleCards;
            });
        }

        // Time Tracking
        let activeTimer = null;
        let timerInterval = null;
        let startTime = null;
        let elapsedSeconds = 0;

        function startTimer(taskId, taskTitle) {
            if (activeTimer) {
                alert('Please stop the current timer first!');
                return;
            }
            
            activeTimer = taskId;
            startTime = Date.now();
            elapsedSeconds = 0;
            
            // Create timer modal
            const modal = document.createElement('div');
            modal.className = 'timer-modal active';
            modal.id = 'timer-modal';
            modal.innerHTML = `
                <div class="timer-header">
                    <div class="timer-task-name">${taskTitle}</div>
                    <div class="timer-display" id="timer-display">00:00:00</div>
                </div>
                <div class="timer-body">
                    <div class="timer-actions">
                        <button class="btn-timer btn-stop-timer" onclick="stopTimer(${taskId})">⏹️ Stop & Save</button>
                        <button class="btn-timer btn-pause-timer" onclick="closeTimer()">✕ Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Start interval
            timerInterval = setInterval(updateTimerDisplay, 1000);
            updateTimerDisplay();
            
            // Close all menus
            document.querySelectorAll('.task-menu').forEach(m => m.classList.remove('active'));
        }

        function updateTimerDisplay() {
            if (!startTime) return;
            
            elapsedSeconds = Math.floor((Date.now() - startTime) / 1000);
            
            const hours = Math.floor(elapsedSeconds / 3600);
            const minutes = Math.floor((elapsedSeconds % 3600) / 60);
            const seconds = elapsedSeconds % 60;
            
            const display = document.getElementById('timer-display');
            if (display) {
                display.textContent = 
                    String(hours).padStart(2, '0') + ':' +
                    String(minutes).padStart(2, '0') + ':' +
                    String(seconds).padStart(2, '0');
            }
        }

        function stopTimer(taskId) {
            if (!activeTimer) return;
            
            clearInterval(timerInterval);
            
            const hours = Math.floor(elapsedSeconds / 3600);
            const minutes = Math.floor((elapsedSeconds % 3600) / 60);
            
            // Save via AJAX
            jQuery.post(ajaxurl, {
                action: 'wnq_log_time',
                task_id: taskId,
                duration: elapsedSeconds,
                nonce: '<?php echo wp_create_nonce('wnq_log_time'); ?>'
            }, function(response) {
                if (response.success) {
                    alert(`Time logged: ${hours}h ${minutes}m`);
                    location.reload();
                } else {
                    alert('Failed to log time. Please try manually.');
                }
            });
            
            closeTimer();
        }

        function closeTimer() {
            clearInterval(timerInterval);
            const modal = document.getElementById('timer-modal');
            if (modal) modal.remove();
            activeTimer = null;
            startTime = null;
            elapsedSeconds = 0;
        }

        function logTime(taskId) {
            const hours = prompt('How many hours did you work on this task?', '0');
            if (hours === null) return;
            
            const minutes = prompt('How many minutes?', '0');
            if (minutes === null) return;
            
            const totalSeconds = (parseInt(hours) || 0) * 3600 + (parseInt(minutes) || 0) * 60;
            
            if (totalSeconds <= 0) {
                alert('Please enter a valid time');
                return;
            }
            
            jQuery.post(ajaxurl, {
                action: 'wnq_log_time',
                task_id: taskId,
                duration: totalSeconds,
                nonce: '<?php echo wp_create_nonce('wnq_log_time'); ?>'
            }, function(response) {
                if (response.success) {
                    alert('Time logged successfully!');
                    location.reload();
                } else {
                    alert('Failed to log time');
                }
            });
            
            // Close menu
            document.querySelectorAll('.task-menu').forEach(m => m.classList.remove('active'));
        }
        </script>
        <?php
    }

    private static function renderPremiumScript(): void
    {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Make task cards draggable
            $('.task-card-premium').draggable({
                revert: 'invalid',
                helper: 'clone',
                cursor: 'grabbing',
                opacity: 0.8,
                zIndex: 1000,
                start: function(event, ui) {
                    $(this).addClass('ui-draggable-dragging');
                    ui.helper.css({
                        'width': $(this).outerWidth(),
                        'transform': 'rotate(3deg) scale(1.05)'
                    });
                },
                stop: function() {
                    $(this).removeClass('ui-draggable-dragging');
                }
            });

            // Make columns droppable
            $('.column-tasks-premium').droppable({
                accept: '.task-card-premium',
                tolerance: 'pointer',
                over: function() {
                    $(this).addClass('drag-over');
                },
                out: function() {
                    $(this).removeClass('drag-over');
                },
                drop: function(event, ui) {
                    $(this).removeClass('drag-over');
                    
                    const $card = ui.draggable;
                    const taskId = $card.data('task-id');
                    const newStatus = $(this).parent().data('status');
                    const $targetColumn = $(this);
                    
                    // Remove empty state if exists
                    $targetColumn.find('.empty-column-state').remove();
                    
                    // Append card to new column
                    $card.css({top: 0, left: 0, position: 'relative'});
                    $targetColumn.append($card);
                    
                    // Update counts
                    updateColumnCounts();
                    
                    // AJAX update
                    $.post(ajaxurl, {
                        action: 'wnq_update_task_status',
                        task_id: taskId,
                        status: newStatus,
                        nonce: '<?php echo wp_create_nonce('wnq_task_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            // Show success feedback
                            showNotification('Task moved successfully', 'success');
                        } else {
                            // Revert on error
                            showNotification('Failed to update task', 'error');
                            setTimeout(() => location.reload(), 1000);
                        }
                    }).fail(function() {
                        showNotification('Network error', 'error');
                        setTimeout(() => location.reload(), 1000);
                    });
                }
            });

            function updateColumnCounts() {
                $('.kanban-column-premium').each(function() {
                    const count = $(this).find('.task-card-premium').length;
                    $(this).find('.column-badge').text(count);
                    
                    // Show/hide empty state
                    const $tasks = $(this).find('.column-tasks-premium');
                    if (count === 0 && !$tasks.find('.empty-column-state').length) {
                        const icon = $(this).find('.column-icon').text();
                        $tasks.append(
                            '<div class="empty-column-state">' +
                            '<div class="empty-icon">' + icon + '</div>' +
                            '<p>No tasks yet</p>' +
                            '</div>'
                        );
                    }
                });
            }

            function showNotification(message, type) {
                const colors = {
                    success: '#10b981',
                    error: '#dc2626'
                };
                
                const $notification = $('<div>')
                    .css({
                        position: 'fixed',
                        top: '20px',
                        right: '20px',
                        padding: '16px 24px',
                        background: colors[type],
                        color: 'white',
                        borderRadius: '12px',
                        fontWeight: '600',
                        fontSize: '14px',
                        boxShadow: '0 4px 16px rgba(0,0,0,0.2)',
                        zIndex: 10000,
                        animation: 'slideIn 0.3s ease'
                    })
                    .text(message)
                    .appendTo('body');
                
                setTimeout(() => {
                    $notification.fadeOut(300, () => $notification.remove());
                }, 3000);
            }
        });
        </script>

        <style>
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        </style>
        <?php
    }

    // Form rendering methods (keeping same as before)
    private static function renderAddForm(): void
    {
        ?>
        <div class="wrap wnq-tasks-premium">
            <div class="tasks-top-bar">
                <div class="tasks-branding">
                    <h1>✨ New Task</h1>
                    <p class="subtitle">Create a new task</p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks'); ?>" class="btn-new-task btn-secondary">
                    ← Back to Kanban
                </a>
            </div>
            <?php self::renderTaskForm(null); ?>
        </div>
        <?php
    }

    private static function renderEditForm(int $id): void
    {
        $task = Task::getById($id);
        if (!$task) wp_die('Task not found.');
        ?>
        <div class="wrap wnq-tasks-premium">
            <div class="tasks-top-bar">
                <div class="tasks-branding">
                    <h1>✏️ Edit Task</h1>
                    <p class="subtitle"><?php echo esc_html($task['title']); ?></p>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks'); ?>" class="btn-new-task btn-secondary">
                    ← Back to Kanban
                </a>
            </div>
            <?php self::renderTaskForm($task); ?>
        </div>
        <?php
    }

    private static function renderTaskForm(?array $task): void
    {
        $is_edit = !empty($task);
        
        $clients = [];
        if (file_exists(WNQ_PORTAL_PATH . 'includes/Models/Client.php')) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
            if (class_exists('WNQ\\Models\\Client')) {
                $clients = \WNQ\Models\Client::getAll();
            }
        }

        $team_members = [
            'christopher-scotto' => 'Christopher Scotto',
            'christopher-sanders' => 'Christopher Sanders',
        ];

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="task-form-premium">
            <?php wp_nonce_field('wnq_save_task'); ?>
            <input type="hidden" name="action" value="wnq_save_task">
            <?php if ($is_edit): ?>
                <input type="hidden" name="id" value="<?php echo esc_attr($task['id']); ?>">
            <?php endif; ?>

            <div class="form-section-premium">
                <table class="form-table">
                    <tr>
                        <th><label for="title">Task Title *</label></th>
                        <td><input type="text" name="title" id="title" value="<?php echo esc_attr($task['title'] ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="description">Description</label></th>
                        <td><textarea name="description" id="description" rows="5" class="large-text"><?php echo esc_textarea($task['description'] ?? ''); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="task_type">Task Type</label></th>
                        <td>
                            <select name="task_type" id="task_type" class="regular-text">
                                <option value="general" <?php selected($task['task_type'] ?? 'general', 'general'); ?>>📝 General</option>
                                <option value="client" <?php selected($task['task_type'] ?? '', 'client'); ?>>🏢 Client Task</option>
                                <option value="webnique" <?php selected($task['task_type'] ?? '', 'webnique'); ?>>🏪 WebNique Task</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text">
                                <option value="todo" <?php selected($task['status'] ?? 'todo', 'todo'); ?>>To Do</option>
                                <option value="in_progress" <?php selected($task['status'] ?? '', 'in_progress'); ?>>In Progress</option>
                                <option value="review" <?php selected($task['status'] ?? '', 'review'); ?>>Review</option>
                                <option value="done" <?php selected($task['status'] ?? '', 'done'); ?>>Done</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="priority">Priority</label></th>
                        <td>
                            <select name="priority" id="priority" class="regular-text">
                                <option value="low" <?php selected($task['priority'] ?? '', 'low'); ?>>Low</option>
                                <option value="medium" <?php selected($task['priority'] ?? 'medium', 'medium'); ?>>Medium</option>
                                <option value="high" <?php selected($task['priority'] ?? '', 'high'); ?>>High</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="assigned_to">Assign To</label></th>
                        <td>
                            <select name="assigned_to" id="assigned_to" class="regular-text">
                                <option value="">Unassigned</option>
                                <?php foreach ($team_members as $id => $name): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($task['assigned_to'] ?? '', $id); ?>><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="due_date">Due Date</label></th>
                        <td><input type="date" name="due_date" id="due_date" value="<?php echo esc_attr($task['due_date'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="is_recurring">Daily Habit</label></th>
                        <td>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="is_recurring" id="is_recurring" value="1" 
                                       <?php checked($task['is_recurring'] ?? 0, 1); ?>>
                                <span>This is a recurring daily habit (appears in Recurring tab with streak tracking)</span>
                            </label>
                        </td>
                    </tr>
                    <?php if (!empty($clients)): ?>
                    <tr>
                        <th><label for="client_id">Client (Optional)</label></th>
                        <td>
                            <select name="client_id" id="client_id" class="regular-text">
                                <option value="">No client</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo esc_attr($client['client_id']); ?>" <?php selected($task['client_id'] ?? '', $client['client_id']); ?>>
                                        <?php echo esc_html($client['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><label for="notes">Notes</label></th>
                        <td><textarea name="notes" id="notes" rows="4" class="large-text"><?php echo esc_textarea($task['notes'] ?? ''); ?></textarea></td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <?php echo $is_edit ? '💾 Update Task' : '✨ Create Task'; ?>
                </button>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks'); ?>" class="button button-large">Cancel</a>
            </p>
        </form>

        <style>
        .form-section-premium {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }
        </style>
        <?php
    }

    // Form handlers
    public static function handleSaveTask(): void
    {
        check_admin_referer('wnq_save_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $data = [
            'title' => sanitize_text_field($_POST['title'] ?? ''),
            'description' => wp_kses_post($_POST['description'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'todo'),
            'task_type' => sanitize_text_field($_POST['task_type'] ?? 'general'),
            'priority' => sanitize_text_field($_POST['priority'] ?? 'medium'),
            'assigned_to' => sanitize_text_field($_POST['assigned_to'] ?? ''),
            'due_date' => sanitize_text_field($_POST['due_date'] ?? ''),
            'client_id' => sanitize_text_field($_POST['client_id'] ?? ''),
            'notes' => wp_kses_post($_POST['notes'] ?? ''),
            'is_recurring' => isset($_POST['is_recurring']) ? 1 : 0,
        ];

        $success = $id ? Task::update($id, $data) : Task::create($data);
        wp_redirect(admin_url('admin.php?page=wnq-tasks'));
        exit;
    }

    public static function handleDeleteTask(): void
    {
        check_admin_referer('wnq_delete_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        Task::delete($id);
        
        $redirect = isset($_POST['redirect']) ? sanitize_text_field($_POST['redirect']) : 'kanban';
        wp_redirect(admin_url('admin.php?page=wnq-tasks&view=' . $redirect));
        exit;
    }

    public static function handleArchive(): void
    {
        check_admin_referer('wnq_archive_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        Task::archive($id);
        wp_redirect(admin_url('admin.php?page=wnq-tasks'));
        exit;
    }

    public static function handleRestore(): void
    {
        check_admin_referer('wnq_restore_task');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        Task::restore($id);
        wp_redirect(admin_url('admin.php?page=wnq-tasks&view=archive'));
        exit;
    }

    public static function ajaxUpdateTaskStatus(): void
    {
        check_ajax_referer('wnq_task_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if (!$task_id || !$status) wp_send_json_error('Invalid data');

        $success = Task::update($task_id, ['status' => $status]);
        $success ? wp_send_json_success() : wp_send_json_error('Failed');
    }

    public static function handleToggleRecurring(): void
    {
        check_admin_referer('wnq_toggle_recurring');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : date('Y-m-d');

        $task = Task::getById($task_id);
        if (!$task) wp_die('Task not found');

        // Get current completion data from notes
        $completion_data = !empty($task['notes']) ? json_decode($task['notes'], true) : [];
        if (!is_array($completion_data)) {
            $completion_data = [];
        }

        // Toggle today's completion
        if (isset($completion_data[$date])) {
            unset($completion_data[$date]); // Uncheck
        } else {
            $completion_data[$date] = true; // Check
        }

        // Save back to notes as JSON
        Task::update($task_id, ['notes' => json_encode($completion_data)]);

        wp_redirect(admin_url('admin.php?page=wnq-tasks&view=recurring'));
        exit;
    }

    public static function handleSaveJournal(): void
    {
        check_admin_referer('wnq_save_journal');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : date('Y-m-d');
        
        $journal_data = [
            'mood' => sanitize_text_field($_POST['mood'] ?? ''),
            'accomplishments' => wp_kses_post($_POST['accomplishments'] ?? ''),
            'challenges' => wp_kses_post($_POST['challenges'] ?? ''),
            'notes' => wp_kses_post($_POST['notes'] ?? ''),
            'gratitude' => wp_kses_post($_POST['gratitude'] ?? ''),
            'saved_at' => current_time('mysql'),
        ];

        update_option('wnq_journal_' . $date, $journal_data);

        wp_redirect(admin_url('admin.php?page=wnq-tasks&view=journal&date=' . $date . '&saved=1'));
        exit;
    }

    public static function ajaxLogTime(): void
    {
        check_ajax_referer('wnq_log_time', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        $duration = isset($_POST['duration']) ? intval($_POST['duration']) : 0;

        if (!$task_id || $duration <= 0) wp_send_json_error('Invalid data');

        // Get existing time logs
        $time_logs = get_post_meta($task_id, '_wnq_time_logs', true);
        if (!is_array($time_logs)) {
            $time_logs = [];
        }

        // Add new log entry
        $time_logs[] = [
            'duration' => $duration,
            'date' => current_time('mysql'),
            'user' => get_current_user_id(),
        ];

        // Save time logs
        $success = update_post_meta($task_id, '_wnq_time_logs', $time_logs);

        $success ? wp_send_json_success() : wp_send_json_error('Failed to save');
    }

    private static function renderCalendarStyles(): void
    {
        ?>
        <style>
        .calendar-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px;
            background: white;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .btn-nav {
            padding: 10px 20px;
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-nav:hover {
            background: #e2e8f0;
            transform: translateY(-1px);
        }
        .week-display {
            text-align: center;
        }
        .week-display h2 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #1e293b;
        }
        .btn-today {
            padding: 6px 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 16px;
        }
        .calendar-day {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            min-height: 300px;
            display: flex;
            flex-direction: column;
        }
        .calendar-day.is-today {
            border: 2px solid #10b981;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.2);
        }
        .calendar-day.is-past {
            opacity: 0.7;
        }
        .day-header {
            padding: 16px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }
        .is-today .day-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        .day-name {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }
        .is-today .day-name {
            color: rgba(255, 255, 255, 0.9);
        }
        .day-number {
            font-size: 20px;
            font-weight: 800;
            color: #1e293b;
        }
        .is-today .day-number {
            color: white;
        }
        .today-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 4px 8px;
            background: rgba(255, 255, 255, 0.9);
            color: #10b981;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .day-tasks {
            padding: 12px;
            flex: 1;
            overflow-y: auto;
        }
        .no-tasks {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
            font-size: 13px;
        }
        .calendar-task {
            background: #f8fafc;
            border-left: 4px solid;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .calendar-task:hover {
            background: #f1f5f9;
            transform: translateX(2px);
        }
        .task-status-icon {
            font-size: 16px;
        }
        .task-info {
            flex: 1;
        }
        .task-title {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .task-assignee {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #64748b;
        }
        .mini-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            color: white;
            font-size: 9px;
            font-weight: 700;
        }
        .task-link {
            color: #667eea;
            font-size: 18px;
            text-decoration: none;
            font-weight: 700;
        }
        @media (max-width: 1400px) {
            .calendar-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        @media (max-width: 900px) {
            .calendar-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        </style>
        <?php
    }

    private static function renderRecurringStyles(): void
    {
        ?>
        <style>
        .recurring-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px;
            background: white;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .recurring-header h2 {
            margin: 0;
            font-size: 24px;
            color: #1e293b;
        }
        .today-stats {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        .stat-badge {
            text-align: center;
        }
        .stat-number {
            display: block;
            font-size: 28px;
            font-weight: 800;
            color: #10b981;
        }
        .stat-label {
            display: block;
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 4px;
        }
        .recurring-tasks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }
        .recurring-task-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.2s;
        }
        .recurring-task-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        .recurring-task-card.completed {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 2px solid #10b981;
        }
        .task-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        .task-info h3 {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }
        .task-desc {
            margin: 0 0 12px;
            font-size: 14px;
            color: #64748b;
            line-height: 1.5;
        }
        .task-assignee-small {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #64748b;
        }
        .check-form {
            margin: 0;
        }
        .check-button {
            width: 60px;
            height: 60px;
            border: 3px solid #e2e8f0;
            background: white;
            border-radius: 50%;
            font-size: 32px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .check-button:hover {
            border-color: #10b981;
            transform: scale(1.1);
        }
        .check-button.checked {
            background: #10b981;
            border-color: #10b981;
            animation: checkPulse 0.3s ease;
        }
        @keyframes checkPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }
        .streak-display {
            display: flex;
            gap: 24px;
            padding: 16px;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .recurring-task-card.completed .streak-display {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
        }
        .streak-stat {
            text-align: center;
            flex: 1;
        }
        .streak-icon {
            display: block;
            font-size: 24px;
            margin-bottom: 4px;
        }
        .streak-number {
            display: block;
            font-size: 28px;
            font-weight: 800;
            color: #1e293b;
        }
        .streak-label {
            display: block;
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 4px;
        }
        .completion-history {
            display: flex;
            gap: 8px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .history-day {
            flex: 1;
            text-align: center;
        }
        .day-label {
            font-size: 10px;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .day-dot {
            font-size: 20px;
            color: #e2e8f0;
        }
        .history-day.completed .day-dot {
            color: #10b981;
        }
        .history-day.today {
            background: rgba(102, 126, 234, 0.1);
            border-radius: 6px;
            padding: 4px;
        }
        .task-actions-row {
            display: flex;
            gap: 8px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
        }
        .btn-action-small {
            flex: 1;
            padding: 8px;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-action-small:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        .btn-delete-small {
            color: #dc2626;
            background: #fee2e2;
            border-color: #fecaca;
        }
        .btn-delete-small:hover {
            background: #fecaca;
        }
        </style>
        <?php
    }

    private static function renderJournalStyles(): void
    {
        ?>
        <style>
        .journal-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px;
            background: white;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .journal-date {
            text-align: center;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .journal-date h2 {
            margin: 0;
            font-size: 20px;
            color: #1e293b;
        }
        .journal-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .stat-card .stat-icon {
            font-size: 40px;
        }
        .journal-form {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .journal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-bottom: 24px;
        }
        .journal-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .journal-section h3 {
            margin: 0 0 12px;
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
        }
        .mood-selector {
            display: flex;
            gap: 12px;
        }
        .mood-option {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px;
            border: 3px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .mood-option input {
            display: none;
        }
        .mood-option:hover {
            border-color: #cbd5e1;
            transform: translateY(-2px);
        }
        .mood-option.selected {
            border-color: var(--mood-color);
            background: var(--mood-color);
            color: white;
        }
        .mood-emoji {
            font-size: 32px;
        }
        .mood-label {
            font-size: 13px;
            font-weight: 700;
        }
        .journal-textarea {
            width: 100%;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            line-height: 1.6;
            resize: vertical;
            transition: all 0.2s;
        }
        .journal-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .completed-tasks-details {
            margin-top: 12px;
            padding: 12px;
            background: #f0fdf4;
            border-radius: 8px;
            border-left: 4px solid #10b981;
        }
        .completed-tasks-details summary {
            font-size: 13px;
            font-weight: 700;
            color: #065f46;
            cursor: pointer;
            user-select: none;
        }
        .completed-tasks-list {
            margin: 12px 0 0;
            padding-left: 24px;
            list-style: none;
        }
        .completed-tasks-list li {
            padding: 6px 0;
            font-size: 13px;
            color: #059669;
            position: relative;
        }
        .completed-tasks-list li:before {
            content: "✓";
            position: absolute;
            left: -20px;
            font-weight: bold;
        }
        .journal-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 24px;
            border-top: 2px solid #f1f5f9;
        }
        .btn-save-journal {
            padding: 16px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .btn-save-journal:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }
        .last-saved {
            font-size: 13px;
            color: #64748b;
            font-style: italic;
        }
        @media (max-width: 1200px) {
            .journal-grid {
                grid-template-columns: 1fr;
            }
            .journal-stats {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }


    // ── Daily Schedule View ─────────────────────────────────────────────────

    private static function renderScheduleView(): void
    {
        $today_num  = (int)date('N'); // 1=Mon … 7=Sun
        $today_date = date('Y-m-d');
        $is_sunday  = ($today_num === 7);

        $schedule = [
            1 => ['day' => 'Monday',    'color' => '#6366f1', 'light' => '#eef2ff',
                  'focus' => "Work on Stephen's site",  'media' => 'Post YouTube Video'],
            2 => ['day' => 'Tuesday',   'color' => '#0ea5e9', 'light' => '#e0f2fe',
                  'focus' => "Work on Maurice's site",  'media' => 'Record YouTube Video'],
            3 => ['day' => 'Wednesday', 'color' => '#10b981', 'light' => '#d1fae5',
                  'focus' => "Work on Sam's site",      'media' => 'Post YouTube Video'],
            4 => ['day' => 'Thursday',  'color' => '#f59e0b', 'light' => '#fef3c7',
                  'focus' => "Work on WebNique's site", 'media' => 'Record YouTube Video'],
            5 => ['day' => 'Friday',    'color' => '#ef4444', 'light' => '#fee2e2',
                  'focus' => 'WebNique development',    'media' => 'Post YouTube Video'],
            6 => ['day' => 'Saturday',  'color' => '#8b5cf6', 'light' => '#ede9fe',
                  'focus' => 'Catch up on past tasks',  'media' => 'Record YouTube Video'],
            7 => ['day' => 'Sunday',    'color' => '#64748b', 'light' => '#f1f5f9',
                  'focus' => 'Rest',                    'media' => null],
        ];

        $essentials = [
            ['key' => 'socials', 'icon' => '📱', 'label' => 'Post on socials',        'detail' => 'Facebook & Instagram'],
            ['key' => 'calls',   'icon' => '📞', 'label' => 'Cold call 200 people',   'detail' => '200 outbound calls'],
            ['key' => 'emails',  'icon' => '📧', 'label' => 'Cold email 50 people',   'detail' => '50 personalised emails'],
            ['key' => 'friends', 'icon' => '🤝', 'label' => 'Reach out to 10 people', 'detail' => 'Friends & family referrals'],
        ];

        $td        = $schedule[$today_num];
        $has_media = !empty($td['media']);
        $task_keys = $is_sunday
            ? ['rest']
            : array_values(array_filter(array_merge(
                ['focus', $has_media ? 'media' : null],
                ['socials', 'calls', 'emails', 'friends']
              )));
        $task_count = count($task_keys);

        // ISO dates for Mon–Sun of the current week
        $week_mon   = strtotime('midnight -' . ($today_num - 1) . ' days');
        $week_dates = [];
        for ($i = 0; $i < 7; $i++) {
            $week_dates[$i + 1] = date('Y-m-d', strtotime("+{$i} days", $week_mon));
        }
        ?>
        <div class="wrap wnq-tasks-wrap">

            <div class="tasks-header">
                <div class="header-left">
                    <h1>📋 Daily Schedule</h1>
                    <p class="subtitle">Mission-critical tasks — check them off each day to keep the company alive</p>
                </div>
            </div>

            <div class="premium-tabs">
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=kanban'); ?>" class="tab-item"><span class="tab-icon">📊</span> Kanban</a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=calendar'); ?>" class="tab-item"><span class="tab-icon">📅</span> Calendar</a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=recurring'); ?>" class="tab-item"><span class="tab-icon">🔁</span> Recurring</a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=journal'); ?>" class="tab-item"><span class="tab-icon">📓</span> Daily Journal</a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=archive'); ?>" class="tab-item"><span class="tab-icon">📦</span> Archive</a>
                <a href="<?php echo admin_url('admin.php?page=wnq-tasks&view=schedule'); ?>" class="tab-item active"><span class="tab-icon">📋</span> Daily Schedule</a>
            </div>

            <?php if ($is_sunday): ?>
            <!-- Sunday: rest card -->
            <div class="sched-sunday">
                <div class="sched-sunday-emoji">😴</div>
                <h2>Sunday — Rest Day</h2>
                <p>Recovery is part of the strategy. Recharge so you can execute at full capacity Monday through Saturday.</p>
                <div class="sched-sunday-peek">
                    <strong>Tomorrow (Monday):</strong>
                    <?php echo esc_html($schedule[1]['focus']); ?> + <?php echo esc_html($schedule[1]['media']); ?>
                </div>
            </div>
            <?php else: ?>

            <!-- Main: ring + checklist -->
            <div class="sched-main">
                <!-- Left: progress ring -->
                <div class="sched-ring-col">
                    <div class="sched-day-chip" style="background:<?php echo $td['light']; ?>;color:<?php echo $td['color']; ?>;">
                        <?php echo esc_html(date('l, F j')); ?>
                    </div>
                    <div class="sched-ring-wrap">
                        <svg viewBox="0 0 88 88" class="sched-ring-svg">
                            <circle cx="44" cy="44" r="36" class="sched-ring-track"/>
                            <circle cx="44" cy="44" r="36" class="sched-ring-fill" id="sched-ring-fill"/>
                        </svg>
                        <div class="sched-ring-inner">
                            <div class="sched-ring-n" id="sched-ring-n">0</div>
                            <div class="sched-ring-of">/ <?php echo $task_count; ?></div>
                            <div class="sched-ring-lbl">done</div>
                        </div>
                    </div>
                    <div class="sched-ring-msg" id="sched-ring-msg"><?php echo $task_count; ?> tasks remaining</div>
                    <button class="sched-reset-btn" onclick="schedReset()">↺ Reset today</button>
                </div>

                <!-- Right: task checklists -->
                <div class="sched-checks-col">
                    <div class="sched-section-hd" style="color:<?php echo $td['color']; ?>">Today's Focus</div>
                    <?php self::schedCheckItem('focus', '💼', $td['focus'], 'Main client work block'); ?>
                    <?php if ($has_media): ?>
                        <?php self::schedCheckItem('media', '🎬', $td['media'], 'YouTube content'); ?>
                    <?php endif; ?>

                    <div class="sched-section-hd" style="color:#dc2626;margin-top:18px;">
                        ⚡ Daily Non-Negotiables
                        <span class="sched-section-sub">every workday — without these the pipeline dies</span>
                    </div>
                    <?php foreach ($essentials as $e): ?>
                        <?php self::schedCheckItem($e['key'], $e['icon'], $e['label'], $e['detail']); ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- All-done banner -->
            <div class="sched-all-done" id="sched-all-done" style="display:none;">
                🎉 <strong>All tasks complete!</strong> Every mission-critical item is done. The company survives another day.
            </div>

            <?php endif; ?>

            <!-- Week strip -->
            <div class="sched-week-row">
                <span class="sched-week-label">This Week</span>
                <span class="sched-week-pct" id="sched-week-pct"></span>
            </div>
            <div class="sched-week-strip">
                <?php foreach ($schedule as $num => $day):
                    $date     = $week_dates[$num];
                    $is_today = ($num === $today_num);
                    $is_past  = ($date < $today_date);
                    $day_sun  = ($num === 7);
                    $tc       = $day_sun ? 1 : ($day['media'] ? 6 : 5);
                ?>
                <div class="sched-strip-card <?php echo $is_today ? 'sched-strip-today' : ''; ?> <?php echo $is_past ? 'sched-strip-past' : ''; ?>"
                     data-date="<?php echo $date; ?>" data-tasks="<?php echo $tc; ?>"
                     style="<?php echo $is_today ? "border-color:{$day['color']};background:{$day['light']};" : ''; ?>">
                    <div class="sched-strip-name" style="color:<?php echo ($is_today || (!$is_past && $num < 7)) ? $day['color'] : '#94a3b8'; ?>">
                        <?php echo esc_html(substr($day['day'], 0, 3)); ?>
                        <?php if ($is_today): ?><span class="sched-strip-dot">●</span><?php endif; ?>
                    </div>
                    <div class="sched-strip-focus"><?php echo esc_html($day_sun ? 'Rest' : $day['focus']); ?></div>
                    <?php if ($day['media']): ?>
                        <div class="sched-strip-media">🎬 <?php echo esc_html($day['media']); ?></div>
                    <?php endif; ?>
                    <div class="sched-strip-prog" id="sched-sprog-<?php echo $num; ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>

        </div><!-- .wnq-tasks-wrap -->

        <script>
        (function() {
            var TODAY      = '<?php echo esc_js($today_date); ?>';
            var TASK_COUNT = <?php echo (int)$task_count; ?>;
            var IS_SUNDAY  = <?php echo $is_sunday ? 'true' : 'false'; ?>;
            var DAY_COLOR  = '<?php echo esc_js($td['color']); ?>';
            var WEEK_DATES = <?php echo json_encode($week_dates); ?>;
            // tasks per weekday [1-indexed, matching schedule keys]
            var WEEK_TASKS = <?php
                $wt = [];
                foreach (range(1, 7) as $n) {
                    $wt[$n] = ($n === 7) ? 1 : ($schedule[$n]['media'] ? 6 : 5);
                }
                echo json_encode($wt);
            ?>;

            // ── Storage helpers ──────────────────────────────────────────
            function getAll() {
                try { return JSON.parse(localStorage.getItem('wnq_sched_v2') || '{}'); }
                catch (e) { return {}; }
            }
            function saveAll(d) {
                localStorage.setItem('wnq_sched_v2', JSON.stringify(d));
            }
            function getDay(dt) { return getAll()[dt] || {}; }
            function saveDay(dt, data) {
                var all = getAll();
                all[dt] = data;
                // Prune entries older than 60 days
                var cut = new Date(); cut.setDate(cut.getDate() - 60);
                Object.keys(all).forEach(function(k) { if (new Date(k) < cut) delete all[k]; });
                saveAll(all);
            }
            function countDone(d) { return Object.values(d).filter(Boolean).length; }

            // ── Progress ring ────────────────────────────────────────────
            function setRing(done, total) {
                var fill = document.getElementById('sched-ring-fill');
                if (!fill) return;
                var r = 36, circ = 2 * Math.PI * r;
                fill.style.strokeDasharray  = circ;
                fill.style.strokeDashoffset = circ * (1 - done / Math.max(total, 1));
                fill.style.stroke = done >= total ? '#16a34a' : DAY_COLOR;
            }

            // ── Main checklist UI ────────────────────────────────────────
            function updateMain() {
                if (IS_SUNDAY) return;
                var data = getDay(TODAY);
                var done = 0;
                document.querySelectorAll('.sched-cb').forEach(function(cb) {
                    var checked = !!data[cb.dataset.key];
                    cb.checked = checked;
                    done += checked ? 1 : 0;
                    var item = cb.closest('.sched-check-item');
                    if (item) item.classList.toggle('is-done', checked);
                });
                var nEl    = document.getElementById('sched-ring-n');
                var msgEl  = document.getElementById('sched-ring-msg');
                var doneEl = document.getElementById('sched-all-done');
                if (nEl) nEl.textContent = done;
                setRing(done, TASK_COUNT);
                var rem = TASK_COUNT - done;
                if (msgEl) {
                    msgEl.textContent = rem === 0
                        ? 'All tasks complete — great work! 🎉'
                        : rem + ' task' + (rem !== 1 ? 's' : '') + ' remaining';
                    msgEl.style.color = rem === 0 ? '#16a34a' : '#64748b';
                }
                if (doneEl) doneEl.style.display = rem === 0 ? '' : 'none';
            }

            // ── Week strip completion indicators ─────────────────────────
            function updateStrip() {
                var all = getAll();
                var totalCompleteDays = 0, totalTrackableDays = 0;

                for (var num = 1; num <= 7; num++) {
                    var el = document.getElementById('sched-sprog-' + num);
                    if (!el) continue;
                    var dt = WEEK_DATES[num];
                    var tc = WEEK_TASKS[num];
                    if (!dt) continue;

                    if (dt > TODAY) {
                        el.innerHTML = '';
                        continue;
                    }
                    var d    = all[dt] || {};
                    var done = countDone(d);

                    if (num === 7) { // Sunday
                        el.innerHTML = '';
                        continue;
                    }

                    totalTrackableDays++;
                    if (done >= tc) totalCompleteDays++;

                    if (dt === TODAY && !IS_SUNDAY) {
                        el.innerHTML = '<span class="sp-today">' + done + '/' + tc + ' done</span>';
                    } else if (done >= tc) {
                        el.innerHTML = '<span class="sp-done">✓ All done</span>';
                    } else if (done > 0) {
                        el.innerHTML = '<span class="sp-partial">' + done + '/' + tc + ' done</span>';
                    } else {
                        el.innerHTML = '<span class="sp-missed">—</span>';
                    }
                }

                // Week summary %
                var pctEl = document.getElementById('sched-week-pct');
                if (pctEl && totalTrackableDays > 0) {
                    var pct = Math.round(totalCompleteDays / totalTrackableDays * 100);
                    pctEl.textContent = pct + '% of days fully completed';
                    pctEl.style.color = pct >= 80 ? '#16a34a' : pct >= 50 ? '#f59e0b' : '#dc2626';
                }
            }

            // ── Event handlers ───────────────────────────────────────────
            document.querySelectorAll('.sched-cb').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var data = getDay(TODAY);
                    data[this.dataset.key] = this.checked;
                    saveDay(TODAY, data);
                    updateMain();
                    updateStrip();
                });
            });

            window.schedReset = function() {
                if (!confirm("Reset today's progress?")) return;
                saveDay(TODAY, {});
                updateMain();
                updateStrip();
            };

            // ── Init ─────────────────────────────────────────────────────
            updateMain();
            updateStrip();
        })();
        </script>
        <?php self::renderScheduleStyles(); ?>
        <?php
    }

    private static function schedCheckItem(string $key, string $icon, string $label, string $detail): void
    { ?>
        <label class="sched-check-item">
            <input type="checkbox" class="sched-cb" data-key="<?php echo esc_attr($key); ?>">
            <span class="sched-cbx">
                <svg viewBox="0 0 10 8" fill="none" width="11" height="9">
                    <polyline points="1,4 3.5,6.5 9,1" stroke="currentColor" stroke-width="1.6"
                              stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="sched-cb-icon"><?php echo $icon; ?></span>
            <span class="sched-cb-body">
                <span class="sched-cb-label"><?php echo esc_html($label); ?></span>
                <?php if ($detail): ?><span class="sched-cb-detail"><?php echo esc_html($detail); ?></span><?php endif; ?>
            </span>
        </label>
    <?php }

    private static function renderScheduleStyles(): void
    {
        ?>
        <style>
        /* ═══ Daily Schedule ══════════════════════════════════════════════ */

        /* Sunday rest */
        .sched-sunday { text-align:center;padding:60px 24px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-bottom:20px; }
        .sched-sunday-emoji { font-size:60px;line-height:1;margin-bottom:14px; }
        .sched-sunday h2 { margin:0 0 8px;font-size:22px;color:#1e293b; }
        .sched-sunday p { margin:0 0 18px;font-size:14px;color:#64748b;max-width:480px;display:inline-block; }
        .sched-sunday-peek { background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:10px 18px;font-size:13px;color:#374151;display:inline-block; }

        /* Main two-column layout */
        .sched-main { display:flex;gap:24px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:26px;margin-bottom:16px;flex-wrap:wrap; }

        /* Ring column */
        .sched-ring-col { display:flex;flex-direction:column;align-items:center;gap:12px;min-width:150px; }
        .sched-day-chip { border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;letter-spacing:.3px;white-space:nowrap; }
        .sched-ring-wrap { position:relative;width:110px;height:110px; }
        .sched-ring-svg { width:100%;height:100%;transform:rotate(-90deg); }
        .sched-ring-track { fill:none;stroke:#e5e7eb;stroke-width:7; }
        .sched-ring-fill  { fill:none;stroke-width:7;stroke-linecap:round;transition:stroke-dashoffset .5s ease, stroke .3s; }
        .sched-ring-inner { position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0; }
        .sched-ring-n   { font-size:30px;font-weight:800;color:#1e293b;line-height:1; }
        .sched-ring-of  { font-size:11px;color:#94a3b8;font-weight:500; }
        .sched-ring-lbl { font-size:10px;color:#cbd5e1;font-weight:500; }
        .sched-ring-msg { font-size:12px;color:#64748b;text-align:center;max-width:140px;line-height:1.4;transition:color .3s; }
        .sched-reset-btn { background:none;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;font-size:11px;color:#94a3b8;cursor:pointer;transition:all .15s; }
        .sched-reset-btn:hover { border-color:#cbd5e1;color:#475569; }

        /* Checks column */
        .sched-checks-col { flex:1;min-width:260px; }
        .sched-section-hd { font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;margin-bottom:8px;display:flex;align-items:center;gap:8px; }
        .sched-section-sub { font-size:10px;font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0; }

        /* Check items */
        .sched-check-item { display:flex;align-items:flex-start;gap:10px;padding:9px 10px;border-radius:9px;cursor:pointer;transition:background .12s;margin-bottom:3px;user-select:none; }
        .sched-check-item:hover { background:#f8fafc; }
        .sched-check-item input[type=checkbox] { position:absolute;opacity:0;width:0;height:0; }
        .sched-cbx { width:19px;height:19px;min-width:19px;border:2px solid #d1d5db;border-radius:5px;display:flex;align-items:center;justify-content:center;transition:all .15s;color:transparent;margin-top:1px;flex-shrink:0; }
        .sched-check-item:hover .sched-cbx { border-color:#7c3aed; }
        .sched-check-item.is-done .sched-cbx { background:#7c3aed;border-color:#7c3aed;color:#fff; }
        .sched-cb-icon { font-size:17px;line-height:1.1;flex-shrink:0; }
        .sched-cb-body { display:flex;flex-direction:column;gap:2px; }
        .sched-cb-label  { font-size:13px;font-weight:600;color:#1e293b;transition:color .15s, text-decoration .15s; }
        .sched-check-item.is-done .sched-cb-label { color:#94a3b8;text-decoration:line-through; }
        .sched-cb-detail { font-size:10px;color:#94a3b8; }

        /* All-done banner */
        .sched-all-done { background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:14px 20px;margin-bottom:16px;font-size:14px;color:#065f46;text-align:center;animation:sched-pop .3s ease; }
        @keyframes sched-pop { from { transform:scale(.97); opacity:0; } to { transform:scale(1); opacity:1; } }

        /* Week strip */
        .sched-week-row   { display:flex;align-items:center;gap:10px;margin-bottom:10px; }
        .sched-week-label { font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px; }
        .sched-week-pct   { font-size:12px;font-weight:600; }
        .sched-week-strip { display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:20px; }
        @media (max-width:900px) { .sched-week-strip { grid-template-columns:repeat(4,1fr); } }
        @media (max-width:560px) { .sched-week-strip { grid-template-columns:repeat(2,1fr); } }

        .sched-strip-card  { background:#fff;border:2px solid #e5e7eb;border-radius:10px;padding:10px;transition:box-shadow .15s; }
        .sched-strip-card:hover { box-shadow:0 2px 10px rgba(0,0,0,.08); }
        .sched-strip-today { }
        .sched-strip-past  { opacity:.72; }
        .sched-strip-name  { font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;display:flex;align-items:center;gap:4px;margin-bottom:5px; }
        .sched-strip-dot   { font-size:10px;color:#7c3aed; }
        .sched-strip-focus { font-size:11px;font-weight:500;color:#374151;line-height:1.3;margin-bottom:3px; }
        .sched-strip-media { font-size:10px;color:#6b7280;margin-bottom:4px; }
        .sched-strip-prog  { min-height:14px; }
        .sp-done    { font-size:10px;font-weight:700;color:#16a34a; }
        .sp-partial { font-size:10px;font-weight:600;color:#f59e0b; }
        .sp-missed  { font-size:10px;color:#94a3b8; }
        .sp-today   { font-size:10px;font-weight:700;color:#7c3aed; }

        @media (max-width:640px) {
            .sched-main { flex-direction:column; }
            .sched-ring-col { flex-direction:row;flex-wrap:wrap;justify-content:center;min-width:unset;width:100%; }
        }
        </style>
        <?php
    }
}
