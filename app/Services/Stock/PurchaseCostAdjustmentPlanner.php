<?php

namespace App\Services\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\CostLayerConsumption;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockCount;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\StockTransfer;
use App\Services\FinancialOrigins\OriginReceiptCostAuthority;
use App\Services\Stock\Support\OriginReceiptCostProvenance;
use App\Services\Stock\Support\SupplierCreditCostProvenance;
use App\Services\PurchasingCredits\SupplierCreditCostAuthority;
use App\Services\Purchasing\PurchasingBillAuthority;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Builds a fail-closed, currency-frozen allocation of a supplier price delta. */
final class PurchaseCostAdjustmentPlanner
{
    public const CONTRACT_VERSION = 'solastock-purchase-cost-adjustment.v1';

    public function plan(array $input): array
    {
        $connection = IntegrationOrganizationMapping::query()->where('status', 'verified')->where('activation_state', 'active')
            ->where('solastock_organization_id', app(OrganizationContext::class)->idOrFail())
            ->where('tenant_database_identity', \DB::connection('tenant')->getDatabaseName())->firstOrFail();
        $rows = IntegrationFinancialLineAllocation::query()->where('organization_mapping_uuid', $connection->mapping_uuid)
            ->where('destination_document_type', 'supplier_bill')->where('destination_document_id', $input['destination_document_id'])
            ->where('destination_fingerprint', $input['destination_fingerprint'])->whereIn('state', ['draft_reserved', 'posted'])
            ->lockForUpdate()->get();
        $settlement = request()->attributes->get('posted_purchase_settlement_allocation');
        if ($settlement instanceof IntegrationFinancialLineAllocation
            && str_starts_with((string) request()->attributes->get('verified_workspace_action'), 'purchasing.settlement.')
            && $settlement->organization_mapping_uuid === $connection->mapping_uuid
            && hash_equals((string) $settlement->destination_fingerprint, (string) $input['destination_fingerprint'])) {
            $rows = collect([$settlement]);
        }
        if ($rows->isEmpty()) {
            $this->fail('No reviewed supplier-bill allocations are available.');
        }
        $scale = (int) ($input['finance_money_scale'] ?? 2);
        if ($scale < 0 || $scale > 6) {
            $this->fail('Finance money precision must be between zero and six decimals.');
        }
        $components = collect();
        $exactTotal = '0';
        foreach ($rows as $allocation) {
            if ((string) $allocation->currency_code !== strtoupper($input['currency_code'])
                || (string) $allocation->base_currency_code !== strtoupper($input['base_currency_code'])
                || Decimal::cmp((string) $allocation->exchange_rate, (string) $input['exchange_rate'], 12) !== 0) {
                $this->fail('Currency or exchange-rate provenance changed; FX differences cannot be classified as purchase-price variance.');
            }
            $transactionDifference = ($input['discount_posting_mode'] ?? 'net') === 'gross'
                ? Decimal::sub((string) $allocation->destination_gross, (string) $allocation->source_gross)
                : (string) $allocation->price_difference;
            // Finance exchange-rate convention: 1 base unit = N transaction
            // units, therefore transaction -> base is division. FX itself is
            // frozen and never enters the purchase-price result.
            $baseDifference = Decimal::round(Decimal::div($transactionDifference, (string) $allocation->exchange_rate), 8);
            $exactTotal = Decimal::add($exactTotal, $baseDifference, 8);
            if (Decimal::isZero($baseDifference, 8)) {
                continue;
            }
            $components = $components->concat($this->allocationComponents($allocation, $baseDifference));
        }
        $posted = '0';
        $serialized = $components->map(function (array $component) use (&$posted, $scale): array {
            $componentScale = $component['destination_role'] === 'inventory_asset' ? 2 : $scale;
            $component['posted_base_amount'] = Decimal::round($component['exact_base_amount'], $componentScale);
            $posted = Decimal::add($posted, $component['posted_base_amount'], 8);

            return $component;
        })->values()->all();
        $residual = Decimal::round(Decimal::sub($exactTotal, $posted), $scale);
        $bound = Decimal::round(Decimal::mul((string) max(1, count($serialized)), '0.005'), 6);
        if (Decimal::gt(ltrim($residual, '-'), $bound, 6)) {
            $this->fail('Cumulative valuation rounding exceeds its deterministic bound.');
        }

        return ['contract_version' => self::CONTRACT_VERSION, 'organization_mapping_uuid' => $connection->mapping_uuid,
            'destination_document_id' => (int) $input['destination_document_id'], 'destination_fingerprint' => $input['destination_fingerprint'],
            'currency_code' => $input['currency_code'], 'base_currency_code' => $input['base_currency_code'], 'exchange_rate' => $input['exchange_rate'],
            'finance_money_scale' => $scale, 'stock_money_scale' => 2, 'exact_base_difference' => $exactTotal,
            'allocated_base_difference' => $posted, 'rounding_residual' => $residual, 'rounding_bound' => $bound, 'components' => $serialized];
    }

