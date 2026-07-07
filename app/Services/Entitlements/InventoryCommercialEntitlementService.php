<?php

namespace App\Services\Entitlements;

class InventoryCommercialEntitlementService
{
    public function __construct(private EntitlementsCache $cache)
    {
    }

    /**
     * @return array{allowed:bool,status:int,reason_code:string,access_mode:string,tier:?string,feature:?string,snapshot:array<string,mixed>|null}
     */
    public function checkPermission(string $permission): array
    {
        $feature = $this->featureForPermission($permission);
        $freePermissions = (array) config('inventory_entitlements.free_permissions', []);
        $safePermissions = (array) config('inventory_entitlements.restricted_safe_permissions', []);

        $clientId = $this->cache->currentClientId();
        $snapshot = $clientId ? $this->cache->getProjectSnapshot($clientId) : null;

        if (! $snapshot) {
            return $this->decision(
                in_array($permission, $freePermissions, true),
                'entitlement_service_unavailable',
                'limited',
                null,
                $feature,
                null
            );
        }

        $meta = (array) ($snapshot['_snapshot'] ?? []);
        if ((bool) ($meta['beyond_max_stale'] ?? false)) {
            return $this->decision(
                in_array($permission, $safePermissions, true),
                'entitlement_verification_stale',
                'restricted_safe_mode',
                $snapshot,
                $feature,
                $meta
            );
        }

        $reason = (string) ($snapshot['reason_code'] ?? ($meta['stale'] ?? false ? 'snapshot_stale' : 'paid_active'));
        $accessMode = (string) ($snapshot['access_mode'] ?? 'full');
        $tier = (string) ($snapshot['effective_tier'] ?? $snapshot['tier'] ?? '');

        if ((bool) ($meta['stale'] ?? false)) {
            $reason = 'snapshot_stale';
        }

        if ($accessMode === 'blocked') {
            return $this->decision(
                in_array($permission, $freePermissions, true) && $this->hasFreeFallback($snapshot),
                $reason ?: 'paid_expired_no_fallback',
                $accessMode,
                $snapshot,
                $feature,
                $meta
            );
        }

        if ($feature === null || in_array($permission, $freePermissions, true)) {
            return $this->decision(true, $reason, $accessMode, $snapshot, null, $meta);
        }

        if ($this->featureAllowed($snapshot, $feature)) {
            return $this->decision(true, $reason, $accessMode, $snapshot, $feature, $meta);
        }

        return $this->decision(false, 'feature_not_in_plan', $accessMode, $snapshot, $feature, $meta);
    }

    private function featureForPermission(string $permission): ?string
    {
        $map = (array) config('inventory_entitlements.permission_features', []);

        return isset($map[$permission]) ? (string) $map[$permission] : null;
    }

    private function featureAllowed(array $snapshot, string $feature): bool
    {
        $blocked = (array) ($snapshot['blocked_features'] ?? []);
        if (in_array($feature, $blocked, true)) {
            return false;
        }

        $allowed = (array) ($snapshot['allowed_features'] ?? []);
        if (in_array($feature, $allowed, true)) {
            return true;
        }

        $features = (array) ($snapshot['features'] ?? []);
        if (array_key_exists($feature, $features)) {
            $value = is_array($features[$feature]) ? ($features[$feature]['value'] ?? false) : $features[$feature];

            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (string) ($snapshot['access_mode'] ?? 'full') === 'full'
            && ! in_array((string) ($snapshot['effective_tier'] ?? $snapshot['tier'] ?? 'free'), ['free', ''], true);
    }

    private function hasFreeFallback(array $snapshot): bool
    {
        return in_array((string) ($snapshot['reason_code'] ?? ''), ['free_native', 'free_tier', 'paid_expired_free_fallback'], true)
            || in_array((string) ($snapshot['effective_tier'] ?? $snapshot['tier'] ?? ''), ['free'], true);
    }

    private function decision(bool $allowed, string $reason, string $mode, ?array $snapshot, ?string $feature, ?array $meta = null): array
    {
        return [
            'allowed' => $allowed,
            'status' => $allowed ? 200 : 402,
            'reason_code' => $reason,
            'access_mode' => $mode,
            'tier' => $snapshot['effective_tier'] ?? $snapshot['tier'] ?? null,
            'feature' => $feature,
            'snapshot' => $meta ?? ($snapshot['_snapshot'] ?? null),
        ];
    }
}
