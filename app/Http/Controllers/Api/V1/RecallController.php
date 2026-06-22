<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreRecallRequest;
use App\Models\Tenant\Recall;
use App\Services\Traceability\RecallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Recall case workspace. Cases flag affected lots/serials and compute impact
 * from the canonical ledger; they never move stock or notify customers.
 */
class RecallController extends ApiController
{
    public function __construct(private RecallService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Recall::query()->with('item:id,sku,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', (int) $request->query('item_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Recall $recall): JsonResponse
    {
        $recall->load(['lines', 'actions', 'item:id,sku,name']);

        return $this->success(['recall' => $recall, 'impact' => $this->service->impact($recall)]);
    }

    public function store(StoreRecallRequest $request): JsonResponse
    {
        $data = $request->validated();
        $recall = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);

        return $this->success($recall, 201);
    }

    public function update(StoreRecallRequest $request, Recall $recall): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->service->updateDraft($recall, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('recall_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function activate(Recall $recall): JsonResponse
    {
        try { $r = $this->service->activate($recall); }
        catch (RuntimeException $e) { return $this->error('recall_activate_failed', $e->getMessage(), 422); }

        return $this->success($r->fresh('lines'));
    }

    public function close(Request $request, Recall $recall): JsonResponse
    {
        try { $r = $this->service->close($recall, $request->input('notes')); }
        catch (RuntimeException $e) { return $this->error('recall_close_failed', $e->getMessage(), 422); }

        return $this->success($r);
    }

    public function impactPreview(Recall $recall): JsonResponse
    {
        return $this->success($this->service->impact($recall));
    }
}
