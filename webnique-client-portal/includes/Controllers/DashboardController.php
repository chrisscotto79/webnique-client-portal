<?php

namespace WNQ\Controllers;

use WNQ\Core\Permissions;
use WNQ\Services\FirebaseStore;

if (!defined('ABSPATH')) {
  exit;
}

final class DashboardController
{
  public static function registerRoutes(): void
  {
    // GET /wnq/v1/ping (auth required)
    register_rest_route('wnq/v1', '/ping', [
      'methods'  => 'GET',
      'callback' => [self::class, 'ping'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    // GET /wnq/v1/client (current user's client doc)
    register_rest_route('wnq/v1', '/client', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getClient'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    // POST /wnq/v1/clients/bootstrap (admin-only)
    register_rest_route('wnq/v1', '/clients/bootstrap', [
      'methods'  => 'POST',
      'callback' => [self::class, 'bootstrapClient'],
      'permission_callback' => function () {
        return is_user_logged_in() && Permissions::currentUserCanManagePortal();
      },
    ]);
  }

  public static function ping(\WP_REST_Request $request): \WP_REST_Response
  {
    $firebase = new FirebaseStore();

    return new \WP_REST_Response([
      'ok' => true,
      'wp' => [
        'user_id' => get_current_user_id(),
        'can_manage_portal' => Permissions::currentUserCanManagePortal(),
        'client_id' => Permissions::currentUserClientId(),
      ],
      'firebase' => $firebase->ping(),
    ], 200);
  }

  public static function getClient(\WP_REST_Request $request): \WP_REST_Response
  {
    $clientId = Permissions::currentUserClientId();
    $clientId = $clientId ? sanitize_text_field($clientId) : '';

    if ($clientId === '') {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'No wnq_client_id is set for this WP user.',
      ], 400);
    }

    $firebase = new FirebaseStore();
    $res = $firebase->getClientDocFields($clientId);

    if (!$res['ok']) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => $res['error'] ?? 'Unknown error',
        'status' => $res['status'] ?? 500,
        'details' => $res['body'] ?? ($res['details'] ?? null),
      ], $res['status'] ?? 500);
    }

    return new \WP_REST_Response([
      'ok' => true,
      'client_id' => $clientId,
      'exists' => (bool) ($res['exists'] ?? false),
      'client' => $res['client'] ?? null,
    ], 200);
  }

  public static function bootstrapClient(\WP_REST_Request $request): \WP_REST_Response
  {
    $firebase = new FirebaseStore();

    $body = $request->get_json_params();
    $requestedClientId = is_array($body) && isset($body['client_id']) ? (string) $body['client_id'] : '';

    $clientId = trim($requestedClientId) !== '' ? sanitize_text_field($requestedClientId) : (Permissions::currentUserClientId() ?? '');
    $clientId = $clientId ? sanitize_text_field($clientId) : '';

    if ($clientId === '') {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'Missing client_id (and current user has no wnq_client_id).',
      ], 400);
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $payload = [
      'client_id' => $clientId,
      'status' => 'active',
      'name' => $clientId,
      'created_at' => $now,
      'created_by_wp_user_id' => get_current_user_id(),
    ];

    $res = $firebase->createClientDocIfMissing($clientId, $payload);

    if (!$res['ok']) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => $res['error'] ?? 'Unknown error',
        'status' => $res['status'] ?? 500,
        'details' => $res['body'] ?? ($res['details'] ?? null),
      ], $res['status'] ?? 500);
    }

    return new \WP_REST_Response([
      'ok' => true,
      'client_id' => $clientId,
      'created' => (bool) ($res['created'] ?? false),
      'doc' => $res['doc'] ?? null,
    ], 200);
  }
}
