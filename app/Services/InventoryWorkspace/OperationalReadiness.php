<?php
namespace App\Services\InventoryWorkspace;

/** A commercial grant or an old connection row alone never unlocks operations. */
final class OperationalReadiness
{
    public static function allows(bool $mapped, bool $activated, string $state, array $missingRoles): bool
    {
        return $mapped && $activated && $state === 'CONNECTED_READY' && $missingRoles === [];
    }
}
