<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Unit;
use App\Models\User;
use App\Services\Access\CentralAppAccess;
use App\Services\Catalog\ItemBaseUnitConversion;
use App\Services\Catalog\StockUnitProjection;
use App\Services\Documents\OpeningStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CommittedTenantFixture;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

#[Group('committed-native-transport')]
final class StockUnitProjectionTest extends TestCase
{
    use TenantAware;

    public function test_signed_owner_retry_recovers_remote_commit_and_unblocks_only_after_matching_ack(): void
    {
        $this->useTenantA();
        $fixture = new CommittedTenantFixture($this->tenantTestManager);
        try {
            $ea = Unit::create(['code' => 'EP', 'name' => 'Each', 'is_active' => true]);
            $box = Unit::create(['code' => 'BP', 'name' => 'Box', 'is_active' => true]);
            $item = F::averageItem(['base_unit_id' => $ea->id]);
            $opening = app(OpeningStockService::class);
            $opening->post($opening->createDraft(['entry_number' => 'OWNER-OPEN', 'warehouse_id' => F::warehouse()->id, 'opening_date' => now()->subDays(2)->toDateString()], [['item_id' => $item->id, 'quantity' => 20, 'unit_cost' => 5]]));
            $db = DB::connection('tenant');
            $db->table('organizations')->updateOrInsert(['id' => 14], ['central_org_id' => TenantTestManager::ORG_A]);
            $db->table('accounting_periods')->insert(['organization_id' => 14, 'name' => 'Open owner conversion', 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(), 'status' => 'open']);
            $map = IntegrationOrganizationMapping::create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => $db->getDatabaseName(), 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'contract_version' => 'solastock-journal.v2', 'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now()]);
            foreach ([['item', $item->id, 44], ['unit', $ea->id, 21], ['unit', $box->id, 22]] as [$type,$source,$target]) {
                $db->table('integration_master_data_mappings')->insert(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $map->mapping_uuid, 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'entity_type' => $type, 'solastock_record_id' => (string) $source, 'solabooks_record_id' => (string) $target, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed' => true, 'owner' => true]);
            $this->actingAs((new User)->forceFill(['id' => 777, 'name' => 'Authorized synthetic owner']));
            $service = app(ItemBaseUnitConversion::class);
            $date = now()->subDay()->toDateString();
            $p = $service->preview($item, $box->id, '1', '10', $date);
            $this->assertTrue($p['supported'], json_encode($p['blockers']));
            $result = $service->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified package conversion', $date, ['reference' => 'Synthetic evidence', 'sha256' => str_repeat('c', 64)]);
            $this->assertSame('finance_pending', $result['state']);
            $row = $db->table(ItemBaseUnitConversion::TABLE)->where('id', $result['event_id'])->first();
            $payload = json_decode($row->finance_payload, true);
            $this->assertSame('stock.base_unit_conversion.v1', $payload['schema_version']);
            $this->assertSame('1', $payload['ratio_numerator']);
            $this->assertSame('10', $payload['ratio_denominator']);
            $stockCount = StockLedger::where('item_id', $item->id)->count();
            $fixture->commit();
            config(['services.solabooks.api_base_url' => 'https://finance.test', 'finance_workspace.secret' => str_repeat('s', 64)]);
            $attempt = 0;
            Http::fake(function ($request) use ($payload, &$attempt) {
                $attempt++;

                return Http::response(['data' => ['event_uuid' => $payload['event_uuid'], 'snapshot_hash' => $attempt === 1 ? 'wrong' : $payload['snapshot_hash'], 'revision_after' => $payload['revision_after'], 'target_item_id' => 44, 'target_unit_id' => 22, 'status' => 'ready']]);
            });
            $worker = app(StockUnitProjection::class);
            $this->assertSame(1, $worker->process($map));
            $this->assertSame('finance_pending', $db->table(ItemBaseUnitConversion::TABLE)->where('id', $row->id)->value('state'));
            $db->table(ItemBaseUnitConversion::TABLE)->where('id', $row->id)->update(['next_attempt_at' => null]);
            $this->assertSame(1, $worker->process($map));
            $this->assertSame('ready', $db->table(ItemBaseUnitConversion::TABLE)->where('id', $row->id)->value('state'), (string) $db->table(ItemBaseUnitConversion::TABLE)->where('id', $row->id)->value('last_error'));
            $this->assertSame(0, $worker->process($map));
            $this->assertSame($stockCount, StockLedger::where('item_id', $item->id)->count());
            $this->assertSame('2.000000', StockBalance::where('item_id', $item->id)->first()->on_hand_qty);
            $this->assertSame('100.000000', StockBalance::where('item_id', $item->id)->first()->total_value);
            Http::assertSent(fn ($request) => $request['action'] === 'catalog.unit_transition' && $request['data']['event_uuid'] === $payload['event_uuid'] && strlen($request->header('X-Workspace-Signature')[0] ?? '') > 20);
        } finally {
            $fixture->restore();
        }
    }

    public function test_concurrent_conversion_retries_serialize_under_item_lock_and_create_one_boundary(): void
    {
        $this->useTenantA();
        $fixture = new CommittedTenantFixture($this->tenantTestManager);
        $pid = 0;
        $root = sys_get_temp_dir().'/unit-conversion-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $ea = Unit::create(['code' => 'RACE-EA', 'name' => 'Each', 'is_active' => true]);
            $box = Unit::create(['code' => 'RACE-BOX', 'name' => 'Box', 'is_active' => true]);
            $item = F::averageItem(['base_unit_id' => $ea->id]);
            $s = app(OpeningStockService::class);
            $s->post($s->createDraft(['entry_number' => 'RACE-OPEN', 'warehouse_id' => F::warehouse()->id, 'opening_date' => now()->subDays(2)->toDateString()], [['item_id' => $item->id, 'quantity' => 20, 'unit_cost' => 5]]));
            $date = now()->subDay()->toDateString();
            $service = app(ItemBaseUnitConversion::class);
            $plan = $service->preview($item, $box->id, '1', '10', $date);
            $request = (string) Str::uuid();
            $evidence = ['reference' => 'Synthetic race evidence', 'sha256' => str_repeat('a', 64)];
            $fixture->commit();
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('tenant');
                try {
                    $r = DB::connection('tenant')->transaction(function () use ($item, $box, $plan, $request, $date, $evidence, $root) {
                        Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                        file_put_contents($root.'/locked', '1');
                        $r = app(ItemBaseUnitConversion::class)->apply($item, $box->id, '1', '10', $plan['revision_sha256'], $request, 'Verified concurrent conversion', $date, $evidence);
                        usleep(1200000);

                        return $r;
                    });
                    file_put_contents($root.'/result', json_encode($r, JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents($root.'/error', $e::class.': '.$e->getMessage());
                    exit(1);
                }
            }
            for ($i = 0; $i < 2000 && ! is_file($root.'/locked') && ! is_file($root.'/error'); $i++) {
                usleep(5000);
            }$this->assertFileExists($root.'/locked');
            DB::purge('tenant');
            $start = microtime(true);
            $r = $service->apply($item, $box->id, '1', '10', $plan['revision_sha256'], $request, 'Verified concurrent conversion', $date, $evidence);
            $wait = microtime(true) - $start;
            pcntl_waitpid($pid, $status);
            $pid = 0;
            $this->assertSame(0, pcntl_wexitstatus($status), is_file($root.'/error') ? file_get_contents($root.'/error') : '');
            $this->assertGreaterThan(0.8, $wait);
            $this->assertSame(json_decode(file_get_contents($root.'/result'), true), $r);
            $this->assertSame(1, DB::connection('tenant')->table(ItemBaseUnitConversion::TABLE)->where('item_id', $item->id)->count());
            $this->assertSame(3, StockLedger::where('item_id', $item->id)->count());
            $this->assertSame('2.000000', StockBalance::where('item_id', $item->id)->first()->on_hand_qty);
        } finally {
            if ($pid > 0) {
                pcntl_waitpid($pid, $status);
            }foreach (['locked', 'result', 'error'] as $f) {
                @unlink($root.'/'.$f);
            }@rmdir($root);
            $fixture->restore();
        }
    }
}
