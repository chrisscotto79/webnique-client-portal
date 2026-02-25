<?php

namespace WNQ\Core;

if (!defined('ABSPATH')) {
  exit;
}

final class UserMeta
{
  public static function register(): void
  {
    // Show field
    add_action('show_user_profile', [self::class, 'renderField']);
    add_action('edit_user_profile', [self::class, 'renderField']);

    // Save field
    add_action('personal_options_update', [self::class, 'saveField']);
    add_action('edit_user_profile_update', [self::class, 'saveField']);
  }

  public static function renderField($user): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    $clientId = (string) get_user_meta($user->ID, 'wnq_client_id', true);
    $clientId = esc_attr($clientId);
    ?>
    <h2>WebNique Portal</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th><label for="wnq_client_id">Client ID</label></th>
        <td>
          <input type="text" name="wnq_client_id" id="wnq_client_id" value="<?php echo $clientId; ?>" class="regular-text" />
          <p class="description">
            Internal tenant ID used by the WebNique Client Portal. (v1: 1 WP user → 1 client)
          </p>
        </td>
      </tr>
    </table>
    <?php
  }

  public static function saveField(int $userId): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    if (!isset($_POST['wnq_client_id'])) {
      return;
    }

    $clientId = sanitize_text_field((string) $_POST['wnq_client_id']);
    update_user_meta($userId, 'wnq_client_id', $clientId);
  }
}
