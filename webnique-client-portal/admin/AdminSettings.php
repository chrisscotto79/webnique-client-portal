<?php
/**
 * Admin Settings Page
 * 
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AdminSettings
 * Handles the admin settings page rendering and processing
 */
final class AdminSettings
{
    /**
     * Render the admin settings page
     */
    public static function render(): void
    {
        // Verify user permissions
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Enqueue admin assets
        self::enqueueAssets();

        // Output the settings page HTML
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div id="wnq-admin-settings-root"></div>
            
            <div class="wnq-admin-fallback" style="display: none;">
                <h2>WebNique Portal Settings</h2>
                <p>Loading settings interface...</p>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin-specific CSS and JS
     */
    private static function enqueueAssets(): void
    {
        $version = defined('WNQ_PORTAL_VERSION') ? WNQ_PORTAL_VERSION : time();

        // Enqueue admin CSS
        wp_enqueue_style(
            'wnq-admin-settings',
            WNQ_PORTAL_URL . 'assets/app/app.css',
            [],
            $version
        );

        // Enqueue admin JS as ES module
        wp_enqueue_script(
            'wnq-admin-settings',
            WNQ_PORTAL_URL . 'assets/app/app.js',
            [],
            $version,
            true
        );

        // Mark script as ES module
        add_filter('script_loader_tag', function ($tag, $handle, $src) {
            if ($handle !== 'wnq-admin-settings') {
                return $tag;
            }
            return '<script type="module" src="' . esc_url($src) . '"></script>';
        }, 10, 3);

        // Localize script with necessary data
        wp_localize_script('wnq-admin-settings', 'WNQ_ADMIN', [
            'restUrl'   => esc_url_raw(rest_url('wnq/v1')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'isAdmin'   => true,
            'ajaxUrl'   => admin_url('admin-ajax.php'),
        ]);
    }
}
