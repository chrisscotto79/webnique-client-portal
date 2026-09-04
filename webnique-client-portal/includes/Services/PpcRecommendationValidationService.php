<?php
/** Conservative before/after evidence for documented PPC implementations. */
namespace WNQ\Services;

if (!defined('ABSPATH')) exit;

final class PpcRecommendationValidationService
{
    public function validate(array $recommendation, array $change_history = []): array
    {
        $implemented = substr((string)($recommendation['implemented_at'] ?? ''), 0, 10);
        $customer_id = preg_replace('/\D+/', '', (string)($recommendation['customer_id'] ?? '')) ?: '';
        if ($implemented === '' || strlen($customer_id) !== 10) {
            return self::unavailable('Record a verified implementation date before requesting before/after evidence.');
        }
        $implementation_date = \DateTimeImmutable::createFromFormat('!Y-m-d', $implemented);
        if (!$implementation_date) return self::unavailable('The implementation date is invalid.');
        $today = current_datetime()->setTime(0, 0);
        if ($implementation_date >= $today) return self::unavailable('Monitoring begins after at least one complete post-implementation day.');

        $windows = [];
        foreach ([7, 14, 30] as $days) {
            $after_end = $implementation_date->modify('+' . $days . ' days');
            if ($after_end >= $today) {
                $windows[$days] = ['status' => 'monitor', 'label' => 'Insufficient data', 'message' => $days . ' complete post-implementation days are not available yet.'];
                continue;
            }
            $before_start = $implementation_date->modify('-' . $days . ' days');
            $before_end = $implementation_date->modify('-1 day');
            $after_start = $implementation_date->modify('+1 day');
            $before = $this->metrics($customer_id, (string)($recommendation['campaign_id'] ?? ''), $before_start->format('Y-m-d'), $before_end->format('Y-m-d'));
            $after = $this->metrics($customer_id, (string)($recommendation['campaign_id'] ?? ''), $after_start->format('Y-m-d'), $after_end->format('Y-m-d'));
            if (empty($before['available']) || empty($after['available'])) {
                $windows[$days] = ['status' => 'unavailable', 'label' => 'Insufficient data', 'message' => 'Google Ads did not return both equal-length comparison periods.'];
                continue;
            }
            $confounders = self::confounders($recommendation, $change_history, $before_start, $after_end, $before, $after);
            $assessment = self::assess($before, $after, $days, $confounders);
            $windows[$days] = [
                'status' => 'ready',
                'label' => $assessment['label'],
                'message' => $assessment['message'],
                'before_period' => $before_start->format('Y-m-d') . ' to ' . $before_end->format('Y-m-d'),
                'after_period' => $after_start->format('Y-m-d') . ' to ' . $after_end->format('Y-m-d'),
                'before' => $before,
                'after' => $after,
                'changes' => self::changes($before, $after),
                'confounders' => $confounders,
            ];
        }
        $labels = array_column(array_filter($windows, static fn(array $window): bool => ($window['status'] ?? '') === 'ready'), 'label');
        $overall = 'insufficient_data';
        if ($labels) {
            if (count(array_filter($labels, static fn(string $label): bool => $label === 'Negative evidence')) > count($labels) / 2) $overall = 'negative_evidence';
            elseif (count(array_filter($labels, static fn(string $label): bool => $label === 'Positive evidence')) > count($labels) / 2) $overall = 'positive_evidence';
            else $overall = 'neutral_inconclusive';
        }
        return [
            'available' => true,
            'overall' => $overall,
            'message' => 'This is correlation evidence, not causal proof. A human must confirm the final outcome.',
            'implemented_at' => (string)$recommendation['implemented_at'],
            'windows' => $windows,
            'generated_at' => current_time('mysql'),
        ];
    }

