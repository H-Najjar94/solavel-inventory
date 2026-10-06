<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationPurchaseCostAdjustment;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\PurchaseValuationHold;
use App\Models\Tenant\ReceivingRequest;
use App\Models\Tenant\ReceivingRequestLine;
use App\Models\Tenant\StockLedger;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\FinanceOnboardingReadiness;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\PurchaseCostAdjustmentPlanner;
use App\Services\Stock\PurchaseCostAdjustmentService;
use App\Services\Stock\Support\Decimal;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/** Financial follow-through of exact posted documents; never physical receiving. */
final class PostedPurchaseSettlementService
{
    public function dispatch(array $input, object $organization): array
    {
        abort_unless(($input['authority_kind'] ?? null) === 'posted_purchase_settlement'
            && (int) $input['actor_id'] === 0, 403);
        $operation = substr($input['action'], strlen('purchasing.settlement.'));
        abort_unless(in_array($operation, ['prepare', 'apply', 'reverse', 'status', 'release'], true), 403);
        $central = DB::connection((string) config('tenancy.central_connection', 'mysql'));
        foreach ([['finance', 'accounting', 'construction'], ['inventory']] as $slugs) {
            abort_unless($central->table('organization_projects as assignments')
                ->join('projects', 'projects.id', '=', 'assignments.project_id')
                ->where('assignments.organization_id', $organization->id)->where('assignments.is_active', true)
                ->where('projects.is_active', true)->whereIn('projects.slug', $slugs)->exists(), 403);
        }
        $tenants = app(TenantManager::class);
        $database = $tenants->resolveDatabaseName((int) $organization->client_id);
        $tenants->useTenant((int) $organization->id, $database);
        $mapping = app(ReceivingRequestService::class)->mapping();
        abort_unless((int) $mapping->finance_organization_id === (int) $input['finance_organization_id']
            && $mapping->status === 'verified' && $mapping->activation_state === 'active', 403);
        $setting = IntegrationSetting::query()->where('organization_id', $organization->id)->where('integration', 'solabooks')->firstOrFail();
        abort_unless($setting->mode === 'active', 409);
        app(FinanceOnboardingReadiness::class)->assertComplete((int) $organization->id);
        app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
        $facts = validator((array) ($input['data'] ?? []), [
            'plan_revision' => 'sometimes|integer|min:1', 'plan_fingerprint' => 'sometimes|string|size:64', 'hold_purpose' => 'sometimes|in:apply,reverse', 'direction' => 'sometimes|in:forward,reverse', 'settlement_uuid' => 'required|uuid', 'position_uuid' => 'required|uuid',
            'source_bill_id' => 'required|integer|min:1', 'bill_journal_id' => 'required|integer|min:1',
            'bill_revision' => 'required|string|size:64', 'receipt_id' => 'required|integer|min:1',
            'receipt_line_id' => 'required|integer|min:1', 'receipt_mapping_uuid' => 'required|uuid',
            'receipt_journal_key' => 'required|string|max:191', 'quantity' => 'required|numeric|gt:0',
            'invoice_net_unit_cost' => 'required|numeric|min:0', 'nonrecoverable_tax_unit_cost' => 'required|numeric|min:0',
            'finance_journal_id' => 'sometimes|integer|min:1', 'finance_journal_key' => 'sometimes|string|max:191',
            'finance_reversal_journal_id' => 'sometimes|integer|min:1', 'finance_reversal_journal_key' => 'sometimes|string|max:191',
        ])->validate();
        // Independent remote read happens before taking any Stock locks.
        $authority = app(SolaBooksOutboxDeliveryService::class)->authorizePurchaseSettlement($facts, $operation);
        abort_unless(($authority['settlement_uuid'] ?? null) === $facts['settlement_uuid']
            && (int) ($authority['bill_id'] ?? 0) === (int) $facts['source_bill_id'], 403);
        if ($operation === 'apply') {
            abort_unless((int) ($authority['finance_journal_id'] ?? 0) > 0 && ($authority['finance_journal_key'] ?? null) === 'purchase-settlement:'.$facts['settlement_uuid'], 403);
        }
        if ($operation === 'reverse') {
            abort_unless((int) ($authority['finance_reversal_journal_id'] ?? 0) > 0 && ! empty($authority['finance_reversal_journal_key']), 403);
        }
        foreach (['position_uuid', 'bill_revision', 'receipt_mapping_uuid', 'receipt_journal_key'] as $field) {
            abort_unless(hash_equals((string) $facts[$field], (string) ($authority[$field] ?? '')), 403);
        }
        foreach (['receipt_id', 'receipt_line_id', 'bill_journal_id'] as $field) {
            abort_unless((int) $facts[$field] === (int) ($authority[$field] ?? 0), 403);
        }
        foreach (['quantity', 'invoice_net_unit_cost', 'nonrecoverable_tax_unit_cost'] as $field) {
            abort_unless(isset($authority[$field]) && Decimal::cmp((string) $facts[$field], (string) $authority[$field], 8) === 0, 403);
        }

        abort_unless((int) ($authority['plan_revision'] ?? 1) === (int) ($facts['plan_revision'] ?? 1), 403);

        return DB::connection('tenant')->transaction(function () use ($facts, $authority, $mapping, $setting, $organization, $input, $operation): array {
            $lifecycle = IntegrationDocumentLifecycleMapping::query()->where('mapping_uuid', $facts['receipt_mapping_uuid'])
                ->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('source_document_type', 'goods_receipt')
                ->where('source_document_id', (string) $facts['receipt_id'])->firstOrFail();
            $receipt = GoodsReceipt::query()->withoutGlobalScope('warehouse_access')->where('organization_id', $organization->id)
                ->lockForUpdate()->findOrFail($facts['receipt_id']);
            abort_unless($receipt->status === 'posted' && ! $receipt->reversal_id, 409);
            $line = $receipt->lines()->findOrFail($facts['receipt_line_id']);
            // Validate the durable original Finance line through the native receiving request.
            if ($line->receiving_request_line_id) {
                $requestLine = ReceivingRequestLine::query()->findOrFail($line->receiving_request_line_id);
                $request = ReceivingRequest::query()->findOrFail($requestLine->receiving_request_id);
                abort_unless((int) $request->source_bill_id === (int) $facts['source_bill_id']
                    && (int) $requestLine->source_line_id === (int) ($authority['bill_line_id'] ?? 0), 403);
            }
            foreach (['item' => [$line->item_id, $authority['item_external_id'] ?? 0],
                'unit' => [$line->entered_unit_id, $authority['unit_external_id'] ?? 0]] as $type => $pair) {
                abort_unless(IntegrationMasterDataMapping::query()
                    ->where('organization_mapping_uuid', $mapping->mapping_uuid)
                    ->where('central_client_id', $mapping->central_client_id)
                    ->where('central_organization_id', $mapping->central_organization_id)
                    ->where('finance_organization_id', $mapping->finance_organization_id)
                    ->where('solastock_organization_id', $organization->id)
                    ->where('entity_type', $type)->where('solastock_record_id', (string) $pair[0])
                    ->where('solabooks_record_id', (string) $pair[1])->where('status', 'verified')
                    ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived', false)
                    ->where('solabooks_archived', false)->exists(), 403);
            }
            $journal = IntegrationOutboxEvent::query()->where('organization_id', $organization->id)
                ->where('event_type', 'grn.posted')->where('aggregate_id', $receipt->id)
                ->where('idempotency_key', $facts['receipt_journal_key'])->firstOrFail();
            $currency = (array) data_get($journal->payload, 'currency', []);
            abort_unless(($currency['code'] ?? null) === ($authority['currency_code'] ?? null)
                && $mapping->base_currency_code === ($authority['base_currency_code'] ?? null), 403);
            $rate = (string) ($currency['exchange_rate'] ?? '0');
            abort_unless(Decimal::cmp($rate, (string) ($authority['receipt_exchange_rate'] ?? '0'), 12) === 0
                && Decimal::cmp($rate, '0', 12) > 0, 403);
            $factor = (string) ($line->unit_conversion_factor ?: '1');
            $entered = Decimal::div((string) $line->accepted_qty, $factor, 8);
            abort_unless(Decimal::cmp($facts['quantity'], $entered, 8) <= 0, 403);
            $settledBaseQty = Decimal::mul((string) $facts['quantity'], $factor, 8);
            $legacyUsed = (string) IntegrationFinancialLineAllocation::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
                ->where('source_document_mapping_uuid', $lifecycle->mapping_uuid)->where('source_document_type', 'goods_receipt')
                ->where('source_document_id', (string) $receipt->id)->where('source_line_id', $line->id)
                ->whereIn('state', ['draft_reserved', 'posted'])->sum('base_quantity');
            $otherNewUsed = app(PurchaseSourceOwnership::class)->newUsedBase($mapping, (int) $receipt->id,
                (int) $line->id, $factor, $facts['settlement_uuid']);
            abort_unless(Decimal::cmp(Decimal::add(Decimal::add($legacyUsed, $otherNewUsed, 8), $settledBaseQty, 8), (string) $line->accepted_qty, 8) <= 0, 409, __('receiving.receipt_already_billed'));
            $fingerprint = hash('sha256', 'posted-purchase-settlement-v1|'.$facts['settlement_uuid'].'|'.$facts['position_uuid'].'|'.$facts['bill_revision'].'|'.$lifecycle->mapping_uuid.'|'.$line->id.'|plan:'.(int) ($facts['plan_revision'] ?? 1));
            $receiptCost = Decimal::mul((string) $line->unit_cost, $factor, 8);
            $invoiceCost = Decimal::add($facts['invoice_net_unit_cost'], $facts['nonrecoverable_tax_unit_cost'], 8);
            $allocation = new IntegrationFinancialLineAllocation([
                'allocation_uuid' => $facts['settlement_uuid'], 'organization_mapping_uuid' => $mapping->mapping_uuid,
                'solastock_organization_id' => $organization->id, 'source_document_type' => 'goods_receipt',
                'source_document_id' => $receipt->id, 'source_line_id' => $line->id,
                'destination_fingerprint' => $fingerprint, 'base_quantity' => $settledBaseQty,
                'currency_code' => $authority['currency_code'], 'base_currency_code' => $authority['base_currency_code'],
                'exchange_rate' => $rate,
                'price_difference' => Decimal::mul(Decimal::sub($invoiceCost, $receiptCost, 8), (string) $facts['quantity'], 8),
            ]);
            request()->attributes->set('verified_workspace_action', $input['action']);
            request()->attributes->set('posted_purchase_settlement_allocation', $allocation);
            request()->attributes->set('purchasing_authority', ['organization_mapping_uuid' => $mapping->mapping_uuid,
                'finance_organization_id' => $mapping->finance_organization_id, 'receipt_ids' => [$receipt->id],
                'receipt_mapping_uuids' => [$lifecycle->mapping_uuid]]);
            $native = ['organization_mapping_uuid' => $mapping->mapping_uuid, 'destination_document_id' => $facts['source_bill_id'],
                'destination_fingerprint' => $fingerprint, 'currency_code' => $authority['currency_code'],
                'base_currency_code' => $authority['base_currency_code'], 'exchange_rate' => $rate, 'finance_money_scale' => (int) data_get($setting->meta, 'finance_currency_contract.money_scale', 2)];
            $holds = app(PurchaseValuationHoldService::class);
            $holds->lockItems([(int) $line->item_id]);
            request()->attributes->set('posted_purchase_settlement_identity', [
                'settlement_uuid' => $facts['settlement_uuid'], 'position_uuid' => $facts['position_uuid'],
                'receipt_id' => $receipt->id, 'receipt_line_id' => $line->id,
                'bill_id' => $facts['source_bill_id'], 'bill_journal_id' => $facts['bill_journal_id'],
                'bill_revision' => $facts['bill_revision'],
            ]);
            $service = app(PurchaseCostAdjustmentService::class);
            $previous = in_array($operation, ['status', 'release'], true) ? $service->status($native) : $service->prepare($native);
            $adjustment = IntegrationPurchaseCostAdjustment::query()
                ->where('adjustment_uuid', $previous['adjustment_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless(data_get($adjustment->safe_metadata, 'purchase_settlement.settlement_uuid') === $facts['settlement_uuid'], 409);
            $reversePreview = $operation === 'prepare' && ($facts['direction'] ?? null) === 'reverse';
            if ($reversePreview) {
                abort_unless(in_array($previous['state'], ['applied', 'reversed'], true), 409, __('receiving.valuation_pending'));
            }
            $purpose = ($reversePreview || $operation === 'reverse') ? 'reverse' : ($facts['hold_purpose'] ?? 'apply');
            $normalize = static fn (array $components): array => array_map(static fn ($component) => [
                'destination_role' => $component['destination_role'],
                'destination_source_type' => $component['destination_source_type'],
                'destination_source_id' => (int) $component['destination_source_id'],
                'base_quantity' => Decimal::round((string) $component['base_quantity'], 8),
                'posted_base_amount' => Decimal::round((string) $component['posted_base_amount'], 6),
            ], $components);
            $quoteFingerprint = SolaStockJournalContract::payloadHash([
                'fingerprint' => $fingerprint, 'purpose' => $purpose, 'components' => $normalize($previous['components']),
                'exchange_rate' => $rate, 'currency' => $native['currency_code'], 'base_currency' => $native['base_currency_code'],
            ]);
            $hold = PurchaseValuationHold::query()->where('organization_id', $organization->id)->where('settlement_uuid', $facts['settlement_uuid'])
                ->where('purpose', $purpose)->where('plan_revision', (int) ($facts['plan_revision'] ?? 1))->lockForUpdate()->first();
            $transitionComplete = ($purpose === 'apply' && $previous['state'] === 'applied')
                || ($purpose === 'reverse' && $previous['state'] === 'reversed');
            if ($operation === 'prepare' && ! $transitionComplete) {
                $current = app(PurchaseCostAdjustmentPlanner::class)->plan($native);
                abort_unless(hash_equals(SolaStockJournalContract::payloadHash($normalize($previous['components'])),
                    SolaStockJournalContract::payloadHash($normalize($current['components']))), 409, __('receiving.valuation_changed'));
                $hold = $holds->acquire(['settlement_uuid' => $facts['settlement_uuid'], 'purpose' => $purpose,
                    'plan_revision' => (int) ($facts['plan_revision'] ?? 1), 'item_id' => $line->item_id,
                    'warehouse_id' => $receipt->warehouse_id, 'receipt_id' => $receipt->id,
                    'source_bill_id' => $facts['source_bill_id']], $quoteFingerprint);
            }
            if (in_array($operation, ['apply', 'reverse', 'release'], true)) {
                abort_unless($hold && hash_equals($hold->plan_fingerprint, (string) ($facts['plan_fingerprint'] ?? ''))
                    && hash_equals($hold->plan_fingerprint, (string) ($authority['plan_fingerprint'] ?? ''))
                    && hash_equals($hold->plan_fingerprint, $quoteFingerprint), 403);
                abort_unless($hold->state === 'active' || $transitionComplete || ($operation === 'release' && $hold->state === 'released'), 409);
            }
            if ($operation === 'release') {
                abort_unless(($authority['abandoned'] ?? false) === true && ($authority['hold_purpose'] ?? null) === $purpose
                    && empty($authority[$purpose === 'apply' ? 'finance_journal_id' : 'finance_reversal_journal_id'])
                    && ! $transitionComplete, 403);
                $hold->update(['state' => 'released']);
            }
            if ($hold && in_array($operation, ['apply', 'reverse'], true)) {
                request()->attributes->set('validated_settlement_hold', [
                    'settlement_uuid' => $hold->settlement_uuid, 'purpose' => $hold->purpose,
                    'plan_revision' => $hold->plan_revision, 'plan_fingerprint' => $hold->plan_fingerprint,
                    'group_bill_id' => $hold->source_bill_id,
                ]);
            }
            $result = match ($operation) {
                'prepare' => $previous,
                'status', 'release' => $previous,
                'apply' => $service->apply($native + ['finance_journal_id' => $authority['finance_journal_id'] ?? null]),
                'reverse' => $service->reverse($native + ['finance_journal_id' => $authority['finance_reversal_journal_id'] ?? null]),
            };

            if (in_array($operation, ['apply', 'reverse'], true) && $hold->state === 'active') {
                $hold->update(['state' => 'released']);
            }
            $receiptLedger = StockLedger::query()->withoutGlobalScope('warehouse_access')
                ->where('organization_id', $organization->id)->where('source_type', GoodsReceipt::class)
                ->where('source_id', $receipt->id)->where('source_line_id', $line->id)
                ->where('direction', 'in')->orderBy('id')->firstOrFail();
            $receiptBase = Decimal::round(Decimal::mul((string) $receiptLedger->total_cost, Decimal::div($settledBaseQty, (string) $receiptLedger->quantity, 12), 12), (int) $native['finance_money_scale']);
            $invoiceAtReceiptBase = Decimal::round(Decimal::div(Decimal::mul($invoiceCost, (string) $facts['quantity'], 8), $rate, 8), (int) $native['finance_money_scale']);

            if ($reversePreview) {
                $result['reverse_components'] = array_map(static fn ($component) => array_replace($component,
                    ['posted_base_amount' => Decimal::mul((string) $component['posted_base_amount'], '-1', 6)]), $result['components']);
            }

            return $result + ['settlement_uuid' => $facts['settlement_uuid'], 'adjustment_id' => $result['adjustment_uuid'],
                'fingerprint' => $fingerprint, 'plan_fingerprint' => $hold?->plan_fingerprint ?? $quoteFingerprint,
                'plan_revision' => (int) ($facts['plan_revision'] ?? 1), 'hold_state' => $hold?->state, 'status' => $result['state'], 'total_delta_base' => $result['allocated_base_difference'],
                'receipt_base_amount' => $receiptBase, 'invoice_acquisition_at_receipt_base' => $invoiceAtReceiptBase];
        });
    }
}
