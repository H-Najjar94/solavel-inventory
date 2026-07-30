<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Services\Integration\Phase5aManifestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class Phase5aHistoricalRepairSafetyTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        config()->set('integration_safety.solabooks_delivery_enabled', false);
        config()->set('integration_safety.historical_repair_enabled', false);
        config()->set('integration_safety.pending_event_replay_enabled', false);
    }

    #[Test]
    public function manifest_generation_is_byte_deterministic_and_does_not_mutate_events(): void
    {
        $mapping = $this->mapping();
        $event = IntegrationOutboxEvent::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'event_uuid' => (string) Str::uuid(),
            'integration' => 'solabooks',
            'event_type' => 'adjustment.posted',
            'aggregate_type' => 'StockAdjustment',
            'aggregate_id' => 91,
            'aggregate_number' => 'SAFE-91',
            'occurred_at' => '2026-07-01 00:00:00',
            'payload' => ['total_inventory_value_change' => '12.5000'],
            'status' => 'pending',
            'mapping_status' => 'complete',
            'attempts' => 0,
            'idempotency_key' => 'phase5a-test-91',
        ]);
        $before = $event->fresh()->only(['status', 'attempts']);
        $before['updated_at'] = $event->fresh()->updated_at?->format('Y-m-d H:i:s.u');
        $root = sys_get_temp_dir().'/solastock-phase5a-'.Str::uuid();
        mkdir($root.'/one', 0750, true);
        mkdir($root.'/two', 0750, true);

        $one = app(Phase5aManifestService::class)->generate($mapping->mapping_uuid, $root.'/one');
        $two = app(Phase5aManifestService::class)->generate($mapping->mapping_uuid, $root.'/two');

        $this->assertSame($one['index_sha256'], $two['index_sha256']);
        foreach (array_keys($one['sets']) as $set) {
            $this->assertSame(
                hash_file('sha256', $root.'/one/'.$one['sets'][$set]['file']),
                hash_file('sha256', $root.'/two/'.$two['sets'][$set]['file']),
            );
        }
        $after = $event->fresh()->only(['status', 'attempts']);
        $after['updated_at'] = $event->fresh()->updated_at?->format('Y-m-d H:i:s.u');
        $this->assertSame($before, $after);
        $this->assertSame(0, DB::connection('tenant')->table('integration_outbox_transition_audits')->count());
    }

    #[Test]
    public function apply_abort_is_audited_without_mutating_an_event(): void
    {
        config()->set('integration_safety.historical_repair_enabled', true);

        $beforeAudits = DB::connection('tenant')->table('integration_historical_repair_attempt_audits')->count();
        $beforeEvents = DB::connection('tenant')->table('integration_outbox_events')->count();
        $this->artisan('integration:phase5-repair', [
            '--apply' => true,
            '--manifest' => '/tmp/nonexistent.jsonl',
            '--manifest-sha256' => str_repeat('a', 64),
            '--tenant-database' => (string) DB::connection('tenant')->getDatabaseName(),
            '--organization' => TenantTestManager::ORG_A,
            '--batch' => 'not-approved',
            '--approval' => 'not-approved',
            '--snapshot' => 'not-approved',
        ])->expectsOutputToContain('MANIFEST_SHA256_MISMATCH')->assertFailed();
        $this->assertSame($beforeAudits + 1, DB::connection('tenant')->table('integration_historical_repair_attempt_audits')->count());
        $this->assertSame($beforeEvents, DB::connection('tenant')->table('integration_outbox_events')->count());
    }

    #[Test]
    public function default_repair_command_is_non_mutating_dry_run(): void
    {
        $attempts = DB::connection('tenant')->table('integration_outbox_events')->sum('attempts');
        $this->artisan('integration:phase5-repair')->expectsOutputToContain('DRY RUN ONLY')->assertSuccessful();
        $this->assertSame((int) $attempts, (int) DB::connection('tenant')->table('integration_outbox_events')->sum('attempts'));
    }

    private function mapping(): IntegrationOrganizationMapping
    {
        return IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(),
            'central_client_id' => 990010,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => (string) DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 14,
            'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2',
            'status' => 'verified_hold',
            'activation_state' => 'maintenance_hold',
            'base_currency_code' => 'JOD',
            'verified_at' => now(),
        ]);
    }
}
