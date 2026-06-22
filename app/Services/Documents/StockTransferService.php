<?php

namespace App\Services\Documents;

use App\Models\Tenant\StockTransfer;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock Transfer. Posting emits TWO ledger movements per line: OUT of the source
 * warehouse and IN to the destination, at the source's current cost (captured by
 * the ledger's outbound costing, then re-applied as the inbound unit cost).
 * All writes go through StockLedgerService.
 */
class StockTransferService
{
    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private \App\Services\Integration\IntegrationOutboxService $outbox,
    ) {}

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    private function postNamespace(StockTransfer $t): string
    {
        return 'stock_transfer:'.$t->id.':post';
    }

    private function reverseNamespace(StockTransfer $t): string
    {
        return 'stock_transfer:'.$t->id.':reverse';
    }

    public function createDraft(array $attributes, array $lines): StockTransfer
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($attributes, $lines, $orgId) {
            $t = new StockTransfer(array_merge([
                'status' => 'draft',
                'transfer_date' => $attributes['transfer_date'] ?? now()->toDateString(),
            ], $attributes));
            $t->organization_id = $orgId;

            if ((int) ($attributes['from_warehouse_id'] ?? 0) === (int) ($attributes['to_warehouse_id'] ?? 0)) {
                throw new RuntimeException('Transfer source and destination warehouses must differ.');
            }
            $t->save();

            foreach ($lines as $line) {
                $t->lines()->create([
                    'organization_id' => $orgId,
                    'item_id' => $line['item_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'quantity' => Decimal::qty((string) $line['quantity']),
                    'lot_id' => $line['lot_id'] ?? null,
                    'serial_id' => $line['serial_id'] ?? null,
                    'from_bin_id' => $line['from_bin_id'] ?? null,
                    'to_bin_id' => $line['to_bin_id'] ?? null,
                ]);
            }

            return $t->fresh('lines');
        });
    }

    /** Update a DRAFT transfer: replace header + lines. */
    public function updateDraft(StockTransfer $t, array $attributes, array $lines): StockTransfer
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($t, $attributes, $lines, $orgId) {
            $t = StockTransfer::query()->lockForUpdate()->findOrFail($t->id);
            if ($t->status !== 'draft') {
                throw new RuntimeException("Only a draft transfer can be edited (status '{$t->status}').");
            }
            if ((int) ($attributes['from_warehouse_id'] ?? $t->from_warehouse_id) === (int) ($attributes['to_warehouse_id'] ?? $t->to_warehouse_id)) {
                throw new RuntimeException('Transfer source and destination warehouses must differ.');
            }

            $t->fill(collect($attributes)->only(['transfer_number', 'transfer_date', 'from_warehouse_id', 'to_warehouse_id', 'notes'])->toArray());
            $t->lines()->delete();
            foreach ($lines as $line) {
                $t->lines()->create([
                    'organization_id' => $orgId,
                    'item_id' => $line['item_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'quantity' => Decimal::qty((string) $line['quantity']),
                    'lot_id' => $line['lot_id'] ?? null,
                    'serial_id' => $line['serial_id'] ?? null,
                    'from_bin_id' => $line['from_bin_id'] ?? null,
                    'to_bin_id' => $line['to_bin_id'] ?? null,
                ]);
            }
            $t->save();

            return $t->fresh('lines');
        });
    }

    /**
     * Post the transfer. $overrides carries permission-gated trace overrides for
     * the OUT leg: ['allow_expired_lot' => bool, 'allow_quarantined_lot' => bool].
     */
    public function post(StockTransfer $t, array $overrides = []): StockTransfer
    {
        $allowExpired = (bool) ($overrides['allow_expired_lot'] ?? false);
        $allowQuarantined = (bool) ($overrides['allow_quarantined_lot'] ?? false);

        return DB::connection($this->connection())->transaction(function () use ($t, $allowExpired, $allowQuarantined) {
            $t = StockTransfer::query()->lockForUpdate()->findOrFail($t->id);
            if ($t->isPosted() || $t->status === 'in_transit' || $t->status === 'received') {
                return $t; // idempotent
            }
            if ($t->status !== 'draft') {
                throw new RuntimeException("Transfer {$t->id} cannot be posted from status '{$t->status}'.");
            }

            $t->loadMissing('lines');
            $movements = [];
            foreach ($t->lines as $line) {
                // OUT of source — engine costs it (average/FIFO) and that cost is
                // what the matching IN should carry. We post OUT first, then read
                // its unit cost for the IN, all in one transaction.
                $out = $this->ledger->post([
                    new StockMovement(
                        direction: 'out',
                        itemId: (int) $line->item_id,
                        warehouseId: (int) $t->from_warehouse_id,
                        quantity: (string) $line->quantity,
                        sourceType: StockTransfer::class,
                        sourceId: (int) $t->id,
                        sourceLineId: (int) $line->id,
                        variantId: $line->variant_id ? (int) $line->variant_id : null,
                        binId: $line->from_bin_id ? (int) $line->from_bin_id : null,
                        lotId: $line->lot_id ? (int) $line->lot_id : null,
                        serialId: $line->serial_id ? (int) $line->serial_id : null,
                        movedAt: $t->transfer_date?->toDateTimeString() ?? now()->toDateTimeString(),
                        allowExpiredLot: $allowExpired,
                        allowQuarantinedLot: $allowQuarantined,
                    ),
                ], $this->postNamespace($t).':out:'.$line->id, [
                    'action' => 'stock_transfer.out',
                    'entity_type' => 'stock_transfer',
                    'entity_id' => $t->id,
                    'document_ref' => $t->transfer_number,
                ]);

                $outRow = is_array($out) ? ($out[0] ?? null) : null;
                $movedAt = $t->transfer_date?->toDateTimeString() ?? now()->toDateTimeString();
                $inAudit = [
                    'action' => 'stock_transfer.in',
                    'entity_type' => 'stock_transfer',
                    'entity_id' => $t->id,
                    'document_ref' => $t->transfer_number,
                ];
                $mkIn = fn (string $qty, string $unitCost) => new StockMovement(
                    direction: 'in',
                    itemId: (int) $line->item_id,
                    warehouseId: (int) $t->to_warehouse_id,
                    quantity: $qty,
                    sourceType: StockTransfer::class,
                    sourceId: (int) $t->id,
                    sourceLineId: (int) $line->id,
                    variantId: $line->variant_id ? (int) $line->variant_id : null,
                    binId: $line->to_bin_id ? (int) $line->to_bin_id : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    serialId: $line->serial_id ? (int) $line->serial_id : null,
                    unitCost: $unitCost,
                    movedAt: $movedAt,
                );

                // FIFO: recreate ONE destination layer per consumed SOURCE layer
                // (at that layer's cost) so the destination keeps the same cost-
                // layer structure rather than collapsing to one blended layer.
                // Average (or no layers): a single IN at the outbound cost.
                $consumed = $outRow
                    ? \App\Models\Tenant\CostLayerConsumption::query()->where('ledger_id', $outRow->id)->orderBy('id')->get()
                    : collect();

                if ($consumed->isNotEmpty()) {
                    foreach ($consumed as $i => $c) {
                        $this->ledger->post([$mkIn((string) $c->qty, (string) $c->unit_cost)],
                            $this->postNamespace($t).':in:'.$line->id.':'.$i, $inAudit);
                    }
                } else {
                    $this->ledger->post([$mkIn((string) $line->quantity, $outRow ? (string) $outRow->unit_cost : '0')],
                        $this->postNamespace($t).':in:'.$line->id, $inAudit);
                }
            }

            $t->status = 'posted';
            $t->posted_at = now();
            $t->posted_by = auth()->id();
            $t->posted_guard_key = $this->postNamespace($t);
            $t->markSystemTransition()->save();

            $this->outbox->record('transfer.posted', $t, 'stock_transfer', $t->transfer_number, (string) $t->transfer_date);

            return $t;
        });
    }
}
