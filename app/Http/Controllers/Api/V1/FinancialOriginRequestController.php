<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FinancialOriginRequest;
use App\Services\Access\{InventoryPermissionService, WarehouseAccessService};
use App\Services\FinancialOrigins\{OriginDispatchService, OriginRequestService};
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Native warehouse users use their own app authority, never an accountant's identity. */
final class FinancialOriginRequestController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['side'=>'required|in:purchase,sales', 'state'=>'sometimes|in:active,history,cancelled']);
        $permissions = app(InventoryPermissionService::class);
        $side = $data['side'];
        abort_unless($permissions->can($request->user(), $side === 'sales' ? 'inventory.view_sales' : 'inventory.view_stock'), 403);
        if (!Schema::connection('tenant')->hasTable('stock_financial_origin_requests')) {
            return response()->json(['success'=>true, 'data'=>[], 'available'=>false]);
        }
        $organization = app(OrganizationContext::class)->idOrFail();
        $allowed = app(WarehouseAccessService::class)->allowedIds();
        $mayAssign = $permissions->can($request->user(), $side === 'sales' ? 'inventory.manage_sales_orders' : 'inventory.receive_goods');
        $query = FinancialOriginRequest::query()->where('organization_id', $organization)->where('side', $side);
        if ($allowed !== null) {
            $query->where(function ($query) use ($allowed, $mayAssign) {
                $query->whereIn('warehouse_id', $allowed);
                if ($mayAssign && $allowed !== []) $query->orWhereNull('warehouse_id');
            });
        }
        $state = $data['state'] ?? 'active';
        $query->whereIn('status', match ($state) {
            'cancelled'=>['cancelled'], 'history'=>['complete', 'cancelled'], default=>['pending', 'partial'],
        });
        $rows = $query->latest('id')->paginate(25);
        $service = app(OriginRequestService::class);
        return response()->json(['success'=>true, 'available'=>true,
            'data'=>$rows->getCollection()->map(fn ($row)=>$service->summary($row))->all(),
            'pagination'=>['page'=>$rows->currentPage(), 'last_page'=>$rows->lastPage(), 'total'=>$rows->total()]]);
    }

    public function options(Request $request, string $uuid)
    {
        $data = $this->identity($request, $uuid);
        return response()->json(['success'=>true, 'data'=>app(OriginDispatchService::class)->optionsNative($data, (int)$request->user()->id)]);
    }

    public function approve(Request $request, string $uuid)
    {
        $data = $this->identity($request, $uuid) + $request->validate(['request_revision'=>'required|string|size:64', 'warehouse_id'=>'required|integer|min:1']);
        return response()->json(['success'=>true, 'data'=>app(OriginRequestService::class)->approveNative($data, (int)$request->user()->id)]);
    }

    public function prepare(Request $request, string $uuid)
    {
        $data = $this->physicalPayload($request, $uuid);
        return response()->json(['success'=>true, 'data'=>app(OriginDispatchService::class)->prepareNative($data, (int)$request->user()->id)]);
    }

    public function execute(Request $request, string $uuid)
    {
        $data = $this->physicalPayload($request, $uuid);
        return response()->json(['success'=>true, 'data'=>app(OriginDispatchService::class)->executeNative($data, (int)$request->user()->id)]);
    }

    public function status(Request $request, string $uuid)
    {
        $data = $this->identity($request, $uuid) + $request->validate(['operation_uuid'=>'required|uuid']);
        return response()->json(['success'=>true, 'data'=>app(OriginDispatchService::class)->statusNative($data, (int)$request->user()->id)]);
    }

    public function abandon(Request $request, string $uuid)
    {
        $data = $this->identity($request, $uuid) + $request->validate(['operation_uuid'=>'required|uuid']);
        return response()->json(['success'=>true, 'data'=>app(OriginDispatchService::class)->abandonNative($data, (int)$request->user()->id)]);
    }

    private function physicalPayload(Request $request, string $uuid): array
    {
        $identity = $this->identity($request, $uuid);
        $data = $request->validate([
            'arrival_confirmed'=>'required|accepted', 'operation_uuid'=>'required|uuid',
            'request_revision'=>'required|string|size:64', 'warehouse_id'=>'required|integer|min:1',
            'physical_date'=>'required|date_format:Y-m-d', 'reserve_stock'=>'sometimes|boolean',
            'lines'=>'required|array|min:1', 'lines.*.request_line_id'=>'required|integer|min:1|distinct',
            'lines.*.source_document_line_id'=>'required|integer|min:1|distinct', 'lines.*.unit_id'=>'required|integer|min:1',
            'lines.*.quantity'=>'required|numeric|gt:0', 'lines.*.bin_id'=>'nullable|integer|min:1',
            'lines.*.lot_id'=>'nullable|integer|min:1', 'lines.*.variant_id'=>'nullable|integer|min:1',
            'lines.*.serial_ids'=>'nullable|array', 'lines.*.serial_ids.*'=>'integer|min:1|distinct',
            'lines.*.serials'=>'nullable|array', 'lines.*.serials.*'=>'string|max:255|distinct',
            'lines.*.lot_code'=>'nullable|string|max:255', 'lines.*.expiry_date'=>'nullable|date_format:Y-m-d',
            'lines.*.unit_cost'=>'nullable|numeric|min:0',
        ]);
        unset($data['arrival_confirmed']);
        return $identity + $data;
    }

    private function identity(Request $request, string $uuid): array
    {
        abort_unless(Schema::connection('tenant')->hasTable('stock_financial_origin_requests'), 503);
        $organization = app(OrganizationContext::class)->idOrFail();
        $row = FinancialOriginRequest::query()->where('organization_id', $organization)->where('request_uuid', $uuid)->firstOrFail();
        $permission = $row->side === 'sales' ? 'inventory.view_sales' : 'inventory.view_stock';
        abort_unless(app(InventoryPermissionService::class)->can($request->user(), $permission), 403);
        if ($row->warehouse_id) app(WarehouseAccessService::class)->assertAllowed((int)$row->warehouse_id);
        return ['request_uuid'=>$row->request_uuid, 'source_document_type'=>$row->source_document_type,
            'source_document_id'=>(int)$row->source_document_id, 'source_journal_id'=>(int)$row->source_journal_id];
    }
}
