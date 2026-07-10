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

    public function getChatId(): string
    {
        return $this->chat_id;
    }

    public function send(string $message, array $options = []): array
    {
        $html = !empty($options['html']);
        $message = $html
            ? trim(wp_kses($message, [
                'b' => [],
                'strong' => [],
                'i' => [],
                'em' => [],
                'code' => [],
            ]))
            : trim(wp_strip_all_tags($message));
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Telegram bot token and group chat ID are required.'];
        }
        if ($message === '') {
            return ['ok' => false, 'message' => 'Telegram message cannot be empty.'];
        }

        $body = [
            'chat_id' => $this->chat_id,
            'text' => function_exists('mb_substr') ? mb_substr($message, 0, 4000) : substr($message, 0, 4000),
            'disable_web_page_preview' => 'true',
        ];
        if ($html) {
            $body['parse_mode'] = 'HTML';
        }
        $buttons = self::sanitizeButtons((array)($options['buttons'] ?? []));
        if ($buttons !== []) {
            $body['reply_markup'] = wp_json_encode(['inline_keyboard' => [$buttons]]);
        }

        $response = wp_remote_post(
            'https://api.telegram.org/bot' . $this->bot_token . '/sendMessage',
            ['timeout' => 20, 'body' => $body]
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

    public function notify(string $event, string $message, string $dedupe_key = '', int $dedupe_ttl = DAY_IN_SECONDS, array $options = []): array
    {
        if (!(bool)get_option('wnq_telegram_enabled', false)) {
            return ['ok' => false, 'skipped' => true, 'message' => 'Telegram notifications are disabled.'];
        }
        $stored_events = get_option('wnq_telegram_events', []);
        $defaults = class_exists(NotificationManager::class) ? NotificationManager::eventDefaults() : [];
        $events = array_merge($defaults, is_array($stored_events) ? $stored_events : []);
        if (array_key_exists($event, $events) && empty($events[$event])) {
            return ['ok' => false, 'skipped' => true, 'message' => 'This Telegram alert type is disabled.'];
        }

        $dedupe_key = sanitize_key($dedupe_key);
        $transient_key = $dedupe_key !== '' ? 'wnq_telegram_sent_' . md5($event . '|' . $dedupe_key) : '';
        if ($transient_key !== '' && get_transient($transient_key)) {
            return ['ok' => false, 'skipped' => true, 'message' => 'Duplicate Telegram alert suppressed.'];
        }

        $result = $this->send($message, $options);
        if (!empty($result['ok'])) {
            if ($transient_key !== '') {
                set_transient($transient_key, 1, max(MINUTE_IN_SECONDS, $dedupe_ttl));
            }
            update_option('wnq_telegram_last_sent_at', current_time('mysql'), false);
            delete_option('wnq_telegram_last_error');
        } else {
            update_option('wnq_telegram_last_error', sanitize_text_field((string)($result['message'] ?? 'Telegram delivery failed.')), false);
        }
        return $result;
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

    /**
     * Read recent bot updates for the command poller.
     *
     * @return array{ok: bool, message: string, updates: array<int, array>}
     */
    public function getUpdates(int $offset = 0): array
    {
        if (preg_match('/^\d+:[A-Za-z0-9_-]+$/', $this->bot_token) !== 1) {
            return ['ok' => false, 'message' => 'A valid Telegram bot token is required.', 'updates' => []];
        }

        $url = add_query_arg([
            'offset' => max(0, $offset),
            'limit' => 50,
            'timeout' => 0,
            'allowed_updates' => wp_json_encode(['message']),
        ], 'https://api.telegram.org/bot' . $this->bot_token . '/getUpdates');
        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message(), 'updates' => []];
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($body['ok'])) {
            return [
                'ok' => false,
                'message' => sanitize_text_field((string)($body['description'] ?? 'Telegram could not retrieve bot commands.')),
                'updates' => [],
            ];
        }
        return ['ok' => true, 'message' => 'Telegram updates retrieved.', 'updates' => (array)($body['result'] ?? [])];
    }

    public function setCommands(array $commands): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Telegram bot token and group chat ID are required.'];
        }
        $clean = [];
        foreach ($commands as $command => $description) {
            $command = sanitize_key((string)$command);
            $description = sanitize_text_field((string)$description);
            if ($command !== '' && $description !== '') {
                $clean[] = ['command' => $command, 'description' => $description];
            }
        }
        if ($clean === []) {
            return ['ok' => false, 'message' => 'At least one bot command is required.'];
        }
        $response = wp_remote_post(
            'https://api.telegram.org/bot' . $this->bot_token . '/setMyCommands',
            [
                'timeout' => 20,
                'body' => ['commands' => wp_json_encode($clean)],
            ]
        );
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($body['ok'])) {
            return ['ok' => false, 'message' => sanitize_text_field((string)($body['description'] ?? 'Telegram could not save bot commands.'))];
        }
        return ['ok' => true, 'message' => 'Telegram bot commands are ready.'];
    }

    public function setWebhook(string $url, string $secret): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Telegram bot token and group chat ID are required.'];
        }
        $url = esc_url_raw($url);
        $secret = preg_replace('/[^A-Za-z0-9_-]/', '', $secret) ?? '';
        if ($url === '' || !str_starts_with($url, 'https://') || $secret === '') {
            return ['ok' => false, 'message' => 'Telegram instant replies require an HTTPS webhook URL and a valid secret.'];
        }
        $response = wp_remote_post(
            'https://api.telegram.org/bot' . $this->bot_token . '/setWebhook',
            [
                'timeout' => 20,
                'body' => [
                    'url' => $url,
                    'secret_token' => $secret,
                    'allowed_updates' => wp_json_encode(['message']),
                    'drop_pending_updates' => 'false',
                ],
            ]
        );
        return self::telegramApiResult($response, 'Telegram could not enable instant replies.', 'Telegram instant replies are connected.');
    }

    public function deleteWebhook(): array
    {
        if (preg_match('/^\d+:[A-Za-z0-9_-]+$/', $this->bot_token) !== 1) {
            return ['ok' => false, 'message' => 'A valid Telegram bot token is required.'];
        }
        $response = wp_remote_post(
            'https://api.telegram.org/bot' . $this->bot_token . '/deleteWebhook',
            ['timeout' => 20, 'body' => ['drop_pending_updates' => 'false']]
        );
        return self::telegramApiResult($response, 'Telegram could not disable the webhook.', 'Telegram webhook disabled.');
    }

    private static function telegramApiResult($response, string $failure_message, string $success_message): array
    {
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($body['ok'])) {
            return ['ok' => false, 'message' => sanitize_text_field((string)($body['description'] ?? $failure_message))];
        }
        return ['ok' => true, 'message' => $success_message];
    }

    private static function sanitizeButtons(array $buttons): array
    {
        $clean = [];
        foreach (array_slice($buttons, 0, 3) as $button) {
            if (!is_array($button)) {
                continue;
            }
            $text = sanitize_text_field((string)($button['text'] ?? ''));
            $url = esc_url_raw((string)($button['url'] ?? ''));
            if ($text !== '' && $url !== '') {
                $clean[] = ['text' => $text, 'url' => $url];
            }
        }
        return $clean;
    }
}
