<?php

namespace WNQ\Core;

if (!defined('ABSPATH')) {
  exit;
}

final class Plugin
{
  private static bool $booted = false;

  /**
   * Boot the plugin (runs on plugins_loaded)
   */
  public static function init(): void
  {
    if (self::$booted) {
      return;
    }
    self::$booted = true;

    self::includes();

    // ✅ Register REST routes (v1: /wnq/v1/ping)
    \WNQ\Core\Router::register();

    /**
     * Public (client-facing)
     */
    add_action('init', [\WNQ\PublicSite\Shortcode::class, 'register']);

    /**
     * Core user meta (client ↔ WP user mapping)
     */
    add_action('init', [\WNQ\Core\UserMeta::class, 'register']);
    \WNQ\Core\ClientPortalUsers::register();

    /**
     * Admin (Golden Web Marketing-only)
     */
    if (is_admin()) {
      add_action('admin_menu', [\WNQ\Admin\AdminMenu::class, 'register']);
      \WNQ\Admin\ClientPortalAdmin::register();
    }
  }

  /**
   * Runs once on plugin activation
   */
  public static function activate(): void
  {
    self::includes();
    \WNQ\Models\ClientPortal::createTables();
    Activator::run();
  }

  /**
   * Load all required files
   */
  private static function includes(): void
  {
    /**
     * Controllers (✅ required for REST routes)
     */
    require_once WNQ_PORTAL_PATH . 'includes/Controllers/DashboardController.php';
    require_once WNQ_PORTAL_PATH . 'includes/Models/Client.php';
    require_once WNQ_PORTAL_PATH . 'includes/Models/ClientPortal.php';
    require_once WNQ_PORTAL_PATH . 'includes/Models/Task.php';

    /**
     * Core
     */
    require_once WNQ_PORTAL_PATH . 'includes/Core/Activator.php';
    require_once WNQ_PORTAL_PATH . 'includes/Core/Permissions.php';
    require_once WNQ_PORTAL_PATH . 'includes/Core/Router.php';
    require_once WNQ_PORTAL_PATH . 'includes/Core/Logger.php';
    require_once WNQ_PORTAL_PATH . 'includes/Core/UserMeta.php';
    require_once WNQ_PORTAL_PATH . 'includes/Core/ClientPortalUsers.php';

    /**
     * Services
     */
    require_once WNQ_PORTAL_PATH . 'includes/Services/FirebaseStore.php';
    require_once WNQ_PORTAL_PATH . 'includes/Services/GoogleAdsClient.php';
    require_once WNQ_PORTAL_PATH . 'includes/Services/TelegramNotifier.php';

    /**
     * Views
     */
    require_once WNQ_PORTAL_PATH . 'includes/Views/portal-shell.php';

    /**
     * Public
     */
    require_once WNQ_PORTAL_PATH . 'public/Shortcode.php';

    /**
     * Admin
     */
    require_once WNQ_PORTAL_PATH . 'admin/AdminMenu.php';
    require_once WNQ_PORTAL_PATH . 'admin/AdminSettings.php';
    require_once WNQ_PORTAL_PATH . 'admin/ClientPortalAdmin.php';
  }
}
