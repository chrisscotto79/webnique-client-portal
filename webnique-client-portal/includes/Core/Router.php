<?php

namespace WNQ\Core;

if (!defined('ABSPATH')) {
  exit;
}

final class Router
{
  public static function register(): void
  {
    // Register immediately on rest_api_init.
    add_action('rest_api_init', [self::class, 'registerRoutes']);
  }

  public static function registerRoutes(): void
  {
    // v1: only ping exists right now.
    if (class_exists('\WNQ\Controllers\DashboardController') && method_exists('\WNQ\Controllers\DashboardController', 'registerRoutes')) {
      \WNQ\Controllers\DashboardController::registerRoutes();
    }
  }
}
