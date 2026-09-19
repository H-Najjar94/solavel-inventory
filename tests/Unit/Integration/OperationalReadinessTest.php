<?php
namespace Tests\Unit\Integration;
use App\Services\InventoryWorkspace\OperationalReadiness;
use PHPUnit\Framework\TestCase;
final class OperationalReadinessTest extends TestCase
{
 public function test_only_verified_connected_ready_with_complete_mappings_unlocks_operations(): void
 {
  self::assertTrue(OperationalReadiness::allows(true,true,'CONNECTED_READY',[]));
  foreach(['ACCESS_REQUIRED','FINANCE_READY','CONNECTION_SETUP_INCOMPLETE','CONNECTION_BLOCKED','MAINTENANCE_HOLD','READINESS_UNAVAILABLE'] as $state)
   self::assertFalse(OperationalReadiness::allows(true,true,$state,[]),$state);
  self::assertFalse(OperationalReadiness::allows(false,true,'CONNECTED_READY',[]));
  self::assertFalse(OperationalReadiness::allows(true,false,'CONNECTED_READY',[]));
  self::assertFalse(OperationalReadiness::allows(true,true,'CONNECTED_READY',['inventory_asset']));
 }
}
