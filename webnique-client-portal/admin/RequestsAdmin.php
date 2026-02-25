<?php
/**
 * Admin Page: Web Requests Manager
 * 
 * Allows admin to view and reply to client web requests from Firebase
 * 
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RequestsAdmin
 * Handles admin interface for managing client web requests
 */
final class RequestsAdmin
{
    /**
     * Register as submenu under WebNique Portal
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSubmenu'], 20); // Priority 20 to run after main menu
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Add submenu page under WebNique Portal
     */
    public static function addSubmenu(): void
    {
        // Check if user has custom capability, fallback to manage_options
        $capability = current_user_can('wnq_manage_portal') 
            ? 'wnq_manage_portal' 
            : 'manage_options';

        add_submenu_page(
            'wnq-portal',                         // Parent slug
            'Web Requests',                       // Page title
            'Web Requests',                       // Menu title
            $capability,                          // Capability
            'wnq-web-requests',                  // Menu slug
            [self::class, 'render']              // Callback
        );
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueueAssets($hook): void
    {
        // Only load on our admin page
        if ($hook !== 'webnique-portal_page_wnq-web-requests') {
            return;
        }

        $version = defined('WNQ_PORTAL_VERSION') ? WNQ_PORTAL_VERSION : time();

        // Admin CSS
        wp_enqueue_style(
            'wnq-admin-requests',
            WNQ_PORTAL_URL . 'assets/admin/requests-admin.css',
            [],
            $version
        );

        // Admin JS (ES module)
        wp_enqueue_script(
            'wnq-admin-requests',
            WNQ_PORTAL_URL . 'assets/admin/requests-admin.js',
            [],
            $version,
            true
        );

        // Mark as ES module
        add_filter('script_loader_tag', function ($tag, $handle, $src) {
            if ($handle !== 'wnq-admin-requests') {
                return $tag;
            }
            return '<script type="module" src="' . esc_url($src) . '"></script>';
        }, 10, 3);

        // Get current user info
        $current_user = wp_get_current_user();

        // Pass Firebase config to admin
        wp_localize_script('wnq-admin-requests', 'WNQ_ADMIN_CONFIG', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('wnq_admin_requests'),
            'restUrl'           => esc_url_raw(rest_url('wnq/v1')),
            'user'              => [
                'id'    => get_current_user_id(),
                'name'  => $current_user->display_name ?: $current_user->user_login,
                'email' => $current_user->user_email,
            ],
            // Use same Firebase config as frontend
            'firebaseConfig'    => [
                'apiKey'            => 'AIzaSyDlN2rZlJYC0u1_mq9oEqApb1crQe-Nrk8',
                'authDomain'        => 'webnique-client-portal.firebaseapp.com',
                'projectId'         => 'webnique-client-portal',
                'storageBucket'     => 'webnique-client-portal.firebasestorage.app',
                'messagingSenderId' => '174763137944',
                'appId'             => '1:174763137944:web:2e6e6b3adb39b9ccc5a9ed',
            ],
        ]);
    }

    /**
     * Render admin page
     */
    public static function render(): void
    {
        // Verify user permissions
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        ?>
        <div class="wrap wnq-admin-requests">
            <div id="wnq-admin-requests-root">
                <div style="padding: 40px; text-align: center;">
                    <div class="wnq-spinner"></div>
                    <p style="margin-top: 20px; color: #6b7280;">Loading requests...</p>
                </div>
            </div>
        </div>
        <?php
    }
}