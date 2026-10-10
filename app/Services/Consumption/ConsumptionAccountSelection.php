<?php

namespace App\Services\Consumption;

use InvalidArgumentException;

/** Select a configured account without substituting another account for an invalid choice. */
final class ConsumptionAccountSelection
{
    /**
     * IDs are Finance account identities, never Stock organization identities.
     * Validation of the selected identity belongs to the existing organization mapping.
     *
     * @return array{account_id:int,source:string}|null
     */
    public static function resolve(
        ?int $override,
        ?int $itemDefault,
        ?int $categoryDefault,
        ?int $connectionDefault,
        bool $mayOverride,
    ): ?array {
        if ($override !== null && ! $mayOverride) {
            throw new InvalidArgumentException('consumption_account_override_forbidden');
        }
        foreach (['override' => $override, 'item' => $itemDefault,
            'category' => $categoryDefault, 'connection' => $connectionDefault] as $source => $id) {
            if ($id === null) continue;
            if ($id <= 0) throw new InvalidArgumentException('consumption_account_invalid');
            return ['account_id' => $id, 'source' => $source];
        }
        return null;
    }

    /** A selected invalid account must fail closed, even if lower-priority defaults exist. */
    public static function validAccount(?array $account, int $financeOrganizationId): bool
    {
        return $financeOrganizationId > 0 && $account !== null
            && (int) ($account['organization_id'] ?? 0) === $financeOrganizationId
            && (bool) ($account['is_active'] ?? false)
            && (bool) ($account['is_postable'] ?? false)
            && empty($account['deleted_at'])
            && strtolower((string) ($account['type'] ?? '')) === 'expense';
    }
}
