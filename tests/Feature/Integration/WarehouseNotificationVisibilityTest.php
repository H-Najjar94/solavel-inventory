<?php
namespace Tests\Feature\Integration;

use App\Http\Controllers\Api\V1\PurchasingNotificationController;
use App\Models\Tenant\{ReceivingRequest, Supplier};
use App\Services\Access\{InventoryPermissionService, WarehouseAccessService};
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native org/warehouse/revision row filtering; Central notification HTTP and current capabilities are isolated seams. */
final class WarehouseNotificationVisibilityTest extends TestCase
{
    use TenantAware;
    private static int $fixtureSequence = 800;
    private function fixture(): array
    {
        $sourceBill = ++self::$fixtureSequence;
        $this->useTenantA();
        // Private controller fixture only; no producer service or production migration is simulated.
        if (! Schema::connection('tenant')->hasTable('sales_fulfillment_requests')) {
            Schema::connection('tenant')->create('sales_fulfillment_requests', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('organization_id');
                $table->uuid('organization_mapping_uuid'); $table->uuid('request_uuid');
                $table->unsignedBigInteger('source_invoice_id'); $table->string('source_revision', 64);
                $table->string('source_status'); $table->unsignedBigInteger('customer_id');
                $table->date('invoice_date'); $table->string('currency_code', 3); $table->string('base_currency_code', 3);
                $table->text('source_payload'); $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->timestamp('approved_at')->nullable(); $table->string('approved_revision', 64)->nullable();
                $table->timestamps();
            });
        }
        $org = app(OrganizationContext::class)->idOrFail(); $warehouse = F::warehouse();
        $supplier = Supplier::create(['code' => 'VIS-'.Str::random(5), 'name' => 'Synthetic supplier', 'is_active' => true]);
        $revision = str_repeat('a', 64);
        $purchase = ReceivingRequest::create(['organization_mapping_uuid' => (string) Str::uuid(), 'finance_organization_id' => 14,
            'request_uuid' => (string) Str::uuid(), 'source_bill_id' => $sourceBill, 'source_bill_number' => 'QA-BILL-'.$sourceBill, 'source_revision' => $revision,
            'supplier_id' => $supplier->id, 'currency_code' => 'JOD', 'status' => 'pending', 'source_payload' => [],
            'warehouse_id' => $warehouse->id, 'approved_at' => now(), 'approved_revision' => $revision]);
        $sales = DB::connection('tenant')->table('sales_fulfillment_requests')->insertGetId([
            'organization_id' => $org, 'organization_mapping_uuid' => (string) Str::uuid(), 'request_uuid' => (string) Str::uuid(),
            'source_invoice_id' => 801, 'source_revision' => $revision, 'source_status' => 'posted', 'customer_id' => 901,
            'invoice_date' => '2026-10-07', 'currency_code' => 'JOD', 'base_currency_code' => 'JOD', 'source_payload' => '{}',
            'warehouse_id' => $warehouse->id, 'approved_at' => now(), 'approved_revision' => $revision, 'created_at' => now(), 'updated_at' => now()]);
        $user = new \App\Models\User; $user->id = 1007;
        $request = Request::create('/inventory/api/v1/purchasing/notifications'); $request->setUserResolver(fn () => $user);
        config(['solavel_sync.secret' => str_repeat('n', 40), 'sso.central_app_url' => 'https://central.test']);
        $notifications = [['id' => (string) Str::uuid(), 'title' => 'Purchase private', 'action_url' => '/inventory/receiving-requests?request='.$purchase->id],
            ['id' => (string) Str::uuid(), 'title' => 'Dispatch private', 'action_url' => '/inventory/fulfillment-requests?request='.$sales]];
        Http::fake(fn () => Http::response(['data' => $notifications], 200));
        return compact('org', 'warehouse', 'purchase', 'sales', 'user', 'request', 'notifications');
    }

    private function permissions($user, bool $receive, bool $dispatch, ?array $warehouses): void
    {
        $this->mock(InventoryPermissionService::class, function ($mock) use ($user, $receive, $dispatch) {
            $mock->shouldReceive('can')->with($user, 'inventory.receive_goods')->andReturn($receive);
            $mock->shouldReceive('can')->with($user, 'inventory.manage_shipments')->andReturn($dispatch);
            foreach (['inventory.approve_purchase_orders', 'inventory.manage_adjustments', 'inventory.manage_sales_orders'] as $permission) {
                $mock->shouldReceive('can')->with($user, $permission)->andReturn(false);
            }
        });
        $this->mock(WarehouseAccessService::class, function ($mock) use ($warehouses) {
            $mock->shouldReceive('allowedIds')->andReturn($warehouses);
            $mock->shouldReceive('scope')->andReturnUsing(fn ($query) => $query);
        });
    }

    private function items(Request $request): array
    {
        return app(PurchasingNotificationController::class)->index($request, app(OrganizationContext::class))->getData(true)['data']['items'];
    }

    public function test_dispatch_only_user_never_receives_purchase_notification_body(): void
    {
        extract($this->fixture()); $this->permissions($user, false, true, [$warehouse->id]);
        $items = $this->items($request); $this->assertCount(1, $items); $this->assertSame('Dispatch private', $items[0]['title']);
    }

    public function test_receiving_only_user_never_receives_dispatch_notification_body(): void
    {
        extract($this->fixture()); $this->permissions($user, true, false, [$warehouse->id]);
        $items = $this->items($request); $this->assertCount(1, $items); $this->assertSame('Purchase private', $items[0]['title']);
    }

    public function test_current_warehouse_revision_and_tenant_are_independently_enforced(): void
    {
        extract($this->fixture()); $this->permissions($user, true, true, []); $this->assertSame([], $this->items($request));
        $this->permissions($user, true, true, [$warehouse->id]);
        $purchase->update(['source_revision' => str_repeat('b', 64)]);
        DB::connection('tenant')->table('sales_fulfillment_requests')->where('id', $sales)->update(['source_revision' => str_repeat('b', 64)]);
        $this->assertSame([], $this->items($request));
        $purchase->update(['approved_revision' => $purchase->source_revision]);
        DB::connection('tenant')->table('sales_fulfillment_requests')->where('id', $sales)->update(['approved_revision' => str_repeat('b', 64)]);
        $this->assertCount(2, $this->items($request));
        $this->useTenantB(); $this->assertSame([], $this->items($request));
    }

    public function test_revoked_both_current_permissions_prevents_remote_fetch_or_body_disclosure(): void
    {
        extract($this->fixture()); $this->permissions($user, false, false, [$warehouse->id]);
        try { $this->items($request); $this->fail('Revoked authority fetched stored notification bodies.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        Http::assertNothingSent();
    }
}
