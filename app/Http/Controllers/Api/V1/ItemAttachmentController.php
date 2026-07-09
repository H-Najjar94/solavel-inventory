<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemAttachmentController extends ApiController
{
    private const DISK = 'local';

    private function present(ItemAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'item_id' => $attachment->item_id,
            'name' => $attachment->name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'download_url' => "/inventory/api/v1/item-attachments/{$attachment->id}",
            'created_at' => $attachment->created_at,
        ];
    }

    public function index(Item $item): JsonResponse
    {
        $rows = ItemAttachment::query()
            ->where('item_id', $item->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ItemAttachment $attachment) => $this->present($attachment));

        return $this->success($rows);
    }

    public function store(Request $request, Item $item): JsonResponse
    {
        $request->validate([
            'attachment' => ['required', 'file', 'max:10240'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $file = $request->file('attachment');
        $orgId = (int) $item->organization_id;
        $name = $request->input('name') ?: $file->getClientOriginalName();
        $ext = $file->getClientOriginalExtension() ?: 'bin';
        $path = "inventory/item-attachments/{$orgId}/{$item->id}/".Str::uuid()->toString().'.'.$ext;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        $attachment = ItemAttachment::query()->create([
            'organization_id' => $orgId,
            'item_id' => $item->id,
            'name' => $name,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
        ]);

        return $this->success($this->present($attachment), 201);
    }

    public function show(ItemAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($attachment->path), 404);

        return Storage::disk(self::DISK)->response(
            $attachment->path,
            $attachment->name,
            ['Cache-Control' => 'private, max-age=0, no-store']
        );
    }

    public function destroy(ItemAttachment $attachment): JsonResponse
    {
        Storage::disk(self::DISK)->delete($attachment->path);
        $attachment->delete();

        return $this->success(['deleted' => true]);
    }
}
