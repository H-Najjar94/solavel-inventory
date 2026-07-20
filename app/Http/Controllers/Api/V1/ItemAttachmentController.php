<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ItemAttachmentController extends ApiController
{
    private const DISK = 'local';

    private const ALLOWED_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
    ];

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
            'attachment' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $file = $request->file('attachment');
        if (($file->getSize() ?: 0) <= 0) {
            throw ValidationException::withMessages(['attachment' => 'The attachment must not be empty.']);
        }
        $orgId = (int) $item->organization_id;
        $name = $request->input('name') ?: $file->getClientOriginalName();
        if (mb_strlen($name) > 191) {
            throw ValidationException::withMessages(['name' => 'The attachment name must not exceed 191 characters.']);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();
        if (! isset(self::ALLOWED_TYPES[$ext]) || ! in_array($mime, self::ALLOWED_TYPES[$ext], true)) {
            throw ValidationException::withMessages(['attachment' => 'The attachment content does not match an allowed image or PDF type.']);
        }
        if ($ext === 'pdf') {
            $content = file_get_contents($file->getRealPath()) ?: '';
            if (preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile)\b/i', $content)) {
                throw ValidationException::withMessages(['attachment' => 'PDF attachments containing active or embedded content are not allowed.']);
            }
        }
        $path = "inventory/item-attachments/{$orgId}/{$item->id}/".Str::uuid()->toString().'.'.$ext;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        try {
            $attachment = ItemAttachment::query()->create([
                'organization_id' => $orgId,
                'item_id' => $item->id,
                'name' => $name,
                'path' => $path,
                'mime_type' => $mime,
                'size_bytes' => $file->getSize() ?: 0,
            ]);
            InventoryAuditLog::create([
                'organization_id' => $orgId,
                'actor_user_id' => auth()->id(),
                'action' => 'item.attachment.created',
                'entity_type' => 'item_attachment',
                'entity_id' => $attachment->id,
                'after' => ['item_id' => $item->id, 'name' => $name, 'mime_type' => $mime, 'size_bytes' => $file->getSize() ?: 0],
                'document_ref' => $item->sku,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }

        return $this->success($this->present($attachment), 201);
    }

    public function show(ItemAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($attachment->path), 404);

        return Storage::disk(self::DISK)->response(
            $attachment->path,
            $attachment->name,
            [
                'Cache-Control' => 'private, max-age=0, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
            ]
        );
    }

    public function destroy(ItemAttachment $attachment): JsonResponse
    {
        $audit = [
            'organization_id' => $attachment->organization_id,
            'actor_user_id' => auth()->id(),
            'action' => 'item.attachment.deleted',
            'entity_type' => 'item_attachment',
            'entity_id' => $attachment->id,
            'before' => ['item_id' => $attachment->item_id, 'name' => $attachment->name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes],
            'created_at' => now(),
        ];
        Storage::disk(self::DISK)->delete($attachment->path);
        $attachment->delete();
        InventoryAuditLog::create($audit);

        return $this->success(['deleted' => true]);
    }
}
