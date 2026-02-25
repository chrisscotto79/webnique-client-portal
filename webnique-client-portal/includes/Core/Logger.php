<?php

namespace WNQ\Core;

if (!defined('ABSPATH')) {
  exit;
}

final class Logger
{
  public static function debug(string $message, array $context = []): void
  {
    if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
      return;
    }

    $payload = $context ? ' ' . wp_json_encode($context) : '';
    error_log('[WNQ] ' . $message . $payload);
  }
}