    private function metrics(string $customer_id, string $campaign_id, string $start, string $end): array
    {
        $query = new GoogleAdsQueryService();
        $campaign_id = preg_replace('/\D+/', '', $campaign_id) ?: '';
        $where = "segments.date BETWEEN '{$start}' AND '{$end}'";
        if ($campaign_id !== '') $where .= " AND campaign.id = {$campaign_id}";
        $rows = $query->select($customer_id, "SELECT campaign.id, campaign.name, campaign.status, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.search_impression_share, metrics.search_budget_lost_impression_share, metrics.search_rank_lost_impression_share FROM campaign WHERE {$where} LIMIT 1000");
        if ($query->errors()) return ['available' => false];
        $totals = ['available' => true, 'period_start' => $start, 'period_end' => $end, 'day_count' => (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1, 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0.0, 'conversion_value' => 0.0, 'campaign_statuses' => []];
        $weighted = ['impression_share' => 0.0, 'lost_budget' => 0.0, 'lost_rank' => 0.0, 'weight' => 0];
        foreach ($rows as $row) {
            $metrics = (array)($row['metrics'] ?? []);
            $status = strtolower(sanitize_key((string)($row['campaign']['status'] ?? 'unknown')));
            $totals['campaign_statuses'][$status] = ($totals['campaign_statuses'][$status] ?? 0) + 1;
            $impressions = (int)($metrics['impressions'] ?? 0);
            $totals['spend'] += ((float)($metrics['costMicros'] ?? 0)) / 1000000;
            $totals['impressions'] += $impressions;
            $totals['clicks'] += (int)($metrics['clicks'] ?? 0);
            $totals['conversions'] += (float)($metrics['conversions'] ?? 0);
            $totals['conversion_value'] += (float)($metrics['conversionsValue'] ?? 0);
            foreach (['impression_share' => 'searchImpressionShare', 'lost_budget' => 'searchBudgetLostImpressionShare', 'lost_rank' => 'searchRankLostImpressionShare'] as $key => $field) {
                if (is_numeric($metrics[$field] ?? null)) $weighted[$key] += (float)$metrics[$field] * $impressions;
            }
            $weighted['weight'] += $impressions;
        }
        $totals['spend'] = round($totals['spend'], 2);
        $totals['conversions'] = round($totals['conversions'], 2);
        $totals['conversion_value'] = round($totals['conversion_value'], 2);
        $totals['ctr'] = $totals['impressions'] > 0 ? $totals['clicks'] / $totals['impressions'] : 0;
        $totals['average_cpc'] = $totals['clicks'] > 0 ? $totals['spend'] / $totals['clicks'] : 0;
        $totals['conversion_rate'] = $totals['clicks'] > 0 ? $totals['conversions'] / $totals['clicks'] : 0;
        $totals['cpa'] = $totals['conversions'] > 0 ? $totals['spend'] / $totals['conversions'] : null;
        foreach (['impression_share', 'lost_budget', 'lost_rank'] as $key) $totals[$key] = $weighted['weight'] > 0 ? $weighted[$key] / $weighted['weight'] : null;
        return $totals;
    }

    private static function assess(array $before, array $after, int $days, array $confounders): array
    {
        $combined_clicks = (int)$before['clicks'] + (int)$after['clicks'];
        $combined_conversions = (float)$before['conversions'] + (float)$after['conversions'];
        if ($combined_clicks < max(10, $days) && $combined_conversions < 3) {
            return ['label' => 'Insufficient data', 'message' => 'Traffic and conversion volume are too sparse for a stable directional assessment. Continue monitoring.'];
        }
        $positive = 0; $negative = 0;
        $conversion_change = self::rate((float)$before['conversions'], (float)$after['conversions']);
        $rate_change = self::rate((float)$before['conversion_rate'], (float)$after['conversion_rate']);
        if ($conversion_change >= .15) $positive++; elseif ($conversion_change <= -.15) $negative++;
        if ($rate_change >= .1) $positive++; elseif ($rate_change <= -.1) $negative++;
        if (is_numeric($before['cpa']) && is_numeric($after['cpa'])) {
            $cpa_change = self::rate((float)$before['cpa'], (float)$after['cpa']);
            if ($cpa_change <= -.1) $positive++; elseif ($cpa_change >= .1) $negative++;
        }
        $major_confounder = count($confounders) > 0;
        if ($positive >= 2 && $negative === 0 && !$major_confounder) return ['label' => 'Positive evidence', 'message' => 'Multiple directional indicators improved without a detected major confounder. This remains correlation, not proof.'];
        if ($negative >= 2 && $positive === 0 && !$major_confounder) return ['label' => 'Negative evidence', 'message' => 'Multiple directional indicators worsened without a detected major confounder. Human investigation is required.'];
        return ['label' => 'Neutral / inconclusive', 'message' => $major_confounder ? 'The comparison contains concurrent changes or material spend imbalance.' : 'The available metrics are mixed or changes are not directionally strong.'];
    }

    private static function changes(array $before, array $after): array
    {
        $result = [];
        foreach (['spend','impressions','clicks','ctr','average_cpc','conversions','conversion_rate','cpa','conversion_value','impression_share','lost_budget','lost_rank'] as $metric) {
            $result[$metric] = ['absolute' => is_numeric($after[$metric] ?? null) && is_numeric($before[$metric] ?? null) ? (float)$after[$metric] - (float)$before[$metric] : null, 'relative' => is_numeric($after[$metric] ?? null) && is_numeric($before[$metric] ?? null) ? self::rate((float)$before[$metric], (float)$after[$metric]) : null];
        }
        return $result;
    }

    private static function confounders(array $recommendation, array $history, \DateTimeImmutable $start, \DateTimeImmutable $end, array $before, array $after): array
    {
        $confounders = [];
        $campaign_id = preg_replace('/\D+/', '', (string)($recommendation['campaign_id'] ?? '')) ?: '';
        foreach ((array)($history['changes'] ?? []) as $change) {
            $date = substr((string)($change['date_time'] ?? ''), 0, 10);
            if ($date < $start->format('Y-m-d') || $date > $end->format('Y-m-d')) continue;
            $changed_campaign = preg_replace('/\D+/', '', (string)($change['campaign_id'] ?? '')) ?: '';
            if ($campaign_id !== '' && $changed_campaign !== '' && $campaign_id !== $changed_campaign) continue;
            $fields = implode(' ', (array)($change['fields'] ?? []));
            if (preg_match('/budget|bidding|status|negative|conversion|ad_group_ad|responsive_search_ad/i', $fields . ' ' . (string)($change['resource_type'] ?? ''))) $confounders[] = 'Concurrent ' . str_replace('_', ' ', (string)($change['resource_type'] ?? 'account')) . ' change on ' . $date . '.';
        }
        $spend_change = self::rate((float)$before['spend'], (float)$after['spend']);
        if (abs($spend_change) >= .5) $confounders[] = 'Spend differed by ' . round(abs($spend_change) * 100) . '% between equal-length periods.';
        if (!empty($before['campaign_statuses']['paused']) || !empty($after['campaign_statuses']['paused'])) $confounders[] = 'At least one compared campaign is currently paused; serving-day equivalence requires human verification.';
        return array_values(array_unique($confounders));
    }

    private static function rate(float $before, float $after): float
    {
        if (abs($before) < .000001) return abs($after) < .000001 ? 0 : 1;
        return ($after - $before) / abs($before);
    }

    private static function unavailable(string $message): array
    {
        return ['available' => false, 'overall' => 'insufficient_data', 'message' => $message, 'windows' => []];
    }
}
