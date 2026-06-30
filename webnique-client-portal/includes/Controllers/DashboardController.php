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

    foreach (['overview', 'customers', 'messages', 'tickets', 'requests', 'work', 'reports', 'profile', 'performance', 'learning', 'ads', 'notifications', 'settings'] as $resource) {
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

    register_rest_route('wnq/v1', '/portal/leads/(?P<id>\d+)/convert', [
      'methods'  => 'POST',
      'callback' => [self::class, 'convertLeadToJob'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/work', [
      'methods'  => 'POST',
      'callback' => [self::class, 'saveWork'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/messages', [
      'methods'  => 'POST',
      'callback' => [self::class, 'sendMessage'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/tickets/(?P<ticket_key>[a-z0-9-]+)', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getTicket'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/learning-requests', [
      'methods'  => 'POST',
      'callback' => [self::class, 'saveLearningRequest'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/requests', [
      'methods'  => 'POST',
      'callback' => [self::class, 'saveRequest'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/profile', [
      'methods'  => 'POST',
      'callback' => [self::class, 'saveProfile'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/settings', [
      'methods'  => 'POST',
      'callback' => [self::class, 'savePortalSettings'],
      'permission_callback' => [self::class, 'canUsePortal'],
    ]);

    register_rest_route('wnq/v1', '/portal/ads-settings', [
      'methods'  => 'POST',
      'callback' => [self::class, 'saveAdsSettings'],
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
    $include_private = Permissions::currentUserCanManagePortal();
    $data = match ($resource) {
      'overview'  => ClientPortal::overview($client_id, $include_private),
      'customers' => ClientPortal::getCustomers($client_id, 100, $include_private),
      'messages'  => ClientPortal::getMessages($client_id),
      'tickets'   => ClientPortal::getTickets($client_id),
      'requests'  => ['types' => ClientPortal::requestTypes(), 'items' => ClientPortal::getRequests($client_id)],
      'work'      => ClientPortal::getTasks($client_id, 100, $include_private),
      'reports'   => ClientPortal::getReports($client_id),
      'profile'   => ClientPortal::publicClient(Client::getByClientId($client_id) ?: []),
      'performance' => ClientPortal::getMonthlyPerformance($client_id, 6, $include_private),
      'learning'  => ['courses' => ClientPortal::courses(), 'requests' => ClientPortal::getLearningRequests($client_id)],
      'ads'       => ClientPortal::getAdsResource($client_id),
      'notifications' => ClientPortal::getPortalNotifications($client_id),
      'settings'  => ClientPortal::getPortalSettings($client_id),
      default     => [],
    };
    return new \WP_REST_Response(['ok' => true, 'data' => $data], 200);
  }

  public static function getTicket(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $ticket_key = sanitize_key((string)($request['ticket_key'] ?? ''));
    $ticket = $client_id !== '' ? ClientPortal::getTicket($client_id, $ticket_key) : null;
    if (!$ticket) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Ticket not found.'], 404);
    }
    ClientPortal::markTicketMessagesRead(
      $client_id,
      $ticket_key,
      Permissions::currentUserCanManagePortal() ? 'client' : 'admin'
    );
    $ticket['unread'] = false;
    return new \WP_REST_Response(['ok' => true, 'data' => $ticket], 200);
  }

  public static function saveCustomer(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $body['files'] = self::handleUploads($request, 'attachments');
    $body['before_photos'] = self::handleUploads($request, 'before_photos');
    $body['after_photos'] = self::handleUploads($request, 'after_photos');
    if ($client_id === '') {
      ClientPortal::deletePrivateAttachments($body['files']);
      ClientPortal::deletePrivateAttachments($body['before_photos']);
      ClientPortal::deletePrivateAttachments($body['after_photos']);
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client is linked to this portal user.'], 403);
    }
    if (!Permissions::currentUserCanManagePortal()) {
      unset($body['job_cost'], $body['internal_notes']);
    }
    $submitted_name = trim((string)($body['name'] ?? $body['customer_name'] ?? $body['customerName'] ?? ''));
    $id = ClientPortal::saveCustomer($client_id, $body);
    if (!$id) {
      ClientPortal::deletePrivateAttachments($body['files']);
      ClientPortal::deletePrivateAttachments($body['before_photos']);
      ClientPortal::deletePrivateAttachments($body['after_photos']);
      $db_error = Permissions::currentUserCanManagePortal() ? ClientPortal::lastError() : '';
      return new \WP_REST_Response([
        'ok' => false,
        'error' => $submitted_name === ''
          ? 'Contact name is required.'
          : ($db_error !== '' ? 'The CRM record could not be saved. Database error: ' . $db_error : 'The CRM record could not be saved. Please refresh and try again.'),
      ], 400);
    }
    $saved_record = ClientPortal::getCustomer((int)$id, $client_id);
    if ($saved_record && !Permissions::currentUserCanManagePortal()) {
      $saved_record = ClientPortal::publicCustomerRecord($saved_record);
    }
    return new \WP_REST_Response(['ok' => true, 'id' => $id, 'data' => $saved_record], 200);
  }

  public static function convertLeadToJob(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $id = absint($request['id'] ?? 0);
    $converted = $client_id !== '' && $id > 0 ? ClientPortal::convertLeadToJob($client_id, $id) : false;
    if (!$converted) {
      $db_error = Permissions::currentUserCanManagePortal() ? ClientPortal::lastError() : '';
      return new \WP_REST_Response([
        'ok' => false,
        'error' => $db_error !== '' ? 'The lead could not be converted. Database error: ' . $db_error : 'The lead could not be converted to a job.',
      ], 400);
    }
    $record = ClientPortal::getCustomer($id, $client_id);
    if ($record && !Permissions::currentUserCanManagePortal()) {
      $record = ClientPortal::publicCustomerRecord($record);
    }
    return new \WP_REST_Response(['ok' => true, 'id' => $id, 'data' => $record], 200);
  }

  public static function saveWork(\WP_REST_Request $request): \WP_REST_Response
  {
    if (!Permissions::currentUserCanManagePortal()) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Only admins can add marketing work history.'], 403);
    }
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $id = $client_id !== '' ? ClientPortal::createMarketingWork($client_id, $body) : false;
    return $id
      ? new \WP_REST_Response(['ok' => true, 'id' => $id, 'data' => ClientPortal::getTasks($client_id, 100, true)], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'Add a title for the marketing work item.'], 400);
  }

  public static function sendMessage(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $role = Permissions::currentUserCanManagePortal() ? 'admin' : 'client';
    $error = ClientPortal::messageValidationError($client_id, $body, $role);
    if ($error !== '') {
      return new \WP_REST_Response(['ok' => false, 'error' => $error], 400);
    }
    $body['attachments'] = self::handleUploads($request);
    $id = $client_id !== '' && is_array($body) ? ClientPortal::createMessage($client_id, $body, $role) : false;
    if (!$id) {
      ClientPortal::deletePrivateAttachments($body['attachments']);
    }
    return $id
      ? new \WP_REST_Response(['ok' => true, 'id' => $id], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'The support ticket could not be saved.'], 400);
  }

  public static function saveLearningRequest(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $id = $client_id !== '' && is_array($body) ? ClientPortal::createLearningRequest($client_id, $body) : false;
    return $id
      ? new \WP_REST_Response(['ok' => true, 'id' => $id], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'A request title is required.'], 400);
  }

  public static function saveProfile(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $saved = $client_id !== '' && is_array($body) && ClientPortal::updatePublicProfile($client_id, $body);
    return $saved
      ? new \WP_REST_Response(['ok' => true, 'data' => ClientPortal::publicClient(Client::getByClientId($client_id) ?: [])], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'Business profile could not be saved.'], 400);
  }

  public static function savePortalSettings(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $saved = $client_id !== '' && ClientPortal::savePortalSettings($client_id, $body);
    return $saved
      ? new \WP_REST_Response(['ok' => true, 'data' => ClientPortal::getPortalSettings($client_id)], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'Portal settings could not be saved.'], 400);
  }

  public static function saveAdsSettings(\WP_REST_Request $request): \WP_REST_Response
  {
    if (!Permissions::currentUserCanManagePortal()) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Only admins can update Google Ads settings.'], 403);
    }
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $saved = $client_id !== '' && is_array($body) && ClientPortal::saveAdsSettings($client_id, $body);
    return $saved
      ? new \WP_REST_Response(['ok' => true, 'data' => ClientPortal::getAdsResource($client_id)], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'Google Ads settings could not be saved.'], 400);
  }

  public static function saveRequest(\WP_REST_Request $request): \WP_REST_Response
  {
    $client_id = self::requestClientId($request);
    $body = self::requestBody($request);
    $body['request_data'] = isset($body['request_data']) && is_string($body['request_data'])
      ? (json_decode($body['request_data'], true) ?: [])
      : ($body['request_data'] ?? []);
    if ($client_id === '' || !ClientPortal::isValidRequestInput($body)) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'Choose a request type and add a title.'], 400);
    }
    $body['attachments'] = self::handleUploads($request);
    $id = ClientPortal::createRequest($client_id, $body);
    if (!$id) {
      ClientPortal::deletePrivateAttachments($body['attachments']);
    }
    return $id
      ? new \WP_REST_Response(['ok' => true, 'id' => $id], 200)
      : new \WP_REST_Response(['ok' => false, 'error' => 'Choose a request type and add a title.'], 400);
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

  private static function requestBody(\WP_REST_Request $request): array
  {
    $merged = [];
    foreach ([$request->get_params(), $request->get_body_params(), $_POST ?? []] as $source) {
      if (!is_array($source) || empty($source)) {
        continue;
      }
      $merged = array_merge($merged, wp_unslash($source));
    }
    $json = $request->get_json_params();
    if (is_array($json) && !empty($json)) {
      $merged = array_merge($merged, $json);
    }
    foreach (self::knownRequestBodyFields() as $key) {
      if (array_key_exists($key, $merged)) {
        continue;
      }
      $value = $request->get_param($key);
      if ($value !== null) {
        $merged[$key] = $value;
      }
    }
    unset($merged['client_id'], $merged['rest_route']);
    return $merged;
  }

  private static function knownRequestBodyFields(): array
  {
    return [
      'id', 'record_type', 'name', 'customer_name', 'customerName', 'phone', 'email',
      'address', 'job_address', 'service', 'crew', 'lead_source', 'status',
      'follow_up_date', 'reminder_date', 'job_date', 'completion_date', 'job_count',
      'estimated_value', 'final_value', 'job_cost', 'notes', 'internal_notes',
      'lost_reason', 'subject', 'message', 'category', 'priority', 'ticket_status',
      'request_type', 'title', 'details', 'request_data', 'customer_id',
      'manager_customer_id', 'service_account_email', 'api_key', 'developer_token',
      'oauth_client_id', 'oauth_client_secret', 'refresh_token', 'work_type',
      'work_date', 'task_type', 'assigned_to',
    ];
  }

  private static function handleUploads(\WP_REST_Request $request, string $field = 'attachments'): array
  {
    $files = $request->get_file_params();
    if (empty($files[$field])) return [];
    $group = $files[$field];
    $uploads = [];
    $normalized = [];
    if (is_array($group['name'] ?? null)) {
      foreach (array_keys($group['name']) as $index) {
        $normalized[] = [
          'name' => $group['name'][$index] ?? '',
          'type' => $group['type'][$index] ?? '',
          'tmp_name' => $group['tmp_name'][$index] ?? '',
          'error' => $group['error'][$index] ?? UPLOAD_ERR_NO_FILE,
          'size' => $group['size'][$index] ?? 0,
        ];
      }
    } else {
      $normalized[] = $group;
    }
    foreach (array_slice($normalized, 0, 5) as $file) {
      if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > 10 * MB_IN_BYTES) continue;
      $uploaded = ClientPortal::storePrivateUpload($file);
      if ($uploaded) $uploads[] = $uploaded;
    }
    return $uploads;
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
