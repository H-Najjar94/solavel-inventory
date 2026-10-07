<?php

namespace App\Services\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationPurchaseCostAdjustment;
use App\Models\Tenant\IntegrationPurchaseCostAdjustmentComponent;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Purchasing\LandedCostAllocationAuthority;
use App\Services\FinancialOrigins\OriginReceiptCostAuthority;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Atomic and retry-safe persistence/application of a reviewed cost plan. */
final class PurchaseCostAdjustmentService
{
    public function __construct(private PurchaseCostAdjustmentPlanner $planner) {}

    public function prepare(array $input): array
    {
        return DB::connection('tenant')->transaction(function () use ($input): array {
            $plan = $this->planner->plan($input);
            if (! hash_equals((string) $plan['organization_mapping_uuid'], (string) $input['organization_mapping_uuid'])) {
                $this->fail('The requested connection does not match the active organization.');
            }
            $key = hash('sha256', $plan['organization_mapping_uuid'].'|'.$plan['destination_document_id'].'|'.$plan['destination_fingerprint']);
            $row = IntegrationPurchaseCostAdjustment::query()->where('organization_mapping_uuid', $plan['organization_mapping_uuid'])
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if (! $row) {
                $row = IntegrationPurchaseCostAdjustment::query()->create([
                    'adjustment_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $plan['organization_mapping_uuid'],
                    'organization_id' => app(OrganizationContext::class)->idOrFail(), 'destination_document_type' => 'supplier_bill',
                    'destination_document_id' => $plan['destination_document_id'], 'destination_fingerprint' => $plan['destination_fingerprint'],
                    'currency_code' => $plan['currency_code'], 'base_currency_code' => $plan['base_currency_code'], 'exchange_rate' => $plan['exchange_rate'],
                    'finance_money_scale' => $plan['finance_money_scale'], 'stock_money_scale' => 2, 'exact_base_difference' => $plan['exact_base_difference'],
                    'allocated_base_difference' => $plan['allocated_base_difference'], 'rounding_residual' => $plan['rounding_residual'],
                    'rounding_bound' => $plan['rounding_bound'], 'state' => 'prepared', 'idempotency_key' => $key,
                    'safe_metadata' => ['contract_version' => PurchaseCostAdjustmentPlanner::CONTRACT_VERSION] + (str_starts_with((string) request()->attributes->get('verified_workspace_action'), 'purchasing.settlement.') && request()->attributes->has('posted_purchase_settlement_identity') ? ['purchase_settlement' => request()->attributes->get('posted_purchase_settlement_identity')] : []),
                ]);
                foreach ($plan['components'] as $component) {
                    IntegrationPurchaseCostAdjustmentComponent::query()->create($component + [
                        'adjustment_uuid' => $row->adjustment_uuid, 'organization_id' => $row->organization_id]);
                }
            }

            return $this->serialize($row);
        }, 5);
    }

    public function prepareLandedCost(LandedCostAllocationAuthority $authority, array $plan): array
    {
        return DB::connection('tenant')->transaction(function () use ($authority, $plan): array {
            abort_unless($authority->action() === 'prepare' && !$authority->reverse(), 403);
            $canonical = $this->planner->planLandedCost($authority);
            abort_unless($canonical === $plan, 409);
            $key = hash('sha256', 'landed_cost|'.$authority->mappingUuid().'|'.$authority->operationUuid());
            $row = IntegrationPurchaseCostAdjustment::query()->where('organization_id', $authority->organizationId())->where('organization_mapping_uuid', $authority->mappingUuid())->where('idempotency_key', $key)->lockForUpdate()->first();
            if (!$row) {
                $row = IntegrationPurchaseCostAdjustment::create([
                    'adjustment_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $authority->mappingUuid(),
                    'organization_id' => $authority->organizationId(), 'destination_document_type' => 'landed_cost',
                    'destination_document_id' => $authority->landedCostId(), 'destination_fingerprint' => $authority->fingerprint(),
                    'currency_code' => $authority->currencyCode(), 'base_currency_code' => $authority->baseCurrencyCode(), 'exchange_rate' => $authority->exchangeRate(),
                    'finance_money_scale' => $authority->moneyScale(), 'stock_money_scale' => 2, 'exact_base_difference' => $plan['exact_base_difference'],
                    'allocated_base_difference' => $plan['allocated_base_difference'], 'rounding_residual' => $plan['rounding_residual'], 'rounding_bound' => $plan['rounding_bound'],
                    'state' => 'prepared', 'idempotency_key' => $key, 'safe_metadata' => ['contract_version' => 'solastock-landed-cost.v1',
                        'landed_cost' => ['operation_uuid' => $authority->operationUuid(), 'source_bill_id' => $authority->sourceBillId(), 'source_bill_journal_id' => $authority->billJournalId()]],
                ]);
                foreach ($plan['components'] as $component) { unset($component['receipt_line_id']); IntegrationPurchaseCostAdjustmentComponent::create($component + ['adjustment_uuid' => $row->adjustment_uuid, 'organization_id' => $row->organization_id]); }
            }
            abort_unless($row->destination_document_type === 'landed_cost' && hash_equals($row->destination_fingerprint, $authority->fingerprint()), 409);
            return $this->serialize($row);
        }, 5);
    }

