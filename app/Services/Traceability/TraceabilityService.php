<?php

namespace App\Services\Traceability;

use App\Models\Tenant\Lot;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/**
 * Read-only where-used traceability built from the canonical ledger + balances.
 * Answers "where did this lot/serial come from, where is it now, where did it go".
 * Never writes stock or traceability tables.
 */
class TraceabilityService
{
    public function __construct(private OrganizationContext $context) {}

    private function db()
    {
        return DB::connection(config('tenancy.tenant_connection', 'tenant'));
    }

    /** Full trace for a lot: summary, quantities, locations, source docs, movements. */
    public function lotTrace(Lot $lot): array
    {
        $movements = StockLedger::query()->where('lot_id', $lot->id)->orderBy('moved_at')->orderBy('id')->get();

        $receivedQty = (string) $movements->where('direction', 'in')->sum(fn ($m) => (float) $m->quantity);
        $issuedQty = (string) $movements->where('direction', 'out')->sum(fn ($m) => (float) $m->quantity);
        $onHand = Decimal::qty(Decimal::sub($receivedQty, $issuedQty));

        $balances = $this->db()->table('stock_balances as b')
            ->where('b.organization_id', (int) $this->context->id()) // SECURITY: org scope (raw query bypasses global scope)
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('warehouse_bins as bin', 'bin.id', '=', 'b.bin_id')
            ->where('b.lot_id', $lot->id)
            ->selectRaw('w.name warehouse, bin.code bin, b.warehouse_id, b.bin_id, b.on_hand_qty, b.reserved_qty')
            ->get();

        $reserved = (string) $balances->sum(fn ($b) => (float) $b->reserved_qty);

        $sources = $this->groupBySource($movements->where('direction', 'in'));
        $shipments = $this->groupBySource($movements->where('direction', 'out')->where('source_type', 'like', '%Shipment'));
        $returns = $this->groupBySource($movements->where('direction', 'in')->where('source_type', 'like', '%SalesReturn'));
        $transfers = $this->groupBySource($movements->where('source_type', 'like', '%Transfer'));

        return [
            'lot' => $lot,
            'item' => $lot->item,
            'expiry_status' => $lot->effectiveStatus(),
            'is_expired' => $lot->isExpired(),
            'recall_status' => $lot->status === 'recalled' ? 'recalled' : 'none',
            'quantities' => [
                'received' => Decimal::qty($receivedQty),
                'on_hand' => $onHand,
                'reserved' => Decimal::qty($reserved),
                'issued' => Decimal::qty($issuedQty),
            ],
            'locations' => $balances,
            'sources' => $sources,
            'transfers' => $transfers,
            'shipments' => $shipments,
            'returns' => $returns,
            'movements' => $movements,
        ];
    }

    /** Full lifecycle for a serial: timeline, receipt source, ship/return history. */
    public function serialTrace(SerialNumber $serial): array
    {
        $movements = StockLedger::query()->where('serial_id', $serial->id)->orderBy('moved_at')->orderBy('id')->get();

        $timeline = $movements->map(fn ($m) => [
            'at' => $m->moved_at,
            'direction' => $m->direction,
            'event' => $m->direction === 'in' ? 'Received / returned' : 'Issued / shipped',
            'source_type' => class_basename($m->source_type),
            'source_id' => $m->source_id,
            'warehouse_id' => $m->warehouse_id,
        ])->values();

        return [
            'serial' => $serial,
            'item' => $serial->item,
            'lifecycle_status' => $serial->lifecycleStatus(),
            'current_location' => ['warehouse_id' => $serial->warehouse_id, 'bin_id' => $serial->bin_id],
            'receipt_source' => $movements->firstWhere('direction', 'in'),
            'shipments' => $this->groupBySource($movements->where('direction', 'out')),
            'returns' => $this->groupBySource($movements->where('direction', 'in')->where('source_type', 'like', '%SalesReturn')),
            'warranty_until' => $serial->warranty_until,
            'owner_ref' => $serial->owner_ref,
            'timeline' => $timeline,
            'movements' => $movements,
        ];
    }

    /** Lot availability across warehouses/bins (for OUT selectors). */
    public function lotAvailability(int $itemId, ?int $warehouseId = null): array
    {
        $rows = $this->db()->table('stock_balances as b')
            ->where('b.organization_id', (int) $this->context->id()) // SECURITY: org scope (raw query bypasses global scope)
            ->join('lots as l', 'l.id', '=', 'b.lot_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.item_id', $itemId)
            ->where('b.on_hand_qty', '>', 0)
            ->when($warehouseId, fn ($q) => $q->where('b.warehouse_id', $warehouseId))
            ->selectRaw('l.id lot_id, l.lot_code, l.expiry_date, l.status, b.warehouse_id, w.name warehouse, b.bin_id, b.on_hand_qty, b.reserved_qty')
            ->orderBy('l.expiry_date')->get();

        return $rows->all();
    }

