<?php
namespace Tests\Feature\Sales;

use App\Models\Tenant\FulfillmentRequest;
use App\Services\Sales\{FulfillmentRequestService, SalesNotificationPublisher};
use Illuminate\Support\Facades\DB;
use Tests\Support\StockTestFactory;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class SalesNotificationTransitionTest extends TestCase
{
    use TenantAware;
    use \Tests\Support\SalesHandoffFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeSalesFixture();
        (require base_path('database/migrations/tenant/2026_10_07_170500_create_sales_notification_outbox.php'))->up();
        // SQLite DDL is isolated before transactional fixture data on the private runner.
    }

    public function test_replayed_transition_is_deduplicated_and_warehouse_setup_changes_are_recoverable(): void
    {
        $data = $this->data();
        $this->authority($data);
        $result = app(FulfillmentRequestService::class)->upsert($data, 323);
        $request = FulfillmentRequest::findOrFail($result['id']);
        $publisher = app(SalesNotificationPublisher::class);
        $queue = new \ReflectionMethod($publisher, 'queue');
        $queue->invoke($publisher, (int) $request->organization_id, (int) $request->id);
        $queue->invoke($publisher, (int) $request->organization_id, (int) $request->id);
        $this->assertSame(1, DB::connection('tenant')->table('sales_notification_outbox')->count());
        StockTestFactory::warehouse();
        $queue->invoke($publisher, (int) $request->organization_id, (int) $request->id);
        $this->assertSame(2, DB::connection('tenant')->table('sales_notification_outbox')->count());
        $request->lines()->firstOrFail()->update(['cancelled_qty' => '1']);
        $queue->invoke($publisher, (int) $request->organization_id, (int) $request->id);
        $queue->invoke($publisher, (int) $request->organization_id, (int) $request->id);
        $this->assertSame(3, DB::connection('tenant')->table('sales_notification_outbox')->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count());
        $this->assertSame(0, DB::connection('tenant')->table('sales_notification_outbox')->where('state', 'sent')->count());
    }
}
