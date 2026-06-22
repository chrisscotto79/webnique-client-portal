<?php
/**
 * Public Shortcode Handler
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\PublicSite;

use WNQ\Core\Permissions;
use WNQ\Models\Client;

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
        add_shortcode('gwm_client_portal', [self::class, 'render']);
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
        $viewAsClients = $canManage ? self::viewAsClients() : [];

        if ($canManage) {
            $requestedClientId = sanitize_text_field(wp_unslash($_GET['wnq_view_as'] ?? ''));
            $allowedClientIds = array_column($viewAsClients, 'clientId');
            if ($requestedClientId !== '' && in_array($requestedClientId, $allowedClientIds, true)) {
                $clientId = $requestedClientId;
            } elseif (($clientId === null || !in_array($clientId, $allowedClientIds, true)) && $viewAsClients) {
                $clientId = (string)$viewAsClients[0]['clientId'];
            }
        }

        // Check if user has access
        if ($clientId === null && !$canManage) {
            return self::renderNoAccessMessage();
        }

        // Enqueue frontend assets
        self::enqueueAssets($clientId ?? '', $viewAsClients);

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
            esc_html__('Your account is not linked to a client yet. Please contact Golden Web Marketing for access.', 'webnique-portal')
        );
    }

    /**
     * Enqueue frontend assets and localize config
     */
    private static function enqueueAssets(string $clientId, array $viewAsClients = []): void
    {
        $version = defined('WNQ_PORTAL_VERSION') ? WNQ_PORTAL_VERSION : '2.0.0';
        $client = $clientId !== '' ? (Client::getByClientId($clientId) ?: []) : [];
        $clientLabel = sanitize_text_field((string)(($client['company'] ?? '') ?: ($client['name'] ?? '') ?: $clientId));

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
            'restUrl'          => esc_url_raw(rest_url('wnq/v1')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'version'          => $version,
            'clientId'         => $clientId,
            'clientLabel'      => $clientLabel,
            'isAdmin'          => current_user_can('wnq_manage_portal') || current_user_can('manage_options'),
            'viewAsClients'    => $viewAsClients,
            'logoUrl'          => esc_url_raw(WNQ_PORTAL_URL . 'assets/images/golden-web-marketing-logo.png'),
            'userId'           => get_current_user_id(),
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'lostpasswordUrl'  => wp_lostpassword_url(),

            // Support contact — edit via Options API or a future settings page
            'support' => [
                'name'         => get_option('wnq_support_name', 'Christopher Scotto'),
                'title'        => get_option('wnq_support_title', 'Head Developer'),
                'phone'        => get_option('wnq_support_phone', '+14439948595'),
                'phoneDisplay' => get_option('wnq_support_phone_display', '(443) 994-8595'),
            ],

            // User data for Firebase messages
            'user' => [
                'id'    => get_current_user_id(),
                'name'  => $current_user->display_name ?: $current_user->user_login,
                'email' => $current_user->user_email,
            ],
        ]);

    }

    /**
     * Build the safe, admin-only list used by the frontend preview switcher.
     */
    private static function viewAsClients(): array
    {
        $options = [];
        foreach (Client::getAll() as $client) {
            $clientId = sanitize_text_field((string)($client['client_id'] ?? ''));
            if ($clientId === '') {
                continue;
            }

            $users = get_users([
                'meta_key'   => 'wnq_client_id',
                'meta_value' => $clientId,
                'fields'     => ['display_name', 'user_email'],
                'orderby'    => 'display_name',
            ]);
            $userLabels = array_values(array_filter(array_map(
                static fn($user): string => sanitize_text_field(
                    (string)($user->display_name ?: $user->user_email)
                    . ($user->display_name && $user->user_email ? ' (' . $user->user_email . ')' : '')
                ),
                $users
            )));
            $business = sanitize_text_field((string)($client['company'] ?: $client['name'] ?: $clientId));

            $options[] = [
                'clientId' => $clientId,
                'label'    => $business . ($userLabels ? ' — ' . implode(', ', $userLabels) : ' — No portal login'),
                'business' => $business,
            ];
        }

        return $options;
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
