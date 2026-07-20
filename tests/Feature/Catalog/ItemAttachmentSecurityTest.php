<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\Api\V1\ItemAttachmentController;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\ItemAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class ItemAttachmentSecurityTest extends TestCase
{
    use TenantAware;

    private function upload($item, UploadedFile $file, ?string $name = null): array
    {
        $request = Request::create("/items/{$item->id}/attachments", 'POST', $name ? ['name' => $name] : [], [], [
            'attachment' => $file,
        ]);

        return app(ItemAttachmentController::class)->store($request, $item)->getData(true)['data'];
    }

    #[Test]
    public function valid_duplicate_and_arabic_names_are_isolated_and_deletion_removes_storage(): void
    {
        Storage::fake('local');
        $this->useTenantA();
        $item = F::item(['sku' => 'ATTACH-SEC']);

        $first = $this->upload($item, UploadedFile::fake()->create('دليل.pdf', 8, 'application/pdf'));
        $second = $this->upload($item, UploadedFile::fake()->create('دليل.pdf', 8, 'application/pdf'));
        $this->assertSame('دليل.pdf', $first['name']);
        $this->assertSame('دليل.pdf', $second['name']);
        $this->assertNotSame(ItemAttachment::findOrFail($first['id'])->path, ItemAttachment::findOrFail($second['id'])->path);
        $this->assertTrue(InventoryAuditLog::query()->where('action', 'item.attachment.created')->where('entity_id', $first['id'])->exists());

        $path = ItemAttachment::findOrFail($first['id'])->path;
        Storage::disk('local')->assertExists($path);
        app(ItemAttachmentController::class)->destroy(ItemAttachment::findOrFail($first['id']));
        Storage::disk('local')->assertMissing($path);
        $this->assertNull(ItemAttachment::query()->find($first['id']));
        $this->assertTrue(InventoryAuditLog::query()->where('action', 'item.attachment.deleted')->where('entity_id', $first['id'])->exists());

        $this->useTenantB();
        $this->assertNull(ItemAttachment::query()->find($second['id']));
    }

    #[Test]
    public function unsupported_oversized_empty_mismatched_executable_and_long_uploads_leave_no_orphans(): void
    {
        Storage::fake('local');
        $this->useTenantA();
        $item = F::item(['sku' => 'ATTACH-NEG']);
        $cases = [
            UploadedFile::fake()->create('notes.txt', 2, 'text/plain'),
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
            UploadedFile::fake()->create('empty.pdf', 0, 'application/pdf'),
            UploadedFile::fake()->create('image.jpg', 2, 'application/pdf'),
            UploadedFile::fake()->create('payload.php', 2, 'application/x-php'),
            UploadedFile::fake()->createWithContent('active.pdf', "%PDF-1.4\n/JavaScript /JS /Launch\n%%EOF"),
        ];

        foreach ($cases as $file) {
            try {
                $this->upload($item, $file);
                $this->fail("{$file->getClientOriginalName()} should have been rejected.");
            } catch (ValidationException) {
                $this->assertSame(0, ItemAttachment::query()->count());
                $this->assertSame([], Storage::disk('local')->allFiles('inventory/item-attachments'));
            }
        }

        try {
            $this->upload($item, UploadedFile::fake()->create('valid.pdf', 2, 'application/pdf'), str_repeat('a', 192));
            $this->fail('A name longer than 191 characters should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
            $this->assertSame([], Storage::disk('local')->allFiles('inventory/item-attachments'));
        }
    }
}
