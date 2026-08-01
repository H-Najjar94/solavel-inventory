<?php

namespace App\Services\Documents;

use App\Models\Tenant\StockAdjustment;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\Concerns\CapturesTraceability;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Services\Traceability\LotService;
use App\Services\Traceability\SerialService;
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
    use CapturesTraceability;

    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private IntegrationOutboxService $outbox,
        private LotService $lots,
        private SerialService $serials,
        private UnitConversionResolver $conversions,
    ) {}

    protected function lotService(): LotService
    {
        return $this->lots;
    }

    protected function serialService(): SerialService
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
            // Server-issued adjustment number when none was supplied (users don't type it).
            // An explicit number (e.g. the 'COUNT-…' ref from a stock-count variance
            // adjustment) is always respected.
            $attributes['adjustment_number'] = ! empty($attributes['adjustment_number'])
                ? $attributes['adjustment_number']
                : DocumentNumber::next('ADJ', StockAdjustment::class, 'adjustment_number', $orgId, $this->connection());

            // Default a missing/blank date (adjustment_date is a NOT NULL column). Done
            // here rather than only in array_merge so a null in $attributes can't win.
            $attributes['adjustment_date'] = $attributes['adjustment_date'] ?? now()->toDateString();

            $adj = new StockAdjustment(array_merge([
                'status' => 'draft',
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
                throw new RuntimeException(__('inventory.stock.adjustment_direction'));
            }
            if (! empty($line['serials']) && ! empty($line['entered_unit_id'])) {
                throw new RuntimeException('Alternate-unit quantities cannot be combined with explicit serial capture.');
            }
            $unitCost = Decimal::cost((string) ($line['unit_cost'] ?? '0'));

            // Increase + serial capture → one qty-1 line per captured serial.
            if ($direction === 'increase') {
                $cap = $this->resolveCapture($line, $orgId, StockAdjustment::class, (int) $adj->id);
                if ($cap['serial_ids'] !== []) {
                    foreach ($cap['serial_ids'] as $sid) {
                        $serialLine = $this->conversions->normalizeLine(
                            array_merge($line, ['quantity' => '1', 'entered_qty' => '1']),
                            'quantity'
                        );
                        $totalInc = Decimal::add($totalInc, $unitCost);
                        $this->createLine($adj, $orgId, $serialLine, 'increase', '1.0000', $unitCost, $cap['lot_id'], $sid);
                    }

                    continue;
                }
                $line = $this->conversions->normalizeLine($line, 'quantity');
                $qty = $this->requirePositiveQty($line);
                $totalInc = Decimal::add($totalInc, Decimal::money(Decimal::mul($qty, $unitCost)));
                $this->createLine($adj, $orgId, $line, 'increase', $qty, $unitCost, $cap['lot_id'], $line['serial_id'] ?? null);

                continue;
            }

            // Decrease → lot/serial already selected from availability.
            $line = $this->conversions->normalizeLine($line, 'quantity');
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
            'entered_qty' => $line['entered_qty'],
            'entered_unit_id' => $line['entered_unit_id'],
            'base_unit_id' => $line['base_unit_id'],
            'unit_conversion_id' => $line['unit_conversion_id'],
            'unit_conversion_factor' => $line['unit_conversion_factor'],
            'unit_conversion_version' => $line['unit_conversion_version'],
            'unit_conversion_hash' => $line['unit_conversion_hash'],
            'unit_conversion_precision' => $line['unit_conversion_precision'],
            'unit_conversion_rounding_mode' => $line['unit_conversion_rounding_mode'],
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
        app(InventoryReversalService::class)->reverseNegativeAdjustment($adj, 'Canonical source reversal');

        return $adj->fresh(['lines', 'reversal']);
    }
}
