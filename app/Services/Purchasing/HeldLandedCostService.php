<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\IntegrationPurchaseCostAdjustment;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\PurchaseValuationHold;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\FinanceOnboardingReadiness;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\PurchaseCostAdjustmentPlanner;
use App\Services\Stock\PurchaseCostAdjustmentService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/** Value-only landed costs. Financial authority never grants physical warehouse access. */
final class HeldLandedCostService
{
    public function dispatch(array $input, object $organization): array
    {
        $actor = (int) ($input['actor_id'] ?? 0);
        abort_unless($actor > 0 && ($input['authority_kind'] ?? null) === 'posted_landed_cost', 403);
        $operation = substr((string) ($input['action'] ?? ''), strlen('purchasing.landed_cost.'));
        abort_unless(($input['action'] ?? '') === 'purchasing.landed_cost.'.$operation
            && in_array($operation, ['prepare', 'apply', 'reverse', 'status', 'release'], true), 403);
        $facts = validator((array) ($input['data'] ?? []), [
            'operation_uuid' => 'required|uuid', 'landed_cost_id' => 'required|integer|min:1',
            'source_bill_id' => 'required|integer|min:1', 'bill_journal_id' => 'required|integer|min:1',
            'direction' => 'sometimes|in:forward,reverse', 'plan_fingerprint' => 'sometimes|string|size:64',
        ])->validate();
        $facts['direction'] ??= $operation === 'reverse' ? 'reverse' : 'forward';
        abort_unless($operation !== 'reverse' || $facts['direction'] === 'reverse', 403);
        $tenants = app(TenantManager::class);
        $tenants->useTenant((int) $organization->id, $tenants->resolveDatabaseName((int) $organization->client_id));
        $mapping = app(ReceivingRequestService::class)->mapping();
        abort_unless((int) $mapping->finance_organization_id === (int) ($input['finance_organization_id'] ?? 0)
            && (int) $mapping->central_client_id === (int) $organization->client_id
            && (int) $mapping->central_organization_id === (int) $organization->id, 403);
        $setting = IntegrationSetting::query()->where('organization_id', $organization->id)->where('integration', 'solabooks')->firstOrFail();
        abort_unless($setting->mode === 'active', 409);
        app(FinanceOnboardingReadiness::class)->assertComplete((int) $organization->id);
        app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
        // No HTTP under database locks; the private factory independently rechecks native facts.
        $proof = app(SolaBooksOutboxDeliveryService::class)->authorizeLandedCost($facts, $operation, $actor);

        return DB::connection('tenant')->transaction(function () use ($facts, $proof, $mapping, $operation, $actor): array {
            $authority = LandedCostAllocationAuthority::fromLockedNativeProvenance($facts, $proof, $mapping, $operation, $actor);
            $holds = app(PurchaseValuationHoldService::class);
            $holds->lockItems(array_column($authority->sourceAllocations(), 'item_id'));
            $service = app(PurchaseCostAdjustmentService::class);
            $reverse = $authority->reverse();
            $purpose = $reverse ? 'landed_reverse' : 'landed_apply';
            $row = IntegrationPurchaseCostAdjustment::query()->where('organization_id', $authority->organizationId())
                ->where('organization_mapping_uuid', $authority->mappingUuid())->where('destination_document_type', 'landed_cost')
                ->where('destination_document_id', $authority->landedCostId())->where('destination_fingerprint', $authority->fingerprint())
                ->lockForUpdate()->first();
            $quote = $authority->storedQuote();
            if ($operation === 'prepare') {
                $plan = app(PurchaseCostAdjustmentPlanner::class)->planLandedCost($authority);
                if (!$reverse) {
                    $previous = $service->prepareLandedCost($authority, $plan);
                    $row = IntegrationPurchaseCostAdjustment::query()->where('adjustment_uuid', $previous['adjustment_uuid'])->lockForUpdate()->firstOrFail();
                } else {
                    abort_unless($row && $row->state === 'applied', 409);
                    $original = $authority->forwardQuote();
                    // Native Finance inverse undoes its original classification; changed provenance needs review.
                    abort_unless($original && $this->components($original['native_plan']['components']) === $this->components($plan['components']), 409, __('receiving.valuation_changed'));
                }
                $fingerprint = SolaStockJournalContract::payloadHash(['authority' => $authority->fingerprint(), 'purpose' => $purpose, 'plan_revision' => 1, 'native_plan' => $plan]);
                $quote = ['operation_uuid' => $authority->operationUuid(), 'plan_revision' => 1, 'plan_fingerprint' => $fingerprint,
                    'direction' => $authority->direction(), 'total_delta_base' => $plan['allocated_base_difference'],
                    'components' => $plan['components'], 'native_plan' => $plan];
            }
            abort_unless($row && is_array($quote), 409, __('receiving.valuation_pending'));
            $fingerprint = $quote['plan_fingerprint'];
            abort_unless(is_array($quote['native_plan'] ?? null) && hash_equals((string) $fingerprint, SolaStockJournalContract::payloadHash([
                'authority' => $authority->fingerprint(), 'purpose' => $purpose, 'plan_revision' => 1, 'native_plan' => $quote['native_plan'],
            ])), 409);
            abort_unless(($quote['native_plan']['direction'] ?? null) === $authority->direction()
                && ($quote['native_plan']['destination_fingerprint'] ?? null) === $authority->fingerprint()
                && ($quote['native_plan']['operation_uuid'] ?? null) === $authority->operationUuid(), 409);
            $complete = (!$reverse && $row->state === 'applied') || ($reverse && $row->state === 'reversed');
            $pools = [];
            foreach ($authority->sourceAllocations() as $source) $pools[$source['item_id'].'|'.$source['warehouse_id']] = $source;
            ksort($pools, SORT_NATURAL);
            $poolHolds = [];
            foreach ($pools as $source) {
                $uuid = $authority->holdUuid($source['item_id'], $source['warehouse_id'], $reverse ? 'reverse' : 'apply');
                $hold = PurchaseValuationHold::query()->where('organization_id', $authority->organizationId())->where('settlement_uuid', $uuid)
                    ->where('purpose', $purpose)->where('plan_revision', 1)->lockForUpdate()->first();
                if ($operation === 'prepare' && !$complete && !$hold) $hold = $holds->acquire([
                    'settlement_uuid' => $uuid, 'purpose' => $purpose, 'plan_revision' => 1,
                    'item_id' => $source['item_id'], 'warehouse_id' => $source['warehouse_id'],
                    'receipt_id' => $source['receipt_id'], 'source_bill_id' => $authority->sourceBillId(),
                ], $fingerprint);
                abort_unless($hold && hash_equals($hold->plan_fingerprint, $fingerprint), 409);
                if ($operation === 'prepare') abort_unless($hold->state === 'active' || $complete, 409);
                if (in_array($operation, ['apply', 'reverse', 'release'], true)) {
                    abort_unless(hash_equals($fingerprint, (string) ($facts['plan_fingerprint'] ?? ''))
                        && hash_equals($fingerprint, (string) $authority->planFingerprint()), 403);
                    abort_unless($hold->state === 'active' || $complete || ($operation === 'release' && $hold->state === 'released'), 409);
                }
                if (in_array($operation, ['apply', 'reverse'], true) && !$complete) $holds->assertMovable($source['item_id'], $source['warehouse_id'], [
                    'settlement_uuid' => $uuid, 'purpose' => $purpose, 'plan_revision' => 1, 'plan_fingerprint' => $fingerprint,
                ]);
                $poolHolds[] = $hold;
            }
            $result = match ($operation) {
                'apply' => $service->applyLandedCost($authority, $quote['native_plan']),
                'reverse' => $service->reverseLandedCost($authority, $quote['native_plan']),
                default => $service->statusLandedCost($authority),
            };
            if (in_array($operation, ['apply', 'reverse', 'release'], true)) {
                foreach ($poolHolds as $hold) if ($hold->state === 'active') $hold->update(['state' => 'released']);
            }
            return $quote + ['state' => $operation === 'release' ? 'abandoned' : $result['state'], 'adjustment_uuid' => $result['adjustment_uuid']];
        }, 5);
    }

    private function components(array $components): array
    {
        $rows = array_map(static fn ($c) => [(string) $c['destination_role'], (string) $c['destination_source_type'],
            (int) $c['destination_source_id'], (int) $c['receipt_line_id'], (string) $c['base_quantity'], (string) $c['posted_base_amount']], $components);
        sort($rows);
        return $rows;
    }
}
