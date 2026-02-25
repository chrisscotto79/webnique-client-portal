<?php
/**
 * FirebaseStore (v1)
 *
 * - Validates credentials from wp-config.php
 * - Generates Google OAuth access tokens using service-account JWT (RS256)
 * - Minimal Firestore REST helpers (GET doc, CREATE doc)
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
  exit;
}

final class FirebaseStore
{
  private string $projectId;
  private string $clientEmail;
  private string $privateKey;

  public function __construct()
  {
    $this->projectId   = defined('WNQ_FIREBASE_PROJECT_ID') ? (string) WNQ_FIREBASE_PROJECT_ID : '';
    $this->clientEmail = defined('WNQ_FIREBASE_CLIENT_EMAIL') ? (string) WNQ_FIREBASE_CLIENT_EMAIL : '';
    $this->privateKey  = defined('WNQ_FIREBASE_PRIVATE_KEY') ? (string) WNQ_FIREBASE_PRIVATE_KEY : '';

    // Normalize "\n" sequences into real newlines
    if (strpos($this->privateKey, "\\n") !== false) {
      $this->privateKey = str_replace("\\n", "\n", $this->privateKey);
    }
  }
  public function getClientDocFields(string $clientId): array
{
  $res = $this->getClientDoc($clientId);

  if (!$res['ok']) {
    return $res;
  }

  if (empty($res['exists'])) {
    return ['ok' => true, 'exists' => false, 'client' => null];
  }

  $doc = $res['doc'] ?? [];
  $fields = $doc['fields'] ?? [];

  return [
    'ok' => true,
    'exists' => true,
    'client' => $this->fromFirestoreFields($fields),
  ];
}

/**
 * Convert Firestore fields → plain associative array (limited types for v1).
 */