    /** Resolve frozen Expense price deltas through actual native receipt disposition. */
    public function planFinancialOrigin(OriginReceiptCostAuthority $authority): array
    {
        abort_unless($authority->organizationId() === app(OrganizationContext::class)->idOrFail(), 403);
        $components = collect();
        $exact = '0';
        foreach ($authority->sourceAllocations() as $source) {
            $difference = (string) $source['price_delta_base'];
            $exact = Decimal::add($exact, $difference, 8);
            if (!Decimal::isZero($difference, 8)) {
                $components = $components->concat($this->allocationComponents(OriginReceiptCostProvenance::fromAuthority($authority, $source), $difference)->map(function (array $component) use ($source): array {
                    $component['receipt_line_id'] = (int) $source['receipt_line_id'];
                    $component['provenance']['financial_origin_source'] = ['receipt_id' => (int) $source['receipt_id'], 'receipt_line_id' => (int) $source['receipt_line_id'], 'position_uuid' => $source['position_uuid']];
                    return $component;
                }));
            }
        }
        $posted = '0';
        $serialized = $components->map(function (array $component) use (&$posted, $authority): array {
            $scale = $component['destination_role'] === 'inventory_asset' ? 2 : $authority->moneyScale();
            $component['posted_base_amount'] = Decimal::round($component['exact_base_amount'], $scale);
            $posted = Decimal::add($posted, $component['posted_base_amount'], 8);
            return $component;
        })->values()->all();
        $residual = Decimal::round(Decimal::sub($exact, $posted, 8), $authority->moneyScale());
        $bound = Decimal::round(Decimal::mul((string) max(1, count($serialized)), '0.005'), 6);
        if (Decimal::gt(ltrim($residual, '-'), $bound, 6)) $this->fail('Cumulative valuation rounding exceeds its deterministic bound.');
        return ['contract_version' => 'financial-origin.settlement.v1', 'direction' => $authority->direction(), 'organization_mapping_uuid' => $authority->mappingUuid(),
            'destination_document_type' => 'expense', 'destination_document_id' => $authority->sourceDocumentId(),
            'destination_fingerprint' => $authority->fingerprint(), 'operation_uuid' => $authority->operationUuid(),
            'source_document_type' => 'expense', 'source_document_id' => $authority->sourceDocumentId(), 'source_journal_id' => $authority->sourceJournalId(),
            'currency_code' => $authority->currencyCode(), 'base_currency_code' => $authority->baseCurrencyCode(),
            'exchange_rate' => $authority->exchangeRate(), 'finance_money_scale' => $authority->moneyScale(), 'stock_money_scale' => 2,
            'exact_base_difference' => $exact, 'allocated_base_difference' => $posted, 'rounding_residual' => $residual,
            'rounding_bound' => $bound, 'components' => $serialized];
    }

