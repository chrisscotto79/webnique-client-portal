<?php
/**
 * Core Plugin Class
 * 
 * Main initialization and bootstrap for WebNique Client Portal
 * 
 * @package WebNique Portal
 */

namespace WNQ\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Plugin
 * Handles plugin initialization and component loading
 */
final class Plugin
{
    /**
     * Initialize the plugin
     */
    public static function init(): void
    {
        // Load core classes
        self::loadCore();
        
        // Initialize admin area
        if (is_admin()) {
            self::initAdmin();
        }
        
        // Initialize public/frontend area
        self::initPublic();
        
        // Register REST API routes
        self::registerRestRoutes();
    }

    /**
     * Load core classes
     */
    private static function loadCore(): void
    {
        // Core functionality
        $core_files = [
            'Permissions.php',
            'Router.php',
            'UserMeta.php',
        ];

        foreach ($core_files as $file) {
            $filepath = WNQ_PORTAL_PATH . 'includes/Core/' . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
            }
        }
        
        // Initialize permissions if class exists
        if (class_exists('WNQ\\Core\\Permissions')) {
            Permissions::init();
        }
    }

    /**
     * Initialize admin area
     */
    private static function initAdmin(): void
    {
        // Load models if they exist
        $models = [
            'Client.php',
            'Task.php',
        ];

        foreach ($models as $model) {
            $filepath = WNQ_PORTAL_PATH . 'includes/Models/' . $model;
            if (file_exists($filepath)) {
                require_once $filepath;
            }
        }
        
        // Load admin classes if they exist
        $admin_files = [
            'AdminMenu.php',
            'AdminSettings.php',
            'RequestsAdmin.php',
            'ClientsAdmin.php',
            'TasksAdmin.php',
        ];

        foreach ($admin_files as $file) {
            $filepath = WNQ_PORTAL_PATH . 'admin/' . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
            }
        }
        
        // Load analytics admin if exists
        $analytics_admin = WNQ_PORTAL_PATH . 'admin/AnalyticsAdmin.php';
        if (file_exists($analytics_admin)) {
            require_once $analytics_admin;
            if (class_exists('WNQ\\Admin\\AnalyticsAdmin')) {
                \WNQ\Admin\AnalyticsAdmin::register();
            }
        }

        // Register admin pages if classes exist
        if (class_exists('WNQ\\Admin\\AdminMenu')) {
            \WNQ\Admin\AdminMenu::register();
        }
        if (class_exists('WNQ\\Admin\\RequestsAdmin')) {
            \WNQ\Admin\RequestsAdmin::register();
        }
        if (class_exists('WNQ\\Admin\\ClientsAdmin')) {
            \WNQ\Admin\ClientsAdmin::register();
        }
    }

    /**
     * Initialize public/frontend area
     */
    private static function initPublic(): void
    {
        // Load public classes
        $shortcode_file = WNQ_PORTAL_PATH . 'public/Shortcode.php';
        if (file_exists($shortcode_file)) {
            require_once $shortcode_file;
            
            // Register shortcode if class exists
            if (class_exists('WNQ\\PublicSite\\Shortcode')) {
                \WNQ\PublicSite\Shortcode::register();
            }
        }
    }

    /**
     * Register REST API routes
     */
    private static function registerRestRoutes(): void
    {
        // Load REST API controllers
        add_action('rest_api_init', function() {
            $controllers_path = WNQ_PORTAL_PATH . 'includes/Controllers/';
            
            // Only load DashboardController (the only one that exists and works)
            $dashboard_controller = $controllers_path . 'DashboardController.php';
            if (file_exists($dashboard_controller)) {
                require_once $dashboard_controller;
                
                // Register routes if class exists
                if (class_exists('WNQ\\Controllers\\DashboardController')) {
                    \WNQ\Controllers\DashboardController::registerRoutes();
                }
            }
        });
    }

    /**
     * Plugin activation hook
     */
    public static function activate(): void
    {
        // Create custom capabilities
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('wnq_manage_portal');
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log activation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WebNique Portal: Plugin activated');
        }
    }

    /**
     * Plugin deactivation hook
     */
    public static function deactivate(): void
    {
        // Flush rewrite rules
        flush_rewrite_rules();

        // Log deactivation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WebNique Portal: Plugin deactivated');
        }
    }
}