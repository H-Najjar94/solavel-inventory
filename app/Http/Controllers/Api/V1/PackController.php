<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Pack;
use App\Models\Tenant\PickList;
use App\Services\Documents\PackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PackController extends ApiController
{
    public function __construct(private PackService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Pack::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('sales_order_id'), fn ($q) => $q->where('sales_order_id', (int) $request->query('sales_order_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Pack $pack): JsonResponse
    {
        return $this->success(['pack' => $pack->load('lines')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pick_list_id' => ['required','integer'],
            'pack_number' => ['required','string','max:50'],
            'package_count' => ['nullable','integer','min:1'],
            'carrier' => ['nullable','string','max:100'],
            'tracking_number' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
        ]);
        $pl = PickList::query()->findOrFail($data['pick_list_id']);
        try {
            $pack = $this->service->createFromPickList($pl, collect($data)->except('pick_list_id')->toArray());
        } catch (RuntimeException $e) {
            return $this->error('pack_create_failed', $e->getMessage(), 422);
        }

        return $this->success($pack, 201);
    }

    public function update(Request $request, Pack $pack): JsonResponse
    {
        $data = $request->validate([
            'packs' => ['nullable','array'],
            'package_count' => ['nullable','integer','min:1'],
            'package_weight' => ['nullable','numeric','min:0'],
            'carrier' => ['nullable','string','max:100'],
            'tracking_number' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
        ]);
        try {
            $updated = $this->service->updatePacks(
                $pack, $data['packs'] ?? [],
                collect($data)->except('packs')->toArray()
            );
        } catch (RuntimeException $e) {
            return $this->error('pack_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function markPacked(Pack $pack): JsonResponse
    {
        try { $packed = $this->service->markPacked($pack); }
        catch (RuntimeException $e) { return $this->error('pack_finalize_failed', $e->getMessage(), 422); }

        return $this->success($packed);
    }
}
