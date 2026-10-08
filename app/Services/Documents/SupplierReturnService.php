<?php
namespace App\Services\Documents;

use App\Models\Tenant\{GoodsReceipt, InventoryReversal, IntegrationOutboxEvent, IntegrationOrganizationMapping, StockBalance, StockLedger, SupplierReturn};
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Integration\{IntegrationEvents, IntegrationOutboxService, WorkflowValidationService};
use App\Services\Stock\{StockLedgerService, StockMovement};
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A supplier return is a new OUT document, valued by the configured native FIFO/AVG policy. */
final class SupplierReturnService
{
    public function __construct(private OrganizationContext $context, private StockLedgerService $ledger,
        private WarehouseAccessService $warehouses, private IntegrationOutboxService $outbox, private WorkflowValidationService $workflow) {}

    public function createDraft(array $attributes, array $lines): SupplierReturn
    {
        validator($attributes, ['goods_receipt_id' => 'required|integer|min:1', 'return_date' => 'required|date', 'reason' => 'required|string|min:3'])->validate();
        validator(['lines' => $lines], ['lines' => 'required|array|min:1', 'lines.*.goods_receipt_line_id' => 'required|integer|min:1',
            'lines.*.source_stock_ledger_id' => 'nullable|integer|min:1|distinct',
            'lines.*.entered_qty' => ['required','regex:/^\d+(?:\.\d{1,4})?$/','numeric','gt:0']])->validate();
        $org = $this->context->idOrFail();
        return DB::connection('tenant')->transaction(function () use ($attributes, $lines, $org) {
            $receipt = GoodsReceipt::query()->where('organization_id', $org)->whereKey($attributes['goods_receipt_id'])->lockForUpdate()->with('lines')->firstOrFail();
            $this->assertReceipt($receipt);
            $this->warehouses->assertAllowed((int) $receipt->warehouse_id);
            $return = SupplierReturn::create(['organization_id' => $org, 'return_uuid' => (string) Str::uuid(),
                'return_number' => DocumentNumber::next('SPR', SupplierReturn::class, 'return_number', $org, 'tenant'),
                'goods_receipt_id' => $receipt->id, 'supplier_id' => $receipt->supplier_id, 'warehouse_id' => $receipt->warehouse_id,
                'return_date' => $attributes['return_date'], 'reason' => $attributes['reason'], 'notes' => $attributes['notes'] ?? null,
                'status' => 'draft', 'created_by' => auth()->id()]);
            foreach ($lines as $input) {
                $source = $receipt->lines->firstWhere('id', (int) $input['goods_receipt_line_id']);
                if (! $source) throw ValidationException::withMessages(['lines' => 'Select an accepted line from this receipt.']);
                $physicalRows = StockLedger::query()->where('organization_id', $org)->where('source_type', GoodsReceipt::class)
                    ->where('source_id', $receipt->id)->where('source_line_id', $source->id)->where('direction', 'in')->get();
                $physical = ! empty($input['source_stock_ledger_id'])
                    ? $physicalRows->firstWhere('id', (int) $input['source_stock_ledger_id'])
                    : ($physicalRows->count() === 1 ? $physicalRows->first() : null);
                if (! $physical) throw ValidationException::withMessages(['lines' => 'Choose the received batch or serial number for this return.']);
                $warehouse = (int) ($source->warehouse_id ?: $receipt->warehouse_id);
                $this->warehouses->assertAllowed($warehouse);
                $factor = (string) $source->unit_conversion_factor;
                if (! is_numeric($factor) || ! Decimal::gt($factor, '0')) throw ValidationException::withMessages(['lines' => 'The original receipt conversion requires review before returning these goods.']);
                $quantity = Decimal::qty(Decimal::mul((string) $input['entered_qty'], $factor));
                if (! Decimal::gt($quantity, '0') || Decimal::gt($quantity, (string) $source->accepted_qty) || Decimal::gt($quantity, (string) $physical->quantity)) {
                    throw ValidationException::withMessages(['lines' => 'Return quantities cannot exceed the accepted receipt quantities.']);
                }
                $return->lines()->create(['organization_id' => $org, 'goods_receipt_line_id' => $source->id, 'source_stock_ledger_id' => $physical->id, 'item_id' => $source->item_id,
                    'warehouse_id' => $warehouse, 'entered_unit_id' => $source->entered_unit_id, 'entered_qty' => $input['entered_qty'],
                    'quantity' => $quantity, 'unit_conversion_factor' => $factor,
                    'base_unit_id' => $source->base_unit_id, 'unit_conversion_id' => $source->unit_conversion_id,
                    'unit_conversion_version' => $source->unit_conversion_version, 'unit_conversion_hash' => $source->unit_conversion_hash,
                    'unit_conversion_precision' => $source->unit_conversion_precision, 'unit_conversion_rounding_mode' => $source->unit_conversion_rounding_mode,
                    'variant_id' => $physical->variant_id, 'bin_id' => $physical->bin_id, 'lot_id' => $physical->lot_id, 'serial_id' => $physical->serial_id]);
            }
            return $return->fresh('lines');
        });
    }

