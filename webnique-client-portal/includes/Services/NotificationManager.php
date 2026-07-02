<?php
/**
 * Coordinates internal Telegram alerts and scheduled operational checks.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

use WNQ\Models\Client;
use WNQ\Models\ClientPortal;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationManager
{
    private const CRON_HOOK = 'wnq_portal_notification_checks';
    private const CRON_LOCK = 'wnq_portal_notification_check_lock';
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('wnq_portal_crm_record_saved', [self::class, 'onCrmRecordSaved'], 10, 4);
        add_action('wnq_portal_lead_converted', [self::class, 'onLeadConverted'], 10, 3);
        add_action('wnq_portal_message_created', [self::class, 'onMessageCreated'], 10, 5);
        add_action('wnq_portal_request_created', [self::class, 'onRequestCreated'], 10, 3);
        add_action('wnq_portal_learning_request_created', [self::class, 'onLearningRequestCreated'], 10, 3);
        add_action('wnq_portal_payment_recorded', [self::class, 'onPaymentRecorded'], 10, 5);
        add_action(self::CRON_HOOK, [self::class, 'runScheduledChecks']);
        add_action('rest_api_init', [self::class, 'registerRoutes']);

        self::syncSchedule();
    }

    public static function eventDefaults(): array
    {
        return [
            'crm_records' => true,
            'lead_conversions' => true,
            'support_messages' => true,
            'client_requests' => true,
            'learning_requests' => true,
            'payments' => true,
            'ads_spend' => true,
            'ads_connection' => true,
            'overdue_followups' => true,
        ];
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
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
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

    public static function onCrmRecordSaved(int $record_id, string $client_id, array $record, bool $is_update): void
    {
        if ($is_update) {
            return;
        }
        $type = ($record['record_type'] ?? 'lead') === 'job' ? 'job' : 'lead';
        $lines = [
            'NEW ' . strtoupper($type),
            'Client: ' . self::clientName($client_id),
            'Contact: ' . self::clean((string)($record['name'] ?? 'Not provided')),
        ];
        if (!empty($record['service'])) {
            $lines[] = 'Service: ' . self::clean((string)$record['service']);
        }
        if (!empty($record['lead_source'])) {
            $lines[] = 'Source: ' . self::clean((string)$record['lead_source']);
        }
        if ($type === 'job' && (float)($record['estimated_value'] ?? 0) > 0) {
            $lines[] = 'Estimated value: ' . self::money((float)$record['estimated_value']);
        }
        $lines[] = 'Open portal: ' . self::adminUrl($client_id);

        (new TelegramNotifier())->notify('crm_records', implode("\n", $lines), 'crm:' . $record_id, 30 * DAY_IN_SECONDS);
    }

    public static function onLeadConverted(int $record_id, string $client_id, array $record): void
    {
        $message = implode("\n", [
            'LEAD CONVERTED TO JOB',
            'Client: ' . self::clientName($client_id),
            'Contact: ' . self::clean((string)($record['name'] ?? 'Not provided')),
            'Service: ' . self::clean((string)($record['service'] ?? 'Not provided')),
            'Open portal: ' . self::adminUrl($client_id),
        ]);
        (new TelegramNotifier())->notify('lead_conversions', $message, 'lead-converted:' . $record_id, 30 * DAY_IN_SECONDS);
    }

    public static function onMessageCreated(int $message_id, string $client_id, string $sender_role, array $data, string $ticket_key): void
    {
        if ($sender_role !== 'client') {
            return;
        }
        $message = implode("\n", [
            'NEW CLIENT MESSAGE',
            'Client: ' . self::clientName($client_id),
            'Subject: ' . self::clean((string)($data['subject'] ?? 'Support ticket')),
            'Priority: ' . self::label((string)($data['priority'] ?? 'normal')),
            'Message: ' . self::clip((string)($data['message'] ?? ''), 180),
            'Open portal: ' . self::adminUrl($client_id),
        ]);
        (new TelegramNotifier())->notify('support_messages', $message, 'message:' . $message_id, 30 * DAY_IN_SECONDS);
    }

    public static function onRequestCreated(int $request_id, string $client_id, string $request_key): void
    {
        global $wpdb;
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT request_type,title,priority,details FROM {$wpdb->prefix}wnq_portal_requests WHERE id=%d AND client_id=%s",
            $request_id,
            $client_id
        ), ARRAY_A) ?: [];
        $message = implode("\n", [
            'NEW CLIENT REQUEST',
            'Client: ' . self::clientName($client_id),
            'Type: ' . self::label((string)($request['request_type'] ?? 'request')),
            'Title: ' . self::clean((string)($request['title'] ?? $request_key)),
            'Priority: ' . self::label((string)($request['priority'] ?? 'normal')),
            'Details: ' . self::clip((string)($request['details'] ?? ''), 180),
            'Open portal: ' . self::adminUrl($client_id),
        ]);
        (new TelegramNotifier())->notify('client_requests', $message, 'request:' . $request_id, 30 * DAY_IN_SECONDS);
    }

    public static function onLearningRequestCreated(int $request_id, string $client_id, array $request): void
    {
        $message = implode("\n", [
            'NEW LEARNING REQUEST',
            'Client: ' . self::clientName($client_id),
            'Type: ' . self::label((string)($request['request_type'] ?? 'topic')),
            'Title: ' . self::clean((string)($request['title'] ?? 'Learning request')),
            'Details: ' . self::clip((string)($request['details'] ?? ''), 180),
            'Open portal: ' . self::adminUrl($client_id),
        ]);
        (new TelegramNotifier())->notify('learning_requests', $message, 'learning:' . $request_id, 30 * DAY_IN_SECONDS);
    }

    public static function onPaymentRecorded(string $client_id, float $amount, string $source, string $payment_id, string $status = 'paid'): void
    {
        $heading = in_array($status, ['failed', 'past_due'], true) ? 'PAYMENT NEEDS ATTENTION' : 'PAYMENT RECEIVED';
        $lines = [
            $heading,
            'Client: ' . ($client_id !== '' ? self::clientName($client_id) : 'Unmatched Stripe customer'),
            'Amount: ' . self::money($amount),
            'Source: ' . self::clean($source),
            'Status: ' . self::label($status),
        ];
        if ($client_id !== '') {
            $lines[] = 'Open portal: ' . self::adminUrl($client_id);
        }
        (new TelegramNotifier())->notify('payments', implode("\n", $lines), 'payment:' . sanitize_key($status . '-' . $payment_id), 90 * DAY_IN_SECONDS);
    }

    public static function runScheduledChecks(bool $force = false): array
    {
        if (!(bool)get_option('wnq_telegram_enabled', false)) {
            return ['ok' => false, 'message' => 'Telegram notifications are disabled.', 'ads_alerts' => 0, 'followup_alerts' => 0];
        }
        if (!$force && get_transient(self::CRON_LOCK)) {
            return ['ok' => true, 'message' => 'Notification checks are already running.', 'ads_alerts' => 0, 'followup_alerts' => 0];
        }
        set_transient(self::CRON_LOCK, 1, 15 * MINUTE_IN_SECONDS);

        try {
            $ads_alerts = self::checkAdsAccounts();
            $followup_alerts = self::checkOverdueFollowups();
        } finally {
            delete_transient(self::CRON_LOCK);
        }

        update_option('wnq_telegram_last_check_at', current_time('mysql'), false);
        return [
            'ok' => true,
            'message' => sprintf('Checks completed. %d Ads alert%s and %d follow-up alert%s sent.', $ads_alerts, $ads_alerts === 1 ? '' : 's', $followup_alerts, $followup_alerts === 1 ? '' : 's'),
            'ads_alerts' => $ads_alerts,
            'followup_alerts' => $followup_alerts,
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
                    "GOOGLE ADS CONNECTION NEEDS ATTENTION\nClient: " . self::clientName($client_id) . "\nIssue: " . self::clip(implode(' ', $errors), 220) . "\nOpen portal: " . self::adminUrl($client_id),
                    'ads-error:' . md5($client_id . implode('|', $errors)),
                    DAY_IN_SECONDS
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
                    "GOOGLE ADS SPEND THRESHOLD\nClient: " . self::clientName($client_id) . "\nLast 30 days: " . self::money($spend) . "\nAlert threshold: " . self::money($threshold) . "\nOpen portal: " . self::adminUrl($client_id),
                    'ads-threshold:' . md5($client_id . '|' . $threshold . '|' . current_time('Y-m-d')),
                    DAY_IN_SECONDS
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

    private static function checkOverdueFollowups(): int
    {
        if (!self::eventEnabled('overdue_followups')) {
            return 0;
        }
        $today = current_time('Y-m-d');
        $total = 0;
        $client_lines = [];
        foreach (Client::getAll() as $client) {
            $client_id = sanitize_text_field((string)($client['client_id'] ?? ''));
            if ($client_id === '') {
                continue;
            }
            $count = 0;
            foreach (ClientPortal::getCustomers($client_id, 500, false) as $record) {
                if (in_array((string)($record['status'] ?? ''), ['completed', 'lost', 'canceled'], true)) {
                    continue;
                }
                $due = array_filter([(string)($record['follow_up_date'] ?? ''), (string)($record['reminder_date'] ?? '')]);
                if ($due && min($due) < $today) {
                    $count++;
                }
            }
            if ($count > 0) {
                $total += $count;
                $client_lines[] = self::clientName($client_id) . ': ' . $count;
            }
        }
        if ($total < 1) {
            return 0;
        }
        $message = "OVERDUE FOLLOW-UPS\nTotal overdue: {$total}\n" . implode("\n", array_slice($client_lines, 0, 12)) . "\nOpen portal: " . admin_url('admin.php?page=wnq-client-portal-dashboard');
        $result = (new TelegramNotifier())->notify('overdue_followups', $message, 'overdue-followups:' . $today, 2 * DAY_IN_SECONDS);
        return !empty($result['ok']) ? 1 : 0;
    }

    private static function eventEnabled(string $event): bool
    {
        $stored = get_option('wnq_telegram_events', []);
        $settings = array_merge(self::eventDefaults(), is_array($stored) ? $stored : []);
        return !empty($settings[$event]);
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
