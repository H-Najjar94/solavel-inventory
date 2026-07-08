<?php

namespace App\Services\Alerts;

use App\Models\Tenant\InventoryAlert;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryAlertService
{
    public function __construct(private OrganizationContext $context) {}

    /** Refresh in-app/email-ready exception alerts from canonical projections. */
    public function refresh(): Collection
    {
        $orgId = $this->context->idOrFail();
        $now = now();
        $openKeys = [];

        $lowRows = DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->table('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('warehouse_reorder_rules as rr', function ($join) {
                $join->on('rr.item_id', '=', 'b.item_id')
                    ->on('rr.warehouse_id', '=', 'b.warehouse_id')
                    ->on('rr.organization_id', '=', 'b.organization_id');
            })
            ->where('b.organization_id', $orgId)
            ->whereRaw('COALESCE(rr.reorder_point, i.reorder_point) IS NOT NULL')
            ->whereRaw('(b.on_hand_qty - b.reserved_qty) <= COALESCE(rr.reorder_point, i.reorder_point)')
            ->selectRaw('i.id item_id, i.sku, i.name item, w.name warehouse, b.warehouse_id,
                (b.on_hand_qty - b.reserved_qty) available_qty, COALESCE(rr.reorder_point, i.reorder_point) reorder_point')
            ->limit(50)
            ->get();

        foreach ($lowRows as $row) {
            $key = "low-stock:{$row->item_id}:{$row->warehouse_id}";
            $openKeys[] = $key;
            InventoryAlert::query()->updateOrCreate(
                ['alert_key' => $key],
                [
                    'type' => ((float) $row->available_qty <= 0) ? 'out_of_stock' : 'low_stock',
                    'severity' => ((float) $row->available_qty <= 0) ? 'critical' : 'warning',
                    'title' => ((float) $row->available_qty <= 0) ? 'Out of stock' : 'Low stock',
                    'message' => "{$row->sku} {$row->item} has {$row->available_qty} available in {$row->warehouse}; reorder point {$row->reorder_point}.",
                    'route' => "/reports?report=low-stock&item_id={$row->item_id}&warehouse_id={$row->warehouse_id}",
                    'channels' => ['in_app', 'email'],
                    'metadata' => [
                        'item_id' => (int) $row->item_id,
                        'warehouse_id' => (int) $row->warehouse_id,
                        'available_qty' => (string) $row->available_qty,
                        'reorder_point' => (string) $row->reorder_point,
                    ],
                    'status' => 'open',
                    'triggered_at' => $now,
                    'acknowledged_at' => null,
                    'acknowledged_by' => null,
                ],
            );
        }

        $expiryRows = DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->table('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->join('lots as l', 'l.id', '=', 'b.lot_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.organization_id', $orgId)
            ->where('b.on_hand_qty', '>', 0)
            ->whereNotNull('l.expiry_date')
            ->whereDate('l.expiry_date', '<=', now()->addDays(30)->toDateString())
            ->selectRaw('i.id item_id, i.sku, i.name item, w.name warehouse, b.warehouse_id, l.id lot_id, l.lot_code, l.expiry_date, b.on_hand_qty')
            ->limit(50)
            ->get();

        foreach ($expiryRows as $row) {
            $expired = (string) $row->expiry_date < now()->toDateString();
            $key = "expiry:{$row->lot_id}:{$row->warehouse_id}";
            $openKeys[] = $key;
            InventoryAlert::query()->updateOrCreate(
                ['alert_key' => $key],
                [
                    'type' => $expired ? 'expired_lot' : 'expiring_lot',
                    'severity' => $expired ? 'critical' : 'warning',
                    'title' => $expired ? 'Expired stock on hand' : 'Lot expiring soon',
                    'message' => "Lot {$row->lot_code} for {$row->sku} expires on {$row->expiry_date} with {$row->on_hand_qty} on hand in {$row->warehouse}.",
                    'route' => "/traceability/lots/{$row->lot_id}",
                    'channels' => ['in_app', 'email'],
                    'metadata' => [
                        'item_id' => (int) $row->item_id,
                        'warehouse_id' => (int) $row->warehouse_id,
                        'lot_id' => (int) $row->lot_id,
                        'expiry_date' => (string) $row->expiry_date,
                        'on_hand_qty' => (string) $row->on_hand_qty,
                    ],
                    'status' => 'open',
                    'triggered_at' => $now,
                    'acknowledged_at' => null,
                    'acknowledged_by' => null,
                ],
            );
        }

        if ($openKeys !== []) {
            InventoryAlert::query()
                ->whereNotIn('alert_key', $openKeys)
                ->where('status', 'open')
                ->update(['status' => 'resolved']);
        }

        return InventoryAlert::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('triggered_at')
            ->limit(100)
            ->get();
    }
}