    public function applyLandedCost(LandedCostAllocationAuthority $authority, ?array $plan = null): array
    {
        abort_unless($authority->action() === 'apply' && !$authority->reverse(), 403);
        return $this->transition($this->landedInput($authority), false, $authority);
    }

    public function reverseLandedCost(LandedCostAllocationAuthority $authority, ?array $plan = null): array
    {
        abort_unless($authority->action() === 'reverse' && $authority->reverse(), 403);
        return $this->transition($this->landedInput($authority), true, $authority);
    }

    public function statusLandedCost(LandedCostAllocationAuthority $authority): array
    {
        $input = $this->landedInput($authority);
        $row = IntegrationPurchaseCostAdjustment::query()->where('organization_id', $authority->organizationId())->where('organization_mapping_uuid', $authority->mappingUuid())
            ->where('destination_document_type', 'landed_cost')->where('destination_document_id', $authority->landedCostId())->where('destination_fingerprint', $authority->fingerprint())->firstOrFail();
        return $this->serialize($row);
    }

    private function landedInput(LandedCostAllocationAuthority $authority): array
    {
        return ['organization_mapping_uuid' => $authority->mappingUuid(), 'destination_document_id' => $authority->landedCostId(), 'destination_fingerprint' => $authority->fingerprint()];
    }

    public function prepareFinancialOrigin(OriginReceiptCostAuthority $authority, array $plan): array
    {
        return DB::connection('tenant')->transaction(function () use ($authority, $plan): array {
            abort_unless($authority->action() === 'prepare' && !$authority->reverse(), 403);
            $canonical = $this->planner->planFinancialOrigin($authority);
            abort_unless($canonical === $plan, 409);
            $key = hash('sha256', 'financial_origin|'.$authority->mappingUuid().'|'.$authority->operationUuid());
            $row = IntegrationPurchaseCostAdjustment::query()->where('organization_id', $authority->organizationId())->where('organization_mapping_uuid', $authority->mappingUuid())->where('idempotency_key', $key)->lockForUpdate()->first();
            if (!$row) {
                $row = IntegrationPurchaseCostAdjustment::create([
                    'adjustment_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $authority->mappingUuid(),
                    'organization_id' => $authority->organizationId(), 'destination_document_type' => 'expense',
                    'destination_document_id' => $authority->sourceDocumentId(), 'destination_fingerprint' => $authority->fingerprint(),
                    'currency_code' => $authority->currencyCode(), 'base_currency_code' => $authority->baseCurrencyCode(), 'exchange_rate' => $authority->exchangeRate(),
                    'finance_money_scale' => $authority->moneyScale(), 'stock_money_scale' => 2, 'exact_base_difference' => $plan['exact_base_difference'],
                    'allocated_base_difference' => $plan['allocated_base_difference'], 'rounding_residual' => $plan['rounding_residual'], 'rounding_bound' => $plan['rounding_bound'],
                    'state' => 'prepared', 'idempotency_key' => $key, 'safe_metadata' => ['contract_version' => 'financial-origin.settlement.v1',
                        'financial_origin' => ['operation_uuid' => $authority->operationUuid(), 'source_document_type' => 'expense', 'source_document_id' => $authority->sourceDocumentId(), 'source_journal_id' => $authority->sourceJournalId()]],
                ]);
                foreach ($plan['components'] as $component) { unset($component['receipt_line_id']); IntegrationPurchaseCostAdjustmentComponent::create($component + ['adjustment_uuid' => $row->adjustment_uuid, 'organization_id' => $row->organization_id]); }
            }
            abort_unless($row->destination_document_type === 'expense' && hash_equals($row->destination_fingerprint, $authority->fingerprint()), 409);
            return $this->serialize($row);
        }, 5);
    }

