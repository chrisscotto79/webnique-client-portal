<?php
/**
 * Server-side Telegram notification delivery.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class TelegramNotifier
{
    private string $bot_token;
    private string $chat_id;

    public function __construct(?string $bot_token = null, ?string $chat_id = null)
    {
        $this->bot_token = trim($bot_token ?? (string)get_option('wnq_telegram_bot_token', ''));
        $this->chat_id = trim($chat_id ?? (string)get_option('wnq_telegram_chat_id', ''));
    }

    public function isConfigured(): bool
    {
        return preg_match('/^\d+:[A-Za-z0-9_-]+$/', $this->bot_token) === 1
            && preg_match('/^-?\d+$/', $this->chat_id) === 1;
    }

    public function send(string $message): array
    {
        $message = trim(wp_strip_all_tags($message));
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Telegram bot token and group chat ID are required.'];
        }
        if ($message === '') {
            return ['ok' => false, 'message' => 'Telegram message cannot be empty.'];
        }

        $response = wp_remote_post(
            'https://api.telegram.org/bot' . $this->bot_token . '/sendMessage',
            [
                'timeout' => 20,
                'body' => [
                    'chat_id' => $this->chat_id,
                    'text' => $message,
                    'disable_web_page_preview' => 'true',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($body['ok'])) {
            return [
                'ok' => false,
                'message' => sanitize_text_field((string)($body['description'] ?? 'Telegram rejected the test message.')),
            ];
        }

        return ['ok' => true, 'message' => 'Test notification sent to the Telegram group.'];
    }
}
