<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreSalesReturnRequest;
use App\Models\Tenant\SalesReturn;
use App\Services\Documents\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SalesReturnController extends ApiController
{
    public function __construct(private SalesReturnService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = SalesReturn::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('shipment_id'), fn ($q) => $q->where('shipment_id', (int) $request->query('shipment_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(SalesReturn $sales_return): JsonResponse
    {
        $sales_return->load('lines');
        $ledger = \App\Models\Tenant\StockLedger::query()
            ->where('source_type', SalesReturn::class)->where('source_id', $sales_return->id)->get();

        return $this->success(['sales_return' => $sales_return, 'ledger' => $ledger]);
    }

    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $return = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);

        return $this->success($return, 201);
    }

    public function update(StoreSalesReturnRequest $request, SalesReturn $sales_return): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->service->updateDraft($sales_return, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('sales_return_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function post(SalesReturn $sales_return): JsonResponse
    {
        try { $posted = $this->service->post($sales_return); }
        catch (RuntimeException $e) { return $this->error('sales_return_post_failed', $e->getMessage(), 422); }

        return $this->success($posted->fresh('lines'));
    }
}
