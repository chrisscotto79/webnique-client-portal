<?php
/**
 * Plugin Name: WebNique Client Portal
 * Description: Complete client management with portal, analytics, billing, tasks, SEO tracking, and messaging
 * Version: 2.3.3
 * Author: WebNique
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: webnique-portal
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WNQ_PORTAL_VERSION', '2.3.3');
define('WNQ_PORTAL_PATH', plugin_dir_path(__FILE__));
define('WNQ_PORTAL_URL', plugin_dir_url(__FILE__));

if (!defined('WNQ_ENABLE_SEO_FEATURES')) {
    define('WNQ_ENABLE_SEO_FEATURES', false);
}

function wnq_seo_features_enabled(): bool {
    return defined('WNQ_ENABLE_SEO_FEATURES') && WNQ_ENABLE_SEO_FEATURES;
}

function wnq_load_portal_sales_tools(): void {
    $files = [
        'includes/Models/Lead.php',
        'includes/Data/FloridaZips.php',
        'includes/Services/GoogleMapsClient.php',
        'includes/Services/LeadSEOScorer.php',
        'includes/Services/LeadEmailExtractor.php',
        'includes/Services/LeadEnrichmentService.php',
        'includes/Services/LeadFinderEngine.php',
        'admin/LeadFinderAdmin.php',
        'includes/Models/ColdTracker.php',
        'admin/ColdTrackerAdmin.php',
    ];

    foreach ($files as $file) {
        $path = WNQ_PORTAL_PATH . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
}

$plugin_file = WNQ_PORTAL_PATH . 'includes/Core/Plugin.php';

if (!file_exists($plugin_file)) {
    add_action('admin_notices', function() use ($plugin_file) {
        echo '<div class="notice notice-error"><p><strong>WebNique Portal Error:</strong> Core Plugin file not found</p></div>';
    });
    return;
}

require_once $plugin_file;

if (!class_exists('WNQ\\Core\\Plugin')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>WebNique Portal Error:</strong> Plugin class could not be loaded</p></div>';
    });
    return;
}

// ACTIVATION
register_activation_hook(__FILE__, function() {
    if (class_exists('WNQ\\Core\\Plugin')) {
        WNQ\Core\Plugin::activate();
    }
    
    $client_model = WNQ_PORTAL_PATH . 'includes/Models/Client.php';
    if (file_exists($client_model)) {
        require_once $client_model;
        if (class_exists('WNQ\\Models\\Client')) {
            \WNQ\Models\Client::createTable();
        }
    }

    $finance_model = WNQ_PORTAL_PATH . 'includes/Models/FinanceEntry.php';
    if (file_exists($finance_model)) {
        require_once $finance_model;
        if (class_exists('WNQ\\Models\\FinanceEntry')) {
            \WNQ\Models\FinanceEntry::createTable();
            update_option('wnq_finance_db_version', WNQ_PORTAL_VERSION);
        }
    }

    wnq_load_portal_sales_tools();
    if (class_exists('WNQ\\Models\\Lead')) {
        \WNQ\Models\Lead::createTable();
        if (\WNQ\Models\Lead::tableNeedsMigration()) {
            \WNQ\Models\Lead::runMigration();
        }
    }
    if (class_exists('WNQ\\Models\\ColdTracker')) {
        \WNQ\Models\ColdTracker::createTable();
    }
    
    $task_model = WNQ_PORTAL_PATH . 'includes/Models/Task.php';
    if (file_exists($task_model)) {
        require_once $task_model;
        if (class_exists('WNQ\\Models\\Task')) {
            \WNQ\Models\Task::createTable();
        }
    }
    
    $analytics_config = WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
    if (file_exists($analytics_config)) {
        require_once $analytics_config;
        if (class_exists('WNQ\\Models\\AnalyticsConfig')) {
            \WNQ\Models\AnalyticsConfig::createTables();
        }
    }
    
    if (wnq_seo_features_enabled()) {
        $seo_model = WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
        if (file_exists($seo_model)) {
            require_once $seo_model;
            if (class_exists('WNQ\\Models\\SEO')) {
                \WNQ\Models\SEO::createTables();
            }
        }

        // SEO OS Hub tables
        $seoos_bootstrap = WNQ_PORTAL_PATH . 'includes/Core/SEOOSBootstrap.php';
        if (file_exists($seoos_bootstrap)) {
            require_once $seoos_bootstrap;
            if (class_exists('WNQ\\Core\\SEOOSBootstrap')) {
                \WNQ\Core\SEOOSBootstrap::createTables();
            }
        }
    }
});

// DEACTIVATION
register_deactivation_hook(__FILE__, function() {
    if (class_exists('WNQ\\Core\\Plugin') && method_exists('WNQ\\Core\\Plugin', 'deactivate')) {
        WNQ\Core\Plugin::deactivate();
    }
    flush_rewrite_rules();
});

// PORTAL PAGE — Always hide page title on pages using [wnq_portal]
add_filter('the_title', function($title, $id = null) {
    if (!in_the_loop() || !is_main_query()) {
        return $title;
    }
    $post = $id ? get_post($id) : get_post();
    if ($post && !empty($post->post_content) && has_shortcode($post->post_content, 'wnq_portal')) {
        return '';
    }
    return $title;
}, 10, 2);

// PORTAL PAGE — Always disable comments on pages using [wnq_portal]
add_filter('comments_open', function($open, $post_id = null) {
    $post = $post_id ? get_post($post_id) : get_post();
    if ($post && !empty($post->post_content) && has_shortcode($post->post_content, 'wnq_portal')) {
        return false;
    }
    return $open;
}, 10, 2);

add_filter('pings_open', function($open, $post_id = null) {
    $post = $post_id ? get_post($post_id) : get_post();
    if ($post && !empty($post->post_content) && has_shortcode($post->post_content, 'wnq_portal')) {
        return false;
    }
    return $open;
}, 10, 2);

// INITIALIZE
add_action('plugins_loaded', function() {
    if (!class_exists('WNQ\\Core\\Plugin')) {
        return;
    }

    try {
        WNQ\Core\Plugin::init();
    } catch (Exception $e) {
        error_log('WebNique Portal Init Error: ' . $e->getMessage());
    }
}, 10);

if (wnq_seo_features_enabled()) {
    // SEO OS — Initialize after portal is loaded
    add_action('plugins_loaded', function() {
        $seoos = WNQ_PORTAL_PATH . 'includes/Core/SEOOSBootstrap.php';
        if (file_exists($seoos)) {
            require_once $seoos;
            if (class_exists('WNQ\\Core\\SEOOSBootstrap')) {
                try {
                    \WNQ\Core\SEOOSBootstrap::init();
                } catch (Exception $e) {
                    error_log('WebNique SEO OS Init Error: ' . $e->getMessage());
                }
            }
        }
    }, 15);

    // SEO OS — Auto-create tables on admin if version changed or tables missing
    add_action('admin_init', function() {
        $installed = get_option('wnq_seoos_db_version', '');
        if ($installed === WNQ_PORTAL_VERSION) {
            return;
        }
        $seoos = WNQ_PORTAL_PATH . 'includes/Core/SEOOSBootstrap.php';
        if (file_exists($seoos)) {
            require_once $seoos;
            if (class_exists('WNQ\\Core\\SEOOSBootstrap')) {
                \WNQ\Core\SEOOSBootstrap::createTables();
                update_option('wnq_seoos_db_version', WNQ_PORTAL_VERSION);
            }
        }
    });
}

add_action('admin_init', function() {
    $installed = get_option('wnq_finance_db_version', '');
    if ($installed === WNQ_PORTAL_VERSION) {
        $finance_model = WNQ_PORTAL_PATH . 'includes/Models/FinanceEntry.php';
        if (file_exists($finance_model)) {
            require_once $finance_model;
            if (class_exists('WNQ\\Models\\FinanceEntry')) {
                \WNQ\Models\FinanceEntry::ensureSchema();
            }
        }
        return;
    }

    $finance_model = WNQ_PORTAL_PATH . 'includes/Models/FinanceEntry.php';
    if (file_exists($finance_model)) {
        require_once $finance_model;
        if (class_exists('WNQ\\Models\\FinanceEntry')) {
            \WNQ\Models\FinanceEntry::createTable();
            update_option('wnq_finance_db_version', WNQ_PORTAL_VERSION);
        }
    }
});

add_action('admin_init', function() {
    $installed = get_option('wnq_sales_tools_db_version', '');
    if ($installed === WNQ_PORTAL_VERSION) {
        return;
    }

    wnq_load_portal_sales_tools();
    if (class_exists('WNQ\\Models\\Lead')) {
        \WNQ\Models\Lead::createTable();
        if (\WNQ\Models\Lead::tableNeedsMigration()) {
            \WNQ\Models\Lead::runMigration();
        }
    }
    if (class_exists('WNQ\\Models\\ColdTracker')) {
        \WNQ\Models\ColdTracker::createTable();
    }
    update_option('wnq_sales_tools_db_version', WNQ_PORTAL_VERSION);
});

add_action('plugins_loaded', function() {
    if (!is_admin()) {
        return;
    }

    wnq_load_portal_sales_tools();
    if (class_exists('WNQ\\Admin\\LeadFinderAdmin')) {
        \WNQ\Admin\LeadFinderAdmin::register();
    }
    if (class_exists('WNQ\\Admin\\ColdTrackerAdmin')) {
        \WNQ\Admin\ColdTrackerAdmin::register();
    }
}, 20);

// CLIENT HANDLERS
add_action('admin_post_wnq_save_client', function() {
    $clients_admin = WNQ_PORTAL_PATH . 'admin/ClientsAdmin.php';
    if (file_exists($clients_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
        require_once $clients_admin;
        \WNQ\Admin\ClientsAdmin::handleSaveClient();
    }
});

add_action('admin_post_wnq_delete_client_from_clients', function() {
    $clients_admin = WNQ_PORTAL_PATH . 'admin/ClientsAdmin.php';
    if (file_exists($clients_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
        require_once $clients_admin;
        \WNQ\Admin\ClientsAdmin::handleDeleteClient();
    }
});

add_action('admin_post_wnq_mark_paid', function() {
    $clients_admin = WNQ_PORTAL_PATH . 'admin/ClientsAdmin.php';
    if (file_exists($clients_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
        require_once WNQ_PORTAL_PATH . 'includes/Models/FinanceEntry.php';
        require_once $clients_admin;
        \WNQ\Admin\ClientsAdmin::handleMarkPaid();
    }
});

add_action('admin_post_wnq_save_finance_entry', function() {
    $clients_admin = WNQ_PORTAL_PATH . 'admin/ClientsAdmin.php';
    if (file_exists($clients_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
        require_once WNQ_PORTAL_PATH . 'includes/Models/FinanceEntry.php';
        require_once $clients_admin;
        \WNQ\Admin\ClientsAdmin::handleSaveFinanceEntry();
    }
});

add_action('admin_post_wnq_delete_finance_entry', function() {
    $clients_admin = WNQ_PORTAL_PATH . 'admin/ClientsAdmin.php';
    if (file_exists($clients_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/FinanceEntry.php';
        require_once $clients_admin;
        \WNQ\Admin\ClientsAdmin::handleDeleteFinanceEntry();
    }
});

add_action('admin_post_wnq_save_portal_settings', function() {
    $settings_admin = WNQ_PORTAL_PATH . 'admin/AdminSettings.php';
    if (file_exists($settings_admin)) {
        require_once $settings_admin;
        \WNQ\Admin\AdminSettings::handleSaveSettings();
    }
});

// TASK HANDLERS
add_action('admin_post_wnq_save_task', function() {
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (file_exists($tasks_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';
        require_once $tasks_admin;
        \WNQ\Admin\TasksAdmin::handleSaveTask();
    }
});

add_action('admin_post_wnq_delete_task', function() {
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (file_exists($tasks_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';
        require_once $tasks_admin;
        \WNQ\Admin\TasksAdmin::handleDeleteTask();
    }
});

add_action('admin_post_wnq_archive_task', function() {
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (file_exists($tasks_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';
        require_once $tasks_admin;
        \WNQ\Admin\TasksAdmin::handleArchive();
    }
});

add_action('admin_post_wnq_restore_task', function() {
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (file_exists($tasks_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';
        require_once $tasks_admin;
        \WNQ\Admin\TasksAdmin::handleRestore();
    }
});

add_action('admin_post_wnq_complete_recurring', function() {
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (file_exists($tasks_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';
        require_once $tasks_admin;
        \WNQ\Admin\TasksAdmin::handleCompleteRecurring();
    }
});

// ANALYTICS HANDLERS
add_action('admin_post_wnq_save_analytics_settings', function() {
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::handleSaveSettings();
    }
});

add_action('admin_post_wnq_test_analytics_connection', function() {
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::handleTestConnection();
    }
});

add_action('admin_post_wnq_clear_analytics_cache', function() {
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/API/AnalyticsCache.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::handleClearCache();
    }
});

add_action('admin_post_wnq_add_analytics_client', function() {
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::handleAddClient();
    }
});

add_action('admin_post_wnq_update_analytics_client', function() {
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::handleUpdateClient();
    }
});

add_action('admin_post_wnq_delete_analytics_client', function() {
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::handleDeleteClient();
    }
});

if (wnq_seo_features_enabled()) {
    // SEO HANDLERS
    add_action('admin_post_wnq_init_seo_client', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleInitClient();
        }
    });

    add_action('admin_post_wnq_init_monthly_tasks', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleInitMonthlyTasks();
        }
    });
}

// AJAX ANALYTICS HANDLERS - FOR CLIENT PORTAL
add_action('wp_ajax_wnq_get_analytics_data', function() {
    // Verify nonce - accept EITHER wp_rest OR wnq_analytics_nonce
    $nonce = $_POST['nonce'] ?? '';
    $valid_nonce = false;

    if (wp_verify_nonce($nonce, 'wp_rest')) {
        $valid_nonce = true;
        // Re-mint as wnq_analytics_nonce so the inner handler's check_ajax_referer passes
        $_POST['nonce'] = wp_create_nonce('wnq_analytics_nonce');
    } elseif (wp_verify_nonce($nonce, 'wnq_analytics_nonce')) {
        $valid_nonce = true;
    }

    if (!$valid_nonce) {
        wp_send_json_error(['message' => 'Invalid security token']);
        return;
    }

    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
        require_once WNQ_PORTAL_PATH . 'includes/API/GoogleAnalytics.php';
        require_once WNQ_PORTAL_PATH . 'includes/API/GoogleSearchConsole.php';
        require_once WNQ_PORTAL_PATH . 'includes/API/AnalyticsCache.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::ajaxGetAnalyticsData();
    } else {
        wp_send_json_error(['message' => 'Analytics handler not found']);
    }
});

if (wnq_seo_features_enabled()) {
    add_action('admin_post_wnq_bulk_import_seo', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleBulkImport();
        }
    });

    add_action('admin_post_wnq_complete_seo_task', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleCompleteTask();
        }
    });

    add_action('admin_post_wnq_uncomplete_seo_task', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleUncompleteTask();
        }
    });

    add_action('admin_post_wnq_update_seo_task', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleUpdateTask();
        }
    });

    add_action('admin_post_wnq_add_seo_task', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleAddTask();
        }
    });

    add_action('admin_post_wnq_delete_seo_task', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleDeleteTask();
        }
    });

    add_action('admin_post_wnq_save_seo_report', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleSaveReport();
        }
    });
}

// OTHER AJAX HANDLERS
add_action('wp_ajax_wnq_update_task_status', function() {
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (file_exists($tasks_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';
        require_once $tasks_admin;
        \WNQ\Admin\TasksAdmin::ajaxUpdateTaskStatus();
    }
});

add_action('wp_ajax_wnq_get_client_analytics', function() {
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (file_exists($analytics_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
        require_once $analytics_admin;
        \WNQ\Admin\AnalyticsAdmin::ajaxGetClientAnalytics();
    }
});

if (wnq_seo_features_enabled()) {
    add_action('admin_post_wnq_csv_import_seo', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleCsvImport();
        }
    });

    add_action('admin_post_wnq_download_csv_template', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleDownloadCsvTemplate();
        }
    });

    add_action('admin_post_wnq_export_seo_csv', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleExportCsv();
        }
    });

    add_action('admin_post_wnq_delete_all_seo_tasks', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::handleDeleteAllTasks();
        }
    });

    add_action('wp_ajax_wnq_seo_toggle_task', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::ajaxToggleTask();
        }
    });
}

add_action('admin_post_wnq_toggle_recurring', function() {
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (file_exists($tasks_admin)) {
        require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';
        require_once $tasks_admin;
        \WNQ\Admin\TasksAdmin::handleToggleRecurring();
    }
});

if (wnq_seo_features_enabled()) {
    add_action('wp_ajax_wnq_seo_update_task_status', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::ajaxUpdateTaskStatus();
        }
    });

    add_action('wp_ajax_wnq_seo_update_task_notes', function() {
        $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
        if (file_exists($seo_admin)) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
            require_once $seo_admin;
            \WNQ\Admin\SEOAdmin::ajaxUpdateTaskNotes();
        }
    });
}


// ADMIN MENU
add_action('admin_menu', function() {
    $capability = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
    
    add_menu_page(
        'WebNique Portal',
        'WebNique Portal',
        $capability,
        'wnq-portal',
        'wnq_render_settings_page',
        'dashicons-chart-area',
        58
    );
    
    add_submenu_page('wnq-portal', 'Settings', 'Settings', $capability, 'wnq-portal', 'wnq_render_settings_page');
    add_submenu_page('wnq-portal', 'Money Mangment', 'Money Mangment', $capability, 'wnq-clients', 'wnq_render_clients_page');
    add_submenu_page('wnq-portal', 'Tasks', 'Tasks', $capability, 'wnq-tasks', 'wnq_render_tasks_page');
    add_submenu_page('wnq-portal', 'Analytics', 'Analytics', $capability, 'wnq-analytics', 'wnq_render_analytics_page');
    if (wnq_seo_features_enabled()) {
        add_submenu_page('wnq-portal', 'SEO Tracking', 'SEO Tracking', $capability, 'wnq-seo', 'wnq_render_seo_page');
    }
    add_submenu_page('wnq-portal', 'Web Requests', 'Web Requests', $capability, 'wnq-web-requests', 'wnq_render_requests_page');
}, 10);


// RENDER FUNCTIONS
function wnq_render_settings_page() {
    if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $settings_admin = WNQ_PORTAL_PATH . 'admin/AdminSettings.php';
    if (!file_exists($settings_admin)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> AdminSettings not found.</p></div></div>';
        return;
    }

    require_once $settings_admin;
    \WNQ\Admin\AdminSettings::render();
}

function wnq_render_clients_page() {
    if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $client_model = WNQ_PORTAL_PATH . 'includes/Models/Client.php';
    if (!file_exists($client_model)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> Client model not found.</p></div></div>';
        return;
    }
    
    require_once $client_model;
    
    $clients_admin = WNQ_PORTAL_PATH . 'admin/ClientsAdmin.php';
    if (!file_exists($clients_admin)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> ClientsAdmin not found.</p></div></div>';
        return;
    }
    
    require_once $clients_admin;
    \WNQ\Admin\ClientsAdmin::render();
}

function wnq_render_tasks_page() {
    if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $task_model = WNQ_PORTAL_PATH . 'includes/Models/Task.php';
    if (!file_exists($task_model)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> Task model not found.</p></div></div>';
        return;
    }
    
    require_once $task_model;
    
    $tasks_admin = WNQ_PORTAL_PATH . 'admin/TasksAdmin.php';
    if (!file_exists($tasks_admin)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> TasksAdmin not found.</p></div></div>';
        return;
    }
    
    require_once $tasks_admin;
    \WNQ\Admin\TasksAdmin::render();
}

function wnq_render_analytics_page() {
    if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $analytics_config = WNQ_PORTAL_PATH . 'includes/Models/AnalyticsConfig.php';
    if (!file_exists($analytics_config)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> AnalyticsConfig model not found.</p></div></div>';
        return;
    }
    
    require_once $analytics_config;
    require_once WNQ_PORTAL_PATH . 'includes/API/GoogleAnalytics.php';
    require_once WNQ_PORTAL_PATH . 'includes/API/GoogleSearchConsole.php';
    require_once WNQ_PORTAL_PATH . 'includes/API/AnalyticsCache.php';
    
    $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
    if (!file_exists($analytics_admin)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> AnalyticsAdmin not found.</p></div></div>';
        return;
    }
    
    require_once $analytics_admin;
    \WNQ\Admin\AnalyticsAdmin::render();
}

function wnq_render_seo_page() {
    if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $seo_model = WNQ_PORTAL_PATH . 'includes/Models/SEO.php';
    if (!file_exists($seo_model)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> SEO model not found.</p></div></div>';
        return;
    }
    
    require_once $seo_model;
    require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
    
    $seo_admin = WNQ_PORTAL_PATH . 'admin/SEOAdmin.php';
    if (!file_exists($seo_admin)) {
        echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> SEOAdmin not found.</p></div></div>';
        return;
    }
    
    require_once $seo_admin;
    \WNQ\Admin\SEOAdmin::render();
}

function wnq_render_requests_page() {
    if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $version = WNQ_PORTAL_VERSION;
    
    wp_enqueue_style('wnq-admin-requests', WNQ_PORTAL_URL . 'assets/admin/requests-admin.css', [], $version);
    wp_enqueue_script('wnq-admin-requests', WNQ_PORTAL_URL . 'assets/admin/requests-admin.js', [], $version, true);
    
    add_filter('script_loader_tag', function ($tag, $handle, $src) {
        if ($handle !== 'wnq-admin-requests') return $tag;
        return '<script type="module" src="' . esc_url($src) . '"></script>';
    }, 10, 3);
    
    $current_user = wp_get_current_user();
    
    wp_localize_script('wnq-admin-requests', 'WNQ_ADMIN_CONFIG', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wnq_admin_requests'),
        'restUrl' => esc_url_raw(rest_url('wnq/v1')),
        'user' => [
            'id' => get_current_user_id(),
            'name' => $current_user->display_name ?: $current_user->user_login,
            'email' => $current_user->user_email,
        ],
        'firebaseConfig' => [
            'apiKey' => 'AIzaSyDlN2rZlJYC0u1_mq9oEqApb1crQe-Nrk8',
            'authDomain' => 'webnique-client-portal.firebaseapp.com',
            'projectId' => 'webnique-client-portal',
            'storageBucket' => 'webnique-client-portal.firebasestorage.app',
            'messagingSenderId' => '174763137944',
            'appId' => '1:174763137944:web:2e6e6b3adb39b9ccc5a9ed',
        ],
    ]);
    
    ?>
    <div class="wrap wnq-admin-requests">
        <div id="wnq-admin-requests-root">
            <div style="padding: 40px; text-align: center;">
                <div class="wnq-spinner"></div>
                <p style="margin-top: 20px; color: #6b7280;">Loading requests...</p>
            </div>
        </div>
    </div>
    
    <style>
    .wnq-spinner {
        border: 3px solid #e5e7eb;
        border-top-color: #0d539e;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        animation: spin 0.8s linear infinite;
        margin: 0 auto;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    </style>
    <?php
}
