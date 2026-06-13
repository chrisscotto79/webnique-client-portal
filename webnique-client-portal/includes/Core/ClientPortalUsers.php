<?php
/**
 * Restricted WordPress users for client portal access.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class ClientPortalUsers
{
    public const ROLE = 'wnq_client_portal_user';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensureRole']);
        add_filter('login_redirect', [self::class, 'loginRedirect'], 10, 3);
        add_filter('show_admin_bar', [self::class, 'showAdminBar']);
        add_action('admin_init', [self::class, 'restrictAdmin']);
    }

    public static function ensureRole(): void
    {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, 'Client Portal User', ['read' => true]);
        }
    }

    public static function isPortalUser(?\WP_User $user = null): bool
    {
        $user = $user ?: wp_get_current_user();
        return $user instanceof \WP_User && in_array(self::ROLE, (array)$user->roles, true);
    }

    public static function loginRedirect(string $redirect_to, string $requested_redirect_to, $user): string
    {
        if ($user instanceof \WP_User && self::isPortalUser($user)) {
            return self::portalUrl();
        }
        return $redirect_to;
    }

    public static function showAdminBar(bool $show): bool
    {
        return self::isPortalUser() ? false : $show;
    }

    public static function restrictAdmin(): void
    {
        if (!self::isPortalUser() || wp_doing_ajax()) {
            return;
        }
        wp_safe_redirect(self::portalUrl());
        exit;
    }

    public static function portalUrl(): string
    {
        return esc_url_raw((string)get_option('wnq_portal_page_url', home_url('/client-portal/')));
    }
}
