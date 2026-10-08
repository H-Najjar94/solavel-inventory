<?php
namespace Tests\Feature\Integration;

use App\Services\Integration\ApprovedTransportTargetRegistry;
use App\Services\Integration\TransportTargetIsolation;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\File;
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

        $this->artisan('integration:transport-supervise', ['--once' => true])->assertExitCode(0);

        $this->assertSame(['tenant_000077', 'tenant_000078'], $visited);
        $diagnostics = (new TransportTargetIsolation())->diagnostics();
        $this->assertSame(['42:77:connection', '42:78:connection'], array_keys($diagnostics));
        $this->assertSame('stopped', json_decode((string) file_get_contents($this->directory.'/heartbeat.json'), true)['state'], 'The once-cycle completed instead of aborting on the first organization.');
    }
}
