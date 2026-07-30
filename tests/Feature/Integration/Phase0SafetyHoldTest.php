<?php

namespace Tests\Feature\Integration;

use App\Http\Controllers\Api\V1\IntegrationController;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\DurableOutboxTransportService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class Phase0SafetyHoldTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        config()->set('integration_safety.solabooks_delivery_enabled', false);
        config()->set('integration_safety.legacy_finance_inventory_writes_blocked', true);
        Http::fake();
    }

    #[Test]
    public function manual_direct_and_bulk_delivery_are_blocked_without_mutating_events_or_using_http(): void
    {
        $pending = $this->event('pending');
        $ignored = $this->event('ignored');
        $before = IntegrationOutboxEvent::query()
            ->whereKey([$pending->id, $ignored->id])
            ->get()->keyBy('id')->map->only(['status', 'attempts', 'next_attempt_at', 'last_error'])->all();

        foreach ([
            fn () => app(SolaBooksOutboxDeliveryService::class)->deliver($pending, true),
            fn () => app(SolaBooksOutboxDeliveryService::class)->deliver($pending),
            fn () => app(SolaBooksOutboxDeliveryService::class)->deliverDue(100),
        ] as $delivery) {
            try {
                $delivery();
                $this->fail('Phase 0 must block every delivery path.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('currency-contract', $exception->getMessage());
            }
        }

        $response = app(IntegrationController::class)->retry($pending->id);
        $this->assertSame(423, $response->status());
        $this->assertSame('integration_safety_hold', $response->getData(true)['error']['code']);
        $this->assertSame(423, app(IntegrationController::class)->ignore($pending->id)->status());
        $this->assertSame(
            $before,
            IntegrationOutboxEvent::query()
                ->whereKey([$pending->id, $ignored->id])
                ->get()->keyBy('id')->map->only(['status', 'attempts', 'next_attempt_at', 'last_error'])->all()
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function new_activation_is_blocked_while_existing_configuration_and_status_remain_readable(): void
    {
        $blocked = app(IntegrationController::class)->configure(Request::create('/integration/solabooks/connection', 'PUT', [
            'mode' => 'active',
            'client_id' => 990002,
            'solabooks_organization_id' => 14,
            'api_key' => str_repeat('x', 40),
        ]));
        $this->assertSame(423, $blocked->status());
        $this->assertDatabaseMissing('integration_settings', [
            'organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks',
        ], 'tenant');

        IntegrationSetting::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks',
            'mode' => 'active',
            'solabooks_organization_id' => 14,
        ]);
        $this->event('ignored');
        $status = app(IntegrationController::class)->status()->getData(true)['data'];
        $this->assertSame('maintenance_hold', $status['health']);
        $this->assertFalse($status['delivery_enabled']);
        $this->assertSame('currency_contract_maintenance', $status['delivery_disabled_reason']);
        $this->assertTrue($status['legacy_finance_inventory_writes_blocked']);
        $this->assertSame(1, $status['events']['ignored']);
        $this->assertArrayHasKey('pending', $status['events']);
        $this->assertArrayHasKey('ignored', $status['events']);
    }

    #[Test]
    public function safety_message_is_localized_in_english_and_arabic(): void
    {
        app()->setLocale('en');
        $this->assertStringContainsString('currency-contract', __('inventory.integration.safety_hold'));
        app()->setLocale('ar');
        $this->assertStringContainsString('عقد العملات', __('inventory.integration.safety_hold'));
        $this->assertStringContainsString('dir="{{ $dir }}"', file_get_contents(resource_path('views/solastock-app.blade.php')));
    }

    #[Test]
    public function dedicated_worker_claim_cannot_bypass_the_delivery_hold(): void
    {
        config()->set('integration_transport.worker_enabled', true);
        $event = $this->event('ready');
        $event->update([
            'contract_version' => 'solastock-journal.v2',
            'transport_eligible_at' => now(),
            'ordering_key' => 'StockAdjustment:'.$event->aggregate_id,
        ]);
        $before = $event->fresh()->only([
            'status', 'attempts', 'lease_owner', 'lease_token',
            'claimed_at', 'lease_expires_at', 'state_version',
        ]);

        try {
            app(DurableOutboxTransportService::class)->claim(TenantTestManager::ORG_A, 'blocked-worker');
            $this->fail('The dedicated worker must fail before claiming.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('currency-contract', $exception->getMessage());
        }

        $this->assertSame($before, $event->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('integration_outbox_transition_audits', 0, 'tenant');
        Http::assertNothingSent();
    }

    private function event(string $status): IntegrationOutboxEvent
    {
        $id = random_int(100000, 999999);

        return IntegrationOutboxEvent::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'event_uuid' => 'phase0-'.uniqid(),
            'integration' => 'solabooks',
            'event_type' => 'adjustment.posted',
            'aggregate_type' => 'StockAdjustment',
            'aggregate_id' => $id,
            'aggregate_number' => 'ADJ-'.$id,
            'occurred_at' => now(),
            'payload' => ['document_date' => now()->toDateString(), 'lines' => []],
            'status' => $status,
            'mapping_status' => 'complete',
            'attempts' => 0,
            'idempotency_key' => 'phase0:'.$status.':'.$id,
        ]);
    }
}
