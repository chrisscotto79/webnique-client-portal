<?php

namespace WNQ\Core;

if (!defined('ABSPATH')) {
  exit;
}

final class Permissions
{
  /**
   * v1 mapping: store the client_id in WP usermeta.
   * usermeta key: wnq_client_id
   */
  public static function getClientIdForUser(int $wpUserId): ?string
  {
    $clientId = (string) get_user_meta($wpUserId, 'wnq_client_id', true);
    $clientId = trim($clientId);

    return $clientId !== '' ? $clientId : null;
  }

  public static function currentUserClientId(): ?string
  {
    $userId = get_current_user_id();
    if ($userId <= 0) {
      return null;
    }
    return self::getClientIdForUser($userId);
  }

  public static function isLoggedIn(): bool
  {
    return is_user_logged_in();
  }

  public static function currentUserCanManagePortal(): bool
  {
    return current_user_can('wnq_manage_portal') || current_user_can('manage_options');
  }

  /**
   * True if the current user is allowed to access the given client_id.
   * v1: user must match that client_id OR be an admin with view_all.
   */
  public static function canAccessClient(string $clientId): bool
  {
    if ($clientId === '') {
      return false;
    }

    if (current_user_can('wnq_view_all_clients') || current_user_can('manage_options')) {
      return true;
    }

    $currentClientId = self::currentUserClientId();
    return $currentClientId !== null && hash_equals($currentClientId, $clientId);
  }
}