    public function post(SupplierReturn $return): SupplierReturn
    {
        $org = $this->context->idOrFail();
        return DB::connection('tenant')->transaction(function () use ($return, $org) {
            $receipt = GoodsReceipt::query()->where('organization_id', $org)->whereKey($return->goods_receipt_id)->lockForUpdate()->with('lines')->firstOrFail();
            $return = SupplierReturn::query()->where('organization_id', $org)->whereKey($return->id)->lockForUpdate()->with('lines')->firstOrFail();
            if ($return->status === 'posted' && ! $return->reversed_at) return $return;
            if ($return->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only a draft supplier return can be posted.']);
            $this->assertReceipt($receipt);
            $connected = IntegrationOrganizationMapping::query()->where('solastock_organization_id', $org)
                ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->exists();
            // Connected activation remains disabled until canonical reviewed_supplier_returns_v1
            // capability and the actual Finance allocation consumer are independently qualified.
            // Registering an event type alone never authorizes this accounting workflow.
            if ($connected) {
                throw ValidationException::withMessages(['integration' => 'Supplier-return accounting must be configured before goods can be returned.']);
            }
            $movements = []; $currentByReceiptLine = [];
            foreach ($return->lines as $line) {
                $this->warehouses->assertAllowed((int) $line->warehouse_id);
                $source = $receipt->lines->firstWhere('id', (int) $line->goods_receipt_line_id);
                if (! $source || (int) $line->item_id !== (int) $source->item_id) abort(409);
                $physical = StockLedger::query()->where('organization_id', $org)->where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)
                    ->where('source_line_id', $source->id)->where('direction', 'in')->whereKey($line->source_stock_ledger_id)->firstOrFail();
                foreach (['item_id','warehouse_id','variant_id','bin_id','lot_id','serial_id'] as $dimension) {
                    if ((int) $line->{$dimension} !== (int) $physical->{$dimension}) abort(409);
                }
                $alreadyReturned = DB::connection('tenant')->table('supplier_return_lines AS lines')->join('supplier_returns AS documents', 'documents.id', '=', 'lines.supplier_return_id')
                    ->where('lines.organization_id', $org)->where('lines.goods_receipt_line_id', $source->id)
                    ->where('documents.status', 'posted')->whereNull('documents.reversed_at')->sum('lines.quantity');
                $currentByReceiptLine[$source->id] = Decimal::add($currentByReceiptLine[$source->id] ?? '0', (string) $line->quantity);
                $returnedPhysical = DB::connection('tenant')->table('supplier_return_lines AS lines')->join('supplier_returns AS documents', 'documents.id', '=', 'lines.supplier_return_id')
                    ->where('lines.organization_id', $org)->where('lines.source_stock_ledger_id', $physical->id)
                    ->where('documents.status', 'posted')->whereNull('documents.reversed_at')->sum('lines.quantity');
                if (Decimal::gt(Decimal::add((string) $alreadyReturned, $currentByReceiptLine[$source->id]), (string) $source->accepted_qty)
                    || Decimal::gt(Decimal::add((string) $returnedPhysical, (string) $line->quantity), (string) $physical->quantity)) {
                    throw ValidationException::withMessages(['lines' => 'This receipt quantity has already been returned. Review the earlier supplier returns.']);
                }
                $available = StockBalance::query()->where('organization_id', $org)->where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id)
                    ->when($line->variant_id, fn ($q) => $q->where('variant_id', $line->variant_id), fn ($q) => $q->whereNull('variant_id'))->first();
                if (! $available || Decimal::gt((string) $line->quantity, (string) $available->on_hand_qty)) {
                    throw ValidationException::withMessages(['lines' => 'There is not enough available stock in the receipt warehouse to return these goods.']);
                }
                $movements[] = new StockMovement(direction: 'out', itemId: (int) $line->item_id, warehouseId: (int) $line->warehouse_id,
                    quantity: (string) $line->quantity, sourceType: SupplierReturn::class, sourceId: $return->id, sourceLineId: $line->id,
                    variantId: $line->variant_id, binId: $line->bin_id, lotId: $line->lot_id, serialId: $line->serial_id,
                    movedAt: $return->return_date->toDateTimeString());
            }
            $this->workflow->assertOperationalDocumentReady($return, 'supplier_return.posted');
            $this->ledger->post($movements, 'supplier_return:'.$return->id.':post', ['action' => 'supplier_return.post', 'entity_type' => 'supplier_return', 'entity_id' => $return->id]);
            // The engine acquires Item -> balance locks in its canonical order. Validate under those
            // retained locks, rather than locking a balance first or trusting a stale preflight.
            foreach ($return->lines as $line) {
                $balance = StockBalance::query()->where('organization_id', $org)->where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id)
                    ->when($line->variant_id, fn ($q) => $q->where('variant_id', $line->variant_id), fn ($q) => $q->whereNull('variant_id'))->first();
                if (! $balance || Decimal::lt((string) $balance->on_hand_qty, '0')) {
                    throw ValidationException::withMessages(['lines' => 'There is not enough available stock to complete this supplier return.']);
                }
            }
            foreach ($return->lines as $line) {
                $entries = StockLedger::query()->where('organization_id', $org)->where('source_type', SupplierReturn::class)->where('source_id', $return->id)->where('source_line_id', $line->id)->get();
                $cost = $entries->reduce(fn ($sum, $entry) => Decimal::add($sum, (string) $entry->total_cost), '0');
                $line->update(['actual_return_cost_base' => $cost, 'unit_cost' => Decimal::div($cost, (string) $line->quantity)]);
            }
            $return->status = 'posted'; $return->posted_at = now(); $return->posted_by = auth()->id(); $return->markSystemTransition()->save();
            if ($connected) {
                $this->outbox->record('supplier_return.posted', $return, 'supplier_return', $return->return_number, $return->return_date->format('Y-m-d'));
                // Financial delivery and the commercial supplier-credit draft are distinct.
                // Both durable events belong to the same native physical-return transaction.
                app(\App\Services\Sales\SupplierReturnDocumentBuilder::class)->record($return);
            }
            return $return->fresh('lines');
        });
    }

    /** Native inverse of this return OUT, never a reversal of the original purchase receipt. */
    public function reverse(SupplierReturn $return, string $reason): InventoryReversal
    {
        validator(['reason'=>$reason],['reason'=>'required|string|min:3|max:2000'])->validate();
        $org=$this->context->idOrFail();
        return DB::connection('tenant')->transaction(function()use($return,$reason,$org){
            abort_unless((int)$return->organization_id===(int)$org,404);
            $connected=app(\App\Services\Returns\SupplierReturnFinancialReversalGuard::class)->lockAndAssert($return);
            GoodsReceipt::query()->where('organization_id',$org)->whereKey($return->goods_receipt_id)->lockForUpdate()->firstOrFail();
            $return=SupplierReturn::query()->where('organization_id',$org)->whereKey($return->id)->with('lines')->lockForUpdate()->firstOrFail();
            if($return->reversal_id)return InventoryReversal::query()->where('organization_id',$org)->whereKey($return->reversal_id)->firstOrFail();
            abort_unless($return->status==='posted',409);
            foreach($return->lines as$line)$this->warehouses->assertAllowed((int)$line->warehouse_id);
            if($connected)app(\App\Services\Returns\SupplierReturnFinancialReversalGuard::class)->assertMappingCurrent($connected,$return);
            $original=IntegrationOutboxEvent::query()->where('organization_id',$org)->where('event_type','supplier_return.posted')->where('aggregate_id',$return->id)->first();
            $reversal=InventoryReversal::create(['organization_id'=>$org,'reversal_number'=>DocumentNumber::next('REV-SPR',InventoryReversal::class,'reversal_number',$org,'tenant'),
                'source_type'=>'supplier_return','source_id'=>$return->id,'source_number'=>$return->return_number,'reversal_date'=>now()->toDateString(),
                'status'=>'posted','reason'=>trim($reason),'posted_by'=>auth()->id(),'posted_at'=>now(),'posted_guard_key'=>'supplier_return:'.$return->id.':reversal','original_event_uuid'=>$original?->event_uuid]);
            $this->ledger->reverse('supplier_return:'.$return->id.':post','inventory_reversal:'.$reversal->id.':post',
                ['action'=>'supplier_return.reverse','entity_type'=>'inventory_reversal','entity_id'=>$reversal->id,'document_ref'=>$reversal->reversal_number],InventoryReversal::class,$reversal->id);
            $return->status='reversed';$return->reversal_id=$reversal->id;$return->reversed_at=now();$return->reversed_by=auth()->id();$return->markSystemTransition()->save();
            if($connected){
                $this->outbox->record('supplier_return.reversed',$reversal,'inventory_reversal',$reversal->reversal_number,$reversal->reversal_date->format('Y-m-d'));
                app(\App\Services\Sales\SupplierReturnDocumentBuilder::class)->record($return,true,$connected->mapping_uuid);
            }
            return $reversal;
        });
    }

    private function assertReceipt(GoodsReceipt $receipt): void
    {
        if ($receipt->status !== 'posted' || $receipt->reversed_at || ! $receipt->supplier_id) {
            throw ValidationException::withMessages(['goods_receipt_id' => 'Choose a confirmed, unreversed purchase receipt with a supplier.']);
        }
    }
}
