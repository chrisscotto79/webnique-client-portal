<?php
/**
 * Portal Shell View (v1)
 *
 * IMPORTANT:
 * This file must be in the global namespace so it can be called from anywhere.
 */

namespace {
  if (!defined('ABSPATH')) {
    exit;
  }

  function wnq_portal_shell_html(array $args = []): string
  {
    $clientId = isset($args['client_id']) ? esc_attr((string) $args['client_id']) : '';
    $mode     = isset($args['mode']) ? esc_attr((string) $args['mode']) : 'client';

    // Temporary visible placeholder so you can confirm shortcode output.
    $debugHtml = sprintf(
      '<div style="padding:12px;border:1px dashed #999;border-radius:8px;font-family:system-ui;">
        <strong>WebNique Portal Mounted</strong><br>
        Mode: <code>%s</code><br>
        Client ID: <code>%s</code>
      </div>',
      $mode,
      $clientId !== '' ? $clientId : '(none)'
    );

    return sprintf(
      '<div id="wnq-portal-root" data-client-id="%s" data-mode="%s">%s</div>',
      $clientId,
      $mode,
      $debugHtml
    );
  }
}
