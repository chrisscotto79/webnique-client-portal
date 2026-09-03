<?php
/** Privacy-safe GA4 lead-quality evidence for PPC Intelligence. */
namespace WNQ\Services;
use WNQ\API\GoogleAnalytics;
if (!defined('ABSPATH')) exit;
final class PpcLeadQualityService
{
    private const LEVELS = [
        'engagement'      => 'Engagement',
        'raw_lead'        => 'Raw lead',
        'qualified_lead'  => 'Qualified lead',
        'booked_work'     => 'Booked work',
        'revenue_outcome' => 'Known revenue outcome',
    ];

    public static function config(string $client_id): array
    {
        $stored = get_option('wnq_ppc_quality_events_' . md5($client_id), []);
        $defaults = [
            'engagement'      => ['phone_click', 'email_click', 'contact_page_visit'],
            'raw_lead'        => ['generate_lead'],
            'qualified_lead'  => [],
            'booked_work'     => [],
            'revenue_outcome' => ['purchase'],
        ];
        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    public static function saveConfig(string $client_id, array $input): bool
    {
        $data = [];
        foreach (self::LEVELS as $key => $label) {
            $items = preg_split('/[\r\n,]+/', (string)($input[$key] ?? '')) ?: [];
            $data[$key] = array_values(array_unique(array_filter(array_map(
                static fn($value): string => self::normalizeEventName((string)$value),
                $items
            ))));
        }
        $option = 'wnq_ppc_quality_events_' . md5($client_id);
        return update_option($option, $data, false) || get_option($option) === $data;
    }

    public function report(string $client_id, string $google_ads_customer_id): array
    {
        $google_ads_customer_id = preg_replace('/\D+/', '', $google_ads_customer_id) ?: '';
        $config = self::config($client_id);
        $names = [];
        $lookup = [];
        foreach ($config as $level => $events) {
            foreach ($events as $event) {
                $names[] = $event;
                $lookup[$event] = $level;
            }
        }
        if (!$names) {
            return ['available' => false, 'status' => 'unavailable', 'message' => 'Configure at least one GA4 event for a lead-quality level.', 'levels' => [], 'rows' => [], 'config' => $config];
        }
        if ($google_ads_customer_id === '') {
            return ['available' => false, 'status' => 'unavailable', 'message' => 'Link this client to an exact Google Ads account before loading paid lead-quality evidence.', 'levels' => [], 'rows' => [], 'config' => $config];
        }

        try {
            $ga = new GoogleAnalytics($client_id);
            $raw = $ga->getLeadQualityRows($names, $google_ads_customer_id);
            if ($ga->getErrors()) {
                return ['available' => false, 'status' => 'unavailable', 'message' => 'GA4 lead-quality evidence could not be loaded.', 'levels' => [], 'rows' => [], 'config' => $config];
            }
        } catch (\Throwable $error) {
            return ['available' => false, 'status' => 'unavailable', 'message' => 'GA4 is not configured for this client.', 'levels' => [], 'rows' => [], 'config' => $config];
        }

        $rows = [];
        $levels = [];
        foreach (self::LEVELS as $key => $label) {
            $levels[$key] = ['label' => $label, 'configured' => !empty($config[$key]), 'count' => 0];
        }
        foreach ($raw as $row) {
            if (!self::matchesCustomerId((string)($row['google_ads_customer_id'] ?? ''), $google_ads_customer_id)) {
                continue;
            }
            $level = $lookup[$row['event_name']] ?? '';
            if ($level === '') {
                continue;
            }
            $row['quality_level'] = $level;
            $levels[$level]['count'] += (int)$row['count'];
            $rows[] = $row;
        }

        return [
            'available' => true,
            'status'    => 'ready',
            'message'   => 'GA4 event totals are aggregated and contain no person-level identifiers.',
            'levels'    => $levels,
            'rows'      => $rows,
            'config'    => $config,
            'period'    => 'Last 30 Days',
            'scope'     => 'GA4 events attributed to the exact linked Google Ads customer',
        ];
    }

    public static function matchesCustomerId(string $reported_customer_id, string $linked_customer_id): bool
    {
        $reported = preg_replace('/\D+/', '', $reported_customer_id) ?: '';
        $linked = preg_replace('/\D+/', '', $linked_customer_id) ?: '';
        return $reported !== '' && $linked !== '' && hash_equals($linked, $reported);
    }

    public static function normalizeEventName(string $event_name): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_]/', '', trim($event_name)) ?: '', 0, 40);
    }

    public static function labels(): array
    {
        return self::LEVELS;
    }
}
