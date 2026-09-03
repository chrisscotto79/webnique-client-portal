<?php
/**
 * Reusable, read-only GAQL access for PPC Intelligence modules.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleAdsQueryService
{
    private GoogleAdsClient $client;

    public function __construct(?array $credentials = null)
    {
        $this->client = new GoogleAdsClient($credentials ?? GoogleAdsCredentials::get());
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function discoverAccounts(bool $refresh = false): array
    {
        return $this->client->listManagerAccounts($refresh);
    }

    public function accountMetadata(string $customer_id): array
    {
        return $this->client->accountMetadata($customer_id);
    }

    public function select(string $customer_id, string $gaql): array
    {
        $gaql = trim($gaql);
        if (!self::isReadOnlyQuery($gaql)) {
            throw new \InvalidArgumentException('PPC Intelligence only permits a single read-only GAQL SELECT query.');
        }
        return $this->client->query($customer_id, $gaql);
    }

    public function errors(): array
    {
        return $this->client->errors();
    }

    public static function isReadOnlyQuery(string $gaql): bool
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $gaql) ?: '');
        if ($normalized === '' || preg_match('/^SELECT\s/i', $normalized) !== 1) {
            return false;
        }
        if (str_contains($normalized, ';')) {
            return false;
        }
        return preg_match('/\b(?:MUTATE|CREATE|UPDATE|DELETE|REMOVE|INSERT)\b/i', $normalized) !== 1;
    }
}
