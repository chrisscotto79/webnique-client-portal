<?php
/**
 * Coordinates internal Telegram alerts and scheduled operational checks.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

use WNQ\Models\Client;
use WNQ\Models\ClientPortal;
use WNQ\Models\Task;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationManager
{
    private const CRON_HOOK = 'wnq_portal_notification_checks';
    private const CRON_LOCK = 'wnq_portal_notification_check_lock';
    private const COMMAND_CRON_HOOK = 'wnq_telegram_command_poll';
    private const COMMAND_CRON_SCHEDULE = 'wnq_every_five_minutes';
    private const COMMAND_OFFSET_OPTION = 'wnq_telegram_update_offset';
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_filter('cron_schedules', [self::class, 'addCronSchedules']);
        add_action('wnq_portal_message_created', [self::class, 'onMessageCreated'], 10, 6);
        add_action('wnq_portal_request_created', [self::class, 'onRequestCreated'], 10, 3);
        add_action('wnq_portal_learning_request_created', [self::class, 'onLearningRequestCreated'], 10, 3);
        add_action('wnq_portal_payment_recorded', [self::class, 'onPaymentRecorded'], 10, 5);
        add_action('wnq_task_created', [self::class, 'onTaskCreated'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'runScheduledChecks']);
        add_action(self::COMMAND_CRON_HOOK, [self::class, 'pollBotCommands']);
        add_action('rest_api_init', [self::class, 'registerRoutes']);

        self::syncSchedule();
    }

    public static function eventDefaults(): array
    {
        return [
            'tasks' => true,
            'support_messages' => true,
            'client_requests' => true,
            'learning_requests' => true,
            'payments' => true,
            'ads_spend' => true,
            'ads_connection' => true,
            'overdue_tasks' => true,
        ];
    }

    public static function botCommands(): array
    {
        return [
            'tasks' => 'Show open agency tasks',
            'today' => 'Show tasks due today',
            'overdue' => 'Show overdue agency tasks',
            'ads' => 'Show 30-day client ad spend',
            'requests' => 'Show open client requests',
            'status' => 'Show notification system status',
            'help' => 'Show all available commands',
        ];
    }

    public static function addCronSchedules(array $schedules): array
    {
        $schedules[self::COMMAND_CRON_SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'Every five minutes',
        ];
        return $schedules;
    }

    public static function syncSchedule(): void
    {
        if (!(bool)get_option('wnq_telegram_enabled', false)) {
            self::unschedule();
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + (10 * MINUTE_IN_SECONDS), 'daily', self::CRON_HOOK);
        }
        if (!wp_next_scheduled(self::COMMAND_CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::COMMAND_CRON_SCHEDULE, self::COMMAND_CRON_HOOK);
        }
        self::syncBotCommands();
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
        $timestamp = wp_next_scheduled(self::COMMAND_CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::COMMAND_CRON_HOOK);
            $timestamp = wp_next_scheduled(self::COMMAND_CRON_HOOK);
        }
        delete_transient(self::CRON_LOCK);
    }

    public static function registerRoutes(): void
    {
        register_rest_route('wnq/v1', '/notifications/stripe', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleStripeWebhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function onMessageCreated(int $message_id, string $client_id, string $sender_role, array $data, string $ticket_key, bool $is_new_ticket = false): void
    {
        if ($sender_role !== 'client') {
            return;
        }
        if ($is_new_ticket) {
            self::createPortalTask(
                'Support ticket: ' . self::clean((string)($data['subject'] ?? 'Client message')),
                $client_id,
                (string)($data['priority'] ?? 'normal'),
                self::clip((string)($data['message'] ?? ''), 500),
                'message:' . $message_id
            );
        }
        $message = self::htmlMessage('New client message', [
            'Client' => self::clientName($client_id),
            'Subject' => self::clean((string)($data['subject'] ?? 'Support ticket')),
            'Priority' => self::label((string)($data['priority'] ?? 'normal')),
            'Message' => self::clip((string)($data['message'] ?? ''), 180),
        ]);
        (new TelegramNotifier())->notify(
            'support_messages',
            $message,
            'message:' . $message_id,
            30 * DAY_IN_SECONDS,
            self::telegramOptions('Open client', self::adminUrl($client_id))
        );
    }

    public static function onRequestCreated(int $request_id, string $client_id, string $request_key): void
    {
        global $wpdb;
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT request_type,title,priority,details FROM {$wpdb->prefix}wnq_portal_requests WHERE id=%d AND client_id=%s",
            $request_id,
            $client_id
        ), ARRAY_A) ?: [];
        self::createPortalTask(
            'Client request: ' . self::clean((string)($request['title'] ?? $request_key)),
            $client_id,
            (string)($request['priority'] ?? 'normal'),
            self::clip((string)($request['details'] ?? ''), 500),
            'request:' . $request_id
        );
        $message = self::htmlMessage('New client request', [
            'Client' => self::clientName($client_id),
            'Type' => self::label((string)($request['request_type'] ?? 'request')),
            'Title' => self::clean((string)($request['title'] ?? $request_key)),
            'Priority' => self::label((string)($request['priority'] ?? 'normal')),
            'Details' => self::clip((string)($request['details'] ?? ''), 180),
        ]);
        (new TelegramNotifier())->notify(
            'client_requests',
            $message,
            'request:' . $request_id,
            30 * DAY_IN_SECONDS,
            self::telegramOptions('Open tasks', self::tasksUrl())
        );
    }

    public static function onLearningRequestCreated(int $request_id, string $client_id, array $request): void
    {
        self::createPortalTask(
            'Learning request: ' . self::clean((string)($request['title'] ?? 'Learning request')),
            $client_id,
            'normal',
            self::clip((string)($request['details'] ?? ''), 500),
            'learning:' . $request_id
        );
        $message = self::htmlMessage('New learning request', [
            'Client' => self::clientName($client_id),
            'Type' => self::label((string)($request['request_type'] ?? 'topic')),
            'Title' => self::clean((string)($request['title'] ?? 'Learning request')),
            'Details' => self::clip((string)($request['details'] ?? ''), 180),
        ]);
        (new TelegramNotifier())->notify(
            'learning_requests',
            $message,
            'learning:' . $request_id,
            30 * DAY_IN_SECONDS,
            self::telegramOptions('Open tasks', self::tasksUrl())
        );
    }

    public static function onTaskCreated(int $task_id, array $task): void
    {
        if (($task['status'] ?? 'todo') === 'done' || str_contains((string)($task['notes'] ?? ''), '[portal-auto]')) {
            return;
        }
        $message = self::htmlMessage('New agency task', [
            'Task' => self::clean((string)($task['title'] ?? 'Untitled task')),
            'Priority' => self::label((string)($task['priority'] ?? 'medium')),
            'Due' => self::formatDate((string)($task['due_date'] ?? '')),
            'Assigned to' => self::label((string)($task['assigned_to'] ?? 'Unassigned')),
        ]);
        (new TelegramNotifier())->notify(
            'tasks',
            $message,
            'task:' . $task_id,
            30 * DAY_IN_SECONDS,
            self::telegramOptions('Open tasks', self::tasksUrl())
        );
    }

    public static function onPaymentRecorded(string $client_id, float $amount, string $source, string $payment_id, string $status = 'paid'): void
    {
        $heading = in_array($status, ['failed', 'past_due'], true) ? 'Payment needs attention' : 'Payment received';
        $message = self::htmlMessage($heading, [
            'Client' => $client_id !== '' ? self::clientName($client_id) : 'Unmatched Stripe customer',
            'Amount' => self::money($amount),
            'Source' => self::clean($source),
            'Status' => self::label($status),
        ]);
        $options = $client_id !== '' ? self::telegramOptions('Open client', self::adminUrl($client_id)) : ['html' => true];
        (new TelegramNotifier())->notify('payments', $message, 'payment:' . sanitize_key($status . '-' . $payment_id), 90 * DAY_IN_SECONDS, $options);
    }

    public static function runScheduledChecks(bool $force = false): array
    {
        if (!(bool)get_option('wnq_telegram_enabled', false)) {
            return ['ok' => false, 'message' => 'Telegram notifications are disabled.', 'ads_alerts' => 0, 'task_alerts' => 0];
        }
        if (!$force && get_transient(self::CRON_LOCK)) {
            return ['ok' => true, 'message' => 'Notification checks are already running.', 'ads_alerts' => 0, 'task_alerts' => 0];
        }
        set_transient(self::CRON_LOCK, 1, 15 * MINUTE_IN_SECONDS);

        try {
            $ads_alerts = self::checkAdsAccounts();
            $task_alerts = self::checkOverdueTasks();
        } finally {
            delete_transient(self::CRON_LOCK);
        }

        update_option('wnq_telegram_last_check_at', current_time('mysql'), false);
        return [
            'ok' => true,
            'message' => sprintf('Checks completed. %d Ads alert%s and %d overdue task alert%s sent.', $ads_alerts, $ads_alerts === 1 ? '' : 's', $task_alerts, $task_alerts === 1 ? '' : 's'),
            'ads_alerts' => $ads_alerts,
            'task_alerts' => $task_alerts,
        ];
    }

    public static function handleStripeWebhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $secret = trim((string)get_option('wnq_stripe_webhook_secret', ''));
        if ($secret === '') {
            return new \WP_REST_Response(['ok' => false, 'error' => 'Stripe webhook is not configured.'], 503);
        }

        $payload = (string)$request->get_body();
        $signature = (string)$request->get_header('stripe-signature');
        if (!self::validStripeSignature($payload, $signature, $secret)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'Invalid Stripe signature.'], 401);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'Invalid Stripe event.'], 400);
        }

        $type = sanitize_text_field((string)$event['type']);
        $success_types = ['invoice.paid', 'payment_intent.succeeded', 'checkout.session.completed'];
        $failure_types = ['invoice.payment_failed', 'payment_intent.payment_failed'];
        if (!in_array($type, array_merge($success_types, $failure_types), true)) {
            return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
        }

        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $client_id = self::resolveStripeClientId($object);
        $amount = self::stripeAmount($object);
        $status = in_array($type, $failure_types, true) ? 'failed' : 'paid';
        $payment_key = sanitize_text_field((string)($object['payment_intent'] ?? $object['id'] ?? $event['id']));
        self::onPaymentRecorded($client_id, $amount, 'Stripe ' . self::label($type), $payment_key, $status);

        return new \WP_REST_Response(['ok' => true], 200);
    }

    private static function checkAdsAccounts(): int
    {
        if (!self::eventEnabled('ads_spend') && !self::eventEnabled('ads_connection')) {
            return 0;
        }
        $threshold = max(0.0, (float)get_option('wnq_telegram_ads_spend_threshold', 1000));
        $state = get_option('wnq_telegram_ads_spend_state', []);
        $state = is_array($state) ? $state : [];
        $sent = 0;
        $notifier = new TelegramNotifier();

        foreach (Client::getAll() as $client) {
            $client_id = sanitize_text_field((string)($client['client_id'] ?? ''));
            if ($client_id === '' || empty(ClientPortal::getAdsPublicStatus($client_id)['has_linked_account'])) {
                continue;
            }
            $ads = ClientPortal::getAdsResource($client_id, true, false);
            $errors = array_values(array_filter(array_map('sanitize_text_field', (array)($ads['errors'] ?? []))));
            if ($errors && self::eventEnabled('ads_connection')) {
                $result = $notifier->notify(
                    'ads_connection',
                    self::htmlMessage('Google Ads connection needs attention', [
                        'Client' => self::clientName($client_id),
                        'Issue' => self::clip(implode(' ', $errors), 220),
                    ]),
                    'ads-error:' . md5($client_id . implode('|', $errors)),
                    DAY_IN_SECONDS,
                    self::telegramOptions('Open client', self::adminUrl($client_id))
                );
                if (!empty($result['ok'])) {
                    $sent++;
                }
            }
            if ($threshold <= 0 || !self::eventEnabled('ads_spend') || empty($ads['configured'])) {
                continue;
            }

            $spend = (float)($ads['summary']['spend'] ?? 0);
            $previous = is_array($state[$client_id] ?? null) ? $state[$client_id] : [];
            $same_threshold = isset($previous['threshold']) && (float)$previous['threshold'] === $threshold;
            $was_above = $same_threshold && !empty($previous['above']);
            if ($spend >= $threshold && !$was_above) {
                $result = $notifier->notify(
                    'ads_spend',
                    self::htmlMessage('Google Ads spend threshold', [
                        'Client' => self::clientName($client_id),
                        'Last 30 days' => self::money($spend),
                        'Alert threshold' => self::money($threshold),
                    ]),
                    'ads-threshold:' . md5($client_id . '|' . $threshold . '|' . current_time('Y-m-d')),
                    DAY_IN_SECONDS,
                    self::telegramOptions('Open client', self::adminUrl($client_id))
                );
                if (!empty($result['ok'])) {
                    $sent++;
                    $was_above = true;
                }
            } elseif ($spend < $threshold) {
                $was_above = false;
            }
            $state[$client_id] = ['above' => $was_above, 'threshold' => $threshold, 'spend' => $spend, 'checked_at' => current_time('mysql')];
        }
        update_option('wnq_telegram_ads_spend_state', $state, false);
        return $sent;
    }

    private static function checkOverdueTasks(): int
    {
        if (!self::eventEnabled('overdue_tasks')) {
            return 0;
        }
        $today = current_time('Y-m-d');
        $tasks = Task::getOverdue();
        if ($tasks === []) {
            return 0;
        }
        $message = self::taskListMessage('Overdue agency tasks', $tasks, 10);
        $result = (new TelegramNotifier())->notify(
            'overdue_tasks',
            $message,
            'overdue-tasks:' . $today,
            2 * DAY_IN_SECONDS,
            self::telegramOptions('Open tasks', self::tasksUrl())
        );
        return !empty($result['ok']) ? 1 : 0;
    }

    public static function syncBotCommands(bool $force = false): array
    {
        $notifier = new TelegramNotifier();
        if (!(bool)get_option('wnq_telegram_enabled', false) || !$notifier->isConfigured()) {
            return ['ok' => false, 'skipped' => true, 'message' => 'Configure and enable Telegram before syncing commands.'];
        }
        $hash = hash('sha256', (string)get_option('wnq_telegram_bot_token', '') . '|' . wp_json_encode(self::botCommands()));
        if (!$force && hash_equals((string)get_option('wnq_telegram_commands_hash', ''), $hash)) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Telegram bot commands are already current.'];
        }
        $result = $notifier->setCommands(self::botCommands());
        if (!empty($result['ok'])) {
            update_option('wnq_telegram_commands_hash', $hash, false);
            self::primeCommandOffset($notifier);
        }
        return $result;
    }

    public static function pollBotCommands(): void
    {
        if (!(bool)get_option('wnq_telegram_enabled', false)) {
            return;
        }
        $notifier = new TelegramNotifier();
        if (!$notifier->isConfigured()) {
            return;
        }
        $offset = max(0, (int)get_option(self::COMMAND_OFFSET_OPTION, 0));
        $result = $notifier->getUpdates($offset);
        if (empty($result['ok'])) {
            update_option('wnq_telegram_last_error', sanitize_text_field((string)($result['message'] ?? 'Telegram command polling failed.')), false);
            return;
        }

        foreach ((array)($result['updates'] ?? []) as $update) {
            $update_id = absint($update['update_id'] ?? 0);
            if ($update_id > 0) {
                update_option(self::COMMAND_OFFSET_OPTION, $update_id + 1, false);
            }
            $message = is_array($update['message'] ?? null) ? $update['message'] : [];
            $chat_id = (string)($message['chat']['id'] ?? '');
            $text = trim((string)($message['text'] ?? ''));
            if ($chat_id !== $notifier->getChatId() || !str_starts_with($text, '/')) {
                continue;
            }
            $command = strtolower((string)preg_replace('/@[^\s]+$/', '', strtok($text, " \t\r\n") ?: ''));
            self::respondToCommand($notifier, ltrim($command, '/'));
        }
        delete_option('wnq_telegram_last_error');
        update_option('wnq_telegram_last_command_check_at', current_time('mysql'), false);
    }

    private static function respondToCommand(TelegramNotifier $notifier, string $command): void
    {
        switch ($command) {
            case 'tasks':
                $tasks = array_values(array_filter(Task::getAll(), static fn(array $task): bool => ($task['status'] ?? '') !== 'done'));
                self::sortTasks($tasks);
                $notifier->send(self::taskListMessage('Open agency tasks', $tasks, 12), self::telegramOptions('Open tasks', self::tasksUrl()));
                break;
            case 'today':
                $today = current_time('Y-m-d');
                $tasks = array_values(array_filter(Task::getAll(), static fn(array $task): bool => ($task['status'] ?? '') !== 'done' && ($task['due_date'] ?? '') === $today));
                self::sortTasks($tasks);
                $notifier->send(self::taskListMessage('Tasks due today', $tasks, 12), self::telegramOptions('Open tasks', self::tasksUrl()));
                break;
            case 'overdue':
                $notifier->send(self::taskListMessage('Overdue agency tasks', Task::getOverdue(), 12), self::telegramOptions('Open tasks', self::tasksUrl()));
                break;
            case 'ads':
                $notifier->send(self::adsCommandMessage(), self::telegramOptions('Open portal', admin_url('admin.php?page=wnq-client-portal-dashboard')));
                break;
            case 'requests':
                $notifier->send(self::requestsCommandMessage(), self::telegramOptions('Open portal', admin_url('admin.php?page=wnq-client-portal-dashboard')));
                break;
            case 'status':
                $notifier->send(self::statusCommandMessage(), self::telegramOptions('Open settings', admin_url('admin.php?page=wnq-portal')));
                break;
            case 'start':
            case 'help':
            default:
                $notifier->send(self::helpCommandMessage(), self::telegramOptions('Open tasks', self::tasksUrl()));
                break;
        }
    }

    private static function adsCommandMessage(): string
    {
        $rows = [];
        $total = 0.0;
        foreach (Client::getAll() as $client) {
            $client_id = sanitize_text_field((string)($client['client_id'] ?? ''));
            if ($client_id === '' || empty(ClientPortal::getAdsPublicStatus($client_id)['has_linked_account'])) {
                continue;
            }
            $ads = ClientPortal::getAdsResource($client_id, true, false);
            if (empty($ads['configured'])) {
                $rows[] = '<b>' . self::html(self::clientName($client_id)) . '</b>: connection needs attention';
                continue;
            }
            $spend = (float)($ads['summary']['spend'] ?? 0);
            $total += $spend;
            $rows[] = '<b>' . self::html(self::clientName($client_id)) . '</b>: ' . self::html(self::money($spend));
        }
        if ($rows === []) {
            return '<b>Google Ads spend</b>' . "\n" . 'No client Ads accounts are currently linked.';
        }
        return '<b>Google Ads spend</b>' . "\n"
            . '<b>Window:</b> Last 30 days' . "\n"
            . '<b>Total:</b> ' . self::html(self::money($total)) . "\n\n"
            . implode("\n", array_slice($rows, 0, 20));
    }

    private static function requestsCommandMessage(): string
    {
        global $wpdb;
        $statuses = ['submitted', 'reviewing', 'scheduled', 'in_progress'];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.title,r.priority,r.status,r.client_id,COALESCE(c.company,c.name,r.client_id) client_name
             FROM {$wpdb->prefix}wnq_portal_requests r
             LEFT JOIN {$wpdb->prefix}wnq_clients c ON c.client_id=r.client_id
             WHERE r.status IN ($placeholders)
             ORDER BY FIELD(r.priority,'urgent','high','normal','low'),r.created_at ASC LIMIT 12",
            $statuses
        ), ARRAY_A) ?: [];
        if ($rows === []) {
            return '<b>Open client requests</b>' . "\n" . 'No open requests.';
        }
        $lines = ['<b>Open client requests</b>', '<b>Total:</b> ' . ClientPortal::getOpenRequestCount(), ''];
        foreach ($rows as $index => $row) {
            $lines[] = ($index + 1) . '. <b>' . self::html(self::clean((string)$row['title'])) . '</b>';
            $lines[] = '   ' . self::html(self::clean((string)$row['client_name'])) . ' · ' . self::html(self::label((string)$row['priority']));
        }
        return implode("\n", $lines);
    }

    private static function statusCommandMessage(): string
    {
        $last_check = (string)get_option('wnq_telegram_last_check_at', '');
        $last_alert = (string)get_option('wnq_telegram_last_sent_at', '');
        $open_tasks = count(array_filter(Task::getAll(), static fn(array $task): bool => ($task['status'] ?? '') !== 'done'));
        return self::htmlMessage('Notification system status', [
            'Telegram' => (bool)get_option('wnq_telegram_enabled', false) ? 'Enabled' : 'Disabled',
            'Open tasks' => (string)$open_tasks,
            'Overdue tasks' => (string)count(Task::getOverdue()),
            'Open client requests' => (string)ClientPortal::getOpenRequestCount(),
            'Last scheduled check' => $last_check !== '' ? $last_check : 'Never',
            'Last alert' => $last_alert !== '' ? $last_alert : 'Never',
        ]);
    }

    private static function helpCommandMessage(): string
    {
        $lines = ['<b>Golden Web Marketing bot commands</b>', ''];
        foreach (self::botCommands() as $command => $description) {
            $lines[] = '<code>/' . self::html($command) . '</code> - ' . self::html($description);
        }
        $lines[] = '';
        $lines[] = 'Commands are read-only and only work in the connected internal group.';
        return implode("\n", $lines);
    }

    private static function taskListMessage(string $title, array $tasks, int $limit): string
    {
        if ($tasks === []) {
            return '<b>' . self::html($title) . '</b>' . "\n" . 'No matching tasks.';
        }
        $lines = ['<b>' . self::html($title) . '</b>', '<b>Total:</b> ' . count($tasks), ''];
        foreach (array_slice($tasks, 0, max(1, $limit)) as $index => $task) {
            $lines[] = ($index + 1) . '. <b>' . self::html(self::clean((string)($task['title'] ?? 'Untitled task'))) . '</b>';
            $parts = [self::formatDate((string)($task['due_date'] ?? '')), self::label((string)($task['priority'] ?? 'medium'))];
            if (!empty($task['client_id'])) {
                $parts[] = self::clientName((string)$task['client_id']);
            }
            $lines[] = '   ' . self::html(implode(' · ', $parts));
        }
        if (count($tasks) > $limit) {
            $lines[] = '';
            $lines[] = '+' . (count($tasks) - $limit) . ' more in WordPress';
        }
        return implode("\n", $lines);
    }

    private static function sortTasks(array &$tasks): void
    {
        $priority = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'normal' => 2, 'low' => 3];
        usort($tasks, static function (array $left, array $right) use ($priority): int {
            $left_due = (string)($left['due_date'] ?? '9999-12-31');
            $right_due = (string)($right['due_date'] ?? '9999-12-31');
            return [$left_due, $priority[$left['priority'] ?? 'medium'] ?? 2]
                <=> [$right_due, $priority[$right['priority'] ?? 'medium'] ?? 2];
        });
    }

    private static function createPortalTask(string $title, string $client_id, string $priority, string $description, string $source): void
    {
        $priority = sanitize_key($priority);
        if (!in_array($priority, ['low', 'normal', 'medium', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        $days = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'medium' => 2, 'low' => 5][$priority];
        Task::create([
            'title' => $title,
            'description' => $description,
            'status' => 'todo',
            'task_type' => 'client',
            'priority' => $priority,
            'client_id' => $client_id,
            'due_date' => current_datetime()->modify('+' . $days . ' days')->format('Y-m-d'),
            'notes' => '[portal-auto] ' . sanitize_text_field($source),
        ]);
    }

    private static function primeCommandOffset(TelegramNotifier $notifier): void
    {
        if ((int)get_option(self::COMMAND_OFFSET_OPTION, 0) > 0) {
            return;
        }
        $result = $notifier->getUpdates(0);
        $max = 0;
        foreach ((array)($result['updates'] ?? []) as $update) {
            $max = max($max, absint($update['update_id'] ?? 0));
        }
        if ($max > 0) {
            update_option(self::COMMAND_OFFSET_OPTION, $max + 1, false);
        }
    }

    private static function eventEnabled(string $event): bool
    {
        $stored = get_option('wnq_telegram_events', []);
        $settings = array_merge(self::eventDefaults(), is_array($stored) ? $stored : []);
        return !empty($settings[$event]);
    }

    private static function htmlMessage(string $title, array $fields): string
    {
        $lines = ['<b>' . self::html($title) . '</b>'];
        foreach ($fields as $label => $value) {
            $lines[] = '<b>' . self::html((string)$label) . ':</b> ' . self::html((string)$value);
        }
        return implode("\n", $lines);
    }

    private static function telegramOptions(string $button_text, string $button_url): array
    {
        return [
            'html' => true,
            'buttons' => [['text' => $button_text, 'url' => $button_url]],
        ];
    }

    private static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function tasksUrl(): string
    {
        return admin_url('admin.php?page=wnq-tasks');
    }

    private static function formatDate(string $date): string
    {
        if ($date === '') {
            return 'No due date';
        }
        $timestamp = strtotime($date);
        return $timestamp ? wp_date('M j, Y', $timestamp) : self::clean($date);
    }

    private static function clientName(string $client_id): string
    {
        $client = Client::getByClientId($client_id) ?: [];
        return self::clean((string)(($client['company'] ?? '') ?: ($client['name'] ?? '') ?: $client_id));
    }

    private static function adminUrl(string $client_id): string
    {
        return admin_url('admin.php?page=wnq-client-portal-dashboard&client_id=' . rawurlencode($client_id));
    }

    private static function clean(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($value)) ?? '');
        return $value !== '' ? $value : 'Not provided';
    }

    private static function clip(string $value, int $length): string
    {
        $value = self::clean($value);
        return function_exists('mb_strlen') && mb_strlen($value) > $length
            ? mb_substr($value, 0, $length - 3) . '...'
            : (strlen($value) > $length ? substr($value, 0, $length - 3) . '...' : $value);
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace(['_', '.'], ' ', sanitize_text_field($value)));
    }

    private static function money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    private static function validStripeSignature(string $payload, string $header, string $secret): bool
    {
        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = absint($value);
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($timestamp < 1 || abs(time() - $timestamp) > 300 || !$signatures) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }

    private static function resolveStripeClientId(array $object): string
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        foreach (['wnq_client_id', 'client_id'] as $key) {
            $candidate = sanitize_text_field((string)($metadata[$key] ?? ''));
            if ($candidate !== '' && Client::getByClientId($candidate)) {
                return $candidate;
            }
        }
        $reference = sanitize_text_field((string)($object['client_reference_id'] ?? ''));
        if ($reference !== '' && Client::getByClientId($reference)) {
            return $reference;
        }
        $email = sanitize_email((string)($object['customer_details']['email'] ?? $object['customer_email'] ?? $object['receipt_email'] ?? ''));
        if ($email !== '') {
            foreach (Client::getAll() as $client) {
                if (strtolower((string)($client['email'] ?? '')) === strtolower($email) || strtolower((string)($client['billing_email'] ?? '')) === strtolower($email)) {
                    return sanitize_text_field((string)$client['client_id']);
                }
            }
        }
        return '';
    }

    private static function stripeAmount(array $object): float
    {
        foreach (['amount_paid', 'amount_received', 'amount_total', 'amount_due'] as $key) {
            if (isset($object[$key]) && is_numeric($object[$key])) {
                return round(((float)$object[$key]) / 100, 2);
            }
        }
        return 0.0;
    }
}
