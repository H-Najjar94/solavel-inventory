<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Item;
use App\Services\Catalog\ItemParts\ItemPartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Item Parts links: catalog metadata only. */
class ItemPartController extends ApiController
{
    public function __construct(private readonly ItemPartService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'integer', 'min:1']]);
        $item = Item::query()->whereKey($data['item_id'])->firstOrFail();

        return $this->success(['item_id' => $item->id] + $this->service->links($item->id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_item_id' => ['required', 'integer', 'min:1'],
            'part_item_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->service->attach((int) $data['parent_item_id'], (int) $data['part_item_id'], $request->user()?->id);

        return $this->success(['parent_item_id' => (int) $data['parent_item_id']] + $this->service->links((int) $data['parent_item_id']));
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_item_id' => ['required', 'integer', 'min:1'],
            'part_item_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->service->detach((int) $data['parent_item_id'], (int) $data['part_item_id'], $request->user()?->id);

        return $this->success(['deleted' => true]);
    }
}
