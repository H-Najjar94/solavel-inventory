<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\ReceivingRequest;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\WarehouseAccessService;
use App\Services\Purchasing\ReceivingRequestService;
use Illuminate\Http\Request;

final class ReceivingRequestController extends ApiController
{
    public function __construct(private ReceivingRequestService $requests) {}

    public function index()
    {
        $query = ReceivingRequest::query();
        $permissions = app(InventoryPermissionService::class);
        $approver = $permissions->can(request()->user(), 'inventory.approve_purchase_orders') || $permissions->can(request()->user(), 'inventory.manage_adjustments');
        $warehouses = app(WarehouseAccessService::class)->allowedIds();
        if ($warehouses !== null) {
            $query->where(fn ($q) => $q->whereIn('warehouse_id', $warehouses)->when($approver, fn ($q) => $q->orWhereNull('warehouse_id')));
        } elseif (! $approver) {
            $query->whereNotNull('warehouse_id');
        }

        return $this->success($query->orderByDesc('id')->limit(100)->get()->map(fn ($r) => $this->requests->status($r)));
    }

    public function approve(Request $request, ReceivingRequest $receiving_request)
    {
        $d = $request->validate(['warehouse_id' => 'required|integer|min:1']);

        return $this->success($this->requests->approve($receiving_request, $d['warehouse_id']));
    }

    public function show(ReceivingRequest $receiving_request)
    {
        if ($receiving_request->warehouse_id) {
            app(WarehouseAccessService::class)->assertAllowed((int) $receiving_request->warehouse_id);
        } else {
            $permissions = app(InventoryPermissionService::class);
            abort_unless($permissions->can(request()->user(), 'inventory.approve_purchase_orders') || $permissions->can(request()->user(), 'inventory.manage_adjustments'), 403, __('inventory.purchasing.approval_needed'));
        }

        return $this->success($this->requests->status($receiving_request));
    }

    public function status(Request $request)
    {
        abort_unless($request->attributes->get('purchasing_authority'), 403);
        $d = $request->validate(['request_uuid' => 'required|uuid', 'source_bill_id' => 'required|integer|min:1']);
        $r = ReceivingRequest::query()->where('request_uuid', $d['request_uuid'])->where('source_bill_id', $d['source_bill_id'])->firstOrFail();

        return $this->success($this->requests->status($r));
    }

    public function upsert(Request $request)
    {
        abort_unless($request->attributes->get('purchasing_authority'), 403);
        $d = $request->validate([
            'schema_version' => 'required|in:purchasing.v1', 'source_status' => 'sometimes|in:posted', 'billing_policy' => 'sometimes|in:billed-unreceived-v1', 'posted_bill_journal_id' => 'sometimes|integer|min:1', 'reopened_from_bill_journal_id' => 'sometimes|integer|min:1', 'request_uuid' => 'required|uuid', 'source_bill_id' => 'required|integer|min:1', 'source_bill_number' => 'nullable|string|max:100', 'source_revision' => 'required|string|size:64', 'expected_revision' => 'nullable|string|size:64', 'supplier_external_id' => 'required|integer|min:1', 'currency_code' => 'required|regex:/^[A-Z]{3}$/', 'exchange_rate' => 'nullable|numeric|gt:0', 'exchange_rate_date' => 'nullable|date', 'invoice_date' => 'nullable|date', 'lines' => 'required|array|min:1', 'lines.*.source_line_id' => 'required|integer|min:1|distinct', 'lines.*.item_external_id' => 'required|integer|min:1', 'lines.*.unit_external_id' => 'required|integer|min:1', 'lines.*.quantity' => 'required|numeric|gt:0', 'lines.*.unit_cost' => 'required|numeric|min:0']);
        $d['source_bill_number'] = $d['source_bill_number'] ?? '';

        return $this->success($this->requests->upsert($d));
    }

    public function cancel(Request $request)
    {
        abort_unless($request->attributes->get('purchasing_authority'), 403);
        $d = $request->validate(['request_uuid' => 'required|uuid', 'source_bill_id' => 'required|integer|min:1', 'source_revision' => 'required|string|size:64']);
        $r = ReceivingRequest::query()->where('request_uuid', $d['request_uuid'])->where('source_bill_id', $d['source_bill_id'])->firstOrFail();

        return $this->success($this->requests->cancel($r, $d['source_revision']));
    }
}
