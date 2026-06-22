<?php

namespace App\Services\Documents;

use App\Models\Tenant\SalesReturn;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sales return — the inbound counterpart of a shipment. Posting puts physically
 * resellable / quarantine stock BACK via StockLedgerService (IN); damaged units
 * are recorded but NOT returned to on-hand. The IN cost is the cost the units
 * left at (carried on the line as unit_cost), so value reconciles with the
 * shipment's COGS. Records a sales_return.posted outbox event (reverse COGS hint).
 * Never writes stock tables directly; never creates credit notes/accounting.
 */
class SalesReturnService
{
    /** Conditions whose units physically re-enter stock. */
    private const STOCKABLE = ['resellable', 'quarantine'];

    /** Map a return line condition to the resulting serial lifecycle status. */
    private const SERIAL_DISPOSITION = [
        'resellable' => 'available',
        'quarantine' => 'quarantined',
        'damaged' => 'damaged',
        'retired' => 'retired',
    ];

    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private IntegrationOutboxService $outbox,
        private \App\Services\Traceability\SerialService $serials,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    private function postNamespace(SalesReturn $r): string
    {
        return 'sales_return:'.$r->id.':post';
    }

    public function createDraft(array $attributes, array $lines): SalesReturn
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($attributes, $lines, $orgId) {
            $r = new SalesReturn(array_merge([
                'status' => 'draft', 'return_date' => $attributes['return_date'] ?? now()->toDateString(),
            ], $attributes));
            $r->organization_id = $orgId;
            $r->save();
            $this->syncLines($r, $lines, $orgId);

            return $r->fresh('lines');
        });
    }

    public function updateDraft(SalesReturn $r, array $attributes, array $lines): SalesReturn
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($r, $attributes, $lines, $orgId) {
            $r = SalesReturn::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->status !== 'draft') {
                throw new RuntimeException("Only a draft sales return can be edited (status '{$r->status}').");
            }
            $r->fill(collect($attributes)->only(['return_number', 'shipment_id', 'customer_name', 'return_date', 'warehouse_id', 'reason', 'notes'])->toArray());
            $r->save();
            $r->lines()->delete();
            $this->syncLines($r, $lines, $orgId);

            return $r->fresh('lines');
        });
    }

    public function post(SalesReturn $r): SalesReturn
    {
        return DB::connection($this->conn())->transaction(function () use ($r) {
            $r = SalesReturn::query()->lockForUpdate()->with('lines')->findOrFail($r->id);
            if ($r->status === 'posted') {
                return $r; // idempotent
            }
            if ($r->status !== 'draft') {
                throw new RuntimeException("Sales return {$r->id} cannot be posted from status '{$r->status}'.");
            }

            $movements = [];
            foreach ($r->lines as $line) {
                if (! in_array($line->condition, self::STOCKABLE, true)) {
                    continue; // damaged: recorded, not returned to stock
                }
                if (! Decimal::gt((string) $line->returned_qty, '0')) {
                    continue;
                }
                $movements[] = new StockMovement(
                    direction: 'in',
                    itemId: (int) $line->item_id,
                    warehouseId: (int) ($line->warehouse_id ?? $r->warehouse_id),
                    quantity: (string) $line->returned_qty,
                    sourceType: SalesReturn::class,
                    sourceId: (int) $r->id,
                    sourceLineId: (int) $line->id,
                    variantId: $line->variant_id ? (int) $line->variant_id : null,
                    binId: $line->bin_id ? (int) $line->bin_id : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    serialId: $line->serial_id ? (int) $line->serial_id : null,
                    unitCost: Decimal::cost((string) ($line->unit_cost ?? '0')),
                    movedAt: $r->return_date?->toDateTimeString() ?? now()->toDateTimeString(),
                );
            }

            if ($movements !== []) {
                $this->ledger->post($movements, $this->postNamespace($r), [
                    'action' => 'sales_return.post', 'entity_type' => 'sales_return',
                    'entity_id' => $r->id, 'document_ref' => $r->return_number,
                ]);
            }

            // Serial disposition: stockable serials are already set to in_stock by
            // the engine's IN; mark non-stockable (damaged/retired) and quarantine
            // serials to their disposition status so they do not read as available.
            foreach ($r->lines as $line) {
                if (! $line->serial_id) {
                    continue;
                }
                $status = self::SERIAL_DISPOSITION[$line->condition] ?? 'returned';
                $serial = \App\Models\Tenant\SerialNumber::query()->find($line->serial_id);
                if ($serial) {
                    $this->serials->setStatus($serial, $status, ['sales_return_id' => $r->id, 'warehouse_id' => $line->warehouse_id ?? $r->warehouse_id]);
                }
            }

            $r->status = 'posted';
            $r->posted_at = now();
            $r->posted_by = auth()->id();
            $r->posted_guard_key = $this->postNamespace($r);
            $r->markSystemTransition()->save();

            $this->outbox->record('sales_return.posted', $r, 'sales_return', $r->return_number, (string) $r->return_date);

            return $r->fresh('lines');
        });
    }

    private function syncLines(SalesReturn $r, array $lines, int $orgId): void
    {
        foreach ($lines as $line) {
            $r->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? $r->warehouse_id,
                'bin_id' => $line['bin_id'] ?? null,
                'returned_qty' => Decimal::qty((string) $line['returned_qty']),
                'unit_cost' => Decimal::cost((string) ($line['unit_cost'] ?? '0')),
                'condition' => $line['condition'] ?? 'resellable',
                'lot_id' => $line['lot_id'] ?? null,
                'serial_id' => $line['serial_id'] ?? null,
            ]);
        }
    }
}
