<?php
namespace Tests\Unit\Integration;

use App\Services\Integration\TransportWorkerHeartbeat;
use Tests\TestCase;

final class TransportWorkerHeartbeatTest extends TestCase
{
    public function test_supervisor_health_requires_current_release_fresh_timestamp_and_running_process(): void
    {
        $this->travelTo(now()->startOfSecond());
        $heartbeat=['contract_version'=>'solastock-finance-worker.v1','state'=>'running','release_sha'=>'current-release','updated_at'=>now()->toIso8601String()];
        $service=app(TransportWorkerHeartbeat::class);
        $this->assertTrue($service->isCurrent($heartbeat,'current-release'));
        foreach ([['state'=>'stopped'],['state'=>'idle'],['release_sha'=>'old-release'],
            ['updated_at'=>now()->subMinutes(3)->toIso8601String()],['updated_at'=>now()->addMinute()->toIso8601String()],
            ['updated_at'=>'invalid'],['contract_version'=>'unknown']] as $change) {
            $this->assertFalse($service->isCurrent(array_replace($heartbeat,$change),'current-release'));
        }
        $this->assertFalse($service->isCurrent($heartbeat,''));
        $this->assertFalse($service->isCurrent([],'current-release'));
    }
}
