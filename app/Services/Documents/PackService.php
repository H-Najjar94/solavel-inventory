<?php

namespace App\Services\Documents;

use App\Models\Tenant\Pack;
use App\Models\Tenant\PickList;
use App\Models\Tenant\SalesOrder;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pack — cartonize picked stock for shipment. Packing does NOT move stock; it
 * records what went into each package and advances fulfillment state. The OUT
 * happens only at shipment.
 */
class PackService
{
    public function __construct(
        private OrganizationContext $context,
        private IntegrationOutboxService $outbox,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /** Generate a pack from a finalized pick list's picked lines. */
    public function createFromPickList(PickList $pl, array $attributes = []): Pack
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($pl, $attributes, $orgId) {
            $pl = PickList::query()->with('lines')->findOrFail($pl->id);
            if ($pl->status !== 'picked') {
                throw new RuntimeException("Pick list {$pl->id} must be picked before packing (status '{$pl->status}').");
            }

            $pack = new Pack(array_merge([
                'status' => 'draft',
                'pack_number' => $attributes['pack_number'] ?? null,
                'package_count' => $attributes['package_count'] ?? 1,
            ], $attributes));
            $pack->organization_id = $orgId;
            $pack->sales_order_id = $pl->sales_order_id;
            $pack->pick_list_id = $pl->id;
            $pack->save();

            foreach ($pl->lines as $line) {
                if (! Decimal::gt((string) $line->picked_qty, '0')) {
                    continue;
                }
                $pack->lines()->create([
                    'organization_id' => $orgId,
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'item_id' => $line->item_id,
                    'picked_qty' => Decimal::qty((string) $line->picked_qty),
                    'packed_qty' => '0',
                    'package_number' => 1,
                ]);
            }

            return $pack->fresh('lines');
        });
    }

    /** Record packed quantities ([pack_line_id => qty]) and package metadata. */
    public function updatePacks(Pack $pack, array $packs, array $attributes = []): Pack
    {
        return DB::connection($this->conn())->transaction(function () use ($pack, $packs, $attributes) {
            $pack = Pack::query()->lockForUpdate()->with('lines')->findOrFail($pack->id);
            if (in_array($pack->status, ['packed', 'cancelled'], true)) {
                throw new RuntimeException("Pack {$pack->id} is {$pack->status} and cannot be edited.");
            }
            if ($attributes) {
                $pack->fill(collect($attributes)->only(['package_count', 'package_weight', 'carrier', 'tracking_number', 'notes'])->toArray());
                $pack->save();
            }
            foreach ($pack->lines as $line) {
                if (array_key_exists($line->id, $packs)) {
                    $qty = Decimal::qty((string) $packs[$line->id]);
                    if (Decimal::gt($qty, (string) $line->picked_qty)) {
                        throw new RuntimeException("Cannot pack {$qty} for line {$line->id}: only {$line->picked_qty} picked.");
                    }
                    $line->packed_qty = $qty;
                    $line->save();
                }
            }

            return $pack->fresh('lines');
        });
    }

    /** Finalize the pack: mark packed, roll up SO line packed_qty, emit event. */
    public function markPacked(Pack $pack): Pack
    {
        return DB::connection($this->conn())->transaction(function () use ($pack) {
            $pack = Pack::query()->lockForUpdate()->with('lines')->findOrFail($pack->id);
            if ($pack->status === 'packed') {
                return $pack;
            }
            if ($pack->status === 'cancelled') {
                throw new RuntimeException("Cancelled pack {$pack->id} cannot be marked packed.");
            }
            $pack->status = 'packed';
            $pack->save();

            $this->rollUpSalesOrder($pack);
            $this->outbox->record('pack.packed', $pack, 'pack', $pack->pack_number);

            return $pack->fresh('lines');
        });
    }

    private function rollUpSalesOrder(Pack $pack): void
    {
        $so = SalesOrder::query()->with('lines')->find($pack->sales_order_id);
        if (! $so) {
            return;
        }
        foreach ($pack->lines as $line) {
            if (! $line->sales_order_line_id) {
                continue;
            }
            $soLine = $so->lines->firstWhere('id', $line->sales_order_line_id);
            if ($soLine) {
                $soLine->packed_qty = Decimal::qty((string) $line->packed_qty);
                $soLine->save();
            }
        }
        $allPacked = $so->lines->every(fn ($l) => Decimal::gte((string) $l->packed_qty, (string) $l->ordered_qty));
        $so->status = $allPacked ? 'packed' : 'packing';
        $so->save();
    }
}