    public function applyFinancialOrigin(OriginReceiptCostAuthority $authority, ?array $plan = null): array
    {
        abort_unless($authority->action() === 'apply' && !$authority->reverse(), 403);
        return $this->transition($this->originInput($authority), false, $authority);
    }

    public function reverseFinancialOrigin(OriginReceiptCostAuthority $authority, ?array $plan = null): array
    {
        abort_unless($authority->action() === 'reverse' && $authority->reverse(), 403);
        return $this->transition($this->originInput($authority), true, $authority);
    }

    public function statusFinancialOrigin(OriginReceiptCostAuthority $authority): array
    {
        $input = $this->originInput($authority);
        $row = IntegrationPurchaseCostAdjustment::query()->where('organization_id', $authority->organizationId())->where('organization_mapping_uuid', $authority->mappingUuid())
            ->where('destination_document_type', 'expense')->where('destination_document_id', $authority->sourceDocumentId())->where('destination_fingerprint', $authority->fingerprint())->firstOrFail();
        return $this->serialize($row);
    }

    private function originInput(OriginReceiptCostAuthority $authority): array
    {
        return ['organization_mapping_uuid' => $authority->mappingUuid(), 'destination_document_id' => $authority->sourceDocumentId(), 'destination_fingerprint' => $authority->fingerprint()];
    }

    public function status(array $input): array
    {
        $row = IntegrationPurchaseCostAdjustment::query()
            ->where('organization_id', app(OrganizationContext::class)->idOrFail())
            ->where('organization_mapping_uuid', $input['organization_mapping_uuid'])
            ->where('destination_document_type', 'supplier_bill')->where('destination_document_id', $input['destination_document_id'])
            ->where('destination_fingerprint', $input['destination_fingerprint'])->firstOrFail();

        return $this->serialize($row);
    }

    public function apply(array $input): array
    {
        return $this->transition($input, false);
    }

    public function reverse(array $input): array
    {
        return $this->transition($input, true);
    }