    /**
     * Suggest lots to consume for an outbound movement. Sorts by the picking
     * policy: FEFO (earliest expiry first) for expiry-tracked items or when the
     * org policy is 'fefo'; FIFO (earliest received) for 'fifo'; otherwise the raw
     * availability order. Greedily allocates up to `quantity` and flags whether
     * the chosen set covers it. Suggestion only — never forces selection.
     *
     * @return array{policy:string, lines:array, fully_covered:bool, shortfall:string}
     */
    public function suggestOutboundLots(int $itemId, ?int $warehouseId, string $quantity, ?bool $tracksExpiry = null): array
    {
        $rows = $this->lotAvailability($itemId, $warehouseId);
        // Only shippable lots are eligible for a suggestion.
        $rows = array_values(array_filter($rows, fn ($r) => ! in_array($r->status, ['expired', 'quarantined', 'recalled'], true)));

        $policy = $this->pickingPolicy();
        if ($tracksExpiry === null) {
            $tracksExpiry = (bool) \App\Models\Tenant\Item::query()->where('id', $itemId)->value('tracks_expiry');
        }
        $effective = $policy === 'manual' && $tracksExpiry ? 'fefo' : $policy;

        $sorted = self::sortLotsForPolicy($rows, $effective);

        $remaining = Decimal::qty($quantity);
        $lines = [];
        foreach ($sorted as $r) {
            if (! Decimal::gt($remaining, '0')) {
                break;
            }
            $available = Decimal::sub((string) $r->on_hand_qty, (string) ($r->reserved_qty ?? '0'));
            if (! Decimal::gt($available, '0')) {
                continue;
            }
            $take = Decimal::lt($available, $remaining) ? $available : $remaining;
            $lines[] = [
                'lot_id' => $r->lot_id, 'lot_code' => $r->lot_code, 'expiry_date' => $r->expiry_date,
                'warehouse_id' => $r->warehouse_id, 'bin_id' => $r->bin_id,
                'suggested_qty' => Decimal::qty($take), 'available' => Decimal::qty($available),
            ];
            $remaining = Decimal::sub($remaining, $take);
        }

        return [
            'policy' => $effective,
            'lines' => $lines,
            'fully_covered' => ! Decimal::gt($remaining, '0'),
            'shortfall' => Decimal::gt($remaining, '0') ? Decimal::qty($remaining) : '0.0000',
        ];
    }

    /**
     * Pure sorter for lot rows by picking policy. FEFO: nulls-last by expiry then
     * lot_id; FIFO: by lot_id (proxy for receipt order); manual: unchanged.
     * Accepts arrays or objects with lot_id / expiry_date.
     */
    public static function sortLotsForPolicy(array $rows, string $policy): array
    {
        $get = fn ($r, $k) => is_array($r) ? ($r[$k] ?? null) : ($r->{$k} ?? null);
        if ($policy === 'fefo') {
            usort($rows, function ($a, $b) use ($get) {
                $ea = $get($a, 'expiry_date');
                $eb = $get($b, 'expiry_date');
                if ($ea === $eb) {
                    return (int) $get($a, 'lot_id') <=> (int) $get($b, 'lot_id');
                }
                if ($ea === null) {
                    return 1; // no expiry sorts last
                }
                if ($eb === null) {
                    return -1;
                }

                return strcmp((string) $ea, (string) $eb);
            });
        } elseif ($policy === 'fifo') {
            usort($rows, fn ($a, $b) => (int) $get($a, 'lot_id') <=> (int) $get($b, 'lot_id'));
        }

        return $rows;
    }

    public function pickingPolicy(): string
    {
        return (string) (\App\Models\Tenant\InventorySetting::query()->value('picking_policy') ?? 'manual');
    }

    /** Serials currently available for an item (for OUT selectors). */
    public function serialAvailability(int $itemId, ?int $warehouseId = null): array
    {
        return SerialNumber::query()->where('item_id', $itemId)
            ->whereIn('status', ['available', 'in_stock', 'returned'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('serial')->limit(500)->get()->all();
    }

    /** Group ledger movements by source document for the where-used panels. */
    private function groupBySource($movements): array
    {
        return collect($movements)->groupBy(fn ($m) => $m->source_type.'#'.$m->source_id)
            ->map(fn ($g) => [
                'source_type' => class_basename($g->first()->source_type),
                'source_id' => $g->first()->source_id,
                'qty' => Decimal::qty((string) $g->sum(fn ($m) => (float) $m->quantity)),
                'at' => $g->first()->moved_at,
            ])->values()->all();
    }
}
