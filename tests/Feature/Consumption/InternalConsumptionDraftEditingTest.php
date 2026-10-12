<?php
namespace Tests\Feature\Consumption;

use App\Models\Tenant\{InventoryAuditLog,InventorySetting,InternalConsumption,InternalConsumptionLine,StockBalance};
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Consumption\InternalConsumptionService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SourceDocumentPresenter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class InternalConsumptionDraftEditingTest extends TestCase {
    use TenantAware;
    private function setupInventory(): array {
        $this->useTenantA();
        $this->mock(InventoryPermissionService::class,fn($m)=>$m->shouldReceive('can')->andReturn(true));
        InventorySetting::query()->updateOrCreate(['organization_id'=>TenantTestManager::ORG_A],['default_costing_method'=>'average','allow_negative_stock'=>false]);
        $wh=F::warehouse();$item=F::item(['costing_method'=>'average','available_for_sale'=>false,'available_for_purchase'=>true]);
        $opening=app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id'=>$wh->id],[['item_id'=>$item->id,'quantity'=>'20','unit_cost'=>'2']]));
        return [$wh,$item,app(InternalConsumptionService::class)];
    }
    private function payload($wh,$item,string $qty='3',array $extra=[]): array {
        return array_merge(['warehouse_id'=>$wh->id,'document_date'=>'2026-10-10','reason'=>'Office supplies','lines'=>[['item_id'=>$item->id,'quantity'=>$qty]]],$extra);
    }
    private function draft($service,$wh,$item,string $qty='3',array $extra=[]): InternalConsumption {
        return $service->create(['submission_key'=>(string)Str::uuid()]+$this->payload($wh,$item,$qty,$extra));
    }
    public function testDraftCanBeEditedAndIsAudited(): void {
        [$wh,$item,$service]=$this->setupInventory();$draft=$this->draft($service,$wh,$item);
        $updated=$service->updateDraft($draft,$this->payload($wh,$item,'5',['reason'=>'Workshop use','document_date'=>'2026-10-11']));
        self::assertSame($draft->id,$updated->id);
        self::assertSame('draft',$updated->status);
        self::assertSame('Workshop use',$updated->reason);
        self::assertSame('2026-10-11',$updated->document_date->toDateString());
        self::assertSame(1,$updated->lines->count());
        self::assertSame('5.0000',$updated->lines->first()->quantity);
        self::assertSame(1,InternalConsumptionLine::query()->where('internal_consumption_id',$draft->id)->count());
        self::assertSame('20.0000',StockBalance::query()->first()->on_hand_qty);
        $audit=InventoryAuditLog::query()->where('entity_type','internal_consumption')->where('entity_id',$draft->id)->where('action','internal_consumption.update')->sole();
        self::assertSame('Office supplies',$audit->before['reason']);
        self::assertSame('Workshop use',$audit->after['reason']);
        self::assertSame('5.0000',$service->post($updated)->lines->first()->quantity);
        self::assertSame('15.0000',StockBalance::query()->first()->on_hand_qty);
    }
    public function testDraftCanBeDeletedAndIsAudited(): void {
        [$wh,$item,$service]=$this->setupInventory();$draft=$this->draft($service,$wh,$item);
        $service->deleteDraft($draft);
        self::assertNull(InternalConsumption::query()->find($draft->id));
        self::assertSame(0,InternalConsumptionLine::query()->where('internal_consumption_id',$draft->id)->count());
        $audit=InventoryAuditLog::query()->where('entity_type','internal_consumption')->where('entity_id',$draft->id)->where('action','internal_consumption.delete')->sole();
        self::assertSame($draft->document_number,$audit->document_ref);
        self::assertSame('20.0000',StockBalance::query()->first()->on_hand_qty);
    }
    public function testPostedAndApprovedDocumentsCannotBeEditedOrDeleted(): void {
        [$wh,$item,$service]=$this->setupInventory();
        $posted=$service->post($this->draft($service,$wh,$item));
        $approved=$service->approve($this->draft($service,$wh,$item,'1'));
        foreach ([$posted,$approved] as $doc) {
            try {$service->updateDraft($doc,$this->payload($wh,$item,'9'));self::fail('Non-draft edited');} catch (ValidationException $e) {}
            try {$service->deleteDraft($doc);self::fail('Non-draft deleted');} catch (ValidationException $e) {}
            self::assertNotNull(InternalConsumption::query()->find($doc->id));
        }
        self::assertSame('3.0000',$posted->fresh('lines')->lines->first()->quantity);
        self::assertSame('1.0000',$approved->fresh('lines')->lines->first()->quantity);
        self::assertSame(0,InventoryAuditLog::query()->where('entity_type','internal_consumption')->whereIn('action',['internal_consumption.update','internal_consumption.delete'])->count());
    }
    public function testReturnDraftEditKeepsOriginalIssueLinkage(): void {
        [$wh,$item,$service]=$this->setupInventory();$issue=$service->post($this->draft($service,$wh,$item));
        $lineId=$issue->lines->first()->id;
        $return=$this->draft($service,$wh,$item,'1',['original_issue_id'=>$issue->id,'lines'=>[['original_line_id'=>$lineId,'quantity'=>'1']]]);
        $updated=$service->updateDraft($return,['document_date'=>'2026-10-12','reason'=>'Unused leftovers','lines'=>[['original_line_id'=>$lineId,'quantity'=>'2']]]);
        self::assertSame('return',$updated->kind);
        self::assertSame($issue->id,$updated->original_issue_id);
        self::assertSame($wh->id,$updated->warehouse_id);
        self::assertSame($lineId,$updated->lines->first()->original_line_id);
        self::assertSame('2.0000',$updated->lines->first()->quantity);
        try {$service->updateDraft($return,['document_date'=>'2026-10-01','reason'=>'Too early','lines'=>[['original_line_id'=>$lineId,'quantity'=>'1']]]);self::fail('Return dated before issue');} catch (ValidationException $e) {}
        $service->deleteDraft($updated);
        self::assertNull(InternalConsumption::query()->find($return->id));
        self::assertNotNull(InternalConsumption::query()->find($issue->id));
    }
    public function testEditAndDeleteRequireTheCreatePermission(): void {
        [$wh,$item,$service]=$this->setupInventory();$draft=$this->draft($service,$wh,$item);
        $this->mock(InventoryPermissionService::class,fn($m)=>$m->shouldReceive('can')->andReturnUsing(fn($user,$perm)=>$perm!=='inventory.consumption.create'));
        $service=app(InternalConsumptionService::class);
        try {$service->updateDraft($draft,$this->payload($wh,$item,'4'));self::fail('Edit without permission');} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {self::assertSame(403,$e->getStatusCode());}
        try {$service->deleteDraft($draft);self::fail('Delete without permission');} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {self::assertSame(403,$e->getStatusCode());}
        self::assertSame('3.0000',$draft->fresh('lines')->lines->first()->quantity);
    }
    public function testEditAndDeleteStayWithinWarehouseScope(): void {
        [$wh,$item,$service]=$this->setupInventory();$draft=$this->draft($service,$wh,$item);$other=F::warehouse();
        $this->partialMock(WarehouseAccessService::class,fn($m)=>$m->shouldReceive('allowedIds')->andReturn([(int)$other->id]));
        $service=app(InternalConsumptionService::class);
        foreach ([fn()=>$service->updateDraft($draft,$this->payload($wh,$item,'4')),fn()=>$service->deleteDraft($draft)] as $attempt) {
            try {$attempt();self::fail('Out-of-scope draft changed');}
            catch (\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {}
        }
        $kept=InternalConsumption::query()->withoutGlobalScope('warehouse_access')->with('lines')->find($draft->id);
        self::assertNotNull($kept);
        self::assertSame('3.0000',$kept->lines->first()->quantity);
    }
    public function testMovingADraftToAnUnassignedWarehouseIsRefused(): void {
        [$wh,$item,$service]=$this->setupInventory();$draft=$this->draft($service,$wh,$item);$other=F::warehouse();
        $this->partialMock(WarehouseAccessService::class,fn($m)=>$m->shouldReceive('allowedIds')->andReturn([(int)$wh->id]));
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(InternalConsumptionService::class)->updateDraft($draft,$this->payload($other,$item,'4'));
    }
    public function testDraftRoutesAreRegistered(): void {
        self::assertContains('PUT',Route::getRoutes()->getByName('api.v1.consumption.update')->methods());
        self::assertContains('DELETE',Route::getRoutes()->getByName('api.v1.consumption.destroy')->methods());
    }
    public function testLedgerSourceLabelIsTranslated(): void {
        [$wh,$item,$service]=$this->setupInventory();$posted=$service->post($this->draft($service,$wh,$item));
        foreach (['en'=>'Internal consumption','ar'=>'الصرف الداخلي'] as $locale=>$label) {
            app()->setLocale($locale);
            self::assertSame($label,SourceDocumentPresenter::describe(InternalConsumption::class,$posted->id)['source_label']);
            $row=SourceDocumentPresenter::decorateRows(collect([(object)['source_type'=>InternalConsumption::class,'source_id'=>$posted->id]]))->first();
            self::assertStringStartsWith($label,(string)data_get($row,'source_display'));
        }
        app()->setLocale('en');
    }
}