private function fromFirestoreFields(array $fields): array
{
  $out = [];

  foreach ($fields as $key => $wrapped) {
    if (isset($wrapped['stringValue'])) {
      $out[$key] = (string) $wrapped['stringValue'];
      continue;
    }
    if (isset($wrapped['booleanValue'])) {
      $out[$key] = (bool) $wrapped['booleanValue'];
      continue;
    }
    if (isset($wrapped['integerValue'])) {
      $out[$key] = (int) $wrapped['integerValue'];
      continue;
    }
    if (isset($wrapped['doubleValue'])) {
      $out[$key] = (float) $wrapped['doubleValue'];
      continue;
    }
    if (isset($wrapped['timestampValue'])) {
      $out[$key] = (string) $wrapped['timestampValue'];
      continue;
    }
    if (array_key_exists('nullValue', $wrapped)) {
      $out[$key] = null;
      continue;
    }

    // fallback (unknown type)
    $out[$key] = $wrapped;
  }

  return $out;
}


  public function ping(): array
  {
    $errors = [];

    if ($this->projectId === '') {
      $errors[] = 'Missing WNQ_FIREBASE_PROJECT_ID';
    }

    if ($this->clientEmail === '' || strpos($this->clientEmail, '@') === false) {
      $errors[] = 'Missing/invalid WNQ_FIREBASE_CLIENT_EMAIL';
    }

    if ($this->privateKey === '' || strpos($this->privateKey, 'BEGIN PRIVATE KEY') === false) {
      $errors[] = 'Missing/invalid WNQ_FIREBASE_PRIVATE_KEY';
    }

    return [
      'ok' => empty($errors),
      'errors' => $errors,
      'meta' => [
        'project_id_set' => ($this->projectId !== ''),
        'client_email_set' => ($this->clientEmail !== ''),
        'private_key_set' => ($this->privateKey !== ''),
      ],
    ];
  }

  /**
   * -----------------------------
   * Firestore (REST)
   * -----------------------------
   */

  public function getClientDoc(string $clientId): array
  {
    $clientId = trim($clientId);
    if ($clientId === '') {
      return ['ok' => false, 'status' => 400, 'error' => 'Missing clientId'];
    }

    $url = $this->firestoreBaseUrl() . '/clients/' . rawurlencode($clientId);

    $res = $this->request('GET', $url);
    if (!$res['ok'] && ($res['status'] ?? 0) === 404) {
      return ['ok' => true, 'exists' => false];
    }

    if (!$res['ok']) {
      return $res + ['exists' => null];
    }

    return ['ok' => true, 'exists' => true, 'doc' => $res['body']];
  }

  public function createClientDocIfMissing(string $clientId, array $data): array
  {
    $clientId = trim($clientId);
    if ($clientId === '') {
      return ['ok' => false, 'status' => 400, 'error' => 'Missing clientId'];
    }

    // Check existence
    $existsRes = $this->getClientDoc($clientId);
    if (!$existsRes['ok']) {
      return $existsRes;
    }
    if (!empty($existsRes['exists'])) {
      return ['ok' => true, 'created' => false, 'exists' => true];
    }

    $url = $this->firestoreBaseUrl() . '/clients?documentId=' . rawurlencode($clientId);

    $fields = $this->toFirestoreFields($data);

    $body = [
      'fields' => $fields,
    ];

    $res = $this->request('POST', $url, $body);

    if (!$res['ok']) {
      return $res + ['created' => false];
    }

    return ['ok' => true, 'created' => true, 'doc' => $res['body']];
  }

  private function firestoreBaseUrl(): string
  {
    return 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($this->projectId) . '/databases/(default)/documents';
  }

  /**
   * Converts a simple associative array into Firestore "fields" payload.
   * Supported: string, bool, int, float, timestamp (DateTimeInterface), null
   */
  private function toFirestoreFields(array $data): array
  {
    $out = [];

    foreach ($data as $key => $value) {
      if ($value === null) {
        $out[$key] = ['nullValue' => null];
        continue;
      }

      if ($value instanceof \DateTimeInterface) {
        $out[$key] = ['timestampValue' => $value->format('c')];
        continue;
      }

      if (is_bool($value)) {
        $out[$key] = ['booleanValue' => $value];
        continue;
      }

      if (is_int($value)) {
        $out[$key] = ['integerValue' => (string) $value];
        continue;
      }

      if (is_float($value)) {
        $out[$key] = ['doubleValue' => $value];
        continue;
      }

      // default string
      $out[$key] = ['stringValue' => (string) $value];
    }

    return $out;
  }

  /**
   * -----------------------------
   * Auth + HTTP
   * -----------------------------
   */

  private function getAccessToken(): array
  {
    // Cache for ~55 minutes
    $cacheKey = 'wnq_fb_token_' . md5($this->projectId . '|' . $this->clientEmail);
    $cached = get_transient($cacheKey);
    if (is_array($cached) && !empty($cached['access_token'])) {
      return ['ok' => true, 'access_token' => $cached['access_token']];
    }

    $scope = 'https://www.googleapis.com/auth/datastore';
    $now = time();

    $jwtHeader = ['alg' => 'RS256', 'typ' => 'JWT'];
    $jwtClaim = [
      'iss' => $this->clientEmail,
      'sub' => $this->clientEmail,
      'aud' => 'https://oauth2.googleapis.com/token',
      'iat' => $now,
      'exp' => $now + 3600,
      'scope' => $scope,
    ];

    $segments = [];
    $segments[] = $this->base64UrlEncode(wp_json_encode($jwtHeader));
    $segments[] = $this->base64UrlEncode(wp_json_encode($jwtClaim));

    $signingInput = implode('.', $segments);

    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
      return ['ok' => false, 'status' => 500, 'error' => 'Failed to sign JWT (openssl_sign)'];
    }

    $segments[] = $this->base64UrlEncode($signature);
    $jwt = implode('.', $segments);

    $tokenRes = wp_remote_post('https://oauth2.googleapis.com/token', [
      'timeout' => 20,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
      'body' => http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
      ]),
    ]);

    if (is_wp_error($tokenRes)) {
      return ['ok' => false, 'status' => 500, 'error' => $tokenRes->get_error_message()];
    }

    $status = (int) wp_remote_retrieve_response_code($tokenRes);
    $raw = (string) wp_remote_retrieve_body($tokenRes);
    $json = json_decode($raw, true);

    if ($status < 200 || $status >= 300 || empty($json['access_token'])) {
      return [
        'ok' => false,
        'status' => $status,
        'error' => 'Token request failed',
        'body' => $json ?: $raw,
      ];
    }

    set_transient($cacheKey, ['access_token' => $json['access_token']], 55 * MINUTE_IN_SECONDS);

    return ['ok' => true, 'access_token' => $json['access_token']];
  }

  private function request(string $method, string $url, ?array $jsonBody = null): array
  {
    $ping = $this->ping();
    if (!$ping['ok']) {
      return ['ok' => false, 'status' => 500, 'error' => 'Firebase credentials invalid', 'details' => $ping];
    }

    $token = $this->getAccessToken();
    if (!$token['ok']) {
      return $token;
    }

    $args = [
      'method' => $method,
      'timeout' => 25,
      'headers' => [
        'Authorization' => 'Bearer ' . $token['access_token'],
        'Content-Type' => 'application/json',
      ],
    ];

    if ($jsonBody !== null) {
      $args['body'] = wp_json_encode($jsonBody);
    }

    $res = wp_remote_request($url, $args);

    if (is_wp_error($res)) {
      return ['ok' => false, 'status' => 500, 'error' => $res->get_error_message()];
    }

    $status = (int) wp_remote_retrieve_response_code($res);
    $raw = (string) wp_remote_retrieve_body($res);
    $body = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
      return [
        'ok' => false,
        'status' => $status,
        'error' => 'Firestore request failed',
        'body' => $body ?: $raw,
      ];
    }

    return ['ok' => true, 'status' => $status, 'body' => $body];
  }

  private function base64UrlEncode(string $data): string
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }
}
