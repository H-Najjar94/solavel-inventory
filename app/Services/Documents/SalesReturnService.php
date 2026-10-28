<?php

namespace App\Services\Documents;

use App\Models\Tenant\Customer;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\ShipmentLine;
use App\Models\Tenant\StockLedger;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Integration\WorkflowValidationService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Services\Traceability\SerialService;
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
        private SerialService $serials,
        private WorkflowValidationService $workflowValidation,
        private UnitConversionResolver $conversions,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    private function postNamespace(SalesReturn $r): string
    {
        return 'sales_return:'.$r->id.':post';
    }

    public function createDraft(array $attributes, array $lines, ?\App\Services\FinancialOrigins\CashPartialReturnAdmission $cashAdmission = null): SalesReturn
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($attributes, $lines, $orgId, $cashAdmission) {
            $sourceShipment = ! empty($attributes['shipment_id'])
                ? Shipment::query()->with('lines')->lockForUpdate()->findOrFail((int) $attributes['shipment_id'])
                : null;
            if ($sourceShipment) {
                if ($sourceShipment->status !== 'posted' || $sourceShipment->reversed_at) {
                    throw new RuntimeException(__('inventory.documents.return_source_invalid'));
                }
                if (mb_strlen(trim((string) ($attributes['reason'] ?? ''))) < 3) {
                    throw new RuntimeException('A source reversal reason of at least 3 characters is required.');
                }
                $attributes['warehouse_id'] = $sourceShipment->warehouse_id;
                if ($cashAdmission) $cashAdmission->assertSource($sourceShipment);
                $fullReversal = !$cashAdmission && $this->isFullResellableSourceRequest($sourceShipment, $lines);
                if ($fullReversal) {
                    $existing = SalesReturn::query()->where('source_reversal_shipment_id', $sourceShipment->id)
                        ->lockForUpdate()->first();
                    if ($existing) return $existing->fresh('lines');
                    $hasPrior = SalesReturn::query()->where('shipment_id', $sourceShipment->id)
                        ->whereNull('deleted_at')->whereNotIn('status', ['cancelled'])->lockForUpdate()->exists();
                    if ($hasPrior) throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_bddcb11727e5')]);
                }
                $attributes['is_source_reversal'] = $fullReversal;
                $attributes['source_reversal_shipment_id'] = $fullReversal ? $sourceShipment->id : null;
                $attributes['shipment_id'] = $sourceShipment->id;
                $attributes['original_event_uuid'] = IntegrationOutboxEvent::query()
                    ->where('event_type', 'shipment.posted')
                    ->where('aggregate_id', $sourceShipment->id)
                    ->value('event_uuid');
            }
            $r = new SalesReturn(array_merge([
                'status' => 'draft', 'return_date' => $attributes['return_date'] ?? now()->toDateString(),
            ], $this->applyCustomerName($attributes)));
            $r->organization_id = $orgId;
            $r->save();
            $sourceShipment
                ? ($r->is_source_reversal
                    ? $this->syncSourceLines($r, $sourceShipment, $orgId)
                    : $this->syncAllocatedSourceLines($r, $sourceShipment, $lines, $orgId, $cashAdmission !== null))
                : $this->syncLines($r, $lines, $orgId);

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
            if ($r->is_source_reversal) {
                throw new RuntimeException(__('inventory.documents.return_source_immutable'));
            }
            $attributes = $this->applyCustomerName($attributes);
            $r->fill(collect($attributes)->only(['return_number', 'shipment_id', 'customer_id', 'customer_name', 'return_date', 'warehouse_id', 'reason', 'notes'])->toArray());
            $r->save();
            $r->lines()->delete();
            if ($r->shipment_id) {
                $shipment = Shipment::query()->with('lines')->lockForUpdate()->findOrFail($r->shipment_id);
                $this->syncAllocatedSourceLines($r, $shipment, $lines, $orgId);
            } else {
                $this->syncLines($r, $lines, $orgId);
            }

            return $r->fresh('lines');
        });
    }

    public function post(SalesReturn $r, ?\App\Services\FinancialOrigins\CashPartialReturnAdmission $cashAdmission = null): SalesReturn
    {
        // Canonical factual proof is fetched outside the native transaction; Stock permissions remain independent.
        $originShipment = $r->source_reversal_shipment_id ?: $r->shipment_id;
        $originSource = $originShipment ? Shipment::query()->where('organization_id',$r->organization_id)->findOrFail($originShipment) : null;
        $originContext = !$cashAdmission && $originSource ? app(\App\Services\FinancialOrigins\OriginPhysicalService::class)->beforeReverse($originSource) : null;
        abort_unless(!$originContext || $r->is_source_reversal,409,'cash_origin_partial_return_policy_unavailable');
        return DB::connection($this->conn())->transaction(function () use ($r,$originSource,$originContext,$cashAdmission) {
            $originContext?->lockAndValidate();
            $cashAdmission?->lockAndValidate($r);
            if($originContext)Shipment::query()->where('organization_id',$r->organization_id)->whereKey($originSource->id)->lockForUpdate()->firstOrFail();
            $r = SalesReturn::query()->lockForUpdate()->with('lines')->findOrFail($r->id);
            if ($r->status === 'posted') {
                return $r; // idempotent
            }
            if (! in_array($r->status, ['draft', 'authorized', 'inspected'], true)) {
                throw new RuntimeException("Sales return {$r->id} cannot be posted from status '{$r->status}'.");
            }

            $this->workflowValidation->assertOperationalDocumentReady($r, 'sales_return.posted');
            $this->assertWriteOffReady($r);
            $movements = [];
            foreach ($r->lines as $line) {
                // Every returned unit is received at its original cost so the return
                // journal reverses COGS in full; damaged/retired units are written
                // off in the same transaction (see writeOffNonStockable).
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

            if ($r->is_source_reversal) {
                $shipment = Shipment::query()->lockForUpdate()->findOrFail($r->source_reversal_shipment_id);
                if ($shipment->reversal_sales_return_id && (int) $shipment->reversal_sales_return_id !== (int) $r->id) {
                    throw new RuntimeException(__('inventory.documents.shipment_already_reversed'));
                }
                $this->ledger->reverse(
                    'shipment:'.$shipment->id.':post',
                    $this->postNamespace($r),
                    [
                        'action' => 'shipment.reverse',
                        'entity_type' => 'sales_return',
                        'entity_id' => $r->id,
                        'document_ref' => $r->return_number,
                    ],
                    SalesReturn::class,
                    $r->id,
                );
                $shipment->reversal_sales_return_id = $r->id;
                $shipment->reversed_at = now();
                $shipment->reversed_by = auth()->id();
                $shipment->markSystemTransition()->save();
            } elseif ($movements !== []) {
                $this->ledger->post($movements, $this->postNamespace($r), [
                    'action' => 'sales_return.post', 'entity_type' => 'sales_return',
                    'entity_id' => $r->id, 'document_ref' => $r->return_number,
                ]);
                $this->writeOffNonStockable($r);
            }

            // Serial disposition: stockable serials are already set to in_stock by
            // the engine's IN; mark non-stockable (damaged/retired) and quarantine
            // serials to their disposition status so they do not read as available.
            foreach ($r->lines as $line) {
                if (! $line->serial_id) {
                    continue;
                }
                $status = self::SERIAL_DISPOSITION[$line->condition] ?? 'returned';
                $serial = SerialNumber::query()->find($line->serial_id);
                if ($serial) {
                    $this->serials->setStatus($serial, $status, ['sales_return_id' => $r->id, 'warehouse_id' => $line->warehouse_id ?? $r->warehouse_id]);
                }
            }

            $r->status = 'posted';
            $r->posted_at = now();
            $r->posted_by = auth()->id();
            $r->posted_guard_key = $this->postNamespace($r);
            $r->markSystemTransition()->save();

            $event = $this->outbox->record('sales_return.posted', $r, 'sales_return', $r->return_number, (string) $r->return_date);
            if ($event) {
                $r->reversal_event_uuid = $event->event_uuid;
                $r->markSystemTransition()->save();
            }

            if($originContext)app(\App\Services\FinancialOrigins\OriginPhysicalService::class)->reversed($originSource->fresh(),$r);
            app(\App\Services\Sales\ReturnHandoffService::class)->record($r);

            return $r->fresh('lines');
        });
    }

    /** Deterministic number of the write-off adjustment(s) for a return (also used to find them on reversal). */
    public static function writeOffNumber(SalesReturn $r, ?int $warehouseId = null, bool $suffix = false): string
    {
        return 'DMG-'.$r->return_number.($suffix && $warehouseId ? '-W'.$warehouseId : '');
    }

    /**
     * Damaged / retired units must never become sellable stock. They are received
     * (so COGS is reversed at the original cost) and immediately written off
     * through a posted decrease adjustment (Dr adjustment_loss / Cr inventory_asset
     * via the existing adjustment.posted event), all inside the return's transaction.
     * Idempotent: a re-post returns early before reaching here.
     */
    /**
     * Damaged/retired lines are written off through adjustment.posted, so a connected organization
     * must have that operation in its reviewed scope and its roles mapped. Fail before any stock
     * moves, with an actionable message (standalone/disconnected organizations always pass).
     */
    private function assertWriteOffReady(SalesReturn $r): void
    {
        $hasNonStockable = $r->lines->contains(fn ($l) => ! in_array($l->condition, self::STOCKABLE, true)
            && Decimal::gt((string) $l->returned_qty, '0'));
        if (! $hasNonStockable || $r->is_source_reversal) {
            return;
        }
        try {
            app(\App\Services\Integration\OrganizationAccountRequirements::class)
                ->assertOperationReady((int) $r->organization_id, 'adjustment.posted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $detail = collect($e->errors())->flatten()->implode('; ');
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lines' => __('inventory.documents.damaged_return_adjustment_required', ['detail' => $detail]),
            ]);
        }
    }

    /** The write-off adjustment names a return can own: exact strings, never a LIKE pattern. */
    public static function writeOffNumbers(SalesReturn $r): array
    {
        $warehouses = $r->lines->map(fn ($l) => (int) ($l->warehouse_id ?? $r->warehouse_id))->push((int) $r->warehouse_id)->unique()->filter()->values();

        return $warehouses->map(fn ($w) => self::writeOffNumber($r, $w, true))->push(self::writeOffNumber($r))->unique()->values()->all();
    }

    private function writeOffNonStockable(SalesReturn $r): void
    {
        $byWarehouse = [];
        foreach ($r->lines as $line) {
            if (in_array($line->condition, self::STOCKABLE, true) || ! Decimal::gt((string) $line->returned_qty, '0')) {
                continue;
            }
            $byWarehouse[(int) ($line->warehouse_id ?? $r->warehouse_id)][] = $line;
        }
        if ($byWarehouse === []) {
            return;
        }
        $adjustments = app(StockAdjustmentService::class);
        $suffix = count($byWarehouse) > 1;
        foreach ($byWarehouse as $warehouseId => $lines) {
            // The IN row each line created: remove exactly that cost / that FIFO layer.
            $received = StockLedger::query()->withoutGlobalScope('warehouse_access')
                ->where('source_type', SalesReturn::class)->where('source_id', $r->id)->where('direction', 'in')
                ->whereIn('source_line_id', array_map(fn ($l) => (int) $l->id, $lines))->get()->keyBy('source_line_id');
            $payload = array_map(fn ($line) => [
                'direction' => 'decrease', 'item_id' => (int) $line->item_id,
                'variant_id' => $line->variant_id ? (int) $line->variant_id : null,
                'quantity' => (string) $line->returned_qty,
                'unit_cost' => Decimal::cost((string) ($line->unit_cost ?? '0')),
                'lot_id' => $line->lot_id ? (int) $line->lot_id : null,
                'serial_id' => $line->serial_id ? (int) $line->serial_id : null,
                'bin_id' => $line->bin_id ? (int) $line->bin_id : null,
                'notes' => 'Customer return '.$r->return_number.' ('.$line->condition.')',
            ], $lines);
            $adj = $adjustments->createDraft([
                'adjustment_number' => self::writeOffNumber($r, $warehouseId, $suffix),
                'adjustment_date' => $r->return_date?->toDateString() ?? now()->toDateString(),
                'warehouse_id' => $warehouseId,
                'reason_code' => 'customer_return_damage',
                'notes' => 'Auto write-off of damaged/retired units from customer return '.$r->return_number,
            ], $payload);
            $exact = [];
            foreach ($adj->lines()->orderBy('id')->get()->values() as $i => $adjLine) {
                $in = $received->get((int) $lines[$i]->id);
                if ($in) {
                    $exact[(int) $adjLine->id] = ['unit_cost' => (string) $in->unit_cost, 'layer_id' => $in->cost_layer_id ? (int) $in->cost_layer_id : null];
                }
            }
            // A system write-off of units that are physically back: the lot overrides the return IN
            // used (expired / quarantined / recalled lots) apply to the write-off too.
            $adjustments->post($adj, ['allow_expired_lot' => true, 'allow_quarantined_lot' => true, 'exact_by_line' => $exact]);
        }
    }

    public function authorizeReturn(SalesReturn $r): SalesReturn
    {
        return DB::connection($this->conn())->transaction(function () use ($r) {
            $r = SalesReturn::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->status === 'authorized') {
                return $r;
            }
            if ($r->status !== 'draft') {
                throw new RuntimeException("Only a draft return can be authorized (status '{$r->status}').");
            }
            $r->status = 'authorized';
            $r->authorized_at = now();
            $r->authorized_by = auth()->id();
            $r->save();

            return $r->fresh('lines');
        });
    }

    public function cancel(SalesReturn $return): SalesReturn
    {
        return DB::connection($this->conn())->transaction(function () use ($return) {
            $return = SalesReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status === 'cancelled') return $return;
            if (! in_array($return->status, ['draft', 'authorized', 'inspected'], true)) {
                throw new RuntimeException('Only an unposted sales return can be cancelled.');
            }
            $return->status = 'cancelled';
            $return->save();
            return $return->fresh('lines');
        });
    }

    public function inspect(SalesReturn $r, ?string $notes = null): SalesReturn
    {
        return DB::connection($this->conn())->transaction(function () use ($r, $notes) {
            $r = SalesReturn::query()->lockForUpdate()->with('lines')->findOrFail($r->id);
            if (! in_array($r->status, ['authorized', 'inspected'], true)) {
                throw new RuntimeException("Return must be authorized before inspection (status '{$r->status}').");
            }
            foreach ($r->lines as $line) {
                $line->inspection_status = match ($line->condition) {
                    'resellable' => 'accepted',
                    'quarantine' => 'quarantine',
                    'damaged' => 'damaged',
                    'retired' => 'retired',
                    default => 'accepted',
                };
                $line->disposition = match ($line->condition) {
                    'resellable' => 'restock',
                    'quarantine' => 'quarantine',
                    'damaged' => 'damage',
                    'retired' => 'retire',
                    default => 'restock',
                };
                $line->save();
            }
            $r->status = 'inspected';
            $r->inspected_at = now();
            $r->inspected_by = auth()->id();
            $r->inspection_notes = $notes;
            $r->save();

            return $r->fresh('lines');
        });
    }

    private function syncLines(SalesReturn $r, array $lines, int $orgId): void
    {
        foreach ($lines as $line) {
            $line = $this->conversions->normalizeLine($line, 'returned_qty');
            $r->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? $r->warehouse_id,
                'bin_id' => $line['bin_id'] ?? null,
                'returned_qty' => Decimal::qty((string) $line['returned_qty']),
                'entered_qty' => $line['entered_qty'],
                'entered_unit_id' => $line['entered_unit_id'],
                'base_unit_id' => $line['base_unit_id'],
                'unit_conversion_id' => $line['unit_conversion_id'],
                'unit_conversion_factor' => $line['unit_conversion_factor'],
                'unit_conversion_version' => $line['unit_conversion_version'],
                'unit_conversion_hash' => $line['unit_conversion_hash'],
                'unit_conversion_precision' => $line['unit_conversion_precision'],
                'unit_conversion_rounding_mode' => $line['unit_conversion_rounding_mode'],
                'unit_cost' => Decimal::cost((string) ($line['unit_cost'] ?? '0')),
                'condition' => $line['condition'] ?? 'resellable',
                'inspection_status' => $line['inspection_status'] ?? 'pending',
                'disposition' => $line['disposition'] ?? match ($line['condition'] ?? 'resellable') {
                    'resellable' => 'restock',
                    'quarantine' => 'quarantine',
                    'damaged' => 'damage',
                    'retired' => 'retire',
                    default => 'restock',
                },
                'lot_id' => $line['lot_id'] ?? null,
                'serial_id' => $line['serial_id'] ?? null,
            ]);
        }
    }

    /** Preserve the legacy exact reversal only for a complete resellable request. */
    private function isFullResellableSourceRequest(Shipment $shipment, array $lines): bool
    {
        // The explicit source reversal action supplies no editable lines.
        if ($lines === []) {
            return true;
        }
        $expected = StockLedger::query()->where('source_type', Shipment::class)
            ->where('source_id', $shipment->id)->where('direction', 'out')->get()
            ->groupBy('item_id')->map(fn ($rows) => $rows->reduce(fn ($sum, $row) => Decimal::add($sum, (string) $row->quantity), '0'))->all();
        $requested = [];
        foreach ($lines as $line) {
            if (($line['condition'] ?? 'resellable') !== 'resellable'
                || ($line['disposition'] ?? 'restock') !== 'restock') {
                return false;
            }
            $line = $this->conversions->normalizeLine($line, 'returned_qty');
            $itemId = (int) $line['item_id'];
            $requested[$itemId] = Decimal::add($requested[$itemId] ?? '0', (string) $line['returned_qty']);
        }
        ksort($expected);
        ksort($requested);
        if (array_keys($requested) !== array_keys($expected)) {
            return false;
        }
        foreach ($expected as $itemId => $quantity) {
            if (Decimal::cmp($requested[$itemId], $quantity) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create immutable source allocations for partial/disposition returns. Draft
     * lines reserve quantity; deleting/replacing a draft releases it. Posted lines
     * remain permanent and cumulative quantities are checked under shipment lock.
     */
    private function syncAllocatedSourceLines(SalesReturn $return, Shipment $shipment, array $lines, int $orgId, bool $frozenSource = false): void
    {
        if ($lines === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_11c53e1902a9')]);
        }
        $seenSerials = [];
        $requestedBySourceLine = [];
        foreach ($lines as $input) {
            $sourceLineId = (int) ($input['source_line_id'] ?? 0);
            $sourceLine = ShipmentLine::query()->where('shipment_id', $shipment->id)->lockForUpdate()->find($sourceLineId);
            if (! $sourceLine || (int) $sourceLine->organization_id !== $orgId) {
                throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_20cbd438d4c5')]);
            }
            if ((int) $sourceLine->item_id !== (int) $input['item_id']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_523eab36d68c')]);
            }
            $input = $frozenSource ? $this->conversions->normalizeLineFromSnapshot($input, 'returned_qty', $sourceLine) : $this->conversions->normalizeLine($input, 'returned_qty');
            $baseQty = Decimal::qty((string) $input['returned_qty']);
            $already = (string) DB::connection($this->conn())->table('sales_return_lines as lines')
                ->join('sales_returns as returns', 'returns.id', '=', 'lines.sales_return_id')
                ->where('lines.organization_id', $orgId)->where('lines.source_shipment_line_id', $sourceLine->id)
                ->where('returns.id', '!=', $return->id)->whereNull('returns.deleted_at')
                ->whereNotIn('returns.status', ['cancelled'])->sum('lines.returned_qty');
            $requestedBySourceLine[$sourceLine->id] = Decimal::add($requestedBySourceLine[$sourceLine->id] ?? '0', $baseQty);
            if (Decimal::gt(Decimal::add($already, $requestedBySourceLine[$sourceLine->id]), (string) $sourceLine->quantity)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_433191a43ed7')]);
            }
            if ((int) ($input['entered_unit_id'] ?? 0) !== (int) ($sourceLine->entered_unit_id ?? 0)
                || Decimal::cmp((string) ($input['unit_conversion_factor'] ?? 1), (string) ($sourceLine->unit_conversion_factor ?? 1), 8) !== 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_3512acd4158e')]);
            }
            $ledgerQuery = StockLedger::query()->where('organization_id', $orgId)->where('source_type', Shipment::class)
                ->where('source_id', $shipment->id)->where('source_line_id', $sourceLine->id)->where('direction', 'out')->lockForUpdate();
            $ledger = ! empty($input['source_stock_ledger_id'])
                ? (clone $ledgerQuery)->whereKey((int) $input['source_stock_ledger_id'])->first()
                : $ledgerQuery->first();
            if (! $ledger) throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_47a1085bbaed')]);
            if ($sourceLine->serial_id) {
                if (Decimal::cmp($baseQty, '1') !== 0 || isset($seenSerials[$sourceLine->serial_id])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_fa93258523f6')]);
                }
                $seenSerials[$sourceLine->serial_id] = true;
            }
            $condition = $input['condition'] ?? 'resellable';
            $disposition = $input['disposition'] ?? match ($condition) {
                'resellable' => 'restock', 'quarantine' => 'quarantine', 'damaged' => 'damage', 'retired' => 'retire', default => 'restock',
            };
            $validDisposition = match ($condition) {
                'resellable' => $disposition === 'restock', 'quarantine' => $disposition === 'quarantine',
                'damaged' => in_array($disposition, ['damage', 'no_restock'], true), 'retired' => in_array($disposition, ['retire', 'no_restock'], true), default => false,
            };
            if (! $validDisposition) throw \Illuminate\Validation\ValidationException::withMessages(['lines' => __('inventory.validation.extra_0230da161a33')]);
            $return->lines()->create([
                'organization_id' => $orgId, 'source_shipment_line_id' => $sourceLine->id,
                'source_stock_ledger_id' => $ledger->id, 'item_id' => $sourceLine->item_id,
                'variant_id' => $sourceLine->variant_id, 'warehouse_id' => $shipment->warehouse_id,
                'bin_id' => $sourceLine->bin_id, 'returned_qty' => $baseQty,
                'entered_qty' => $input['entered_qty'], 'entered_unit_id' => $sourceLine->entered_unit_id,
                'base_unit_id' => $sourceLine->base_unit_id, 'unit_conversion_id' => $sourceLine->unit_conversion_id,
                'unit_conversion_factor' => $sourceLine->unit_conversion_factor,
                'unit_conversion_version' => $sourceLine->unit_conversion_version,
                'unit_conversion_hash' => $sourceLine->unit_conversion_hash,
                'unit_conversion_precision' => $sourceLine->unit_conversion_precision,
                'unit_conversion_rounding_mode' => $sourceLine->unit_conversion_rounding_mode,
                'unit_cost' => $ledger->unit_cost, 'condition' => $condition,
                'inspection_status' => 'pending', 'disposition' => $disposition,
                'lot_id' => $sourceLine->lot_id, 'serial_id' => $sourceLine->serial_id,
            ]);
        }
    }

    private function syncSourceLines(SalesReturn $return, Shipment $shipment, int $orgId): void
    {
        $ledger = StockLedger::query()
            ->where('source_type', Shipment::class)
            ->where('source_id', $shipment->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->get();
        if ($ledger->isEmpty()) {
            throw new RuntimeException(__('inventory.documents.shipment_no_ledger'));
        }

        foreach ($ledger as $row) {
            $shipmentLine = ShipmentLine::query()->find($row->source_line_id);
            $return->lines()->create([
                'organization_id' => $orgId,
                'source_shipment_line_id' => $shipmentLine?->id,
                'source_stock_ledger_id' => $row->id,
                'item_id' => $row->item_id,
                'variant_id' => $row->variant_id,
                'warehouse_id' => $row->warehouse_id,
                'bin_id' => $row->bin_id,
                'returned_qty' => $row->quantity,
                'entered_qty' => $shipmentLine?->entered_qty ?? $row->quantity,
                'entered_unit_id' => $shipmentLine?->entered_unit_id,
                'base_unit_id' => $shipmentLine?->base_unit_id,
                'unit_conversion_id' => $shipmentLine?->unit_conversion_id,
                'unit_conversion_factor' => $shipmentLine?->unit_conversion_factor,
                'unit_conversion_version' => $shipmentLine?->unit_conversion_version,
                'unit_conversion_hash' => $shipmentLine?->unit_conversion_hash,
                'unit_conversion_precision' => $shipmentLine?->unit_conversion_precision,
                'unit_conversion_rounding_mode' => $shipmentLine?->unit_conversion_rounding_mode,
                'unit_cost' => $row->unit_cost,
                'condition' => 'resellable',
                'inspection_status' => 'pending',
                'disposition' => 'restock',
                'lot_id' => $row->lot_id,
                'serial_id' => $row->serial_id,
            ]);
        }
    }

    private function applyCustomerName(array $attributes): array
    {
        if (! empty($attributes['customer_id'])) {
            $customer = Customer::query()->find((int) $attributes['customer_id']);
            if ($customer) {
                $attributes['customer_name'] = $attributes['customer_name'] ?? $customer->name;
            }
        }

        return $attributes;
    }
}
