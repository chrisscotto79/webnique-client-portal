<?php
/**
 * Read-only Google Ads diagnostics for the internal PPC workspace.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcDiagnosticService
{
    private array $credentials;

    public function __construct(?array $credentials = null)
    {
        $this->credentials = $credentials ?? GoogleAdsCredentials::get();
    }

    public function dashboard(string $customer_id, bool $refresh = false): array
    {
        $customer_id = preg_replace('/\D+/', '', $customer_id) ?: '';
        if (strlen($customer_id) !== 10 || !GoogleAdsCredentials::isConfigured($this->credentials)) {
            return $this->unavailableDashboard('Connect and test this client’s Google Ads account to load PPC diagnostics.');
        }

        $dashboard = [
            'account_diagnostic' => $this->module($customer_id, 'account', $refresh, fn() => $this->accountDiagnostic($customer_id)),
            'conversion_health'  => $this->module($customer_id, 'conversions', $refresh, fn() => $this->conversionHealth($customer_id)),
            'change_history'     => $this->module($customer_id, 'changes', $refresh, fn() => $this->changeHistory($customer_id)),
            'impression_share'   => $this->module($customer_id, 'impression-share', $refresh, fn() => $this->impressionShare($customer_id)),
            'budget_analysis'    => $this->module($customer_id, 'budgets', $refresh, fn() => $this->budgetAnalysis($customer_id)),
            'generated_at'       => current_time('mysql'),
        ];
        $dashboard['findings'] = self::prioritize($dashboard);
        return $dashboard;
    }

    public static function prioritize(array $dashboard): array
    {
        $findings = [];
        $conversions = (array)($dashboard['conversion_health'] ?? []);
        if (!empty($conversions['available'])) {
            $active_primary = array_filter((array)($conversions['actions'] ?? []), static fn(array $action): bool => !empty($action['primary']) && !empty($action['included_in_conversions']) && ($action['status'] ?? '') === 'enabled');
            if (!$active_primary) $findings[] = self::finding('critical', 'No active primary conversions', 'Google Ads returned zero enabled primary conversion actions included in Conversions.', 'Current configuration', 'Review conversion goals before optimizing campaigns.', 0.98);
            $stale = (int)($conversions['counts']['stale'] ?? 0);
            if ($stale > 0) $findings[] = self::finding('warning', 'Stale conversion actions', "{$stale} conversion action(s) had prior activity but none during the last 30 days.", 'Last 90 days', 'Confirm whether these actions are still expected to receive leads.', 0.9);
        }
        $budgets = (array)($dashboard['budget_analysis'] ?? []);
        if (!empty($budgets['available'])) {
            foreach ((array)($budgets['campaigns'] ?? []) as $campaign) {
                if (($campaign['status'] ?? '') !== 'enabled') continue;
                if (($campaign['pace_status'] ?? '') !== 'on_track') $findings[] = self::finding('warning', 'Campaign budget pacing is significantly off', (string)$campaign['name'] . ' is projected at ' . round((float)$campaign['pace'] * 100) . '% of its monthly budget capacity.', 'Current month', (string)$campaign['recommendation'], 0.86, (string)$campaign['id']);
            }
        }
        $share = (array)($dashboard['impression_share'] ?? []);
        if (!empty($share['available'])) {
            foreach ((array)($share['campaigns'] ?? []) as $campaign) {
                if (($campaign['status'] ?? '') !== 'enabled') continue;
                $lost_budget = (float)($campaign['lost_budget'] ?? 0);
                $lost_rank = (float)($campaign['lost_rank'] ?? 0);
                if (max($lost_budget, $lost_rank) >= 0.25) $findings[] = self::finding('warning', 'High lost search impression share', (string)$campaign['name'] . ' lost ' . round(max($lost_budget, $lost_rank) * 100, 1) . '% to ' . ($lost_budget >= $lost_rank ? 'budget' : 'rank') . '.', 'Last 30 days', 'Investigate performance, demand, rank, and budget before making changes.', 0.9, (string)$campaign['id']);
                elseif ($lost_budget >= 0.15) $findings[] = self::finding('opportunity', 'Potential campaign growth opportunity', (string)$campaign['name'] . ' lost ' . round($lost_budget * 100, 1) . '% search impression share to budget.', 'Last 30 days', 'Review CPA and lead quality before considering a conservative staged increase.', 0.75, (string)$campaign['id']);
            }
        }
        $available = array_filter(['account_diagnostic', 'conversion_health', 'change_history', 'impression_share', 'budget_analysis'], static fn(string $key): bool => !empty($dashboard[$key]['available']));
        if (!$findings && !$available) $findings[] = self::finding('warning', 'Diagnostics unavailable', 'Google Ads did not return any Phase 2 diagnostic modules.', 'Current refresh', 'Test the linked account and review the individual module errors.', 1.0);
        elseif (!$findings) $findings[] = self::finding('healthy', 'No urgent PPC issues detected', 'The available Phase 2 checks did not identify a critical issue, warning, or clear opportunity.', 'Current diagnostic windows', 'Continue monitoring; healthy does not guarantee every account setting is optimal.', 0.8);
        $order = ['critical' => 0, 'warning' => 1, 'opportunity' => 2, 'healthy' => 3];
        usort($findings, static fn(array $a, array $b): int => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));
        return $findings;
    }

    private static function finding(string $severity, string $title, string $evidence, string $period, string $action, float $confidence, string $campaign_id = ''): array
    {
        return compact('severity', 'title', 'evidence', 'period', 'action', 'confidence', 'campaign_id');
    }

    private function module(string $customer_id, string $name, bool $refresh, callable $loader): array
    {
        $cache_key = 'wnq_ppc_v2_' . sanitize_key($name) . '_' . md5($customer_id);
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $result = $loader();
        } catch (\Throwable $error) {
            $result = $this->unavailable('Google Ads could not load this diagnostic right now.');
        }
        if (!empty($result['available'])) {
            set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        }
        return $result;
    }

    private function accountDiagnostic(string $customer_id): array
    {
        $dates = $this->dates();
        $daily = $this->select($customer_id, "SELECT segments.date, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value FROM customer WHERE segments.date BETWEEN '{$dates['previous_month_start']}' AND '{$dates['today']}' ORDER BY segments.date DESC");
        if (!$daily['ok']) {
            return $this->unavailable($daily['error']);
        }
        $campaign_result = $this->select($customer_id, "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign_budget.amount_micros, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '{$dates['last_30_start']}' AND '{$dates['today']}' ORDER BY metrics.cost_micros DESC LIMIT 200");

        $periods = [
            'today' => $this->emptyMetrics(),
            'last_7_days' => $this->emptyMetrics(),
            'last_30_days' => $this->emptyMetrics(),
            'current_month' => $this->emptyMetrics(),
            'previous_month' => $this->emptyMetrics(),
        ];
        foreach ($daily['rows'] as $row) {
            $date = (string)($row['segments']['date'] ?? '');
            $metrics = $this->metrics((array)($row['metrics'] ?? []));
            if ($date === $dates['today']) $periods['today'] = $this->addMetrics($periods['today'], $metrics);
            if ($date >= $dates['last_7_start']) $periods['last_7_days'] = $this->addMetrics($periods['last_7_days'], $metrics);
            if ($date >= $dates['last_30_start']) $periods['last_30_days'] = $this->addMetrics($periods['last_30_days'], $metrics);
            if ($date >= $dates['current_month_start']) $periods['current_month'] = $this->addMetrics($periods['current_month'], $metrics);
            if ($date >= $dates['previous_month_start'] && $date <= $dates['previous_month_end']) $periods['previous_month'] = $this->addMetrics($periods['previous_month'], $metrics);
        }
        foreach ($periods as &$period) $period = $this->finishMetrics($period);
        unset($period);

        $campaigns = [];
        $enabled = 0;
        $paused = 0;
        if ($campaign_result['ok']) {
            foreach ($campaign_result['rows'] as $row) {
                $campaign = (array)($row['campaign'] ?? []);
                $budget = (array)($row['campaignBudget'] ?? []);
                $status = strtolower(sanitize_key((string)($campaign['status'] ?? 'unknown')));
                if ($status === 'enabled') $enabled++;
                if ($status === 'paused') $paused++;
                $campaigns[] = array_merge([
                    'id' => sanitize_text_field((string)($campaign['id'] ?? '')),
                    'name' => sanitize_text_field((string)($campaign['name'] ?? 'Campaign')),
                    'status' => $status,
                    'type' => strtolower(sanitize_key((string)($campaign['advertisingChannelType'] ?? 'unknown'))),
                    'bidding_strategy' => strtolower(sanitize_key((string)($campaign['biddingStrategyType'] ?? 'unknown'))),
                    'daily_budget' => ((float)($budget['amountMicros'] ?? 0)) / 1000000,
                ], $this->finishMetrics($this->metrics((array)($row['metrics'] ?? []))));
            }
        }

        return [
            'available' => true,
            'status' => $campaign_result['ok'] ? 'ready' : 'partial',
            'message' => $campaign_result['ok'] ? '' : $campaign_result['error'],
            'periods' => $periods,
            'campaign_counts' => ['enabled' => $enabled, 'paused' => $paused, 'total' => count($campaigns)],
            'campaigns' => $campaigns,
        ];
    }

    private function conversionHealth(string $customer_id): array
    {
        $dates = $this->dates();
        $actions_result = $this->select($customer_id, "SELECT conversion_action.resource_name, conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal, conversion_action.include_in_conversions_metric, conversion_action.attribution_model_settings.attribution_model, conversion_action.counting_type FROM conversion_action WHERE conversion_action.status != 'REMOVED' ORDER BY conversion_action.name");
        if (!$actions_result['ok']) {
            return $this->unavailable($actions_result['error']);
        }

        $stats_result = $this->select($customer_id, "SELECT segments.conversion_action, segments.conversion_action_name, segments.date, segments.device, segments.hour, metrics.conversions, metrics.all_conversions, metrics.conversions_by_conversion_date, metrics.all_conversions_by_conversion_date FROM customer WHERE segments.date BETWEEN '{$dates['last_90_start']}' AND '{$dates['today']}' AND metrics.all_conversions > 0 ORDER BY segments.date DESC LIMIT 10000");
        $campaign_result = $this->select($customer_id, "SELECT campaign.name, segments.conversion_action, metrics.all_conversions FROM campaign WHERE segments.date BETWEEN '{$dates['last_30_start']}' AND '{$dates['today']}' AND metrics.all_conversions > 0 ORDER BY metrics.all_conversions DESC LIMIT 2000");
        $stats = [];
        $timeline = [];
        $devices = [];
        $hours = [];
        $associated_campaigns = [];
        $activity = [];
        if ($stats_result['ok']) {
            foreach ($stats_result['rows'] as $row) {
                $segments = (array)($row['segments'] ?? []);
                $resource = (string)($segments['conversionAction'] ?? '');
                $date = (string)($segments['date'] ?? '');
                $device = strtolower(sanitize_key((string)($segments['device'] ?? 'unknown')));
                $hour = max(0, min(23, (int)($segments['hour'] ?? 0)));
                $conversions = (float)($row['metrics']['allConversionsByConversionDate'] ?? $row['metrics']['conversionsByConversionDate'] ?? $row['metrics']['allConversions'] ?? $row['metrics']['conversions'] ?? 0);
                if (!isset($stats[$resource])) $stats[$resource] = ['last_date' => '', 'conversions_7' => 0.0, 'conversions_30' => 0.0];
                if ($date > $stats[$resource]['last_date']) $stats[$resource]['last_date'] = $date;
                $stats[$resource]['conversions_30'] += $conversions;
                if ($date >= $dates['last_7_start']) $stats[$resource]['conversions_7'] += $conversions;
                $devices[$device] = ($devices[$device] ?? 0) + $conversions;
                $hours[$hour] = ($hours[$hour] ?? 0) + $conversions;
                $timeline[$date] = ($timeline[$date] ?? 0) + $conversions;
                $activity[] = [
                    'date' => $date,
                    'hour' => $hour,
                    'device' => $device,
                    'action_name' => sanitize_text_field((string)($segments['conversionActionName'] ?? 'Conversion action')),
                    'conversions' => round($conversions, 2),
                ];
            }
        }
        if ($campaign_result['ok']) {
            foreach ($campaign_result['rows'] as $row) {
                $resource = (string)($row['segments']['conversionAction'] ?? '');
                $campaign_name = sanitize_text_field((string)($row['campaign']['name'] ?? ''));
                if ($resource !== '' && $campaign_name !== '') $associated_campaigns[$resource][$campaign_name] = true;
            }
        }

        $actions = [];
        $counts = ['healthy' => 0, 'warning' => 0, 'stale' => 0, 'no_recent_data' => 0, 'configuration_issue' => 0];
        foreach ($actions_result['rows'] as $row) {
            $action = (array)($row['conversionAction'] ?? []);
            $resource = (string)($action['resourceName'] ?? '');
            $action_stats = $stats[$resource] ?? ['last_date' => '', 'conversions_7' => 0.0, 'conversions_30' => 0.0];
            $classification = self::classifyConversion($action, $action_stats);
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
            $actions[] = [
                'id' => sanitize_text_field((string)($action['id'] ?? '')),
                'name' => sanitize_text_field((string)($action['name'] ?? 'Conversion action')),
                'type' => strtolower(sanitize_key((string)($action['type'] ?? 'unknown'))),
                'category' => strtolower(sanitize_key((string)($action['category'] ?? 'unknown'))),
                'origin' => strtolower(sanitize_key((string)($action['origin'] ?? 'unknown'))),
                'status' => strtolower(sanitize_key((string)($action['status'] ?? 'unknown'))),
                'primary' => !empty($action['primaryForGoal']),
                'included_in_conversions' => !empty($action['includeInConversionsMetric']),
                'attribution_model' => strtolower(sanitize_key((string)($action['attributionModelSettings']['attributionModel'] ?? 'unknown'))),
                'counting_type' => strtolower(sanitize_key((string)($action['countingType'] ?? 'unknown'))),
                'last_conversion_date' => $action_stats['last_date'],
                'conversions_7' => round($action_stats['conversions_7'], 2),
                'conversions_30' => round($action_stats['conversions_30'], 2),
                'classification' => $classification,
                'campaigns' => array_keys($associated_campaigns[$resource] ?? []),
            ];
        }
        ksort($timeline);
        arsort($devices);
        arsort($hours);

        return [
            'available' => true,
            'status' => $stats_result['ok'] ? 'ready' : 'partial',
            'message' => $stats_result['ok'] ? 'Conversion reporting is aggregated by Google Ads in the Ads account timezone; it does not identify individual people.' : $stats_result['error'],
            'counts' => $counts,
            'actions' => $actions,
            'timeline' => $timeline,
            'devices' => $devices,
            'hours' => $hours,
            'activity' => array_slice($activity, 0, 500),
        ];
    }

    public static function classifyConversion(array $action, array $stats): string
    {
        $status = strtolower((string)($action['status'] ?? 'unknown'));
        $primary = !empty($action['primaryForGoal']);
        $included = !empty($action['includeInConversionsMetric']);
        $recent_7 = (float)($stats['conversions_7'] ?? 0);
        $recent_30 = (float)($stats['conversions_30'] ?? 0);
        if ($status !== 'enabled' || ($primary && !$included)) return 'configuration_issue';
        if ($recent_7 > 0) return 'healthy';
        if ($recent_30 > 0) return 'warning';
        if ((string)($stats['last_date'] ?? '') !== '') return 'stale';
        return 'no_recent_data';
    }

    private function changeHistory(string $customer_id): array
    {
        $end = current_datetime();
        $start = $end->modify('-29 days');
        $from = $start->format('Y-m-d 00:00:00');
        $to = $end->format('Y-m-d 23:59:59');
        $result = $this->select($customer_id, "SELECT change_event.change_date_time, change_event.change_resource_type, change_event.change_resource_name, change_event.client_type, change_event.user_email, change_event.resource_change_operation, change_event.changed_fields, campaign.name, ad_group.name FROM change_event WHERE change_event.change_date_time >= '{$from}' AND change_event.change_date_time <= '{$to}' ORDER BY change_event.change_date_time DESC LIMIT 200");
        if (!$result['ok']) return $this->unavailable($result['error']);
        $changes = [];
        foreach ($result['rows'] as $row) {
            $change = (array)($row['changeEvent'] ?? []);
            $paths = $change['changedFields']['paths'] ?? [];
            $changes[] = [
                'date_time' => sanitize_text_field((string)($change['changeDateTime'] ?? '')),
                'resource_type' => strtolower(sanitize_key((string)($change['changeResourceType'] ?? 'unknown'))),
                'resource_name' => sanitize_text_field((string)($change['changeResourceName'] ?? '')),
                'operation' => strtolower(sanitize_key((string)($change['resourceChangeOperation'] ?? 'unknown'))),
                'client_type' => strtolower(sanitize_key((string)($change['clientType'] ?? 'unknown'))),
                'user_email' => sanitize_email((string)($change['userEmail'] ?? '')),
                'campaign' => sanitize_text_field((string)($row['campaign']['name'] ?? '')),
                'ad_group' => sanitize_text_field((string)($row['adGroup']['name'] ?? '')),
                'fields' => array_values(array_map('sanitize_text_field', is_array($paths) ? $paths : [])),
            ];
        }
        return ['available' => true, 'status' => 'ready', 'message' => '', 'changes' => $changes, 'window_days' => 30];
    }

    private function impressionShare(string $customer_id): array
    {
        $dates = $this->dates();
        $result = $this->select($customer_id, "SELECT campaign.id, campaign.name, campaign.status, metrics.impressions, metrics.search_impression_share, metrics.search_budget_lost_impression_share, metrics.search_rank_lost_impression_share FROM campaign WHERE segments.date BETWEEN '{$dates['last_30_start']}' AND '{$dates['today']}' AND campaign.status != 'REMOVED' ORDER BY metrics.impressions DESC LIMIT 200");
        if (!$result['ok']) return $this->unavailable($result['error']);
        $campaigns = [];
        foreach ($result['rows'] as $row) {
            $campaign = (array)($row['campaign'] ?? []);
            $metrics = (array)($row['metrics'] ?? []);
            $campaigns[] = [
                'id' => sanitize_text_field((string)($campaign['id'] ?? '')),
                'name' => sanitize_text_field((string)($campaign['name'] ?? 'Campaign')),
                'status' => strtolower(sanitize_key((string)($campaign['status'] ?? 'unknown'))),
                'impressions' => (int)($metrics['impressions'] ?? 0),
                'impression_share' => $this->share($metrics['searchImpressionShare'] ?? null),
                'lost_budget' => $this->share($metrics['searchBudgetLostImpressionShare'] ?? null),
                'lost_rank' => $this->share($metrics['searchRankLostImpressionShare'] ?? null),
            ];
        }
        return ['available' => true, 'status' => 'ready', 'message' => 'Search impression-share metrics are only available for eligible Search campaigns.', 'campaigns' => $campaigns];
    }

    private function budgetAnalysis(string $customer_id): array
    {
        $dates = $this->dates();
        $result = $this->select($customer_id, "SELECT campaign.id, campaign.name, campaign.status, campaign_budget.amount_micros, campaign_budget.delivery_method, campaign_budget.explicitly_shared, metrics.cost_micros, metrics.conversions, metrics.clicks FROM campaign WHERE segments.date BETWEEN '{$dates['current_month_start']}' AND '{$dates['today']}' AND campaign.status != 'REMOVED' ORDER BY metrics.cost_micros DESC LIMIT 200");
        if (!$result['ok']) return $this->unavailable($result['error']);
        $elapsed = max(1, (int)current_datetime()->format('j'));
        $days_in_month = (int)current_datetime()->format('t');
        $campaigns = [];
        foreach ($result['rows'] as $row) {
            $campaign = (array)($row['campaign'] ?? []);
            $budget = (array)($row['campaignBudget'] ?? []);
            $metrics = (array)($row['metrics'] ?? []);
            $daily_budget = ((float)($budget['amountMicros'] ?? 0)) / 1000000;
            $spend = ((float)($metrics['costMicros'] ?? 0)) / 1000000;
            $monthly_capacity = $daily_budget * 30.4;
            $projected = ($spend / $elapsed) * $days_in_month;
            $pace = $monthly_capacity > 0 ? $projected / $monthly_capacity : 0;
            $pace_status = $pace > 1.1 ? 'over' : ($pace < 0.75 ? 'under' : 'on_track');
            $campaigns[] = [
                'id' => sanitize_text_field((string)($campaign['id'] ?? '')),
                'name' => sanitize_text_field((string)($campaign['name'] ?? 'Campaign')),
                'status' => strtolower(sanitize_key((string)($campaign['status'] ?? 'unknown'))),
                'daily_budget' => round($daily_budget, 2),
                'monthly_capacity' => round($monthly_capacity, 2),
                'month_spend' => round($spend, 2),
                'projected_spend' => round($projected, 2),
                'pace' => round($pace, 4),
                'pace_status' => $pace_status,
                'recommendation' => $pace_status === 'over'
                    ? 'Projected spend is above current budget capacity. Verify pacing and shared-budget allocation.'
                    : ($pace_status === 'under'
                        ? 'Pacing is below budget capacity. Investigate demand, eligibility, rank, and targeting before changing budget.'
                        : 'Spend is pacing near the current budget capacity.'),
                'conversions' => round((float)($metrics['conversions'] ?? 0), 2),
                'clicks' => (int)($metrics['clicks'] ?? 0),
                'delivery_method' => strtolower(sanitize_key((string)($budget['deliveryMethod'] ?? 'unknown'))),
                'shared_budget' => !empty($budget['explicitlyShared']),
            ];
        }
        return ['available' => true, 'status' => 'ready', 'message' => 'Monthly projections use current-month spend pace and do not change Google Ads budgets.', 'campaigns' => $campaigns, 'days_elapsed' => $elapsed, 'days_in_month' => $days_in_month];
    }

    private function select(string $customer_id, string $gaql): array
    {
        $query = new GoogleAdsQueryService($this->credentials);
        $rows = $query->select($customer_id, $gaql);
        $errors = $query->errors();
        return ['ok' => $errors === [], 'rows' => $rows, 'error' => $errors ? $this->safeError(implode(' ', $errors)) : ''];
    }

    private function dates(): array
    {
        $today = current_datetime()->setTime(0, 0);
        $current_start = $today->modify('first day of this month');
        $previous_start = $today->modify('first day of last month');
        return [
            'today' => $today->format('Y-m-d'),
            'last_7_start' => $today->modify('-6 days')->format('Y-m-d'),
            'last_30_start' => $today->modify('-29 days')->format('Y-m-d'),
            'last_90_start' => $today->modify('-89 days')->format('Y-m-d'),
            'current_month_start' => $current_start->format('Y-m-d'),
            'previous_month_start' => $previous_start->format('Y-m-d'),
            'previous_month_end' => $current_start->modify('-1 day')->format('Y-m-d'),
        ];
    }

    private function metrics(array $metrics): array
    {
        return [
            'impressions' => (int)($metrics['impressions'] ?? 0),
            'clicks' => (int)($metrics['clicks'] ?? 0),
            'spend' => ((float)($metrics['costMicros'] ?? 0)) / 1000000,
            'conversions' => (float)($metrics['conversions'] ?? 0),
            'conversion_value' => (float)($metrics['conversionsValue'] ?? 0),
        ];
    }

    private function emptyMetrics(): array
    {
        return ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0, 'conversions' => 0.0, 'conversion_value' => 0.0];
    }

    private function addMetrics(array $total, array $metrics): array
    {
        foreach ($total as $key => $value) $total[$key] += $metrics[$key] ?? 0;
        return $total;
    }

    private function finishMetrics(array $metrics): array
    {
        $metrics['ctr'] = $metrics['impressions'] > 0 ? $metrics['clicks'] / $metrics['impressions'] : 0;
        $metrics['cpc'] = $metrics['clicks'] > 0 ? $metrics['spend'] / $metrics['clicks'] : 0;
        $metrics['conversion_rate'] = $metrics['clicks'] > 0 ? $metrics['conversions'] / $metrics['clicks'] : 0;
        $metrics['cpa'] = $metrics['conversions'] > 0 ? $metrics['spend'] / $metrics['conversions'] : 0;
        foreach (['spend', 'conversions', 'conversion_value', 'cpc', 'cpa'] as $key) $metrics[$key] = round((float)$metrics[$key], 2);
        return $metrics;
    }

    private function share($value): ?float
    {
        return is_numeric($value) ? round((float)$value, 4) : null;
    }

    private function safeError(string $error): string
    {
        foreach (['developer_token', 'oauth_client_id', 'oauth_client_secret', 'refresh_token'] as $key) {
            $secret = (string)($this->credentials[$key] ?? '');
            if ($secret !== '') $error = str_replace($secret, '[redacted]', $error);
        }
        return sanitize_text_field($error);
    }

    private function unavailable(string $message): array
    {
        return ['available' => false, 'status' => 'unavailable', 'message' => sanitize_text_field($message) ?: 'Unavailable'];
    }

    private function unavailableDashboard(string $message): array
    {
        $module = $this->unavailable($message);
        return ['account_diagnostic' => $module, 'conversion_health' => $module, 'change_history' => $module, 'impression_share' => $module, 'budget_analysis' => $module, 'generated_at' => current_time('mysql')];
    }
}
