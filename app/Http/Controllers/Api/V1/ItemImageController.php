<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Item images — stored PRIVATELY (storage/app/private, never the public disk,
 * never a public URL) and served only through this authenticated, org-scoped
 * controller. All ItemImage rows are org-scoped (BelongsToOrganization), so
 * another organization's image 404s. Permissions:
 *   - view/serve  : inventory.view_items   (viewers can see images)
 *   - upload/delete/primary : inventory.manage_items
 */
class ItemImageController extends ApiController
{
    private const DISK = 'local'; // = storage/app/private (private, not symlinked)

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    /** Public metadata for one image (never the raw path). */
    private function present(ItemImage $img): array
    {
        return [
            'id' => $img->id,
            'item_id' => $img->item_id,
            'is_primary' => (bool) $img->is_primary,
            'sort' => (int) $img->sort,
            // Authenticated, org-scoped serve URL (NOT a public file URL).
            'url' => "/inventory/api/v1/item-images/{$img->id}",
        ];
    }

    /** List an item's images (primary first). */
    public function index(Item $item): JsonResponse
    {
        $images = ItemImage::query()->where('item_id', $item->id)
            ->orderByDesc('is_primary')->orderBy('sort')->orderBy('id')->get();

        return $this->success($images->map(fn ($i) => $this->present($i))->all());
    }

    /** Upload an image for an item. First image becomes primary. */
    public function store(Request $request, Item $item): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'max:5120', 'mimetypes:'.implode(',', self::ALLOWED)],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('image');
        // Defence in depth beyond the validator (never trust the extension alone).
        if (! in_array($file->getMimeType(), self::ALLOWED, true)) {
            return $this->error('invalid_image_type', 'Only JPG, PNG or WEBP images are allowed.', 422);
        }

        $orgId = (int) $item->organization_id;
        $ext = $file->extension() ?: 'bin';
        $path = "inventory/item-images/{$orgId}/{$item->id}/".Str::uuid()->toString().'.'.$ext;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        $isFirst = ItemImage::query()->where('item_id', $item->id)->count() === 0;
        $makePrimary = $isFirst || (bool) ($validated['is_primary'] ?? false);

        if ($makePrimary) {
            ItemImage::query()->where('item_id', $item->id)->update(['is_primary' => false]);
        }

        $img = ItemImage::query()->create([
            'organization_id' => $orgId,
            'item_id' => $item->id,
            'path' => $path,
            'is_primary' => $makePrimary,
            'sort' => (int) (ItemImage::query()->where('item_id', $item->id)->max('sort') ?? 0) + 1,
        ]);

        return $this->success($this->present($img), 201);
    }

    /** Stream the private file. Org isolation comes from the scoped binding. */
    public function show(ItemImage $image): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($image->path), 404);

        return Storage::disk(self::DISK)->response(
            $image->path,
            null,
            ['Cache-Control' => 'private, max-age=0, no-store']
        );
    }

    /** Make an image the primary one for its item. */
    public function setPrimary(ItemImage $image): JsonResponse
    {
        ItemImage::query()->where('item_id', $image->item_id)->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);

        return $this->success($this->present($image->fresh()));
    }

    /** Delete an image (file + row). If it was primary, promote the next one. */
    public function destroy(ItemImage $image): JsonResponse
    {
        $itemId = $image->item_id;
        $wasPrimary = (bool) $image->is_primary;

        Storage::disk(self::DISK)->delete($image->path);
        $image->delete();

        if ($wasPrimary) {
            $next = ItemImage::query()->where('item_id', $itemId)->orderBy('sort')->orderBy('id')->first();
            $next?->update(['is_primary' => true]);
        }

        return $this->success(['deleted' => true]);
    }
}
