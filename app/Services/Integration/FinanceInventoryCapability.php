<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;

/** Reads Central's organization capability; neither plan labels nor service keys confer access. */
class FinanceInventoryCapability
{
    public function allows(int $clientId, int $organizationId): bool
    {
        $row = DB::connection((string) config('tenancy.central_connection', 'mysql'))
            ->table('entitlement_state_snapshots')->where('organization_id', $organizationId)->first();
        $state = $row ? json_decode((string) $row->state_payload, true) : null;

        return $this->allowsState($state, $clientId, $organizationId);
    }

    /** The caller must additionally enforce each app's verified paid-through window. */
    public function allowsState(?array $state, int $clientId, int $organizationId): bool
    {
        if ($clientId < 1 || $organizationId < 1 || ! is_array($state)
            || (int) ($state['client_id'] ?? 0) !== $clientId
            || (int) ($state['organization_id'] ?? 0) !== $organizationId
            || ($state['integration_capabilities']['connection_activation_delivery_entitled'] ?? false) !== true) {
            return false;
        }
        foreach (['finance', 'inventory'] as $slug) {
            $app = $state['applications'][$slug] ?? null;
            if (! is_array($app) || ($app['accessible'] ?? false) !== true
                || ($app['commercially_entitled'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
