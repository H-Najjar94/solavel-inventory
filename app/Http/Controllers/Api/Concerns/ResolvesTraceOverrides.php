<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\Access\InventoryPermissionService;
use Illuminate\Http\Request;

/**
 * Resolves permission-gated lot trace overrides from a request. An override is
 * honoured ONLY when the user both asked for it AND holds the matching
 * permission — a client cannot self-grant a bypass of the expired/quarantine
 * shipping block.
 */
trait ResolvesTraceOverrides
{
    protected function resolveTraceOverrides(Request $request): array
    {
        $perms = app(InventoryPermissionService::class);
        $user = $request->user();

        return [
            'allow_expired_lot' => $request->boolean('allow_expired_lot')
                && $perms->can($user, 'inventory.override_expired_lot'),
            'allow_quarantined_lot' => $request->boolean('allow_quarantined_lot')
                && $perms->can($user, 'inventory.override_quarantine'),
        ];
    }
}
