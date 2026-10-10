<?php
namespace Tests\Feature\Consumption;

use App\Models\Tenant\{InventorySetting,InternalConsumption,StockBalance};
use App\Services\Access\InventoryPermissionService;
use App\Services\Consumption\InternalConsumptionService;
use App\Services\Documents\OpeningStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\{StockTestFactory as F,CommittedTenantFixture};
use Tests\TestCase;
use Tests\Traits\TenantAware;

#[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
final class ConsumptionConcurrencyTest extends TestCase {
    use TenantAware;
    public function testConcurrentIssuesAndReturnsCannotOverspendOrRestoreTwice(): void {
        $this->useTenantA();$fixture=new CommittedTenantFixture($this->tenantTestManager);
        $this->mock(InventoryPermissionService::class,fn($m)=>$m->shouldReceive('can')->andReturn(true));
        InventorySetting::updateOrCreate(['organization_id'=>\Tests\Support\TenantTestManager::ORG_A],['allow_negative_stock'=>false]);
        $wh=F::warehouse();$item=F::averageItem();$opening=app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id'=>$wh->id],[['item_id'=>$item->id,'quantity'=>'20','unit_cost'=>'2']]));
        $service=app(InternalConsumptionService::class);$drafts=[];
        for($i=0;$i<2;$i++)$drafts[]=$service->create(['submission_key'=>(string)Str::uuid(),'warehouse_id'=>$wh->id,'document_date'=>'2026-10-10','reason'=>'Concurrent office use','lines'=>[['item_id'=>$item->id,'quantity'=>'15']]])->id;
        $fixture->commit();
        self::assertSame([0,1],$this->race($drafts));
        self::assertSame('5.0000',StockBalance::first()->on_hand_qty);
        $issue=InternalConsumption::where('status','posted')->with('lines')->sole();$returns=[];
        for($i=0;$i<2;$i++)$returns[]=$service->create(['submission_key'=>(string)Str::uuid(),'warehouse_id'=>$wh->id,'document_date'=>'2026-10-10','reason'=>'Unused materials','original_issue_id'=>$issue->id,'lines'=>[['original_line_id'=>$issue->lines->first()->id,'quantity'=>'10']]])->id;
        self::assertSame([0,1],$this->race($returns));
        self::assertSame('15.0000',StockBalance::first()->on_hand_qty);self::assertSame('30.00',StockBalance::first()->total_value);
        $fixture->restore();
    }
    private function race(array $ids): array {
        self::assertTrue(function_exists('pcntl_fork'),'Real process contention requires pcntl');
        $root=sys_get_temp_dir().'/consumption-race-'.bin2hex(random_bytes(8));mkdir($root,0700);$pids=[];
        DB::disconnect('tenant');
        foreach($ids as$i=>$id){
            $pid=pcntl_fork();self::assertNotSame(-1,$pid);
            if($pid===0){
                DB::purge('tenant');while(!is_file($root.'/go'))usleep(1000);
                try{app(InternalConsumptionService::class)->post(InternalConsumption::findOrFail($id));file_put_contents($root.'/'.$i,'1');}
                catch(\RuntimeException $e){file_put_contents($root.'/'.$i,'0');}
                catch(\Throwable $e){file_put_contents($root.'/'.$i,$e::class.': '.$e->getMessage());}
                exit(0);
            }$pids[]=$pid;
        }
        touch($root.'/go');foreach($pids as$pid)pcntl_waitpid($pid,$status);
        DB::purge('tenant');$results=[];foreach(array_keys($ids)as$i){$value=file_get_contents($root.'/'.$i);self::assertContains($value,['0','1']);$results[]=(int)$value;unlink($root.'/'.$i);}unlink($root.'/go');rmdir($root);sort($results);return$results;
    }
}
