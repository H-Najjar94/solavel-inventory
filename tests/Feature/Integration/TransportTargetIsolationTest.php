<?php
namespace Tests\Feature\Integration;

use App\Services\Integration\ApprovedTransportTargetRegistry;
use App\Services\Integration\TransportTargetIsolation;
use App\Services\Tenancy\TenantManager;
use App\Services\Integration\TransportWorkerHeartbeat;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** One organization's transport failure is diagnosed and backed off without stopping the others. */
final class TransportTargetIsolationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/transport-isolation-'.bin2hex(random_bytes(6)));
        config([
            'integration_transport.worker_enabled' => true,
            'integration_transport.base_backoff_seconds' => 30,
            'integration_transport.max_backoff_seconds' => 3600,
            'integration_transport.supervisor.heartbeat_path' => $this->directory.'/heartbeat.json',
            'integration_transport.supervisor.diagnostics_path' => $this->directory.'/target-diagnostics.json',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function target(int $organization): array
    {
        return ['client_id' => 42, 'organization_id' => $organization, 'database' => 'tenant_0000'.$organization, 'plan' => 'advanced'];
    }

    public function test_failing_organization_is_recorded_backed_off_and_others_continue(): void
    {
        $isolation = new TransportTargetIsolation();
        $ran = [];
        $secret = 'Bearer super-secret-token https://finance.test/?signature=abc';
        $first = $isolation->attempt($this->target(77), 'receipt_handoff', function () use (&$ran, $secret): int {
            $ran[] = 77;
            throw new RuntimeException($secret);
        });
        $second = $isolation->attempt($this->target(78), 'receipt_handoff', function () use (&$ran): int {
            $ran[] = 78;

            return 3;
        });

        $this->assertSame(0, $first);
        $this->assertSame(3, $second);
        $this->assertSame([77, 78], $ran);

        $raw = (string) file_get_contents($this->directory.'/target-diagnostics.json');
        $this->assertStringNotContainsString('secret', $raw, 'Diagnostics must never carry exception messages.');
        $entry = (new TransportTargetIsolation())->diagnostics()['42:77:receipt_handoff'];
        $this->assertSame(77, $entry['organization_id']);
        $this->assertSame(42, $entry['client_id']);
        $this->assertSame('receipt_handoff', $entry['stage']);
        $this->assertSame('unexpected_runtime_exception', $entry['error_code']);
        $this->assertSame(1, $entry['failures']);
        $this->assertArrayNotHasKey('42:78:receipt_handoff', (new TransportTargetIsolation())->diagnostics());

        // A fresh supervisor process honours the durable backoff; other stages of the same org still run.
        $restarted = new TransportTargetIsolation();
        $this->assertTrue($restarted->backingOff($this->target(77), 'receipt_handoff'));
        $this->assertSame(0, $restarted->attempt($this->target(77), 'receipt_handoff', fn () => $this->fail('Backed-off stage ran.')));
        $this->assertSame(1, $restarted->attempt($this->target(77), 'party_sync', fn () => 1));

        // After the backoff window the stage retries; a second failure doubles the delay; success clears it.
        $this->travel(31)->seconds();
        $restarted->attempt($this->target(77), 'receipt_handoff', fn () => throw new RuntimeException('again'));
        $entry = $restarted->diagnostics()['42:77:receipt_handoff'];
        $this->assertSame(2, $entry['failures']);
        $this->travel(61)->seconds();
        $this->assertSame(5, $restarted->attempt($this->target(77), 'receipt_handoff', fn () => 5));
        $this->assertSame([], (new TransportTargetIsolation())->diagnostics());
    }

    public function test_delivery_not_enabled_gets_a_stable_code(): void
    {
        $error = new RuntimeException(__('inventory.purchasing.connection_review_required'));
        $this->assertSame('connection_review_required', TransportTargetIsolation::code($error));
        $this->assertSame('finance_setup_required', TransportTargetIsolation::code(new \App\Exceptions\FinanceSetupRequired()));
    }

    public function test_failure_logs_redacted_message_and_location_once_per_backoff_window(): void
    {
        Log::spy();
        $isolation = new TransportTargetIsolation();
        $secret = 'Finance said no: Bearer abc.def-ghi https://sync:hunter2@finance.test/api?signature=deadbeef&token=t0k3n password=hunter2 owner@example.com '.str_repeat('x', 600);
        $line = __LINE__ + 1;
        $fail = fn () => throw new RuntimeException($secret);
        $isolation->attempt($this->target(77), 'party_sync', $fail);
        // Still inside the backoff window: the stage is skipped, so nothing is logged again.
        $isolation->attempt($this->target(77), 'party_sync', $fail);
        (new TransportTargetIsolation())->attempt($this->target(77), 'party_sync', $fail);

        Log::shouldHaveReceived('warning')->with('integration.transport.target_failed', Mockery::on(function (array $context) use ($line): bool {
            $message = $context['error_message'];
            foreach (['abc.def-ghi', 'hunter2', 'deadbeef', 't0k3n', 'owner@example.com', 'sync:'] as $leak) {
                if (str_contains($message, $leak)) {
                    return false;
                }
            }

            return str_starts_with($message, 'Finance said no: Bearer [redacted]')
                && mb_strlen($message) <= 300
                && $context['error_class'] === RuntimeException::class
                && $context['error_location'] === 'tests/Feature/Integration/TransportTargetIsolationTest.php:'.$line
                && $context['error_code'] === 'unexpected_runtime_exception'
                && $context['organization_id'] === 77;
        }))->once();

        $raw = (string) file_get_contents($this->directory.'/target-diagnostics.json');
        $this->assertStringNotContainsString('Finance said no', $raw, 'The durable diagnostics stay message-free.');
        $this->assertStringContainsString('TransportTargetIsolationTest.php:'.$line, $raw);
    }

    public function test_redaction_removes_credentials_sql_bindings_and_caps_length(): void
    {
        $sql = TransportTargetIsolation::redact("SQLSTATE[HY000] [2002] refused (Connection: tenant, SQL: select * from customers where email = 'a@b.co')\nnext line");
        $this->assertStringNotContainsString('customers', $sql);
        $this->assertStringNotContainsString('a@b.co', $sql);
        $this->assertStringContainsString('next line', $sql, 'Only the SQL text is removed, not the rest of the message.');
        $redacted = TransportTargetIsolation::redact(
            "SQLSTATE[HY000] refused (SQL: select 1)\n"
            ."mysql://root:pw@db.local:3306/tenant api_key=sk_live_1 \"client_secret\":\"s3cr3t\" eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln "
            .str_repeat('a1', 20)
        );
        foreach (['root:pw', 'sk_live_1', 's3cr3t', 'eyJhbGci', str_repeat('a1', 20)] as $leak) {
            $this->assertStringNotContainsString($leak, $redacted);
        }
        $this->assertStringContainsString('mysql://[redacted]@db.local', $redacted);
        $this->assertStringContainsString('SQL: [redacted]', $redacted);
        $this->assertStringNotContainsString("\n", $redacted);
        $this->assertSame('app/Services/Example.php is fine', TransportTargetIsolation::redact('app/Services/Example.php is fine'));
        $capped = TransportTargetIsolation::redact(str_repeat('word ', 200), 50);
        $this->assertSame(50, mb_strlen($capped));
        $this->assertStringEndsWith('…', $capped);
    }

    public function test_heartbeat_reports_degraded_with_failing_count_when_some_targets_fail(): void
    {
        $isolation = new TransportTargetIsolation();
        $isolation->attempt($this->target(77), 'receipt_handoff', fn () => throw new RuntimeException('down'));
        $isolation->attempt($this->target(78), 'receipt_handoff', fn () => 1);
        $targets = [$this->target(77), $this->target(78)];

        $failing = (new TransportTargetIsolation())->failingTargets($targets);
        $this->assertSame(1, $failing, 'Backed-off targets keep counting as failing until they succeed.');
        $this->assertSame('degraded', TransportWorkerHeartbeat::stateFor(2, $failing));
        $this->assertSame('failing', TransportWorkerHeartbeat::stateFor(2, 2));
        $this->assertSame('running', TransportWorkerHeartbeat::stateFor(2, 0));
        $this->assertSame('idle', TransportWorkerHeartbeat::stateFor(0, 0));

        app(TransportWorkerHeartbeat::class)->write('degraded', 2, 9, $failing);
        $heartbeat = json_decode((string) file_get_contents($this->directory.'/heartbeat.json'), true);
        $this->assertSame('solastock-finance-worker.v1', $heartbeat['contract_version'], 'The v1 contract is kept; fields are additive.');
        $this->assertSame('degraded', $heartbeat['state']);
        $this->assertSame(2, $heartbeat['approved_targets']);
        $this->assertSame(9, $heartbeat['processed']);
        $this->assertSame(1, $heartbeat['failing_targets']);
        $this->assertSame(1, $heartbeat['healthy_targets']);
    }

    public function test_supervisor_cycle_survives_an_organization_that_throws_and_visits_every_target(): void
    {
        $registry = $this->createMock(ApprovedTransportTargetRegistry::class);
        $registry->method('targets')->willReturn([$this->target(77), $this->target(78)]);
        $visited = [];
        $tenants = $this->createMock(TenantManager::class);
        $tenants->method('switchToDatabase')->willReturnCallback(function (string $database) use (&$visited): void {
            $visited[] = $database;
            throw new RuntimeException('tenant unavailable for '.$database);
        });
        $this->app->instance(ApprovedTransportTargetRegistry::class, $registry);
        $this->app->instance(TenantManager::class, $tenants);
        Log::spy();

        $this->artisan('integration:transport-supervise', ['--once' => true])->assertExitCode(0);

        // Every approved target failed in the pass: health says so instead of `running`.
        Log::shouldHaveReceived('log')->with('warning', 'integration.transport.supervisor_health',
            Mockery::on(fn (array $context): bool => $context['state'] === 'failing' && $context['failing_targets'] === 2 && $context['approved_targets'] === 2))->once();

        $this->assertSame(['tenant_000077', 'tenant_000078'], $visited);
        $diagnostics = (new TransportTargetIsolation())->diagnostics();
        $this->assertSame(['42:77:connection', '42:78:connection'], array_keys($diagnostics));
        $this->assertSame('stopped', json_decode((string) file_get_contents($this->directory.'/heartbeat.json'), true)['state'], 'The once-cycle completed instead of aborting on the first organization.');
    }

    public function test_an_inactive_target_leaves_the_failing_set_but_a_failing_connection_does_not(): void
    {
        $isolation = new TransportTargetIsolation();
        $isolation->attempt($this->target(77), 'party_sync', fn () => throw new RuntimeException('party down'));
        $isolation->attempt($this->target(77), 'catalog_sync', fn () => throw new RuntimeException('catalog down'));
        $isolation->attempt($this->target(78), 'connection', fn () => throw new RuntimeException('tenant down'));
        $targets = [$this->target(77), $this->target(78)];
        $this->assertSame(2, $isolation->failingTargets($targets));

        Log::spy();
        $isolation->retireInactive($this->target(77));

        $fresh = new TransportTargetIsolation();
        $this->assertSame(['42:78:connection'], array_keys($fresh->diagnostics()), 'Only the inactive target is dropped.');
        $this->assertSame(1, $fresh->failingTargets($targets));
        $this->assertSame('degraded', TransportWorkerHeartbeat::stateFor(2, $fresh->failingTargets($targets)));
        Log::shouldHaveReceived('info')->with('integration.transport.target_inactive', Mockery::on(
            fn (array $context): bool => $context['organization_id'] === 77 && $context['cleared_stages'] === ['party_sync', 'catalog_sync']))->once();
        // Idempotent: nothing left to clear, nothing logged again.
        $fresh->retireInactive($this->target(77));
        Log::shouldHaveReceived('info')->with('integration.transport.target_inactive', Mockery::any())->once();
    }

    public function test_supervisor_clears_stale_stage_failures_of_a_target_whose_mapping_turned_inactive(): void
    {
        // Batch 6 review F2: a party_sync failure recorded while the mapping was active.
        (new TransportTargetIsolation())->attempt($this->target(77), 'party_sync', fn () => throw new RuntimeException('party down'));
        $this->assertSame(1, (new TransportTargetIsolation())->failingTargets([$this->target(77)]));

        $registry = $this->createMock(ApprovedTransportTargetRegistry::class);
        $registry->method('targets')->willReturn([$this->target(77)]);
        // The connection stage reads the (reserved test) tenant: no active verified mapping for
        // client 42 / organization 77 exists there, i.e. the mapping is paused/unverified.
        $tenants = $this->createMock(TenantManager::class);
        $tenants->method('switchToDatabase')->willReturnCallback(fn (string $database) => null);
        $this->app->instance(ApprovedTransportTargetRegistry::class, $registry);
        $this->app->instance(TenantManager::class, $tenants);
        Log::spy();

        $this->artisan('integration:transport-supervise', ['--once' => true])->assertExitCode(0);

        $this->assertSame([], (new TransportTargetIsolation())->diagnostics());
        Log::shouldHaveReceived('log')->with('info', 'integration.transport.supervisor_health',
            Mockery::on(fn (array $context): bool => $context['state'] === 'running' && $context['failing_targets'] === 0))->once();
    }
}
