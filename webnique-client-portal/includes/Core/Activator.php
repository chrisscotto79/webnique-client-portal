<?php

namespace WNQ\Core;

if (!defined('ABSPATH')) {
  exit;
}

final class Activator
{
  public static function run(): void
  {
    // Capabilities (v1 minimal)
    $admin = get_role('administrator');
    if ($admin) {
      $admin->add_cap('wnq_manage_portal');
      $admin->add_cap('wnq_view_all_clients');
    }

    // Optional: create a “WebNique Manager” role later.
    // For v1, keep it to administrators only.
  }
}
