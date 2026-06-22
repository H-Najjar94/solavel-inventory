<?php

namespace App\Services\Documents;

use App\Models\Tenant\PickList;
use App\Models\Tenant\SalesOrder;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pick list — warehouse instruction to gather reserved stock. Picking does NOT
 * move stock (units are still on-hand, just staged); it advances fulfillment
 * state and records picked_qty. The OUT happens only at shipment.
 */
class PickListService
{
    public function __construct(
        private OrganizationContext $context,
        private IntegrationOutboxService $outbox,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /** Generate a pick list from a sales order's reserved lines. */
    public function createFromSalesOrder(SalesOrder $so, array $attributes = []): PickList
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($so, $attributes, $orgId) {
            $so = SalesOrder::query()->with('lines')->findOrFail($so->id);
            $pl = new PickList(array_merge([
                'status' => 'draft',
                'pick_number' => $attributes['pick_number'] ?? null,
            ], $attributes));
            $pl->organization_id = $orgId;
            $pl->sales_order_id = $so->id;
            $pl->warehouse_id = $attributes['warehouse_id'] ?? $so->warehouse_id;
            $pl->save();

            foreach ($so->lines as $line) {
                $reserved = Decimal::qty((string) $line->reserved_qty);
                if (! Decimal::gt($reserved, '0')) {
                    continue;
                }
                $pl->lines()->create([
                    'organization_id' => $orgId,
                    'sales_order_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id ?? $so->warehouse_id,
                    'bin_id' => $line->bin_id,
                    'reserved_qty' => $reserved,
                    'picked_qty' => '0',
                    'status' => 'open',
                ]);
            }

            return $pl->fresh('lines');
        });
    }

    /** Record picked quantities ([pick_list_line_id => qty]) without finalizing. */
    public function updatePicks(PickList $pl, array $picks): PickList
    {
        return DB::connection($this->conn())->transaction(function () use ($pl, $picks) {
            $pl = PickList::query()->lockForUpdate()->with('lines')->findOrFail($pl->id);
            if (in_array($pl->status, ['picked', 'cancelled'], true)) {
                throw new RuntimeException("Pick list {$pl->id} is {$pl->status} and cannot be edited.");
            }
            foreach ($pl->lines as $line) {
                if (array_key_exists($line->id, $picks)) {
                    $qty = Decimal::qty((string) $picks[$line->id]);
                    if (Decimal::gt($qty, (string) $line->reserved_qty)) {
                        throw new RuntimeException("Cannot pick {$qty} for line {$line->id}: only {$line->reserved_qty} reserved.");
                    }
                    $line->picked_qty = $qty;
                    $line->status = Decimal::gte($qty, (string) $line->reserved_qty) ? 'picked' : 'partial';
                    $line->save();
                }
            }
            if ($pl->status === 'draft') {
                $pl->status = 'picking';
                $pl->save();
            }

            return $pl->fresh('lines');
        });
    }

    /** Finalize the pick: mark picked, roll up SO line picked_qty, emit event. */
    public function markPicked(PickList $pl): PickList
    {
        return DB::connection($this->conn())->transaction(function () use ($pl) {
            $pl = PickList::query()->lockForUpdate()->with('lines')->findOrFail($pl->id);
            if ($pl->status === 'picked') {
                return $pl;
            }
            if ($pl->status === 'cancelled') {
                throw new RuntimeException("Cancelled pick list {$pl->id} cannot be marked picked.");
            }
            $pl->status = 'picked';
            $pl->save();

            $this->rollUpSalesOrder($pl);
            $this->outbox->record('pick_list.picked', $pl, 'pick_list', $pl->pick_number);

            return $pl->fresh('lines');
        });
    }

    private function rollUpSalesOrder(PickList $pl): void
    {
        $so = SalesOrder::query()->with('lines')->find($pl->sales_order_id);
        if (! $so) {
            return;
        }
        foreach ($pl->lines as $line) {
            if (! $line->sales_order_line_id) {
                continue;
            }
            $soLine = $so->lines->firstWhere('id', $line->sales_order_line_id);
            if ($soLine) {
                $soLine->picked_qty = Decimal::qty((string) $line->picked_qty);
                $soLine->save();
            }
        }
        $allPicked = $so->lines->every(fn ($l) => Decimal::gte((string) $l->picked_qty, (string) $l->ordered_qty));
        $so->status = $allPicked ? 'picked' : 'partially_picked';
        $so->save();
    }
}
