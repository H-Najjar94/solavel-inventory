<?php

namespace App\Services\Documents;

use App\Models\Tenant\StockAdjustment;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONE stock-adjustment implementation: draft → posted → reversed.
 * Supports increase and decrease lines; reason code; lot/serial; idempotency;
 * posted immutability; reversal via opposite ledger entries.
 *
 * Delegates ALL stock writes to StockLedgerService. Never writes stock tables.
 */
class StockAdjustmentService
{
    use \App\Services\Documents\Concerns\CapturesTraceability;

    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private \App\Services\Integration\IntegrationOutboxService $outbox,
        private \App\Services\Traceability\LotService $lots,
        private \App\Services\Traceability\SerialService $serials,
    ) {}

    protected function lotService(): \App\Services\Traceability\LotService
    {
        return $this->lots;
    }

    protected function serialService(): \App\Services\Traceability\SerialService
    {
        return $this->serials;
    }

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    private function postNamespace(StockAdjustment $adj): string
    {
        return 'stock_adjustment:'.$adj->id.':post';
    }

    private function reverseNamespace(StockAdjustment $adj): string
    {
        return 'stock_adjustment:'.$adj->id.':reverse';
    }

    /** Create a draft adjustment with increase/decrease lines. */
    public function createDraft(array $attributes, array $lines): StockAdjustment
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($attributes, $lines, $orgId) {
            $adj = new StockAdjustment(array_merge([
                'status' => 'draft',
                'adjustment_date' => $attributes['adjustment_date'] ?? now()->toDateString(),
            ], $attributes));
            $adj->organization_id = $orgId;
            $adj->save();

            [$totalInc, $totalDec] = $this->buildLines($adj, $lines, $orgId);
            $adj->total_increase_value = $totalInc;
            $adj->total_decrease_value = $totalDec;
            $adj->markSystemTransition()->save();

            return $adj->fresh('lines');
        });
    }

    /** Update a DRAFT adjustment: replace header + lines. */
    public function updateDraft(StockAdjustment $adj, array $attributes, array $lines): StockAdjustment
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($adj, $attributes, $lines, $orgId) {
            $adj = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->id);
            if ($adj->status !== 'draft') {
                throw new RuntimeException("Only a draft adjustment can be edited (status '{$adj->status}').");
            }

            $adj->fill(collect($attributes)->only(['adjustment_number', 'adjustment_date', 'warehouse_id', 'reason_code', 'notes'])->toArray());
            $adj->lines()->delete();

            [$totalInc, $totalDec] = $this->buildLines($adj, $lines, $orgId);
            $adj->total_increase_value = $totalInc;
            $adj->total_decrease_value = $totalDec;
            $adj->markSystemTransition()->save();

            return $adj->fresh('lines');
        });
    }

    /**
     * Persist adjustment lines. Increase lines resolve lot/serial CAPTURE (mint
     * lots/serials, expand serials to qty-1 lines). Decrease lines carry an
     * already-SELECTED lot_id/serial_id (chosen from availability). Returns
     * [totalIncreaseValue, totalDecreaseValue].
     *
     * @return array{0:string,1:string}
     */
    private function buildLines(StockAdjustment $adj, array $lines, int $orgId): array
    {
        $totalInc = '0';
        $totalDec = '0';
        foreach ($lines as $line) {
            $direction = $line['direction'] ?? null;
            if (! in_array($direction, ['increase', 'decrease'], true)) {
                throw new RuntimeException("Adjustment line direction must be 'increase' or 'decrease'.");
            }
            $unitCost = Decimal::cost((string) ($line['unit_cost'] ?? '0'));

            // Increase + serial capture → one qty-1 line per captured serial.
            if ($direction === 'increase') {
                $cap = $this->resolveCapture($line, $orgId, StockAdjustment::class, (int) $adj->id);
                if ($cap['serial_ids'] !== []) {
                    foreach ($cap['serial_ids'] as $sid) {
                        $totalInc = Decimal::add($totalInc, $unitCost);
                        $this->createLine($adj, $orgId, $line, 'increase', '1.0000', $unitCost, $cap['lot_id'], $sid);
                    }

                    continue;
                }
                $qty = $this->requirePositiveQty($line);
                $totalInc = Decimal::add($totalInc, Decimal::money(Decimal::mul($qty, $unitCost)));
                $this->createLine($adj, $orgId, $line, 'increase', $qty, $unitCost, $cap['lot_id'], $line['serial_id'] ?? null);

                continue;
            }

            // Decrease → lot/serial already selected from availability.
            $qty = $this->requirePositiveQty($line);
            $totalDec = Decimal::add($totalDec, Decimal::money(Decimal::mul($qty, $unitCost)));
            $this->createLine($adj, $orgId, $line, 'decrease', $qty, $unitCost, $line['lot_id'] ?? null, $line['serial_id'] ?? null);
        }

        return [Decimal::money($totalInc), Decimal::money($totalDec)];
    }

    private function requirePositiveQty(array $line): string
    {
        $qty = Decimal::qty((string) $line['quantity']);
        if (! Decimal::gt($qty, '0')) {
            throw new RuntimeException('Adjustment line quantity must be > 0.');
        }

        return $qty;
    }

    private function createLine(StockAdjustment $adj, int $orgId, array $line, string $direction, string $qty, string $unitCost, ?int $lotId, ?int $serialId): void
    {
        $adj->lines()->create([
            'organization_id' => $orgId,
            'item_id' => $line['item_id'],
            'variant_id' => $line['variant_id'] ?? null,
            'direction' => $direction,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'lot_id' => $lotId,
            'serial_id' => $serialId,
            'bin_id' => $line['bin_id'] ?? null,
            'account_ref' => $line['account_ref'] ?? null,
            'notes' => $line['notes'] ?? null,
        ]);
    }

    /**
     * Post a draft adjustment: increase → inbound, decrease → outbound.
     * $overrides may carry permission-gated trace overrides for decrease lines:
     * ['allow_expired_lot' => bool, 'allow_quarantined_lot' => bool].
     */
    public function post(StockAdjustment $adj, array $overrides = []): StockAdjustment
    {
        $allowExpired = (bool) ($overrides['allow_expired_lot'] ?? false);
        $allowQuarantined = (bool) ($overrides['allow_quarantined_lot'] ?? false);

        return DB::connection($this->connection())->transaction(function () use ($adj, $allowExpired, $allowQuarantined) {
            $adj = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->id);

            if ($adj->isPosted()) {
                return $adj; // idempotent
            }
            if ($adj->status !== 'draft') {
                throw new RuntimeException("Adjustment {$adj->id} cannot be posted from status '{$adj->status}'.");
            }

            $adj->loadMissing('lines');
            $movements = [];
            foreach ($adj->lines as $line) {
                $isOut = $line->direction !== 'increase';
                $movements[] = new StockMovement(
                    direction: $isOut ? 'out' : 'in',
                    itemId: (int) $line->item_id,
                    warehouseId: (int) $adj->warehouse_id,
                    quantity: (string) $line->quantity,
                    sourceType: StockAdjustment::class,
                    sourceId: (int) $adj->id,
                    sourceLineId: (int) $line->id,
                    variantId: $line->variant_id ? (int) $line->variant_id : null,
                    binId: $line->bin_id ? (int) $line->bin_id : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    serialId: $line->serial_id ? (int) $line->serial_id : null,
                    // unit cost only used for inbound (increase); outbound is costed
                    // by the engine (average/FIFO).
                    unitCost: (string) $line->unit_cost,
                    movedAt: $adj->adjustment_date?->toDateTimeString() ?? now()->toDateTimeString(),
                    // overrides only meaningful for outbound (decrease) lot moves
                    allowExpiredLot: $isOut && $allowExpired,
                    allowQuarantinedLot: $isOut && $allowQuarantined,
                );
            }

            $this->ledger->post($movements, $this->postNamespace($adj), [
                'action' => 'stock_adjustment.post',
                'entity_type' => 'stock_adjustment',
                'entity_id' => $adj->id,
                'document_ref' => $adj->adjustment_number,
            ]);

            $adj->status = 'posted';
            $adj->posted_at = now();
            $adj->posted_by = auth()->id();
            $adj->posted_guard_key = $this->postNamespace($adj);
            $adj->markSystemTransition()->save();

            $this->outbox->record('adjustment.posted', $adj, 'stock_adjustment', $adj->adjustment_number, (string) $adj->adjustment_date);

            return $adj;
        });
    }

    /** Reverse a posted adjustment via opposite ledger entries. */
    public function reverse(StockAdjustment $adj): StockAdjustment
    {
        return DB::connection($this->connection())->transaction(function () use ($adj) {
            $adj = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->id);

            if ($adj->isReversed()) {
                return $adj; // idempotent
            }
            if (! $adj->isPosted()) {
                throw new RuntimeException("Only a posted adjustment can be reversed (status '{$adj->status}').");
            }

            $this->ledger->reverse($this->postNamespace($adj), $this->reverseNamespace($adj), [
                'action' => 'stock_adjustment.reverse',
                'entity_type' => 'stock_adjustment',
                'entity_id' => $adj->id,
                'document_ref' => $adj->adjustment_number,
            ]);

            $adj->status = 'reversed';
            $adj->reversed_at = now();
            $adj->reversed_by = auth()->id();
            $adj->markSystemTransition()->save();

            $this->outbox->record('adjustment.reversed', $adj, 'stock_adjustment', $adj->adjustment_number, (string) $adj->adjustment_date);

            return $adj;
        });
    }
}
