<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Integration\FinancialLineAllocationService;
use App\Services\Stock\PurchaseCostAdjustmentService;
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
