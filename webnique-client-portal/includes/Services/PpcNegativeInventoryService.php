<?php
/**
 * Read-only negative-keyword inventory across ad group, campaign, shared-list,
 * and account-level shared-list scopes.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class PpcNegativeInventoryService
{
    public function positiveKeywords(string $customer_id): array
    {
        $query = new GoogleAdsQueryService();
        $rows = $query->select(self::digits($customer_id), "SELECT campaign.id, campaign.name, ad_group.id, ad_group.name, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type FROM keyword_view WHERE campaign.advertising_channel_type = 'SEARCH' AND campaign.status = 'ENABLED' AND ad_group.status = 'ENABLED' AND ad_group_criterion.status = 'ENABLED' LIMIT 10000");
        if ($query->errors()) return ['available'=>false,'items'=>[],'errors'=>['Enabled positive keywords are unavailable.']];
        $items = [];
        foreach ($rows as $row) {
            $criterion=(array)($row['adGroupCriterion']??[]);$campaign=(array)($row['campaign']??[]);$group=(array)($row['adGroup']??[]);
            $items[] = ['criterion_id'=>self::digits((string)($criterion['criterionId']??'')),'keyword'=>sanitize_text_field((string)($criterion['keyword']['text']??'')),'match_type'=>strtolower(sanitize_key((string)($criterion['keyword']['matchType']??'unknown'))),'campaign_id'=>self::digits((string)($campaign['id']??'')),'campaign'=>sanitize_text_field((string)($campaign['name']??'')),'ad_group_id'=>self::digits((string)($group['id']??'')),'ad_group'=>sanitize_text_field((string)($group['name']??''))];
        }
        return ['available'=>true,'items'=>$items,'errors'=>[]];
    }

    public function inventory(string $customer_id, bool $refresh = false): array
    {
        $customer_id = self::digits($customer_id);
        $cache_key = 'wnq_ppc_negative_inventory_' . md5($customer_id);
        if (!$refresh && is_array($cached = get_transient($cache_key))) {
            return $cached;
        }

        $errors = [];
        $items = array_merge(
            $this->directNegatives($customer_id, 'ad_group', $errors),
            $this->directNegatives($customer_id, 'campaign', $errors)
        );
        $attachments = array_merge(
            $this->campaignAttachments($customer_id, $errors),
            $this->accountAttachments($customer_id, $errors)
        );

        $sets = [];
        foreach ($attachments as $attachment) {
            $resource = (string)$attachment['shared_set_resource'];
            if ($resource === '') {
                continue;
            }
            if (!isset($sets[$resource])) {
                $sets[$resource] = $attachment + ['campaign_ids' => [], 'campaign_names' => []];
            } elseif ($attachment['scope'] === 'account_level') {
                // An account-level attachment applies more broadly than a campaign
                // attachment to the same shared-set resource.
                $sets[$resource]['scope'] = 'account_level';
            }
            if ($attachment['scope'] === 'shared_list' && $attachment['campaign_id'] !== '') {
                $sets[$resource]['campaign_ids'][$attachment['campaign_id']] = true;
                $sets[$resource]['campaign_names'][$attachment['campaign_name']] = true;
            }
        }

        $sets_by_owner = [];
        foreach ($sets as $resource => $set) {
            $owner = self::resourceCustomerId($resource) ?: $customer_id;
            $sets_by_owner[$owner][$resource] = $set;
        }
        foreach ($sets_by_owner as $owner => $owned_sets) {
            $items = array_merge($items, $this->sharedCriteria($owner, $owned_sets, $errors));
        }

        $result = [
            'available'   => count($items) > 0 || !$errors,
            'status'      => $errors ? 'partial' : 'ready',
            'message'     => $errors ? 'Some negative-keyword scopes could not be read. No unavailable scope is treated as empty.' : '',
            'items'       => $items,
            'shared_sets' => array_values(array_map(static function (array $set): array {
                $set['campaign_ids'] = array_keys((array)$set['campaign_ids']);
                $set['campaign_names'] = array_keys((array)$set['campaign_names']);
                return $set;
            }, $sets)),
            'errors'      => array_values(array_unique($errors)),
        ];
        if (!empty($result['available'])) {
            set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        }
        return $result;
    }

    public static function conflicts(array $keywords, array $negatives): array
    {
        $conflicts = [];
        $seen = [];
        foreach ($keywords as $positive) {
            foreach ($negatives as $negative) {
                if (!self::appliesTo($negative, (string)$positive['campaign_id'], (string)$positive['ad_group_id'])) {
                    continue;
                }
                if (!self::blocks((string)$positive['keyword'], (string)$negative['negative'], (string)$negative['match_type'])) {
                    continue;
                }
                $key = hash('sha256', implode('|', [(string)($positive['criterion_id'] ?? ''), (string)($negative['resource_name'] ?? ''), (string)($negative['negative'] ?? '')]));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $scope = (string)($negative['scope'] ?? $negative['level'] ?? 'negative');
                $conflicts[] = [
                    'positive'            => (string)$positive['keyword'],
                    'positive_match'      => (string)$positive['match_type'],
                    'negative'            => (string)$negative['negative'],
                    'negative_match'      => (string)$negative['match_type'],
                    'level'               => $scope,
                    'scope'               => $scope,
                    'shared_set_name'     => (string)($negative['shared_set_name'] ?? ''),
                    'shared_set_id'       => (string)($negative['shared_set_id'] ?? ''),
                    'shared_set_resource' => (string)($negative['shared_set_resource'] ?? ''),
                    'campaign'            => (string)$positive['campaign'],
                    'campaign_id'         => (string)$positive['campaign_id'],
                    'ad_group'            => (string)$positive['ad_group'],
                    'ad_group_id'         => (string)$positive['ad_group_id'],
                    'priority'            => (string)$positive['match_type'] === 'exact' ? 'high' : 'review',
                    'evidence'            => self::scopeLabel($negative) . ' may block this enabled positive keyword under ' . (string)$negative['match_type'] . '-match semantics.',
                    'recommendation'      => 'Investigate search routing and business intent before changing the negative; the conflict may be intentional.',
                ];
            }
        }
        return $conflicts;
    }

    public static function duplicate(array $inventory, string $text, string $match_type, string $campaign_id, string $ad_group_id): ?array
    {
        foreach ((array)($inventory['items'] ?? []) as $negative) {
            if (!self::appliesTo($negative, self::digits($campaign_id), self::digits($ad_group_id))) {
                continue;
            }
            if (self::words((string)$negative['negative']) === self::words($text) && (string)$negative['match_type'] === sanitize_key($match_type)) {
                return $negative;
            }
        }
        return null;
    }

    public static function protectedTerms(string $query, array $config): array
    {
        $matches = [];
        foreach (['brand_names' => 'brand', 'services' => 'service', 'target_areas' => 'geography'] as $key => $label) {
            foreach ((array)($config[$key] ?? []) as $term) {
                $needle = self::words((string)$term);
                if ($needle !== '' && str_contains(' ' . self::words($query) . ' ', ' ' . $needle . ' ')) {
                    $matches[$label . ':' . $needle] = ['type' => $label, 'term' => (string)$term];
                }
            }
        }
        return array_values($matches);
    }

    public static function appliesTo(array $negative, string $campaign_id, string $ad_group_id): bool
    {
        $scope = (string)($negative['scope'] ?? $negative['level'] ?? '');
        if ($scope === 'account_level') {
            return true;
        }
        if ($scope === 'shared_list') {
            return in_array(self::digits($campaign_id), array_map([self::class, 'digits'], (array)($negative['campaign_ids'] ?? [])), true);
        }
        if (self::digits((string)($negative['campaign_id'] ?? '')) !== self::digits($campaign_id)) {
            return false;
        }
        return $scope !== 'ad_group' || self::digits((string)($negative['ad_group_id'] ?? '')) === self::digits($ad_group_id);
    }

    private function directNegatives(string $customer_id, string $scope, array &$errors): array
    {
        $query = new GoogleAdsQueryService();
        $resource = $scope === 'campaign' ? 'campaign_criterion' : 'ad_group_criterion';
        $fields = $scope === 'campaign'
            ? 'campaign.id, campaign.name, campaign_criterion.resource_name, campaign_criterion.criterion_id, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type'
            : 'campaign.id, campaign.name, ad_group.id, ad_group.name, ad_group_criterion.resource_name, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type';
        $rows = $query->select($customer_id, "SELECT {$fields} FROM {$resource} WHERE {$resource}.negative = TRUE AND {$resource}.type = 'KEYWORD' LIMIT 10000");
        if ($query->errors()) {
            $errors[] = self::scopeName($scope) . ' negatives are unavailable.';
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $campaign = (array)($row['campaign'] ?? []);
            $group = (array)($row['adGroup'] ?? []);
            $criterion = (array)($row[$scope === 'campaign' ? 'campaignCriterion' : 'adGroupCriterion'] ?? []);
            $items[] = [
                'scope' => $scope,
                'negative' => sanitize_text_field((string)($criterion['keyword']['text'] ?? '')),
                'match_type' => strtolower(sanitize_key((string)($criterion['keyword']['matchType'] ?? 'broad'))),
                'resource_name' => sanitize_text_field((string)($criterion['resourceName'] ?? '')),
                'criterion_id' => self::digits((string)($criterion['criterionId'] ?? '')),
                'campaign_id' => self::digits((string)($campaign['id'] ?? '')),
                'campaign_name' => sanitize_text_field((string)($campaign['name'] ?? '')),
                'ad_group_id' => self::digits((string)($group['id'] ?? '')),
                'ad_group_name' => sanitize_text_field((string)($group['name'] ?? '')),
                'campaign_ids' => [],
            ];
        }
        return $items;
    }

    private function campaignAttachments(string $customer_id, array &$errors): array
    {
        $query = new GoogleAdsQueryService();
        $rows = $query->select($customer_id, "SELECT campaign.id, campaign.name, campaign_shared_set.resource_name, campaign_shared_set.status, shared_set.id, shared_set.resource_name, shared_set.name, shared_set.type, shared_set.status FROM campaign_shared_set WHERE campaign_shared_set.status = 'ENABLED' AND shared_set.status = 'ENABLED'");
        if ($query->errors()) {
            $errors[] = 'Campaign shared negative lists are unavailable.';
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $set = (array)($row['sharedSet'] ?? []);
            if (strtolower(sanitize_key((string)($set['type'] ?? ''))) !== 'negative_keywords') {
                continue;
            }
            $campaign = (array)($row['campaign'] ?? []);
            $items[] = self::attachment('shared_list', $set, $campaign);
        }
        return $items;
    }

    private function accountAttachments(string $customer_id, array &$errors): array
    {
        $query = new GoogleAdsQueryService();
        $rows = $query->select($customer_id, "SELECT customer_negative_criterion.id, customer_negative_criterion.resource_name, customer_negative_criterion.type, customer_negative_criterion.negative_keyword_list.shared_set, shared_set.id, shared_set.resource_name, shared_set.name, shared_set.type, shared_set.status FROM customer_negative_criterion WHERE customer_negative_criterion.type = 'NEGATIVE_KEYWORD_LIST'");
        if ($query->errors()) {
            $errors[] = 'Account-level negative lists are unavailable.';
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $criterion = (array)($row['customerNegativeCriterion'] ?? []);
            $set = (array)($row['sharedSet'] ?? []);
            $resource = (string)($criterion['negativeKeywordList']['sharedSet'] ?? $set['resourceName'] ?? '');
            $set['resourceName'] = $resource;
            $items[] = self::attachment('account_level', $set, []);
        }
        return $items;
    }

    private function sharedCriteria(string $owner_customer_id, array $sets, array &$errors): array
    {
        $query = new GoogleAdsQueryService();
        $rows = $query->select($owner_customer_id, "SELECT shared_criterion.resource_name, shared_criterion.shared_set, shared_criterion.type, shared_criterion.negative, shared_criterion.keyword.text, shared_criterion.keyword.match_type, shared_set.id, shared_set.resource_name, shared_set.name, shared_set.type, shared_set.status FROM shared_criterion WHERE shared_criterion.type = 'KEYWORD' AND shared_criterion.negative = TRUE");
        if ($query->errors()) {
            $errors[] = 'Negative keywords inside one or more shared lists are unavailable.';
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $criterion = (array)($row['sharedCriterion'] ?? []);
            $resource = (string)($criterion['sharedSet'] ?? $row['sharedSet']['resourceName'] ?? '');
            if (!isset($sets[$resource])) {
                continue;
            }
            $set = $sets[$resource];
            $items[] = [
                'scope' => (string)$set['scope'],
                'negative' => sanitize_text_field((string)($criterion['keyword']['text'] ?? '')),
                'match_type' => strtolower(sanitize_key((string)($criterion['keyword']['matchType'] ?? 'broad'))),
                'resource_name' => sanitize_text_field((string)($criterion['resourceName'] ?? '')),
                'criterion_id' => self::resourceTail((string)($criterion['resourceName'] ?? '')),
                'campaign_id' => '',
                'campaign_name' => '',
                'ad_group_id' => '',
                'ad_group_name' => '',
                'campaign_ids' => array_keys((array)$set['campaign_ids']),
                'campaign_names' => array_keys((array)$set['campaign_names']),
                'shared_set_name' => (string)$set['shared_set_name'],
                'shared_set_id' => (string)$set['shared_set_id'],
                'shared_set_resource' => $resource,
            ];
        }
        return $items;
    }

    private static function attachment(string $scope, array $set, array $campaign): array
    {
        return [
            'scope' => $scope,
            'shared_set_id' => self::digits((string)($set['id'] ?? '')),
            'shared_set_name' => sanitize_text_field((string)($set['name'] ?? 'Shared negative list')),
            'shared_set_resource' => sanitize_text_field((string)($set['resourceName'] ?? '')),
            'campaign_id' => self::digits((string)($campaign['id'] ?? '')),
            'campaign_name' => sanitize_text_field((string)($campaign['name'] ?? '')),
        ];
    }

    private static function blocks(string $positive, string $negative, string $match_type): bool
    {
        $positive = self::words($positive);
        $negative = self::words($negative);
        if ($negative === '') {
            return false;
        }
        if ($match_type === 'exact') {
            return $positive === $negative;
        }
        if ($match_type === 'phrase') {
            return str_contains(' ' . $positive . ' ', ' ' . $negative . ' ');
        }
        $words = array_unique(explode(' ', $positive));
        foreach (array_unique(explode(' ', $negative)) as $word) {
            if (!in_array($word, $words, true)) {
                return false;
            }
        }
        return true;
    }

    private static function scopeLabel(array $negative): string
    {
        if (($negative['scope'] ?? '') === 'shared_list') {
            return 'Shared negative list “' . (string)($negative['shared_set_name'] ?? 'Unnamed') . '”';
        }
        if (($negative['scope'] ?? '') === 'account_level') {
            return 'Account-level negative list “' . (string)($negative['shared_set_name'] ?? 'Unnamed') . '”';
        }
        return self::scopeName((string)($negative['scope'] ?? 'negative'));
    }

    private static function scopeName(string $scope): string
    {
        return ucwords(str_replace('_', ' ', $scope));
    }

    private static function words(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?: '');
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private static function resourceCustomerId(string $resource): string
    {
        return preg_match('#^customers/(\d+)/#', $resource, $matches) ? self::digits((string)$matches[1]) : '';
    }

    private static function resourceTail(string $resource): string
    {
        $tail = basename($resource);
        return sanitize_text_field($tail);
    }
}
