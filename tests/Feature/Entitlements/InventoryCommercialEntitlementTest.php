<?php

namespace Tests\Feature\Entitlements;

use App\Models\Tenant\Lot;
use App\Http\Controllers\Api\Tenancy\SyncEventsController;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryCommercialEntitlementTest extends TestCase
{
    #[Test]
    public function missing_snapshot_allows_free_tier_but_blocks_paid_feature_without_expiry_reason(): void
    {
        $service = $this->service(null);

        $free = $service->checkPermission('inventory.view_items');
        $paid = $service->checkPermission('inventory.manage_shipments');

        $this->assertTrue($free['allowed']);
        $this->assertSame('entitlement_service_unavailable', $free['reason_code']);
        $this->assertFalse($paid['allowed']);
        $this->assertSame('entitlement_service_unavailable', $paid['reason_code']);
    }

    #[Test]
    public function fresh_paid_snapshot_allows_included_premium_feature(): void
    {
        $decision = $this->service([
            'effective_tier' => 'premium',
            'access_mode' => 'full',
            'reason_code' => 'paid_active',
            'allowed_features' => ['inventory.sales_fulfillment'],
        ])->checkPermission('inventory.manage_shipments');

        $this->assertTrue($decision['allowed']);
        $this->assertSame('paid_active', $decision['reason_code']);
    }

    #[Test]
    public function free_tier_blocks_premium_feature_with_structured_reason(): void
    {
        $decision = $this->service([
            'effective_tier' => 'free',
            'access_mode' => 'free',
            'reason_code' => 'paid_expired_free_fallback',
            'blocked_features' => ['inventory.sales_fulfillment'],
        ])->checkPermission('inventory.manage_shipments');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('feature_not_in_plan', $decision['reason_code']);
        $this->assertSame(402, $decision['status']);
    }

    #[Test]
    public function stale_snapshot_within_maximum_uses_lkg_without_false_expiry(): void
    {
        $decision = $this->service([
            'effective_tier' => 'professional',
            'access_mode' => 'full',
            'reason_code' => 'paid_active',
            'allowed_features' => ['inventory.sales_fulfillment'],
            '_snapshot' => [
                'stale' => true,
                'beyond_max_stale' => false,
            ],
        ])->checkPermission('inventory.manage_shipments');

        $this->assertTrue($decision['allowed']);
        $this->assertSame('snapshot_stale', $decision['reason_code']);
    }

    #[Test]
    public function beyond_maximum_stale_enters_restricted_safe_mode(): void
    {
        $service = $this->service([
            'effective_tier' => 'premium',
            'access_mode' => 'full',
            'reason_code' => 'paid_active',
            'allowed_features' => ['inventory.sales_fulfillment'],
            '_snapshot' => [
                'stale' => true,
                'beyond_max_stale' => true,
            ],
        ]);

        $read = $service->checkPermission('inventory.view_stock');
        $write = $service->checkPermission('inventory.manage_shipments');

        $this->assertTrue($read['allowed']);
        $this->assertSame('restricted_safe_mode', $read['access_mode']);
        $this->assertFalse($write['allowed']);
        $this->assertSame('entitlement_verification_stale', $write['reason_code']);
    }

    #[Test]
    public function inventory_lot_expiry_remains_domain_status_not_subscription_reason(): void
    {
        $lot = (new Lot)->forceFill([
            'lot_code' => 'LOT-EXP',
            'status' => 'active',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame('expired', $lot->effectiveStatus());
        $this->assertNotContains($lot->effectiveStatus(), [
            'paid_expired_free_fallback',
            'paid_expired_no_fallback',
            'entitlement_verification_stale',
            'entitlement_service_unavailable',
        ]);
    }

    #[Test]
    public function older_snapshot_cannot_replace_newer_snapshot(): void
    {
        $this->ensureSnapshotTable();
        Cache::flush();
        DB::connection('tenant')->table('tenant_entitlements_snapshots')->where('client_id', 990010)->delete();

        $cache = app(EntitlementsCache::class);
        $cache->storeProjectSnapshot(990010, 'inventory', [
            'reason_code' => 'free_tier',
        ], 'new', Carbon::parse('2026-07-07 10:00:00'), [
            'pushed_at' => '2026-07-07 10:00:00',
            'valid_until' => '2026-07-07 14:00:00',
            'state_hash' => 'new-hash',
        ]);

        $cache->storeProjectSnapshot(990010, 'inventory', [
            'reason_code' => 'paid_active',
        ], 'old', Carbon::parse('2026-07-07 08:00:00'), [
            'pushed_at' => '2026-07-07 08:00:00',
            'valid_until' => '2026-07-07 12:00:00',
            'state_hash' => 'old-hash',
        ]);

        Cache::flush();
        $snapshot = $cache->getProjectSnapshot(990010, 'inventory');

        $this->assertSame('free_tier', $snapshot['reason_code']);
        $this->assertSame('new-hash', $snapshot['_snapshot']['state_hash']);
        $this->assertSame('new', $snapshot['_snapshot']['version']);
    }

    #[Test]
    public function receiver_rejects_entitlement_snapshot_with_mismatched_checksum(): void
    {
        $payload = [
            'client_id' => 990010,
            'version' => 'checksum-test',
            'projects' => [
                'inventory' => ['reason_code' => 'paid_active'],
            ],
        ];

        $request = Request::create('/inventory/api/tenancy/sync/events', 'POST', [
            'event_id' => 'inventory-checksum-1',
            'event_action' => 'upsert',
            'resource_type' => 'entitlements_snapshot',
            'resource_id' => '990010',
            'client_id' => 990010,
            'tenant_db' => 'tenant_990010',
            'payload' => $payload,
            'checksum' => $this->payloadChecksum($payload + ['tampered' => true]),
        ]);

        $tenantManager = Mockery::mock(TenantManager::class);
        $tenantManager->shouldReceive('switchToDatabase')->once()->with('tenant_990010');

        $controller = new SyncEventsController(
            $tenantManager,
            Mockery::mock(EntitlementsCache::class),
        );

        $response = $controller($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('sync_payload_checksum_invalid', $response->getData(true)['code']);
    }

    private function service(?array $snapshot): InventoryCommercialEntitlementService
    {
        $cache = new class($snapshot) extends EntitlementsCache {
            public function __construct(private ?array $snapshot)
            {
            }

            public function currentClientId(): ?int
            {
                return 990010;
            }

            public function getProjectSnapshot(int $clientId, ?string $projectSlug = null): ?array
            {
                return $this->snapshot;
            }
        };

        return new InventoryCommercialEntitlementService($cache);
    }

    private function ensureSnapshotTable(): void
    {
        if (Schema::connection('tenant')->hasTable('tenant_entitlements_snapshots')) {
            return;
        }

        Schema::connection('tenant')->create('tenant_entitlements_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('project_slug');
            $table->json('payload');
            $table->string('version')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('schema_version')->nullable();
            $table->string('source_version')->nullable();
            $table->string('state_hash')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'project_slug']);
        });
    }

    private function payloadChecksum(array $payload): string
    {
        ksort($payload);

        return 'sha256:' . hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
        );
    }
}
