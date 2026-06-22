<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Warehouse images — stored PRIVATELY (storage/app/private, never the public
 * disk, never a public URL) and served only through this authenticated,
 * org-scoped controller. WarehouseImage rows are org-scoped, so another
 * organization's image 404s. Permissions:
 *   - view/serve  : inventory.view_warehouses   (viewers can see images)
 *   - upload/delete/primary : inventory.manage_warehouses
 *
 * UI treats the primary as a wide banner; that is purely presentational — the
 * storage/serve model is identical to item images.
 */
class WarehouseImageController extends ApiController
{
    private const DISK = 'local'; // = storage/app/private (private, not symlinked)

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    private function present(WarehouseImage $img): array
    {
        return [
            'id' => $img->id,
            'warehouse_id' => $img->warehouse_id,
            'is_primary' => (bool) $img->is_primary,
            'sort' => (int) $img->sort,
            'url' => "/inventory/api/v1/warehouse-images/{$img->id}",
        ];
    }

    public function index(Warehouse $warehouse): JsonResponse
    {
        $images = WarehouseImage::query()->where('warehouse_id', $warehouse->id)
            ->orderByDesc('is_primary')->orderBy('sort')->orderBy('id')->get();

        return $this->success($images->map(fn ($i) => $this->present($i))->all());
    }

    public function store(Request $request, Warehouse $warehouse): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'max:5120', 'mimetypes:'.implode(',', self::ALLOWED)],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('image');
        if (! in_array($file->getMimeType(), self::ALLOWED, true)) {
            return $this->error('invalid_image_type', 'Only JPG, PNG or WEBP images are allowed.', 422);
        }

        $orgId = (int) $warehouse->organization_id;
        $ext = $file->extension() ?: 'bin';
        $path = "inventory/warehouse-images/{$orgId}/{$warehouse->id}/".Str::uuid()->toString().'.'.$ext;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        $isFirst = WarehouseImage::query()->where('warehouse_id', $warehouse->id)->count() === 0;
        $makePrimary = $isFirst || (bool) ($validated['is_primary'] ?? false);

        if ($makePrimary) {
            WarehouseImage::query()->where('warehouse_id', $warehouse->id)->update(['is_primary' => false]);
        }

        $img = WarehouseImage::query()->create([
            'organization_id' => $orgId,
            'warehouse_id' => $warehouse->id,
            'path' => $path,
            'is_primary' => $makePrimary,
            'sort' => (int) (WarehouseImage::query()->where('warehouse_id', $warehouse->id)->max('sort') ?? 0) + 1,
        ]);

        return $this->success($this->present($img), 201);
    }

    public function show(WarehouseImage $image): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($image->path), 404);

        return Storage::disk(self::DISK)->response(
            $image->path,
            null,
            ['Cache-Control' => 'private, max-age=0, no-store']
        );
    }

    public function setPrimary(WarehouseImage $image): JsonResponse
    {
        WarehouseImage::query()->where('warehouse_id', $image->warehouse_id)->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);

        return $this->success($this->present($image->fresh()));
    }

    public function destroy(WarehouseImage $image): JsonResponse
    {
        $warehouseId = $image->warehouse_id;
        $wasPrimary = (bool) $image->is_primary;

        Storage::disk(self::DISK)->delete($image->path);
        $image->delete();

        if ($wasPrimary) {
            $next = WarehouseImage::query()->where('warehouse_id', $warehouseId)->orderBy('sort')->orderBy('id')->first();
            $next?->update(['is_primary' => true]);
        }

        return $this->success(['deleted' => true]);
    }
}