    /** A supplier credit changes value through actual native FIFO/AVG provenance, never quantity. */
    public function planSupplierCredit(SupplierCreditCostAuthority $authority): array
    {
        abort_unless(\DB::connection('tenant')->transactionLevel()>0 && $authority->organizationId()===app(OrganizationContext::class)->idOrFail(),403);
        $components=collect();$exact='0';
        foreach($authority->sourceAllocations()as$source){
            $difference=(string)$source['price_delta_base'];
            abort_unless(Decimal::cmp($difference,'0',8)<=0 && Decimal::gt((string)$source['quantity_base'],'0'),409);
            $exact=Decimal::add($exact,$difference,8);
            if(Decimal::isZero($difference,8))continue;
            $components=$components->concat($this->allocationComponents(SupplierCreditCostProvenance::fromAuthority($authority,$source),$difference)->map(function(array $component)use($authority,$source):array{
                $component['receipt_line_id']=(int)$source['receipt_line_id'];
                $component['provenance']['supplier_credit_source']=['settlement_uuid'=>$source['settlement_uuid'],'receipt_id'=>(int)$source['receipt_id'],'receipt_line_id'=>(int)$source['receipt_line_id'],'source_stock_ledger_id'=>(int)$source['stock_ledger_id'],'position_uuid'=>$source['position_uuid'],'operation_uuid'=>$authority->operationUuid(),'allocation_uuid'=>$authority->allocationUuid()];
                return $component;
            }));
        }
        $posted='0';$serialized=$components->map(function(array $component)use(&$posted,$authority):array{
            $scale=$component['destination_role']==='inventory_asset'?Decimal::MONEY_SCALE:$authority->moneyScale();
            $component['posted_base_amount']=Decimal::round($component['exact_base_amount'],$scale);
            $posted=Decimal::add($posted,$component['posted_base_amount'],8);return $component;
        })->values()->all();
        $residual=Decimal::round(Decimal::sub($exact,$posted,8),$authority->moneyScale());
        $bound=Decimal::round(Decimal::mul((string)max(1,count($serialized)),'0.005'),6);
        if(Decimal::gt(ltrim($residual,'-'),$bound,6))$this->fail('Cumulative supplier-credit valuation rounding exceeds its native deterministic bound.');
        return ['contract_version'=>'purchase-credit-value.v1','direction'=>$authority->direction(),'organization_mapping_uuid'=>$authority->mappingUuid(),
            'operation_uuid'=>$authority->operationUuid(),'allocation_uuid'=>$authority->allocationUuid(),'destination_document_type'=>'supplier_credit','destination_document_id'=>$authority->noteId(),
            'destination_fingerprint'=>$authority->fingerprint(),'source_bill_id'=>$authority->billId(),'source_bill_journal_id'=>$authority->billJournalId(),
            'currency_code'=>$authority->currencyCode(),'base_currency_code'=>$authority->baseCurrencyCode(),'exchange_rate'=>$authority->exchangeRate(),'finance_money_scale'=>$authority->moneyScale(),'stock_money_scale'=>Decimal::MONEY_SCALE,
            'exact_base_difference'=>$exact,'allocated_base_difference'=>$posted,'rounding_residual'=>$residual,'rounding_bound'=>$bound,'components'=>$serialized];
    }

    /** A scoped Finance purchase may inspect only its receipt's cost provenance. */
    private function ledgerQuery(IntegrationFinancialLineAllocation|OriginReceiptCostProvenance|SupplierCreditCostProvenance $allocation): Builder
    {
        $query = StockLedger::query();
        if ($allocation instanceof OriginReceiptCostProvenance || $allocation instanceof SupplierCreditCostProvenance || ($allocation->source_document_type === 'goods_receipt' && PurchasingBillAuthority::receipt((int) $allocation->source_document_id))) {
            $query->withoutGlobalScope('warehouse_access');
        }

        if ($allocation instanceof SupplierCreditCostProvenance) {
            foreach (['variant_id','lot_id','bin_id'] as $coordinate) {
                $value=$allocation->$coordinate;
                $value===null ? $query->whereNull($coordinate) : $query->where($coordinate,$value);
            }
        }
        return $query;
    }

    private function allocationComponents(IntegrationFinancialLineAllocation|OriginReceiptCostProvenance|SupplierCreditCostProvenance $allocation, string $difference): Collection
    {
        $receipt = $this->ledgerQuery($allocation)->where('organization_id', $allocation->solastock_organization_id)
            ->where('source_type', GoodsReceipt::class)->where('source_id', $allocation->source_document_id)
            ->where('source_line_id', $allocation->source_line_id)
            ->when($allocation instanceof SupplierCreditCostProvenance,fn($query)=>$query->whereKey($allocation->stock_ledger_id))
            ->orderBy('id')->first();
        if (! $receipt) {
            $this->fail('The receipt valuation ledger provenance is missing.');
        }

        return $receipt->costing_method === 'fifo'
            ? $this->fifoComponents($allocation, $receipt, $difference)
            : $this->averageComponents($allocation, $receipt, $difference);
    }

