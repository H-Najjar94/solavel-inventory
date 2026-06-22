<?php

namespace Tests\Feature\Warehouse;

use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarehouseImageController;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseImage;
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
 * Private warehouse images — same model as item images, banner is UI-only.
 * Stored on the local (private) disk, served via the authenticated org-scoped
 * controller; viewers view, only manage_warehouses may mutate.
 */
class WarehouseImageTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function controller(): WarehouseImageController
    {
        return app(WarehouseImageController::class);
    }

    private function upload(Warehouse $wh, UploadedFile $file): array
    {
        $req = Request::create('/', 'POST', [], [], ['image' => $file]);

        return $this->controller()->store($req, $wh)->getData(true)['data'];
    }

    #[Test]
    public function uploading_stores_privately_marks_primary_and_uses_an_authenticated_url(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();

        $data = $this->upload($wh, UploadedFile::fake()->create('banner.jpg', 200, 'image/jpeg'));

        $this->assertTrue($data['is_primary']);
        $img = WarehouseImage::query()->findOrFail($data['id']);
        $this->assertStringStartsWith("inventory/warehouse-images/{$wh->organization_id}/{$wh->id}/", $img->path);
        $this->assertStringNotContainsString('public', $img->path);
        Storage::disk('local')->assertExists($img->path);
        Storage::disk('public')->assertMissing($img->path);
        $this->assertSame("/inventory/api/v1/warehouse-images/{$img->id}", $data['url']);
        $this->assertStringNotContainsString('/storage/', $data['url']);
    }

    #[Test]
    public function multiple_upload_primary_first_set_primary_and_delete_work(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $a = $this->upload($wh, UploadedFile::fake()->create('1.jpg', 80, 'image/jpeg'));
        $b = $this->upload($wh, UploadedFile::fake()->create('2.png', 80, 'image/png'));
        $c = $this->upload($wh, UploadedFile::fake()->create('3.webp', 80, 'image/webp'));

        // Listed primary-first; exactly one primary.
        $list = $this->controller()->index($wh)->getData(true)['data'];
        $this->assertCount(3, $list);
        $this->assertTrue($list[0]['is_primary']);
        $this->assertSame(1, collect($list)->where('is_primary', true)->count());

        // Set a different primary.
        $this->controller()->setPrimary(WarehouseImage::query()->findOrFail($b['id']));
        $this->assertTrue((bool) WarehouseImage::query()->find($b['id'])->is_primary);
        $this->assertFalse((bool) WarehouseImage::query()->find($a['id'])->is_primary);

        // Delete the (new) primary → next promoted, file removed.
        $bModel = WarehouseImage::query()->findOrFail($b['id']);
        $path = $bModel->path;
        $this->controller()->destroy($bModel);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull(WarehouseImage::query()->find($b['id']));
        $this->assertSame(1, WarehouseImage::query()->where('warehouse_id', $wh->id)->where('is_primary', true)->count());
    }

    #[Test]
    public function non_image_types_are_rejected(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->upload($wh, UploadedFile::fake()->create('evil.pdf', 100, 'application/pdf'));
    }

    #[Test]
    public function images_are_org_scoped(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $img = $this->upload($wh, UploadedFile::fake()->create('1.jpg', 80, 'image/jpeg'));

        app(OrganizationContext::class)->set(TenantTestManager::ORG_B);
        $this->assertNull(WarehouseImage::query()->find($img['id']), 'ORG_A warehouse image must be invisible to ORG_B');
    }

    #[Test]
    public function warehouse_list_and_detail_include_primary_image_url(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $this->upload($wh, UploadedFile::fake()->create('1.jpg', 80, 'image/jpeg'));

        $list = app(WarehouseController::class)->index(Request::create('/', 'GET'))->getData(true)['data'];
        $row = collect($list)->firstWhere('id', $wh->id);
        $this->assertNotNull($row['primary_image_url'] ?? null);
        $this->assertStringContainsString('/warehouse-images/', $row['primary_image_url']);

        $detail = app(WarehouseController::class)->show($wh->fresh())->getData(true)['data'];
        $this->assertNotNull($detail['primary_image_url']);
        $this->assertCount(1, $detail['images']);
    }

    #[Test]
    public function viewer_can_view_but_cannot_mutate_warehouse_images(): void
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

        $this->assertTrue($perms->can($user, 'inventory.view_warehouses'), 'viewer can view warehouse images');
        $this->assertFalse($perms->can($user, 'inventory.manage_warehouses'), 'viewer cannot mutate warehouse images');

        app(OrganizationContext::class)->forget();
    }
}
