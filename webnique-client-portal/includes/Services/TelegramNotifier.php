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
            $description = sanitize_text_field((string)($body['description'] ?? 'Telegram rejected the test message.'));
            if (stripos($description, 'chat not found') !== false) {
                $description = 'Telegram cannot access that group. Add the bot to the group, send a command that mentions the bot (for example /start@YourBotUsername), then use Find Telegram Groups.';
            }

            return [
                'ok' => false,
                'message' => $description,
            ];
        }

        return ['ok' => true, 'message' => 'Test notification sent to the Telegram group.'];
    }

    /**
     * Find groups that have recently interacted with the configured bot.
     *
     * @return array{ok: bool, message: string, chats: array<int, array{id: string, title: string, type: string}>}
     */
    public function discoverChats(): array
    {
        if (preg_match('/^\d+:[A-Za-z0-9_-]+$/', $this->bot_token) !== 1) {
            return [
                'ok' => false,
                'message' => 'Save a valid Telegram bot token before finding groups.',
                'chats' => [],
            ];
        }

        $response = wp_remote_get(
            'https://api.telegram.org/bot' . $this->bot_token . '/getUpdates',
            ['timeout' => 20]
        );

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message(), 'chats' => []];
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($body['ok'])) {
            return [
                'ok' => false,
                'message' => sanitize_text_field((string)($body['description'] ?? 'Telegram could not retrieve recent groups.')),
                'chats' => [],
            ];
        }

        $chats = [];
        foreach ((array)($body['result'] ?? []) as $update) {
            foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post', 'my_chat_member', 'chat_member', 'chat_join_request'] as $update_key) {
                $chat = $update[$update_key]['chat'] ?? null;
                if (!is_array($chat)) {
                    continue;
                }

                $type = sanitize_key((string)($chat['type'] ?? ''));
                if (!in_array($type, ['group', 'supergroup'], true)) {
                    continue;
                }

                $chat_id = (string)($chat['id'] ?? '');
                if (preg_match('/^-?\d+$/', $chat_id) !== 1) {
                    continue;
                }

                $chats[$chat_id] = [
                    'id' => $chat_id,
                    'title' => sanitize_text_field((string)($chat['title'] ?? 'Telegram group')),
                    'type' => $type,
                ];
            }
        }

        $chats = array_values($chats);
        if ($chats === []) {
            return [
                'ok' => false,
                'message' => 'No Telegram groups were found. Add the bot to the group, send /start@YourBotUsername in that group, and try again.',
                'chats' => [],
            ];
        }

        return [
            'ok' => true,
            'message' => count($chats) === 1
                ? 'One Telegram group was found and selected.'
                : sprintf('%d Telegram groups were found. Select the correct group below.', count($chats)),
            'chats' => $chats,
        ];
    }
}