    private function fifoComponents($allocation, StockLedger $receipt, string $difference): Collection
    {
        $layer = $receipt->cost_layer_id
            ? CostLayer::query()->where('organization_id', $receipt->organization_id)->find($receipt->cost_layer_id)
            : CostLayer::query()->where('organization_id', $receipt->organization_id)->where('source_ledger_id', $receipt->id)->first();
        if (! $layer) {
            $this->fail('FIFO receipt layer provenance is missing.');
        }
        // The allocation's price_difference already covers only its reserved
        // quantity; do not scale it by the receipt share a second time.
        $delta = $difference;
        $parts = collect();
        $allocatedQty = (string) $allocation->base_quantity;
        $remaining = Decimal::mul($allocatedQty, Decimal::div((string) $layer->remaining_qty, (string) $receipt->quantity, 10), 8);
        if (Decimal::gt($remaining, '0')) {
            $parts->push($this->component($allocation, $receipt, 'inventory_asset', $remaining, Decimal::mul($delta, Decimal::div($remaining, $allocatedQty), 8), ['cost_layer_id' => $layer->id]));
        }
        $left = Decimal::sub($allocatedQty, $remaining, 8);
        foreach (CostLayerConsumption::query()->where('organization_id', $receipt->organization_id)->where('cost_layer_id', $layer->id)->orderBy('id')->get() as $consumption) {
            if (! Decimal::gt($left, '0')) {
                break;
            }
            $qty = Decimal::mul($allocatedQty, Decimal::div((string) $consumption->qty, (string) $receipt->quantity, 10), 8);
            if (Decimal::cmp($qty, $left, 8) > 0) {
                $qty = $left;
            }
            $ledger = $this->ledgerQuery($allocation)->where('organization_id', $receipt->organization_id)->where('warehouse_id', $receipt->warehouse_id)->find($consumption->ledger_id);
            if (! $ledger) {
                $this->fail('FIFO disposition ledger provenance is missing.');
            }
            $role = $this->role($ledger);
            $parts->push($this->component($allocation, $ledger, $role, $qty, Decimal::mul($delta, Decimal::div($qty, $allocatedQty), 8), ['cost_layer_id' => $layer->id, 'consumption_id' => $consumption->id]));
            $left = Decimal::sub($left, $qty, 8);
        }
        if (Decimal::gt($left, '0')) {
            $this->fail('FIFO provenance does not explain the allocated receipt quantity.');
        }

        return $parts;
    }

    private function averageComponents($allocation, StockLedger $receipt, string $difference): Collection
    {
        $influence = $difference;
        $parts = collect();
        $later = $this->ledgerQuery($allocation)->where('organization_id', $receipt->organization_id)->where('item_id', $receipt->item_id)
            ->where('warehouse_id', $receipt->warehouse_id)->where('id', '>', $receipt->id)->orderBy('id')->get();
        foreach ($later as $ledger) {
            if ($ledger->direction !== 'out' || Decimal::isZero($influence, 8)) {
                continue;
            }
            $before = Decimal::add((string) $ledger->balance_qty_after, (string) $ledger->quantity, 8);
            if (! Decimal::gt($before, '0')) {
                $this->fail('Weighted-average provenance encountered an invalid pre-movement quantity.');
            }
            $flow = Decimal::mul($influence, Decimal::div((string) $ledger->quantity, $before, 10), 8);
            $parts->push($this->component($allocation, $ledger, $this->role($ledger), (string) $ledger->quantity, $flow, ['average_replay_from_ledger_id' => $receipt->id]));
            $influence = Decimal::sub($influence, $flow, 8);
        }
        if (! Decimal::isZero($influence, 8)) {
            $parts->push($this->component($allocation, $receipt, 'inventory_asset', (string) $allocation->base_quantity, $influence, ['average_replay_from_ledger_id' => $receipt->id]));
        }

        return $parts;
    }

    private function role(StockLedger $ledger): string
    {
        return match ($ledger->source_type) {
            Shipment::class => 'cogs',
            StockAdjustment::class, StockCount::class => 'adjustment_loss',
            StockTransfer::class => $this->fail('Transferred cost requires destination-layer provenance and cannot be absorbed by PPV.'),
            default => $this->fail('An inventory disposition has no reviewed accounting destination; PPV cannot absorb it.'),
        };
    }

    private function component($allocation, StockLedger $ledger, string $role, string $qty, string $amount, array $provenance): array
    {
        return ['allocation_uuid' => $allocation->allocation_uuid, 'stock_ledger_id' => $ledger->id, 'item_id' => $ledger->item_id,
            'warehouse_id' => $ledger->warehouse_id, 'destination_role' => $role, 'destination_source_type' => $ledger->source_type,
            'destination_source_id' => $ledger->source_id, 'base_quantity' => Decimal::round($qty, 8), 'exact_base_amount' => Decimal::round($amount, 8),
            'provenance' => $provenance];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['purchase_cost_adjustment' => $message]);
    }
}
