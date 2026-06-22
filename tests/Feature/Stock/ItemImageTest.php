<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemImageController;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemImage;
use App\Services\Access\InventoryPermissionService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Private item images: stored on the local (private) disk, served only via the
 * authenticated org-scoped controller — never a public URL/disk. Viewers can
 * view; only manage_items may upload/delete/replace. First image is primary.
 */
class ItemImageTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function controller(): ItemImageController
    {
        return app(ItemImageController::class);
    }

    private function uploadRequest(UploadedFile $file): Request
    {
        return Request::create('/', 'POST', [], [], ['image' => $file]);
    }

    private function upload(Item $item, UploadedFile $file): array
    {
        return $this->controller()->store($this->uploadRequest($file), $item)->getData(true)['data'];
    }

    #[Test]
    public function uploading_a_jpg_stores_it_privately_and_marks_it_primary(): void
    {
        $this->useTenantA();
        $item = F::item();

        $data = $this->upload($item, UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg'));

        $this->assertTrue($data['is_primary'], 'first image must be primary');
        $img = ItemImage::query()->findOrFail($data['id']);

        // Stored on the PRIVATE disk, under an org/item-scoped path…
        $this->assertStringStartsWith("inventory/item-images/{$item->organization_id}/{$item->id}/", $img->path);
        Storage::disk('local')->assertExists($img->path);
        // …and NOT on the public disk.
        Storage::disk('public')->assertMissing($img->path);
        // The serve URL is the authenticated API route, not a public file path.
        $this->assertSame("/inventory/api/v1/item-images/{$img->id}", $data['url']);
    }

    #[Test]
    public function png_and_webp_are_accepted_but_other_types_are_rejected(): void
    {
        $this->useTenantA();
        $item = F::item();

        $this->upload($item, UploadedFile::fake()->create('a.png', 100, 'image/png'));
        $this->upload($item, UploadedFile::fake()->create('b.webp', 100, 'image/webp'));
        $this->assertSame(2, ItemImage::query()->where('item_id', $item->id)->count());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->upload($item, UploadedFile::fake()->create('evil.pdf', 100, 'application/pdf'));
    }

    #[Test]
    public function a_second_image_is_not_primary_and_set_primary_swaps_it(): void
    {
        $this->useTenantA();
        $item = F::item();
        $first = $this->upload($item, UploadedFile::fake()->create('1.jpg', 100, 'image/jpeg'));
        $second = $this->upload($item, UploadedFile::fake()->create('2.jpg', 100, 'image/jpeg'));

        $this->assertTrue($first['is_primary']);
        $this->assertFalse($second['is_primary']);

        $this->controller()->setPrimary(ItemImage::query()->findOrFail($second['id']));

        $this->assertFalse((bool) ItemImage::query()->find($first['id'])->is_primary);
        $this->assertTrue((bool) ItemImage::query()->find($second['id'])->is_primary);
    }

    #[Test]
    public function deleting_the_primary_promotes_the_next_image_and_removes_the_file(): void
    {
        $this->useTenantA();
        $item = F::item();
        $first = $this->upload($item, UploadedFile::fake()->create('1.jpg', 100, 'image/jpeg'));
        $second = $this->upload($item, UploadedFile::fake()->create('2.jpg', 100, 'image/jpeg'));
        $firstModel = ItemImage::query()->findOrFail($first['id']);
        $path = $firstModel->path;

        $this->controller()->destroy($firstModel);

        Storage::disk('local')->assertMissing($path);
        $this->assertNull(ItemImage::query()->find($first['id']));
        $this->assertTrue((bool) ItemImage::query()->find($second['id'])->is_primary, 'next image is promoted to primary');
    }

    #[Test]
    public function images_are_org_scoped_other_org_cannot_resolve_them(): void
    {
        $this->useTenantA();
        $item = F::item();
        $img = $this->upload($item, UploadedFile::fake()->create('1.jpg', 100, 'image/jpeg'));
        $imgId = $img['id'];

        // Under ORG_B the image row must not resolve (route binding → 404).
        app(OrganizationContext::class)->set(TenantTestManager::ORG_B);
        $this->assertNull(ItemImage::query()->find($imgId), 'ORG_A image must be invisible to ORG_B');
    }

    #[Test]
    public function multiple_images_can_be_uploaded_and_listed_primary_first(): void
    {
        $this->useTenantA();
        $item = F::item();

        $this->upload($item, UploadedFile::fake()->create('1.jpg', 80, 'image/jpeg'));
        $this->upload($item, UploadedFile::fake()->create('2.png', 80, 'image/png'));
        $this->upload($item, UploadedFile::fake()->create('3.webp', 80, 'image/webp'));

        $list = $this->controller()->index($item)->getData(true)['data'];
        $this->assertCount(3, $list);
        // Listed primary-first; exactly one primary.
        $this->assertTrue($list[0]['is_primary']);
        $this->assertSame(1, collect($list)->where('is_primary', true)->count());
        // Every URL is the private authenticated serve route, never a public path.
        foreach ($list as $img) {
            $this->assertSame("/inventory/api/v1/item-images/{$img['id']}", $img['url']);
            $this->assertStringNotContainsString('/storage/', $img['url']);
        }
    }

    #[Test]
    public function stored_file_lives_under_private_root_not_public(): void
    {
        $this->useTenantA();
        $item = F::item();
        $data = $this->upload($item, UploadedFile::fake()->create('p.jpg', 80, 'image/jpeg'));
        $img = ItemImage::query()->findOrFail($data['id']);

        // The stored path is relative to the PRIVATE 'local' disk root
        // (storage/app/private) — it is not under any public/web-served path.
        $this->assertStringStartsWith('inventory/item-images/', $img->path);
        $this->assertStringNotContainsString('public', $img->path);
        Storage::disk('local')->assertExists($img->path);
        Storage::disk('public')->assertMissing($img->path);
    }

    #[Test]
    public function viewer_can_view_images_but_cannot_upload_or_delete(): void
    {
        app(OrganizationContext::class)->set(660066);
        $perms = new class(app(OrganizationContext::class), 'some_unknown_role') extends InventoryPermissionService {
            public function __construct(OrganizationContext $ctx, private ?string $forced)
            {
                parent::__construct($ctx);
            }

            protected function fetchCentralRole(int $userId, int $orgId): ?string
            {
                return $this->forced;
            }
        };
        $user = (object) ['id' => 4242];

        // Serve/list is gated by view_items; mutations by manage_items.
        $this->assertTrue($perms->can($user, 'inventory.view_items'), 'viewer can view images');
        $this->assertFalse($perms->can($user, 'inventory.manage_items'), 'viewer cannot upload/delete images');

        app(OrganizationContext::class)->forget();
    }
}
