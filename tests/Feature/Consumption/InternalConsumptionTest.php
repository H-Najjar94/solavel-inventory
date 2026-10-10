<?php
namespace Tests\Feature\Consumption;

use App\Models\Tenant\{InventorySetting,InternalConsumption,InternalConsumptionLine,StockBalance,StockLedger,CostLayer,IntegrationOutboxEvent};
use App\Services\Access\InventoryPermissionService;
use App\Services\Consumption\InternalConsumptionService;
use App\Services\Documents\OpeningStockService;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class InternalConsumptionTest extends TestCase {
    use TenantAware;
    private function setupInventory(string $method='average'): array {
        $this->useTenantA();
        $this->mock(InventoryPermissionService::class,fn($m)=>$m->shouldReceive('can')->andReturn(true));
        InventorySetting::query()->updateOrCreate(['organization_id'=>TenantTestManager::ORG_A],['default_costing_method'=>$method,'allow_negative_stock'=>false]);
        $wh=F::warehouse();$item=F::item(['costing_method'=>$method,'available_for_sale'=>false,'available_for_purchase'=>true]);
        $opening=app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id'=>$wh->id],[['item_id'=>$item->id,'quantity'=>'20','unit_cost'=>'2']]));
        return [$wh,$item,app(InternalConsumptionService::class)];
    }
    private function draft($service,$wh,$item,string $qty='3',array $extra=[]): InternalConsumption {
        return $service->create(array_merge(['submission_key'=>(string)Str::uuid(),'warehouse_id'=>$wh->id,'document_date'=>'2026-10-10','reason'=>'Office supplies','lines'=>[['item_id'=>$item->id,'quantity'=>$qty]]],$extra));
    }
    public function testStandaloneNonSaleableIssueCostsStockAndRetriesOnlyOnce(): void {
        [$wh,$item,$service]=$this->setupInventory();
        $data=['submission_key'=>(string)Str::uuid(),'warehouse_id'=>$wh->id,'document_date'=>'2026-10-10','reason'=>'Office use','lines'=>[['item_id'=>$item->id,'quantity'=>'3']]];
        $draft=$service->create($data);
        self::assertSame($draft->id,$service->create($data)->id);
        self::assertSame('20.0000',StockBalance::query()->first()->on_hand_qty);
        $posted=$service->post($draft);$service->post($draft);
        self::assertSame('17.0000',StockBalance::query()->first()->on_hand_qty);
        self::assertSame('34.00',StockBalance::query()->first()->total_value);
        self::assertSame('6.00',$posted->lines->first()->total_cost);
        self::assertSame(1,StockLedger::query()->where('source_type',InternalConsumption::class)->count());
        self::assertFalse($posted->accounting_connected);
        self::assertSame(0,IntegrationOutboxEvent::query()->where('aggregate_type','InternalConsumption')->count());
    }
    public function testPartialAndFullReturnsRestoreOriginalCostAfterSubsequentPurchases(): void {
        [$wh,$item,$service]=$this->setupInventory();$issue=$service->post($this->draft($service,$wh,$item));
        $opening=app(OpeningStockService::class);$opening->post($opening->createDraft(['warehouse_id'=>$wh->id],[['item_id'=>$item->id,'quantity'=>'2','unit_cost'=>'10']]));
        foreach (['1','2'] as $quantity) {
            $return=$this->draft($service,$wh,$item,$quantity,['original_issue_id'=>$issue->id,'lines'=>[['original_line_id'=>$issue->lines->first()->id,'quantity'=>$quantity]]]);
            $posted=$service->post($return);$service->post($return);
            self::assertSame(Decimal::money(Decimal::mul($quantity,'2')),$posted->lines->first()->total_cost);
        }
        self::assertSame('22.0000',StockBalance::query()->first()->on_hand_qty);
        self::assertSame('60.00',StockBalance::query()->first()->total_value);
        $extra=$this->draft($service,$wh,$item,'1',['original_issue_id'=>$issue->id,'lines'=>[['original_line_id'=>$issue->lines->first()->id,'quantity'=>'1']]]);
        try {$service->post($extra);self::fail('Over-return accepted');} catch (\RuntimeException $e) {self::assertSame('draft',$extra->fresh()->status);}
        self::assertSame('22.0000',StockBalance::query()->first()->on_hand_qty);
    }
    public function testReturnUsesOriginalUomAfterCatalogConversionAndActivityChange(): void {
        [$wh,$item,$service]=$this->setupInventory();
        $each=\App\Models\Tenant\Unit::create(['code'=>'IC-EA','name'=>'Each','kind'=>'count','is_active'=>true]);
        $box=\App\Models\Tenant\Unit::create(['code'=>'IC-BOX','name'=>'Box','kind'=>'count','is_active'=>true]);
        $item->update(['base_unit_id'=>$each->id]);
        $conversion=\App\Models\Tenant\UnitConversion::create(['item_id'=>$item->id,'from_unit_id'=>$box->id,'to_unit_id'=>$each->id,'factor'=>'10']);
        $issue=$service->post($this->draft($service,$wh,$item,'1',['lines'=>[['item_id'=>$item->id,'quantity'=>'1','entered_unit_id'=>$box->id]]]));
        $conversion->update(['factor'=>'5']);$box->update(['is_active'=>false]);$item->update(['is_active'=>false]);
        $return=$service->post($this->draft($service,$wh,$item,'0.5',['original_issue_id'=>$issue->id,'lines'=>[['original_line_id'=>$issue->lines->first()->id,'quantity'=>'0.5','entered_unit_id'=>$box->id]]]));
        self::assertSame('5.0000',$return->lines->first()->quantity);
        self::assertSame('10.00',$return->lines->first()->total_cost);
        self::assertSame('15.0000',StockBalance::query()->first()->on_hand_qty);
    }
    public function testFifoReturnsRestoreOriginalLayersAndPreserveCarrying(): void {
        [$wh,$item,$service]=$this->setupInventory('fifo');$layer=CostLayer::query()->first();
        $issue=$service->post($this->draft($service,$wh,$item));
        foreach(['1','2'] as $qty) $service->post($this->draft($service,$wh,$item,$qty,['original_issue_id'=>$issue->id,'lines'=>[['original_line_id'=>$issue->lines->first()->id,'quantity'=>$qty]]]));
        self::assertSame(1,CostLayer::query()->count());self::assertSame('20.0000',$layer->fresh()->remaining_qty);
        self::assertSame('40.00',StockBalance::query()->first()->total_value);
    }
    public function testApprovalDoesNotMoveStockAndPostingRequiresConfiguredApproval(): void {
        [$wh,$item,$service]=$this->setupInventory();InventorySetting::query()->first()->update(['approvals'=>['internal_consumption'=>true]]);
        $draft=$this->draft($service,$wh,$item);
        try {$service->post($draft);self::fail('Unapproved issue posted');} catch (\Illuminate\Validation\ValidationException $e) {}
        self::assertSame('20.0000',StockBalance::query()->first()->on_hand_qty);
        $service->approve($draft);self::assertSame('20.0000',StockBalance::query()->first()->on_hand_qty);
        self::assertSame('posted',$service->post($draft)->status);
    }
    public function testInsufficientStockRollsBackDocumentAndLedger(): void {
        [$wh,$item,$service]=$this->setupInventory();$draft=$this->draft($service,$wh,$item,'21');
        try {$service->post($draft);self::fail('Overspend allowed');} catch (\RuntimeException $e) {}
        self::assertSame('draft',$draft->fresh()->status);self::assertSame('20.0000',StockBalance::query()->first()->on_hand_qty);
    }
    public function testPostedDocumentsAndLinesAreImmutable(): void {
        [$wh,$item,$service]=$this->setupInventory();$doc=$service->post($this->draft($service,$wh,$item));
        try {$doc->delete();self::fail('Posted document deleted');}catch(\RuntimeException $e){}
        try {$doc->lines->first()->update(['quantity'=>'2']);self::fail('Posted line edited');}catch(\RuntimeException $e){}
        self::assertSame('3.0000',$doc->lines->first()->fresh()->quantity);
    }
    public function testPermissionDenialPreventsCreation(): void {
        [$wh,$item]=$this->setupInventory();$this->mock(InventoryPermissionService::class,fn($m)=>$m->shouldReceive('can')->andReturn(false));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->draft(app(InternalConsumptionService::class),$wh,$item);
    }
    public function testOrganizationScopePreventsForeignDocumentPosting(): void {
        [$wh,$item,$service]=$this->setupInventory();$draft=$this->draft($service,$wh,$item);
        app(\App\Tenancy\OrganizationContext::class)->set(TenantTestManager::ORG_B);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);$service->post($draft);
    }
    public function testConnectedPostingFreezesExpenseAssetAndQueuesExactlyOnce(): void {
        [$wh,$item,$service]=$this->setupInventory();
        $db=DB::connection('tenant');$org=TenantTestManager::ORG_A;
        $unit=\App\Models\Tenant\Unit::create(['name'=>'Each','code'=>'ICEA','kind'=>'count','is_active'=>true]);$item->base_unit_id=$unit->id;$item->save();
        $db->table('organizations')->insert(['id'=>14,'central_org_id'=>$org,'name'=>'Consumption accounting','setup_status'=>'complete']);
        $map=\App\Models\Tenant\IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>$org,'solastock_organization_id'=>$org,'finance_organization_id'=>14,'tenant_database_identity'=>$db->getDatabaseName(),'contract_version'=>'solastock-journal.v2','status'=>'verified_hold','activation_state'=>'maintenance_hold','base_currency_code'=>'JOD']);
        \App\Models\Tenant\IntegrationSetting::create(['integration'=>'solabooks','mode'=>'paused','solabooks_organization_id'=>14,'meta'=>['client_id'=>7,'central_organization_id'=>$org,'signing_key_id'=>'consumption-key','transport_enabled_workflows'=>['internal_consumption.posted','internal_consumption.returned'],'finance_currency_contract'=>['base_currency_code'=>'JOD','enabled_currency_codes'=>['JOD'],'money_scale'=>2,'rate_scale'=>8,'inventory_valuation_basis'=>\App\Services\Integration\FinanceBaseValuation::BASIS]]]);
        foreach(['inventory_asset'=>100,'internal_consumption_expense'=>101]as$role=>$id){
            $db->table('accounts')->insert(['id'=>$id,'organization_id'=>14,'code'=>(string)$id,'name'=>$role,'type'=>$id===100?'asset':'expense','is_active'=>true,'is_postable'=>true]);
            \App\Models\Tenant\IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>$role,'solabooks_account_id'=>$id,'status'=>'verified']);
        }
        $fi=$db->table('inventory_items')->insertGetId(['organization_id'=>14,'name'=>'Materials','sku'=>'IC-MATERIAL','type'=>'inventory','inventory_asset_account_id'=>100,'purchase_account_id'=>100]);
        \App\Models\Tenant\IntegrationMasterDataMapping::create(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$map->mapping_uuid,'central_client_id'=>7,'central_organization_id'=>$org,'finance_organization_id'=>14,'solastock_organization_id'=>$org,'entity_type'=>'item','solastock_record_id'=>(string)$item->id,'solabooks_record_id'=>(string)$fi,'status'=>'verified']);
        \App\Models\Tenant\IntegrationMasterDataMapping::create(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$map->mapping_uuid,'central_client_id'=>7,'central_organization_id'=>$org,'finance_organization_id'=>14,'solastock_organization_id'=>$org,'entity_type'=>'unit','solastock_record_id'=>(string)$unit->id,'solabooks_record_id'=>'700','status'=>'verified']);
        $db->table('accounting_periods')->insert(['organization_id'=>14,'name'=>'October','period_no'=>10,'start_date'=>'2026-10-01','end_date'=>'2026-10-31','status'=>'open']);
        $issue=$service->post($this->draft($service,$wh,$item));$service->post($issue);
        $event=IntegrationOutboxEvent::where('event_type','internal_consumption.posted')->sole();
        self::assertTrue($issue->accounting_connected);self::assertSame('6.00',$event->payload['lines'][0]['total_cost']);
        $journal=app(\App\Services\Integration\AccountingJournalBuilder::class)->build($event,$org);
        self::assertSame(101,$journal[0]['account_id']);self::assertSame('6.00',$journal[0]['debit']);self::assertSame(100,$journal[1]['account_id']);self::assertSame('6.00',$journal[1]['credit']);
        $return=$service->post($this->draft($service,$wh,$item,'1',['original_issue_id'=>$issue->id,'lines'=>[['original_line_id'=>$issue->lines->first()->id,'quantity'=>'1']]]));
        $event=IntegrationOutboxEvent::where('event_type','internal_consumption.returned')->sole();
        $journal=app(\App\Services\Integration\AccountingJournalBuilder::class)->build($event,$org);
        self::assertSame('2.00',$journal[0]['credit']);self::assertSame('2.00',$journal[1]['debit']);self::assertSame($issue->original_event_uuid, null);
        // Invalid configured default never falls through to another account.
        $item->internal_consumption_account_id=99999;$item->save();$draft=$this->draft($service,$wh,$item);
        try{$service->post($draft);self::fail('Missing mapping accepted');}catch(\Illuminate\Validation\ValidationException $e){self::assertSame('draft',$draft->fresh()->status);}
        self::assertSame('18.0000',StockBalance::query()->first()->on_hand_qty);
    }

    public function testNonSaleableItemCannotCreateNewSalesOrderButCanBeConsumed(): void {
        [$wh,$item,$service]=$this->setupInventory();
        try {app(\App\Services\Documents\SalesOrderService::class)->createDraft(['warehouse_id'=>$wh->id,'customer_name'=>'Internal test'],[['item_id'=>$item->id,'ordered_qty'=>'1','unit_price'=>'3']]);self::fail('New sale accepted');} catch(\Illuminate\Validation\ValidationException $e) {self::assertNotEmpty($e->errors());}
        self::assertSame('posted',$service->post($this->draft($service,$wh,$item))->status);self::assertTrue($item->fresh()->available_for_purchase);
    }

    public function testFifoPreviewReusesCostingWithoutReducingLayers(): void {
        [$wh,$item]=$this->setupInventory('fifo');
        $cost=app(\App\Services\Stock\StockLedgerService::class)->previewOutbound(new \App\Services\Stock\StockMovement(direction:'out',itemId:$item->id,warehouseId:$wh->id,quantity:'3',sourceType:InternalConsumption::class,sourceId:0));
        self::assertSame('6.00',$cost['total_cost']);self::assertSame('20.0000',CostLayer::first()->remaining_qty);self::assertSame('20.0000',StockBalance::first()->on_hand_qty);
    }

    public function testMigrationRerunPreservesFlagsAndDocuments(): void {
        [$wh,$item,$service]=$this->setupInventory();$doc=$this->draft($service,$wh,$item);
        $migration=require base_path('database/migrations/tenant/2026_10_10_160000_add_internal_consumption.php');$migration->up();$migration->up();
        self::assertFalse($item->fresh()->available_for_sale);self::assertTrue($item->fresh()->available_for_purchase);self::assertSame('draft',$doc->fresh()->status);
    }
}
