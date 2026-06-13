<?php

namespace WNQ\Controllers;

use WNQ\Core\Permissions;
use WNQ\Models\Client;
use WNQ\Models\ClientPortal;
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

    // GET /wnq/v1/subscription (current user's subscription/plan data)
    register_rest_route('wnq/v1', '/subscription', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getSubscription'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    // GET /wnq/v1/invoices (billing history — stub until Stripe is wired)
    register_rest_route('wnq/v1', '/invoices', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getInvoices'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    foreach (['overview', 'customers', 'messages', 'work', 'reports', 'profile'] as $resource) {
      register_rest_route('wnq/v1', '/portal/' . $resource, [
        'methods'  => 'GET',
        'callback' => [self::class, 'getPortalResource'],
        'permission_callback' => [self::class, 'canUsePortal'],
      ]);
    }

    register_rest_route('wnq/v1', '/portal/customers', [
      'methods'  => 'POST',
      'callback' => [self::class, 'saveCustomer'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/messages', [
      'methods'  => 'POST',
      'callback' => [self::class, 'sendMessage'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/reports/(?P<id>\d+)', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getReport'],
      'permission_callback' => [self::class, 'canUsePortal'],
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
    $clientId = self::requestClientId($request);

    if ($clientId === '') {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'No wnq_client_id is set for this WP user.',
      ], 400);
    }

    $client = Client::getByClientId($clientId);
    return new \WP_REST_Response([
      'ok' => true,
      'client_id' => $clientId,
      'exists' => (bool)$client,
      'client' => $client ? ClientPortal::publicClient($client) : null,
    ], 200);
  }

  public static function canUsePortal(): bool
  {
    return is_user_logged_in() && (Permissions::currentUserClientId() !== null || Permissions::currentUserCanManagePortal());
  }

  public static function getPortalResource(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    if ($client_id === '') {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client linked.'], 403);
    }
    $route = $request->get_route();
    $resource = basename($route);
    $data = match ($resource) {
      'overview'  => ClientPortal::overview($client_id),
      'customers' => ClientPortal::getCustomers($client_id),
      'messages'  => ClientPortal::getMessages($client_id),
      'work'      => ClientPortal::getTasks($client_id),
      'reports'   => ClientPortal::getReports($client_id),
      'profile'   => ClientPortal::publicClient(Client::getByClientId($client_id) ?: []),
      default     => [],
    };
    if ($resource === 'messages' && !Permissions::currentUserCanManagePortal()) {
      ClientPortal::markMessagesRead($client_id, 'admin');
    }
    return new \WP_REST_Response(['ok' => true, 'data' => $data], 200);
  }

  public static function saveCustomer(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = $request->get_json_params();
    $id = $client_id !== '' && is_array($body) ? ClientPortal::saveCustomer($client_id, $body) : false;
    if (!$id) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Customer name is required.'], 400);
    }
    return new \WP_REST_Response(['ok' => true, 'id' => $id, 'data' => ClientPortal::getCustomer((int)$id, $client_id)], 200);
  }

  public static function sendMessage(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = $request->get_json_params();
    $role = Permissions::currentUserCanManagePortal() ? 'admin' : 'client';
    $id = $client_id !== '' && is_array($body) ? ClientPortal::createMessage($client_id, $body, $role) : false;
    return $id
      ? new \WP_REST_Response(['ok' => true, 'id' => $id], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'Message is required.'], 400);
  }

  public static function getReport(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $report = ClientPortal::getReport(absint($request['id'] ?? 0), $client_id);
    return $report
      ? new \WP_REST_Response(['ok' => true, 'data' => $report], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'Report not found.'], 404);
  }

  private static function requestClientId(\WP_REST_Request $request): string
  {
    $requested = sanitize_text_field((string)$request->get_param('client_id'));
    if ($requested !== '' && Permissions::currentUserCanManagePortal()) {
      return $requested;
    }
    return sanitize_text_field((string)(Permissions::currentUserClientId() ?? ''));
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

  public static function getSeoAudit(\WP_REST_Request $request): \WP_REST_Response
  {
    return new \WP_REST_Response([
      'ok' => false,
      'error' => 'SEO audit is disabled in portal-only mode.',
    ], 404);
  }

  public static function getSubscription(\WP_REST_Request $request): \WP_REST_Response
  {
    $clientId = Permissions::currentUserClientId();
    if (!$clientId && !Permissions::currentUserCanManagePortal()) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client linked.'], 403);
    }

    $paramId = sanitize_text_field($request->get_param('client_id') ?? '');
    if ($paramId && Permissions::currentUserCanManagePortal()) {
      $clientId = $paramId;
    }

    if (!$clientId) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client ID.'], 400);
    }

    $firebase = new FirebaseStore();
    $res = $firebase->getClientDocFields($clientId);

    if (!$res['ok']) {
      return new \WP_REST_Response(['ok' => false, 'error' => $res['error'] ?? 'Unknown error'], 500);
    }

    $client = $res['client'] ?? [];

    // Pull subscription fields stored on the client doc; fall back to empty values
    $data = [
      'plan_name'      => $client['plan_name']      ?? ($client['tier'] ?? null),
      'price'          => $client['price']           ?? null,
      'billing_cycle'  => $client['billing_cycle']  ?? null,
      'renewal_date'   => $client['renewal_date']   ?? null,
      'payment_method' => $client['payment_method'] ?? null,
      'status'         => $client['status']          ?? 'active',
      'services'       => [],
    ];

    return new \WP_REST_Response(['ok' => true, 'data' => $data], 200);
  }

  public static function getInvoices(\WP_REST_Request $request): \WP_REST_Response
  {
    // Stub — returns empty billing history until Stripe is integrated
    return new \WP_REST_Response(['ok' => true, 'data' => []], 200);
  }

  public static function getSeoReports(\WP_REST_Request $request): \WP_REST_Response
  {
    return new \WP_REST_Response([
      'ok' => false,
      'error' => 'SEO reports are disabled in portal-only mode.',
    ], 404);
  }
}
