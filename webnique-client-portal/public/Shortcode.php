<?php
/**
 * Public Shortcode Handler
 *
 * @package WebNique Portal
 */

namespace WNQ\PublicSite;

use WNQ\Core\Permissions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Shortcode
 * Handles the [wnq_portal] shortcode rendering
 */
final class Shortcode
{
    /**
     * Register the shortcode
     */
    public static function register(): void
    {
        add_shortcode('wnq_portal', [self::class, 'render']);
    }

    /**
     * Render the portal shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Rendered HTML output
     */
    public static function render($atts = []): string
    {
        // Check if user is logged in
        if (!Permissions::isLoggedIn()) {
            return self::renderLoginMessage();
        }

        // Get current user's client ID
        $clientId  = Permissions::currentUserClientId();
        $canManage = Permissions::currentUserCanManagePortal();

        // Check if user has access
        if ($clientId === null && !$canManage) {
            return self::renderNoAccessMessage();
        }

        // Enqueue frontend assets
        self::enqueueAssets($clientId ?? '');

        // Prepare shell args
        $args = [
            'client_id' => $clientId ?? '',
            'mode'      => $canManage ? 'admin' : 'client',
            'user_id'   => get_current_user_id(),
        ];

        // Render portal shell
        return self::renderPortalShell($args);
    }

    /**
     * Render login required message
     */
    private static function renderLoginMessage(): string
    {
        $loginUrl = wp_login_url(get_permalink());

        return sprintf(
            '<div class="wnq-portal-notice wnq-portal-notice--info">
                <p>%s <a href="%s">%s</a></p>
            </div>',
            esc_html__('You must be logged in to view this portal.', 'webnique-portal'),
            esc_url($loginUrl),
            esc_html__('Log in here', 'webnique-portal')
        );
    }

    /**
     * Render no access message
     */
    private static function renderNoAccessMessage(): string
    {
        return sprintf(
            '<div class="wnq-portal-notice wnq-portal-notice--warning">
                <p>%s</p>
            </div>',
            esc_html__('Your account is not linked to a client yet. Please contact WebNique for access.', 'webnique-portal')
        );
    }

    /**
     * Enqueue frontend assets and localize config
     */
    private static function enqueueAssets(string $clientId): void
    {
        $version = '2.0.1-' . time(); // Force fresh load

        // CSS
        wp_enqueue_style(
            'wnq-portal-app',
            WNQ_PORTAL_URL . 'assets/app/app.css',
            [],
            $version
        );

        // JS (ES module)
        wp_enqueue_script(
            'wnq-portal-app',
            WNQ_PORTAL_URL . 'assets/app/app.js',
            [],
            $version,
            true
        );

        // Mark script as ES module
        add_filter('script_loader_tag', function ($tag, $handle, $src) {
            if ($handle !== 'wnq-portal-app') {
                return $tag;
            }
            return '<script type="module" src="' . esc_url($src) . '"></script>';
        }, 10, 3);

        // Get current user data
        $current_user = wp_get_current_user();

        // Portal config - CRITICAL: This is what JavaScript uses
        wp_localize_script('wnq-portal-app', 'WNQ_PORTAL', [
            'restUrl'  => esc_url_raw(rest_url('wnq/v1')),
            'nonce'    => wp_create_nonce('wp_rest'), // ✅ This nonce is accepted by AJAX handler
            'clientId' => $clientId,
            'isAdmin'  => current_user_can('wnq_manage_portal') || current_user_can('manage_options'),
            'userId'   => get_current_user_id(),
            'ajaxUrl'  => admin_url('admin-ajax.php'), // ✅ This is the correct AJAX URL
            
            // User data for Firebase messages
            'user' => [
                'id'    => get_current_user_id(),
                'name'  => $current_user->display_name ?: $current_user->user_login,
                'email' => $current_user->user_email,
            ],
        ]);

        // 🔥 Firebase Configuration
        wp_localize_script('wnq-portal-app', 'WNQ_FIREBASE_CONFIG', [
            'apiKey'            => 'AIzaSyDlN2rZlJYC0u1_mq9oEqApb1crQe-Nrk8',
            'authDomain'        => 'webnique-client-portal.firebaseapp.com',
            'projectId'         => 'webnique-client-portal',
            'storageBucket'     => 'webnique-client-portal.firebasestorage.app',
            'messagingSenderId' => '174763137944',
            'appId'             => '1:174763137944:web:2e6e6b3adb39b9ccc5a9ed',
        ]);
    }

    /**
     * Render portal shell HTML
     */
    private static function renderPortalShell(array $args): string
    {
        if (!function_exists('wnq_portal_shell_html')) {
            return '<div class="wnq-portal-notice wnq-portal-notice--error">
                <p>' . esc_html__('Portal shell function not found.', 'webnique-portal') . '</p>
            </div>';
        }

        return wnq_portal_shell_html($args);
    }
}