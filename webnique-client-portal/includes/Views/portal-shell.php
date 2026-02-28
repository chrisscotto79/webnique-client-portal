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

    return sprintf(
      '<div id="wnq-portal-root" data-client-id="%s" data-mode="%s"></div>',
      $clientId,
      $mode
    );
  }
}
