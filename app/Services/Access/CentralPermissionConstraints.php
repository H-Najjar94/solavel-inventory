<?php

namespace App\Services\Access;

/** Explicit denials constrain native permissions; a grant never creates a native role. */
final class CentralPermissionConstraints
{
    public static function denied(array $decision, string $permission, ?string $scopeType = null, int|string|null $scopeId = null): bool
    {
        foreach ($decision['grants'] ?? [] as $grant) {
            if (($grant['effect'] ?? null) !== 'deny'
                || ! in_array($grant['permission_key'] ?? null, [$permission, '*'], true)) {
                continue;
            }
            $type = $grant['scope_type'] ?? 'organization';
            // A caller without a resource-aware query cannot safely ignore a
            // scoped denial. Deny that operation until it can apply the scope.
            if ($type === 'organization' || $scopeType === null
                || ($type === $scopeType && (string) ($grant['scope_id'] ?? '') === (string) $scopeId)) {
                return true;
            }
        }

        return false;
    }
}
