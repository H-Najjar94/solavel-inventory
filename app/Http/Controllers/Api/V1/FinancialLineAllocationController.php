<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Integration\FinancialLineAllocationService;
use App\Services\Stock\PurchaseCostAdjustmentService;
use App\Models\Tenant\{GoodsReceipt, Shipment, SalesReturn, IntegrationFinancialLineAllocation, IntegrationOrganizationMapping};
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialLineAllocationController extends ApiController
{
    public function __construct(private FinancialLineAllocationService $allocations, private PurchaseCostAdjustmentService $costAdjustments) {}

    public function reserve(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));
        return $this->success($this->allocations->reserve($input));
    }

    /** Validate an existing reviewed reservation without giving the Finance actor source browsing rights. */
    public function reviewStatus(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('verified_workspace_action') === 'finance-allocations.review-status', 403, 'signed_finance_review_required');
        $input = $request->validate([
            'destination_document_type' => ['required', 'in:supplier_bill,customer_invoice,customer_credit_note'],
            'destination_document_id' => ['required', 'integer', 'min:1'],
            'destination_fingerprint' => ['required', 'string', 'size:64'],
            'sources' => ['required', 'array', 'min:1', 'max:100'],
            'sources.*.mapping_uuid' => ['required', 'uuid'],
            'sources.*.document_type' => ['required', 'in:goods_receipt,shipment,sales_return'],
            'sources.*.document_id' => ['required', 'integer', 'min:1'],
        ]);
        $connection = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', app(\App\Tenancy\OrganizationContext::class)->idOrFail())
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
            ->where('status', 'verified')->where('activation_state', 'active')->firstOrFail();
        $rows = IntegrationFinancialLineAllocation::query()
            ->where('organization_mapping_uuid', $connection->mapping_uuid)
            ->where('destination_document_type', $input['destination_document_type'])
            ->where('destination_document_id', $input['destination_document_id'])
            ->where('destination_fingerprint', $input['destination_fingerprint'])->get();
        abort_if($rows->isEmpty() || $rows->contains(fn ($row) => $row->state !== 'draft_reserved'
            || ($row->reserved_until && $row->reserved_until->isPast())), 409, 'finance_review_reservation_missing_or_expired');
        $sourceIds = $rows->pluck('source_document_mapping_uuid')->unique()->values()->all();
        // Keep the complete frozen snapshot for comparison. Laravel's validated()
        // result intentionally retains only the three indexed identity fields.
        $frozen = collect($request->input('sources'))->keyBy('mapping_uuid');
        abort_unless($frozen->count() === count($sourceIds) && $frozen->keys()->sort()->values()->all() === collect($sourceIds)->sort()->values()->all(), 409, 'finance_review_source_mismatch');
        $snapshots = app(FinanceDocumentSourceController::class);
        foreach ($frozen as $source) {
            $model = match ($source['document_type']) {
                'goods_receipt' => GoodsReceipt::withoutGlobalScopes()->where('organization_id', $connection->solastock_organization_id)->findOrFail($source['document_id']),
                'shipment' => Shipment::withoutGlobalScopes()->where('organization_id', $connection->solastock_organization_id)->findOrFail($source['document_id']),
                'sales_return' => SalesReturn::withoutGlobalScopes()->where('organization_id', $connection->solastock_organization_id)->findOrFail($source['document_id']),
            };
            $response = match ($source['document_type']) {
                'goods_receipt' => $snapshots->receipt($request, $model),
                'shipment' => $snapshots->shipment($request, $model),
                'sales_return' => $snapshots->salesReturn($request, $model),
            };
            $current = (array) data_get($response->getData(true), 'data', []);
            foreach (['mapping_uuid', 'source_key', 'document_type', 'document_id', 'organization_id', 'warehouse_id', 'currency', 'base_currency', 'exchange_rate', 'party'] as $field) {
                abort_unless(($current[$field] ?? null) === ($source[$field] ?? null), 409, 'finance_review_source_changed');
            }
            $lines = static fn (array $value): array => array_map(
                static fn (array $line): array => array_diff_key($line, array_flip(['available_base_quantity', 'entered_finance_unit_id', 'base_finance_unit_id'])),
                (array) ($value['lines'] ?? []),
            );
            abort_unless($lines($current) === $lines($source), 409, 'finance_review_source_changed');
        }
        return $this->success(['ready' => true]);
    }

    public function commit(Request $request): JsonResponse
    {
        return $this->transition($request, 'posted');
    }

    public function release(Request $request): JsonResponse
    {
        return $this->transition($request, 'released');
    }

    public function reverse(Request $request): JsonResponse
    {
        return $this->transition($request, 'reversed');
    }

    public function prepareCostAdjustment(Request $request): JsonResponse
    {
        return $this->success($this->costAdjustments->prepare($request->validate($this->costAdjustmentRules(true))));
    }

    public function applyCostAdjustment(Request $request): JsonResponse
    {
        return $this->success($this->costAdjustments->apply($request->validate($this->costAdjustmentRules(false))));
    }

    public function reverseCostAdjustment(Request $request): JsonResponse
    {
        return $this->success($this->costAdjustments->reverse($request->validate($this->costAdjustmentRules(false))));
    }

    private function costAdjustmentRules(bool $prepare): array
    {
        $rules = [
            'organization_mapping_uuid'=>['required','uuid'],'destination_document_id'=>['required','integer','min:1'],
            'destination_fingerprint'=>['required','string','size:64'],
        ];
        if ($prepare) $rules += ['currency_code'=>['required','string','size:3'],'base_currency_code'=>['required','string','size:3'],
            'exchange_rate'=>['required','numeric','gt:0'],'finance_money_scale'=>['required','integer','between:0,6'],
            'discount_posting_mode'=>['required','in:net,gross']];
        return $rules;
    }

    private function transition(Request $request, string $state): JsonResponse
    {
        $input = $request->validate([
            'destination_document_type' => ['required', 'in:supplier_bill,customer_invoice,customer_credit_note'],
            'destination_document_id' => ['required', 'integer', 'min:1'],
            'destination_fingerprint' => ['required', 'string', 'size:64'],
        ]);
        return $this->success($this->allocations->transition($input, $state));
    }

    private function rules(bool $withLines): array
    {
        return [
            'destination_document_type' => ['required', 'in:supplier_bill,customer_invoice,customer_credit_note'],
            'destination_document_id' => ['required', 'integer', 'min:1'],
            'destination_revision' => ['required', 'string', 'max:80'],
            'destination_fingerprint' => ['required', 'string', 'size:64'],
            'allocation_kind' => ['required', 'in:bill,invoice,customer_credit'],
            'currency_code' => ['required', 'string', 'size:3'], 'base_currency_code' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'allocations' => ['required', 'array', 'min:1', 'max:500'],
            'allocations.*.source_document_mapping_uuid' => ['required', 'uuid'],
            'allocations.*.source_document_type' => ['required', 'in:goods_receipt,shipment,sales_return'],
            'allocations.*.source_document_id' => ['required', 'integer', 'min:1'],
            'allocations.*.source_line_id' => ['required', 'integer', 'min:1'],
            'allocations.*.stock_item_id' => ['required', 'integer', 'min:1'],
            'allocations.*.destination_line_id' => ['required', 'integer', 'min:1'],
            'allocations.*.entered_quantity' => ['required', 'numeric', 'gt:0'],
            'allocations.*.base_quantity' => ['required', 'numeric', 'gt:0'],
            'allocations.*.destination_quantity' => ['required', 'numeric', 'gt:0'],
            'allocations.*.destination_unit_id' => ['required', 'integer', 'min:1'],
            'allocations.*.destination_unit_price' => ['required', 'numeric', 'min:0'],
            'allocations.*.destination_gross' => ['required', 'numeric', 'min:0'],
            'allocations.*.line_discount_allocated' => ['sometimes', 'numeric', 'min:0'],
            'allocations.*.document_discount_allocated' => ['sometimes', 'numeric', 'min:0'],
            'allocations.*.destination_net' => ['required', 'numeric', 'min:0'],
        ];
    }
}