    private function transition(array $input, bool $reverse, LandedCostAllocationAuthority|OriginReceiptCostAuthority|null $authority = null): array
    {
        return DB::connection('tenant')->transaction(function () use ($input, $reverse, $authority): array {
            $organizationId = app(OrganizationContext::class)->idOrFail();
            $active = IntegrationOrganizationMapping::query()->where('mapping_uuid', $input['organization_mapping_uuid'])
                ->where('solastock_organization_id', $organizationId)->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
                ->where('status', 'verified')->where('activation_state', 'active')->exists();
            if (! $active) {
                $this->fail('The connection is not active for this organization.');
            }
            $row = IntegrationPurchaseCostAdjustment::query()->where('organization_id', $organizationId)->where('organization_mapping_uuid', $input['organization_mapping_uuid'])
                ->where('destination_document_type', $authority instanceof OriginReceiptCostAuthority ? 'expense' : ($authority ? 'landed_cost' : 'supplier_bill'))->where('destination_document_id', $input['destination_document_id'])->where('destination_fingerprint', $input['destination_fingerprint'])
                ->lockForUpdate()->firstOrFail();
            if (data_get($row->safe_metadata, 'purchase_settlement.settlement_uuid')
                && ! str_starts_with((string) request()->attributes->get('verified_workspace_action'), 'purchasing.settlement.')) {
                $this->fail(__('receiving.valuation_pending'));
            }
            if ($reverse ? $row->state === 'reversed' : $row->state === 'applied') {
                return $this->serialize($row);
            }
            if ($reverse ? $row->state !== 'applied' : $row->state !== 'prepared') {
                $this->fail('The purchase-cost adjustment is not in the required lifecycle state.');
            }
            $sign = $reverse ? '-1' : '1';
            $components = IntegrationPurchaseCostAdjustmentComponent::query()->where('adjustment_uuid', $row->adjustment_uuid)->lockForUpdate()->get();
            $holdService = app(PurchaseValuationHoldService::class);
            $holdService->lockItems($components->pluck('item_id')->all());
            $allowedHold = request()->attributes->get('validated_settlement_hold');
            if ($authority) {
                abort_unless(hash_equals((string) data_get($row->safe_metadata, ($authority instanceof OriginReceiptCostAuthority ? 'financial_origin' : 'landed_cost').'.operation_uuid'), $authority->operationUuid()) && (int) $row->organization_id === $authority->organizationId(), 403);
                abort_unless($reverse ? $authority->reversalJournalId() !== null : $authority->financeJournalId() !== null, 409);
            }
            foreach ($components as $component) {
                if ($authority) {
                    $allowedHold = ['settlement_uuid' => $authority->holdUuid((int) $component->item_id, (int) $component->warehouse_id, $reverse ? 'reverse' : 'apply'),
                        'purpose' => $authority instanceof OriginReceiptCostAuthority ? ($reverse ? 'origin_reverse' : 'origin_apply') : ($reverse ? 'landed_reverse' : 'landed_apply'), 'plan_revision' => $authority->planRevision(), 'plan_fingerprint' => $authority->planFingerprint()];
                }
                $holdService->assertMovable((int) $component->item_id, (int) $component->warehouse_id,
                    is_array($allowedHold) ? $allowedHold : null);
            }
            foreach ($components->where('destination_role', 'inventory_asset') as $component) {
                $amount = Decimal::money(Decimal::mul((string) $component->posted_base_amount, $sign));
                // The reviewed plan fixes these rows; applying it is not a warehouse selection by the actor,
                // who may be a SolaCount-only member completing the bill. Organization scope still binds them.
                $ledger = StockLedger::query()->withoutGlobalScope('warehouse_access')->where('organization_id', $row->organization_id)->findOrFail($component->stock_ledger_id);
                $balance = StockBalance::query()->withoutGlobalScope('warehouse_access')->where('organization_id', $row->organization_id)->where('item_id', $component->item_id)
                    ->where('warehouse_id', $component->warehouse_id)->whereRaw('COALESCE(variant_id,0)=?', [(int) ($ledger->variant_id ?? 0)])
                    ->whereRaw('COALESCE(lot_id,0)=?', [(int) ($ledger->lot_id ?? 0)])->whereRaw('COALESCE(bin_id,0)=?', [(int) ($ledger->bin_id ?? 0)])
                    ->lockForUpdate()->firstOrFail();
                $balance->total_value = Decimal::money(Decimal::add((string) $balance->total_value, $amount));
                $balance->average_cost = Decimal::isZero((string) $balance->on_hand_qty) ? '0' : Decimal::cost(Decimal::div((string) $balance->total_value, (string) $balance->on_hand_qty));
                $balance->save();
                $layerId = data_get($component->provenance, 'cost_layer_id');
                if ($layerId) {
                    $layer = CostLayer::query()->where('organization_id', $row->organization_id)->lockForUpdate()->findOrFail($layerId);
                    if (Decimal::isZero((string) $layer->remaining_qty)) {
                        $this->fail('A FIFO layer changed after cost review.');
                    }
                    $layer->unit_cost = Decimal::cost(Decimal::add((string) $layer->unit_cost, Decimal::div($amount, (string) $layer->remaining_qty)));
                    $layer->save();
                }
            }
            $row->state = $reverse ? 'reversed' : 'applied';
            $row->{$reverse ? 'reversed_at' : 'applied_at'} = now();
            $row->save();

            return $this->serialize($row);
        }, 5);
    }

    private function serialize($row): array
    {
        return ['contract_version' => PurchaseCostAdjustmentPlanner::CONTRACT_VERSION, 'adjustment_uuid' => $row->adjustment_uuid,
            'organization_mapping_uuid' => $row->organization_mapping_uuid, 'destination_document_id' => (int) $row->destination_document_id,
            'destination_fingerprint' => $row->destination_fingerprint, 'state' => $row->state,
            'currency_code' => (string) $row->currency_code, 'base_currency_code' => (string) $row->base_currency_code,
            'exchange_rate' => (string) $row->exchange_rate, 'finance_money_scale' => (int) $row->finance_money_scale,
            'exact_base_difference' => (string) $row->exact_base_difference, 'allocated_base_difference' => (string) $row->allocated_base_difference,
            'rounding_residual' => (string) $row->rounding_residual, 'rounding_bound' => (string) $row->rounding_bound,
            'components' => IntegrationPurchaseCostAdjustmentComponent::query()->where('adjustment_uuid', $row->adjustment_uuid)->orderBy('id')->get()->map(fn ($c) => [
                'destination_role' => $c->destination_role, 'destination_source_type' => $c->destination_source_type,
                'destination_source_id' => $c->destination_source_id, 'base_quantity' => (string) $c->base_quantity,
                'posted_base_amount' => (string) $c->posted_base_amount])->all()];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['purchase_cost_adjustment' => $message]);
    }
}
