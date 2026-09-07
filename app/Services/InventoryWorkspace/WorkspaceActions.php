<?php

namespace App\Services\InventoryWorkspace;

/** Closed contract: no arbitrary route/controller names, administration, transport, or provisioning. */
final class WorkspaceActions
{
    public const ALLOWED = [
        'dashboard',
        'dashboard.alerts',
        'items.index',
        'items.show',
        'items.store',
        'items.update',
        'items.movements',
        'items.valuation',
        'warehouses.index',
        'warehouses.show',
        'warehouses.store',
        'warehouses.update',
        'zones.store',
        'zones.update',
        'bins.store',
        'bins.update',
        'balances.index',
        'ledger.index',
        'audit-logs.index',
        'transfers.index',
        'transfers.show',
        'transfers.store',
        'transfers.update',
        'transfers.post',
        'transfers.ship',
        'transfers.receive',
        'counts.index',
        'counts.show',
        'counts.prefill',
        'counts.store',
        'counts.update',
        'counts.post',
        'adjustments.index',
        'adjustments.show',
        'adjustments.store',
        'adjustments.update',
        'adjustments.post',
        'adjustments.reverse',
        'lots.index',
        'lots.show',
        'lots.movements',
        'serials.index',
        'serials.show',
        'serials.lifecycle',
    ];
}
