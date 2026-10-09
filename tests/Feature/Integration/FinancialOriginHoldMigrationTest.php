<?php
namespace Tests\Feature\Integration;

use App\Models\Tenant\{PurchaseValuationHold,StockLedger};
use App\Services\Purchasing\PurchaseValuationHoldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native additive DDL and legacy hold guard; source-document accounting authorization is not exercised here. */
#[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
final class FinancialOriginHoldMigrationTest extends TestCase
{
    use TenantAware;

    private function schema():void
    {
        $this->useTenantA();
        (require base_path('database/migrations/tenant/2026_10_07_081000_create_purchase_valuation_holds.php'))->up();
        $migration=require base_path('database/migrations/tenant/2026_10_07_188000_add_financial_origin_valuation_hold_identity.php');
        $migration->up();$migration->up();
        $this->tenantTestManager->cleanup();$this->useTenantA();
    }

    private function scope(string $purpose):array
    {
        $item=StockTestFactory::averageItem();$warehouse=StockTestFactory::warehouse();
        return ['settlement_uuid'=>(string)Str::uuid(),'plan_revision'=>1,'purpose'=>$purpose,'item_id'=>$item->id,
            'warehouse_id'=>$warehouse->id,'receipt_id'=>42];
    }

    public function test_additive_native_migration_preserves_old_bill_writer_and_real_typed_identity_without_dummy_bill():void
    {
        $this->schema();$service=app(PurchaseValuationHoldService::class);$before=StockLedger::count();
        $legacy=$service->acquire($this->scope('apply')+['source_bill_id'=>27],str_repeat('a',64));
        $origin=$service->acquire($this->scope('origin_apply')+['source_bill_id'=>null,'source_document_type'=>'expense',
            'source_document_id'=>837,'source_journal_id'=>901],str_repeat('b',64));
        $this->assertSame(27,(int)$legacy->source_bill_id);$this->assertNull($legacy->source_document_type);
        $this->assertNull($origin->source_bill_id);$this->assertSame('expense',$origin->source_document_type);
        $this->assertSame(837,(int)$origin->source_document_id);$this->assertSame(901,(int)$origin->source_journal_id);
        foreach (['source_document_type'=>'sales_receipt','source_document_id'=>838,'source_journal_id'=>902] as $field=>$value) {
            try {$origin->fresh()->update([$field=>$value]);$this->fail('Immutable typed source identity changed');}
            catch (ValidationException $e) {$this->assertArrayHasKey('settlement_uuid',$e->errors());}
        }
        try {$legacy->fresh()->update(['source_bill_id'=>28]);$this->fail('Legacy source identity changed');}
        catch (ValidationException $e) {$this->assertArrayHasKey('settlement_uuid',$e->errors());}
        try {$origin->fresh()->update(['organization_id'=>999]);$this->fail('Typed source crossed organization');}
        catch (\RuntimeException $e) {$this->assertStringContainsString('organization_id is immutable',$e->getMessage());}
        (require base_path('database/migrations/tenant/2026_10_07_188000_add_financial_origin_valuation_hold_identity.php'))->down();
        $this->assertSame('expense',$origin->fresh()->source_document_type);$this->assertSame($before,StockLedger::count());
    }

    public function test_legacy_pool_guard_blocks_typed_hold_and_never_waives_it_with_bill_zero_group():void
    {
        $this->schema();$service=app(PurchaseValuationHoldService::class);$scope=$this->scope('origin_reverse');
        $hold=$service->acquire($scope+['source_bill_id'=>null,'source_document_type'=>'expense','source_document_id'=>837,'source_journal_id'=>901],str_repeat('c',64));
        foreach([null,['purpose'=>'reverse','group_bill_id'=>0]]as$allow){
            try{$service->assertMovable($scope['item_id'],$scope['warehouse_id'],$allow);$this->fail('Typed hold was waived by a legacy movement');}
            catch(ValidationException $e){$this->assertArrayHasKey('item_id',$e->errors());}
        }
        $this->assertSame('active',$hold->fresh()->state);
        $hold->update(['state'=>'released']);$service->assertMovable($scope['item_id'],$scope['warehouse_id']);
        $this->assertSame('released',$hold->fresh()->state);$this->assertSame(0,StockLedger::count());
    }
}
