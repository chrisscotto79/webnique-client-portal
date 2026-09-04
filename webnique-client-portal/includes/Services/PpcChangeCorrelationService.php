<?php
/** Read-only temporal correlation between Google Ads changes and performance. */
namespace WNQ\Services;

if (!defined('ABSPATH')) exit;

final class PpcChangeCorrelationService
{
    public function report(string $customer_id, array $change_history): array
    {
        if (empty($change_history['available'])) {
            return ['available'=>false,'status'=>'unavailable','message'=>'Change history is unavailable, so correlation was not attempted.','correlations'=>[]];
        }
        $today = current_datetime()->setTime(0, 0);
        $end = $today->format('Y-m-d');
        // Change Event covers 30 days; fetch three extra days so the oldest
        // eligible change still has a complete pre-change comparison window.
        $start = $today->modify('-32 days')->format('Y-m-d');
        $query = new GoogleAdsQueryService();
        $rows = $query->select($customer_id, "SELECT segments.date, campaign.id, campaign.name, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions FROM campaign WHERE segments.date BETWEEN '{$start}' AND '{$end}' ORDER BY segments.date");
        if ($query->errors()) {
            return ['available'=>false,'status'=>'unavailable','message'=>'Daily campaign performance is unavailable. Existing investigations remain available.','correlations'=>[]];
        }

        $daily = [];
        foreach ($rows as $row) {
            $date = (string)($row['segments']['date'] ?? '');
            $id = (string)($row['campaign']['id'] ?? '');
            $metrics = (array)($row['metrics'] ?? []);
            $values = ['spend'=>((float)($metrics['costMicros']??0))/1000000,'clicks'=>(int)($metrics['clicks']??0),'impressions'=>(int)($metrics['impressions']??0),'conversions'=>(float)($metrics['conversions']??0)];
            $daily[$id][$date] = $values;
            foreach ($values as $metric => $value) $daily['__account'][$date][$metric] = (float)($daily['__account'][$date][$metric] ?? 0) + $value;
        }

        $correlations = [];
        foreach (array_slice((array)($change_history['changes'] ?? []), 0, 100) as $change) {
            $campaign_id = (string)($change['campaign_id'] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($change['date_time'] ?? ''), 0, 10), $today->getTimezone());
            if (!$date || $date->modify('+3 days') >= $today) continue;
            $series = $campaign_id !== '' ? ($daily[$campaign_id] ?? []) : ($daily['__account'] ?? []);
            $before = self::sum($series, $date->modify('-3 days'), $date->modify('-1 day'));
            $after = self::sum($series, $date->modify('+1 day'), $date->modify('+3 days'));
            $sample_clicks = $before['clicks'] + $after['clicks'];
            $sample_conversions = $before['conversions'] + $after['conversions'];
            if ($sample_clicks < 6 && $sample_conversions < 2) continue;

            $conversion_change = self::rate($before['conversions'], $after['conversions']);
            $cpa_before = $before['conversions'] > 0 ? $before['spend'] / $before['conversions'] : null;
            $cpa_after = $after['conversions'] > 0 ? $after['spend'] / $after['conversions'] : null;
            $cpa_change = is_numeric($cpa_before) && is_numeric($cpa_after) ? self::rate($cpa_before, $cpa_after) : null;
            $magnitude = max(abs($conversion_change), is_numeric($cpa_change) ? abs($cpa_change) : 0);
            $robust_sample = $sample_clicks >= 30 || $sample_conversions >= 6;
            $moderate_sample = $sample_clicks >= 15 || $sample_conversions >= 4;
            $label = self::evidenceLabel($magnitude, $sample_clicks, $sample_conversions);
            $evidence_for = [];
            $evidence_against = [];
            if (!$moderate_sample) $evidence_against[] = 'Minimum-data safeguard: this comparison is too small to support a contributor label.';
            elseif (!$robust_sample) $evidence_against[] = 'The sample supports investigation but is too small for a strong-evidence label.';
            if (abs($conversion_change) >= .15) $evidence_for[] = 'Conversions changed ' . round(abs($conversion_change)*100) . '% in the three complete days after the change.';
            else $evidence_against[] = 'Conversion volume did not materially separate in the immediate comparison.';
            if (is_numeric($cpa_change) && abs($cpa_change) >= .15) $evidence_for[] = 'CPA changed ' . round(abs($cpa_change)*100) . '% after the change.';
            else $evidence_against[] = 'CPA evidence is unavailable or directionally weak.';
            $correlations[] = [
                'label'=>$label,
                'observation'=>(string)($change['resource_type']??'Resource').' '.(string)($change['operation']??'change').' on '.substr((string)$change['date_time'],0,16).'.',
                'change_date'=>(string)$change['date_time'],
                'campaign'=>(string)(($change['campaign']??'') ?: 'Account-wide evidence'),
                'campaign_id'=>$campaign_id,
                'changed_fields'=>implode(', ',(array)($change['fields']??[])),
                'before'=>$before,
                'after'=>$after,
                'evidence_for'=>$evidence_for,
                'evidence_against'=>$evidence_against,
                'root_cause_status'=>'unconfirmed',
                'recommendation'=>'Review the changed fields, longer comparison windows, tracking stability, and concurrent changes before assigning causality.',
            ];
        }
        return ['available'=>true,'status'=>'ready','message'=>'Timing correlations are investigative evidence, not causal proof. Change Event history is limited by Google Ads to recent changes.','correlations'=>$correlations,'period'=>'Last 30 days; three complete days before vs three complete days after'];
    }

    private static function sum(array $daily, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $total = ['spend'=>0.0,'clicks'=>0,'impressions'=>0,'conversions'=>0.0];
        for ($date=$start; $date<=$end; $date=$date->modify('+1 day')) foreach ($total as $key=>$unused) $total[$key] += (float)($daily[$date->format('Y-m-d')][$key] ?? 0);
        $total['spend'] = round($total['spend'], 2);
        $total['conversions'] = round($total['conversions'], 2);
        return $total;
    }

    private static function rate(float $before, float $after): float
    {
        return abs($before) < .00001 ? (abs($after) < .00001 ? 0 : 1) : ($after-$before)/abs($before);
    }

    private static function evidenceLabel(float $magnitude, int $clicks, float $conversions): string
    {
        $robust_sample = $clicks >= 30 || $conversions >= 6;
        $moderate_sample = $clicks >= 15 || $conversions >= 4;
        if ($magnitude >= .35 && $robust_sample) return 'Strong evidence';
        if ($magnitude >= .15 && $moderate_sample) return 'Possible contributor';
        return 'Observation';
    }
}
