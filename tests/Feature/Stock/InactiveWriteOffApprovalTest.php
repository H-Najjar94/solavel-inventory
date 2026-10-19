<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Warehouse;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Stock\InactiveItemWriteOffApproval;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Q12.6 (owner decision 2026-10-18, agent G6): a reduction / write-off of an INACTIVE item's existing stock goes
 * only through draft -> second-person approval -> post, needs inventory.adjustments.write_off_inactive (owner /
 * SolaStock Administrator by default), warns, never goes negative or hides an existing negative, and is audited.
 * Direct service callers (no route middleware) are held to the same rule at the ledger.
 */
class InactiveWriteOffApprovalTest extends TestCase
{
    use TenantAware;

    private const OWNER = 601;

    private const SECOND_OWNER = 602;

    private const MANAGER = 603;

    private const VIEWER = 604;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        // Central role resolution stubbed: owner-level users hold every permission (as AppAuthority::full gives
        // inventory_admin '*'); the manager holds the config inventory_manager bundle; the viewer the viewer bundle.
        $this->mock(\App\Services\Access\InventoryPermissionService::class, function ($m) {
            $m->shouldReceive('can')->andReturnUsing(function ($user, string $permission) {
                $id = (int) ($user->id ?? 0);
                if (in_array($id, [self::OWNER, self::SECOND_OWNER], true)) {
                    return true;
                }
                $role = match ($id) { self::MANAGER => 'inventory_manager', self::VIEWER => 'inventory_viewer', default => null };

                return $role !== null && in_array($permission, (array) config("inventory_permissions.roles.$role"), true);
            });
        });
        $scope = $this->createStub(\App\Services\Access\WarehouseAccessService::class);
        $scope->method('scope')->willReturnCallback(fn ($q) => $q);
        $this->app->instance(\App\Services\Access\WarehouseAccessService::class, $scope);
        $this->as(self::OWNER);
        $this->warehouse = F::warehouse();
    }

    private function as(?int $id): void
    {
        if ($id === null) {
            Auth::forgetUser();
            request()->setUserResolver(fn () => null);

            return;
        }
        $user = new \App\Models\User;
        $user->id = $id;
        Auth::setUser($user);
        request()->setUserResolver(fn () => $user);
    }

    private function inactiveItemWithStock(string $qty, ?int $warehouseId = null): Item
    {
        $item = F::averageItem([
            'category_id' => \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => \App\Models\Tenant\Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ]);
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id' => $warehouseId ?? $this->warehouse->id], [['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => '2']]));
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);

        return $item;
    }

    private function draft(Item $item, string $qty): StockAdjustment
    {
        return app(StockAdjustmentService::class)->createDraft(['warehouse_id' => $this->warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => $qty]]);
    }

    private function forbidden(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Expected 403 '.$message);
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function onHand(Item $item): string
    {
        return (string) StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $this->warehouse->id)->value('on_hand_qty');
    }

    private function approval(): InactiveItemWriteOffApproval
    {
        return app(InactiveItemWriteOffApproval::class);
    }

    #[Test]
    public function a_manager_with_only_manage_adjustments_cannot_post_an_inactive_write_off(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $this->as(self::MANAGER);
        $adj = $this->draft($item, '2');
        $this->forbidden(fn () => app(StockAdjustmentService::class)->post($adj), 'inactive_write_off_permission_required');
        $this->assertSame('5.000000', $this->onHand($item));
        $this->assertSame('draft', $adj->fresh()->status);
        $this->assertFalse(in_array(InactiveItemWriteOffApproval::PERMISSION, (array) config('inventory_permissions.roles.inventory_manager'), true));
    }

    #[Test]
    public function an_owner_cannot_post_an_inactive_write_off_without_a_second_person_approval(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $adj = $this->draft($item, '2');
        $this->forbidden(fn () => app(StockAdjustmentService::class)->post($adj), 'inactive_write_off_approval_required');
        $this->assertSame('5.000000', $this->onHand($item));
        $this->assertSame(0, InventoryAuditLog::query()->where('action', 'like', '%inactive_item_write_off%')->count());
    }

    #[Test]
    public function the_creator_cannot_approve_and_the_approver_cannot_post(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $adj = $this->draft($item, '2');
        $this->forbidden(fn () => $this->approval()->approve($adj), 'inactive_write_off_self_approval_forbidden');
        $this->as(self::SECOND_OWNER);
        $this->approval()->approve($adj);
        $this->forbidden(fn () => app(StockAdjustmentService::class)->post($adj), 'inactive_write_off_self_approval_forbidden');
        $this->assertSame('5.000000', $this->onHand($item));
    }

    #[Test]
    public function viewer_manager_and_no_actor_cannot_approve(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $adj = $this->draft($item, '2');
        foreach ([self::VIEWER, self::MANAGER, null] as $who) {
            $this->as($who);
            $this->forbidden(fn () => $this->approval()->approve($adj), 'inactive_write_off_permission_required');
        }
        $this->as(null);
        $this->forbidden(fn () => app(StockAdjustmentService::class)->post($adj), 'inactive_write_off_permission_required');
        $this->as(self::VIEWER);
        $this->forbidden(fn () => app(StockAdjustmentService::class)->post($adj), 'inactive_write_off_permission_required');
        $this->assertSame(0, InventoryAuditLog::query()->where('action', InactiveItemWriteOffApproval::APPROVED_ACTION)->count());
        $this->assertSame('5.000000', $this->onHand($item));
    }

    #[Test]
    public function the_approved_path_warns_posts_and_audits_actor_organization_operation_and_before_after(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $adj = $this->draft($item, '3');
        $preview = $this->approval()->preview($adj);
        $this->assertTrue($preview['requires_approval']);
        $this->assertNotEmpty($preview['warnings']);
        $this->assertNull($preview['approval']);
        $this->assertSame(['5.0000', '2.0000'], [$preview['lines'][0]['on_hand_before'], $preview['lines'][0]['on_hand_after']]);

        $this->as(self::SECOND_OWNER);
        $approved = $this->approval()->approve($adj, 'Damaged in storage');
        $this->assertSame(self::SECOND_OWNER, $approved['approval']['approved_by']);
        $approvalRow = InventoryAuditLog::query()->where('action', InactiveItemWriteOffApproval::APPROVED_ACTION)->sole();
        $this->assertSame(TenantTestManager::ORG_A, (int) $approvalRow->organization_id);
        $this->assertSame(self::OWNER, $approvalRow->before['requested_by']);
        $this->assertNotEmpty($approvalRow->after['operation_id']);

        $this->as(self::OWNER);
        app(StockAdjustmentService::class)->post($adj);
        $this->assertSame('2.0000', $this->onHand($item));
        $this->assertSame('posted', $adj->fresh()->status);
        $audit = InventoryAuditLog::query()->where('action', 'stock_adjustment.inactive_item_write_off')->sole();
        $this->assertSame(self::OWNER, (int) $audit->actor_user_id);
        $this->assertSame(TenantTestManager::ORG_A, (int) $audit->organization_id);
        $this->assertSame($adj->id, (int) $audit->entity_id);
        $this->assertSame('stock_adjustment:'.$adj->id.':post', $audit->after['operation_id']);
        $this->assertSame($approvalRow->after['operation_id'], $audit->after['approval_operation_id']);
        $this->assertSame(self::SECOND_OWNER, $audit->after['approved_by']);
        $this->assertSame(['5.0000', '2.0000'], [$audit->before['on_hand_qty'], $audit->after['on_hand_qty']]);
    }

    #[Test]
    public function editing_the_draft_after_approval_voids_the_approval(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $adj = $this->draft($item, '1');
        $this->as(self::SECOND_OWNER);
        $this->approval()->approve($adj);
        $this->as(self::OWNER);
        app(StockAdjustmentService::class)->updateDraft($adj, ['warehouse_id' => $this->warehouse->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '4']]);
        $this->assertNull($this->approval()->currentApproval($adj->fresh()));
        $this->forbidden(fn () => app(StockAdjustmentService::class)->post($adj), 'inactive_write_off_approval_required');
        $this->assertSame('5.000000', $this->onHand($item));
        $this->as(self::SECOND_OWNER);
        $this->approval()->approve($adj->fresh());
        $this->as(self::OWNER);
        app(StockAdjustmentService::class)->post($adj);
        $this->assertSame('1.000000', $this->onHand($item));
    }

    #[Test]
    public function an_existing_negative_balance_of_the_item_is_never_hidden_by_a_write_off(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $other = Warehouse::create(['name' => 'Q12 negative', 'code' => 'Q12-NEG-'.uniqid(), 'type' => 'warehouse']);
        $adj = $this->draft($item, '2');
        $this->as(self::SECOND_OWNER);
        $this->approval()->approve($adj); // no negative yet
        StockBalance::create(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $item->id, 'warehouse_id' => $other->id,
            'on_hand_qty' => '-3', 'reserved_qty' => '0', 'total_value' => '0']);
        $this->as(self::OWNER);
        try {
            app(StockAdjustmentService::class)->post($adj);
            $this->fail('A write-off hid a negative balance.');
        } catch (ValidationException $e) {
            $this->assertSame(__('inventory.catalog.inactive_write_off_negative_elsewhere', ['item' => $item->id]), $e->errors()['item_id'][0]);
        }
        $this->assertSame('5.000000', $this->onHand($item));
        $second = $this->draft($item, '1');
        $this->as(self::SECOND_OWNER);
        $this->expectException(ValidationException::class);
        $this->approval()->approve($second);
    }

    #[Test]
    public function a_write_off_never_exceeds_stock_even_when_approved(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $adj = app(StockAdjustmentService::class)->createDraft(['warehouse_id' => $this->warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '3'], ['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '3']]);
        $this->as(self::SECOND_OWNER);
        $this->expectException(ValidationException::class);
        try {
            $this->approval()->approve($adj); // each line fits on its own; the ledger refuses the sum below zero
        } catch (ValidationException) {
            $this->fail('each line alone is within stock');
        }
        $this->as(self::OWNER);
        try {
            app(StockAdjustmentService::class)->post($adj);
        } finally {
            $this->assertSame('5.000000', $this->onHand($item));
        }
    }

    #[Test]
    public function an_ordinary_decrease_of_an_active_item_needs_no_approval(): void
    {
        $item = $this->inactiveItemWithStock('5');
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => true]);
        $this->as(self::MANAGER);
        $adj = $this->draft($item, '2');
        $this->assertFalse($this->approval()->preview($adj)['requires_approval']);
        app(StockAdjustmentService::class)->post($adj);
        $this->assertSame('3.000000', $this->onHand($item));
    }

    /** Q12.6 UI (G6 r2): the draft detail payload carries warnings + whether THIS user may approve. */
    #[Test]
    public function the_draft_detail_payload_tells_the_ui_who_may_approve(): void
    {
        $item = $this->inactiveItemWithStock('5');
        $adj = $this->draft($item, '2');
        $show = fn () => app(\App\Http\Controllers\Api\V1\StockAdjustmentController::class)->show($adj->fresh())->getData(true)['data'];

        $data = $show(); // creator
        $this->assertTrue($data['inactive_write_off']['requires_approval']);
        $this->assertNotEmpty($data['inactive_write_off']['warnings']);
        $this->assertFalse($data['inactive_write_off']['can_approve']);
        $this->as(self::MANAGER);
        $this->assertFalse($show()['inactive_write_off']['can_approve']);
        $this->as(self::VIEWER);
        $this->assertFalse($show()['inactive_write_off']['can_approve']);
        $this->as(self::SECOND_OWNER);
        $this->assertTrue($show()['inactive_write_off']['can_approve']);
        $this->approval()->approve($adj);
        $after = $show();
        $this->assertFalse($after['inactive_write_off']['can_approve']);
        $this->assertSame(self::SECOND_OWNER, $after['inactive_write_off']['approval']['approved_by']);

        $active = $this->inactiveItemWithStock('5');
        DB::connection('tenant')->table('items')->where('id', $active->id)->update(['is_active' => true]);
        $plain = $this->draft($active, '1');
        $this->assertArrayNotHasKey('inactive_write_off', app(\App\Http\Controllers\Api\V1\StockAdjustmentController::class)->show($plain)->getData(true)['data']);
    }
}
