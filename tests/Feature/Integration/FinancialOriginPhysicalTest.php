<?php
namespace Tests\Feature\Integration;
use App\Models\User;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginOutbox,FinancialOriginRequest,GoodsReceipt,IntegrationAccountMapping,InventoryUserWarehouse,SalesDocumentOutbox,Shipment,StockBalance,StockLedger,Supplier};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\FinancialOrigins\{OriginDispatchService,OriginRequestService};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinancialOriginFixture;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native Stock orders/shipments/GRNs/ledgers, with explicit remote admission and Finance source projection seams. */
final class FinancialOriginPhysicalTest extends TestCase
{
    use TenantAware,FinancialOriginFixture;
    private function actor(int $id,bool $owner=false,bool $stock=true):void
    {
        config(['inventory.demo_tenant.enabled'=>false]);$actor=new User;$actor->id=$id;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturnUsing(fn($user,$org,$app)=>['allowed'=>$app==='inventory'&&$stock,'owner'=>$owner,'roles'=>$owner?[]:['warehouse_operator']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
    }
    private function admitted(string $type='sales_receipt',string $tracking='none',bool $anonymous=false):array
    {
        $this->initializeOriginFixture(true,$tracking);
        if($type==='expense'){
            $supplier=Supplier::create(['code'=>'QA-TYPED-PHYSICAL','name'=>'QA typed physical supplier','is_active'=>true]);$this->master('supplier',$supplier->id,704);
            DB::connection('tenant')->table('accounts')->insert(['id'=>300,'organization_id'=>14,'code'=>'300','name'=>'GRNI','type'=>'liability','is_active'=>true,'is_postable'=>true]);
            $a=IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>'grni','solabooks_account_id'=>300,'status'=>'verified']);$this->master('account_role',$a->id,300);
        }
        $data=$this->typed($type,$anonymous);$this->proof($data);$r=app(OriginRequestService::class)->upsert($data,323);
        $context=array_intersect_key($data,array_flip(['source_document_type','source_document_id','source_document_number','source_journal_id','request_uuid']))+['request_revision'=>$data['source_revision']];
        $this->actor(323,true);app(OriginRequestService::class)->approveNative($context+['warehouse_id'=>$this->warehouse->id],323);
        InventoryUserWarehouse::create(['user_id'=>336,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>323]);$this->actor(336);
        $op=$context+['operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'physical_date'=>'2026-10-07',
            'lines'=>[['request_line_id'=>$r['lines'][0]['id'],'source_document_line_id'=>851,'quantity'=>'2','unit_id'=>$this->unit->id]]];
        return [$data,$context,$op];
    }
    #[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
    public function test_expense_receiver_can_assign_and_receive_but_adjustment_authority_cannot_replace_receiving_permission():void
    {
        $this->initializeOriginFixture(true);
        $supplier=Supplier::create(['code'=>'QA-RECEIVER-APPROVAL','name'=>'QA receiver approval','is_active'=>true]);$this->master('supplier',$supplier->id,704);
        DB::connection('tenant')->table('accounts')->insert(['id'=>300,'organization_id'=>14,'code'=>'300','name'=>'GRNI','type'=>'liability','is_active'=>true,'is_postable'=>true]);
        $account=IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>'grni','solabooks_account_id'=>300,'status'=>'verified']);$this->master('account_role',$account->id,300);
        $data=$this->typed('expense');$this->proof($data);$request=app(OriginRequestService::class)->upsert($data,323);
        $context=array_intersect_key($data,array_flip(['source_document_type','source_document_id','source_document_number','source_journal_id','request_uuid']))+['request_revision'=>$data['source_revision']];
        $this->actor(338,true);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>true,'roles'=>[],'grants'=>[['effect'=>'deny','permission_key'=>'inventory.receive_goods','scope_type'=>'organization']]]);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->assertTrue(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_adjustments'));
        $this->assertFalse(app(InventoryPermissionService::class)->can(request()->user(),'inventory.receive_goods'));
        try { app(OriginRequestService::class)->approveNative($context+['warehouse_id'=>$this->warehouse->id],338);$this->fail('Adjustment authority replaced receiving authority'); }
        catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertNull(FinancialOriginRequest::sole()->approved_at);$this->assertSame(0,GoodsReceipt::count());
        InventoryUserWarehouse::create(['user_id'=>336,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>323]);$this->actor(336);
        $this->assertTrue(app(InventoryPermissionService::class)->can(request()->user(),'inventory.receive_goods'));
        $this->assertFalse(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_adjustments'));
        $service=app(OriginDispatchService::class);$this->assertTrue($service->optionsNative($context,336)['can_approve']);
        app(OriginRequestService::class)->approveNative($context+['warehouse_id'=>$this->warehouse->id],336);
        $this->assertTrue($service->optionsNative($context,336)['can_execute']);
        $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->meta=$meta;$setting->save();
        $result=$service->executeNative($context+['operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'physical_date'=>'2026-10-07','lines'=>[['request_line_id'=>$request['lines'][0]['id'],'source_document_line_id'=>851,'quantity'=>'2','unit_id'=>$this->unit->id]]],336);
        $this->assertSame('partial',$result['status']);$this->assertSame(1,GoodsReceipt::count());$this->assertSame(1,FinancialOriginOutbox::count());
        $this->assertSame(FinancialOriginCommand::sole()->operation_uuid,FinancialOriginOutbox::sole()->payload['operation_uuid']);
        $this->assertFalse($service->optionsNative($context,336)['can_reserve']);
        // Render the native human GET outside the testing wrapper's transaction, as live HTTP does.
        $db=DB::connection('tenant');$committedFixture=new \Tests\Support\CommittedTenantFixture($this->tenantTestManager);
        try { $committedFixture->commit();
        $documents=app(OriginRequestService::class)->summary(FinancialOriginRequest::sole())['physical_documents'];
        $this->assertSame(GoodsReceipt::sole()->grn_number,$documents[0]['number']);$this->assertSame('goods_receipt',$documents[0]['type']);
        $this->assertSame([],DB::connection('tenant')->transaction(fn()=>app(OriginRequestService::class)->summary(FinancialOriginRequest::sole())['physical_documents']));
        $this->actor(337);$this->assertSame([],app(OriginRequestService::class)->summary(FinancialOriginRequest::sole())['physical_documents']);
        } finally { $committedFixture->restore(); }
    }
    public function test_native_operation_status_needs_no_finance_access_but_preserves_actor_revision_and_warehouse_scope():void
    {
        [,,$op]=$this->admitted(tracking:'serial');$service=app(OriginDispatchService::class);$before=DB::connection('tenant')->table('stock_ledger')->where('organization_id',\Tests\Support\TenantTestManager::ORG_A)->count();
        $this->assertFalse(app(CentralAppAccess::class)->decision(336,FinancialOriginRequest::sole()->organization_id,'finance')['allowed']);
        $this->item->update(['is_variant_parent'=>true,'tracks_expiry'=>true]);
        $variant=\App\Models\Tenant\ItemVariant::create(['item_id'=>$this->item->id,'sku'=>'QA-VISIBLE-VARIANT','variant_attributes'=>['size'=>'S'],'is_active'=>true]);
        \App\Models\Tenant\ItemVariant::create(['item_id'=>$this->item->id,'sku'=>'QA-INACTIVE-VARIANT','is_active'=>false]);
        $other=\Tests\Support\StockTestFactory::averageItem(['base_unit_id'=>$this->unit->id]);
        \App\Models\Tenant\ItemVariant::create(['item_id'=>$other->id,'sku'=>'QA-OTHER-PARENT-VARIANT','is_active'=>true]);
        $prepared=$service->prepareNative($op,336);$this->assertSame('prepared',$prepared['status']);
        $this->assertSame($prepared,$service->statusNative($op,336));
        $serial=\App\Models\Tenant\SerialNumber::query()->orderBy('id')->firstOrFail();
        $blocked=\App\Models\Tenant\SerialNumber::query()->orderBy('id')->skip(1)->firstOrFail();$blocked->update(['status'=>'quarantined']);
        $lot=\App\Models\Tenant\Lot::create(['item_id'=>$this->item->id,'lot_code'=>'QA-AVAILABLE','status'=>'active','expiry_date'=>now()->addYear()->toDateString()]);
        $expired=\App\Models\Tenant\Lot::create(['item_id'=>$this->item->id,'lot_code'=>'QA-EXPIRED','status'=>'active','expiry_date'=>now()->subDay()->toDateString()]);
        $balance=StockBalance::query()->firstOrFail();foreach([$lot,$expired]as$trace){$copy=$balance->replicate(['lot_key','bin_key','variant_key']);$copy->lot_id=$trace->id;$copy->on_hand_qty='2';$copy->reserved_qty='0';$copy->save();}
        $reserve=app(\App\Services\Stock\StockReservationService::class);$order=\App\Models\Tenant\SalesOrder::findOrFail(FinancialOriginRequest::sole()->sales_order_id);
        $reserve->reserveSerial($this->item->id,$this->warehouse->id,$serial->id,'sales_order',$order->id);
        $foreign=\App\Models\Tenant\SerialNumber::query()->orderBy('id')->skip(2)->firstOrFail();
        $otherOrder=app(\App\Services\Documents\SalesOrderService::class)->createDraft(['warehouse_id'=>$this->warehouse->id,'customer_id'=>$order->customer_id,'order_date'=>'2026-10-07','currency_code'=>'JOD'],[['item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'ordered_qty'=>'1','unit_price'=>'7','variant_id'=>$variant->id]]);
        $reserve->reserveSerial($this->item->id,$this->warehouse->id,$foreign->id,'sales_order',$otherOrder->id);
        $options=$service->optionsNative($op,336);$this->assertCount(1,$options['operations']);$this->assertSame($op,$options['operations'][0]['payload']);
        $this->assertSame([$variant->id],array_column($options['lines'][0]['variant_choices'],'id'));
        $this->assertSame([$lot->id],array_column($options['lines'][0]['lot_choices'],'id'));
        $this->assertContains($serial->id,array_column($options['lines'][0]['serial_choices'],'id'));$this->assertNotContains($blocked->id,array_column($options['lines'][0]['serial_choices'],'id'));
        $this->assertNotContains($foreign->id,array_column($options['lines'][0]['serial_choices'],'id'));$this->assertTrue(collect($options['lines'][0]['serial_choices'])->firstWhere('id',$serial->id)['reserved_for_order']);
        $this->assertSame(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_reservations'),$options['can_reserve']);
        $this->assertTrue($options['lines'][0]['requires_expiry']);$this->assertSame($this->unit->name,$options['lines'][0]['unit_name']);
        $changed=$op;$changed['request_revision']=str_repeat('b',64);
        try{$service->statusNative($changed,336);$this->fail('Stale operation revision disclosed');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->actor(337);InventoryUserWarehouse::create(['user_id'=>337,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>323]);
        $this->assertSame([],$service->optionsNative($op,337)['operations']);
        try{$service->statusNative($op,337);$this->fail('Different actor disclosed captured operation');}catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){$this->assertNotEmpty($e->getMessage());}
        $this->actor(336);InventoryUserWarehouse::query()->where('user_id',336)->delete();$this->app->forgetInstance(\App\Services\Access\WarehouseAccessService::class);
        try{$service->statusNative($op,336);$this->fail('Revoked warehouse assignment disclosed operation');}catch(\Illuminate\Auth\Access\AuthorizationException $e){$this->assertNotEmpty($e->getMessage());}
        try{$service->optionsNative($op,336);$this->fail('Revoked warehouse disclosed source options');}catch(\Illuminate\Auth\Access\AuthorizationException $e){$this->assertNotEmpty($e->getMessage());}
        $this->assertSame($before,DB::connection('tenant')->table('stock_ledger')->where('organization_id',\Tests\Support\TenantTestManager::ORG_A)->count()); // Audit stored rows after warehouse visibility is revoked.$this->assertSame(0,GoodsReceipt::count());$this->assertSame(0,FinancialOriginOutbox::count());
    }
    public function test_stock_only_cash_dispatch_partial_final_and_replay_emit_typed_events_without_new_invoice_draft():void
    {
        [,,$op]=$this->admitted();$service=app(OriginDispatchService::class);$before=StockLedger::count();
        $this->assertTrue(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_shipments'));
        $this->assertFalse(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_adjustments'));
        $this->assertFalse(app(CentralAppAccess::class)->decision(336,FinancialOriginRequest::sole()->organization_id,'finance')['allowed']);
        $prepared=$service->prepareNative($op,336);$this->assertSame('prepared',$prepared['status']);$this->assertSame(0,Shipment::count());$this->assertSame($before,StockLedger::count());
        $partial=$service->executeNative($op,336);$this->assertSame($partial,$service->executeNative($op,336));$this->assertSame('partial',$partial['status']);
        $this->assertSame(1,Shipment::count());$this->assertSame($before+1,StockLedger::count());$this->assertSame(0,SalesDocumentOutbox::count());
        $event=FinancialOriginOutbox::sole()->payload;$this->assertSame('sales_receipt',$event['source_document_type']);$this->assertSame(850,$event['source_document_id']);$this->assertSame(851,$event['physical']['lines'][0]['source_document_line_id']);
        $this->assertSame('2.0000',$event['physical']['lines'][0]['quantity']);$this->assertNotEmpty($event['physical']['journal_key']);
        $final=array_replace($op,['operation_uuid'=>(string)Str::uuid()]);$complete=$service->executeNative($final,336);$this->assertSame('complete',$complete['status']);
        $this->assertSame(2,FinancialOriginOutbox::count());$this->assertSame(2,Shipment::count());$this->assertSame('16.0000',StockBalance::sole()->on_hand_qty);
        $this->assertSame(0,SalesDocumentOutbox::count());
    }
    public function test_native_post_replay_of_completed_typed_receipt_and_shipment_preserves_all_durable_effects():void
    {
        $type='expense'; {
            [,,$op]=$this->admitted($type);
            $op['lines'][0]['quantity']='4';
            if($type==='expense'){
                $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->update(['meta'=>$meta]);
            }
            app(OriginDispatchService::class)->executeNative($op,336);
            $model=$type==='expense'?GoodsReceipt::class:Shipment::class;$document=$model::sole();
            $service=$type==='expense'?\App\Services\Documents\GoodsReceiptService::class:\App\Services\Documents\ShipmentService::class;
            $before=[StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity];
            $this->assertSame($document->id,app($service)->post($document)->id);
            $this->assertSame($before,[StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity]);

        }
    }
    public function test_native_post_replay_of_completed_typed_shipment_preserves_all_durable_effects():void
    {
        $type='sales_receipt'; {
            [,,$op]=$this->admitted($type);
            $op['lines'][0]['quantity']='4';
            if($type==='expense'){
                $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->update(['meta'=>$meta]);
            }
            app(OriginDispatchService::class)->executeNative($op,336);
            $model=$type==='expense'?GoodsReceipt::class:Shipment::class;$document=$model::sole();
            $service=$type==='expense'?\App\Services\Documents\GoodsReceiptService::class:\App\Services\Documents\ShipmentService::class;
            $before=[StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity];
            $this->assertSame($document->id,app($service)->post($document)->id);
            $this->assertSame($before,[StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity]);

        }
    }
    public function test_native_completed_receipt_replay_rejects_forged_command_event_hash_and_foreign_event_without_effects():void
    {
        [,,$op]=$this->admitted('expense');$op['lines'][0]['quantity']='4';
        $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->update(['meta'=>$meta]);
        app(OriginDispatchService::class)->executeNative($op,336);
        $document=GoodsReceipt::sole();$command=\App\Models\Tenant\FinancialOriginCommand::sole();$event=FinancialOriginOutbox::sole();
        $service=app(\App\Services\Documents\GoodsReceiptService::class);
        $effects=[StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity];
        $originalJournal=$command->source_journal_id;$command->update(['source_journal_id'=>$originalJournal+999]);
        try{$service->post($document);$this->fail('Forged command source was accepted');}
        catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){$this->assertNotEmpty($e->getMessage());}
        $command->update(['source_journal_id'=>$originalJournal]);
        $originalHash=$event->payload_hash;
        // Deliberate persisted-corruption fixture; ordinary model writes already reject immutable event changes.
        DB::connection('tenant')->table('stock_financial_origin_outbox')->where('id',$event->id)->update(['payload_hash'=>str_repeat('0',64)]);
        try{$service->post($document);$this->fail('Changed immutable event hash was accepted');}
        catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        DB::connection('tenant')->table('stock_financial_origin_outbox')->where('id',$event->id)->update(['payload_hash'=>$originalHash]);
        $originalOrg=$event->organization_id;DB::connection('tenant')->table('stock_financial_origin_outbox')->where('id',$event->id)->update(['organization_id'=>$originalOrg+999]);
        try{$service->post($document);$this->fail('Foreign event scope was accepted');}
        catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){$this->assertNotEmpty($e->getMessage());}
        DB::connection('tenant')->table('stock_financial_origin_outbox')->where('id',$event->id)->update(['organization_id'=>$originalOrg]);
        $this->assertSame($effects,[StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity]);
    }
    public function test_anonymous_cash_serial_dispatch_retains_null_customer_and_exact_serial_source_links():void
    {
        [,,$op]=$this->admitted(tracking:'serial',anonymous:true);$serials=\App\Models\Tenant\SerialNumber::query()->orderBy('id')->limit(2)->pluck('id')->all();$op['lines'][0]['serial_ids']=$serials;$op['reserve_stock']=true;
        $before=StockLedger::count();app(OriginDispatchService::class)->executeNative($op,336);
        $this->assertNull(\App\Models\Tenant\SalesOrder::sole()->customer_id);$this->assertSame(2,Shipment::sole()->lines()->count());$this->assertSame($before+2,StockLedger::count());
        $event=FinancialOriginOutbox::sole()->payload;$this->assertCount(2,$event['physical']['lines']);foreach($event['physical']['lines']as$line){$this->assertSame(851,$line['source_document_line_id']);$this->assertSame('1.0000',$line['quantity']);}
        $this->assertSame(0,SalesDocumentOutbox::count());
    }
    public function test_stock_only_expense_receipt_uses_native_grn_once_and_never_creates_a_bill_draft():void
    {
        [,,$op]=$this->admitted('expense');
        // A receipt requires its own reviewed native accounting workflow and real GRNI liability mapping.
        $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->meta=$meta;$setting->save();
        $before=StockLedger::count();$service=app(OriginDispatchService::class);$partial=$service->executeNative($op,336);
        $this->assertSame($partial,$service->executeNative($op,336));$this->assertSame('partial',$partial['status']);$this->assertSame(1,GoodsReceipt::count());$this->assertSame($before+1,StockLedger::count());
        $this->assertSame(0,\App\Models\Tenant\PurchasingDocumentOutbox::count());$event=FinancialOriginOutbox::sole()->payload;
        $this->assertSame('expense',$event['source_document_type']);$this->assertSame('financial-origin.receipt.confirmed',$event['event_type']);$this->assertSame('7.00000000',$event['physical']['lines'][0]['unit_cost']);
        $native=StockLedger::query()->where('source_type',GoodsReceipt::class)->where('source_id',GoodsReceipt::sole()->id)->sole();
        $this->assertSame($native->id,$event['physical']['lines'][0]['stock_ledger_id']);
        $this->assertSame((string)$native->total_cost,$event['physical']['lines'][0]['stock_value_base']);
        $this->assertSame(\App\Services\Stock\Support\Decimal::MONEY_SCALE,$event['physical']['lines'][0]['stock_money_scale']);
        $this->assertSame('22.0000',StockBalance::sole()->on_hand_qty);
    }
    public function test_finance_only_or_unassigned_actor_cannot_move_typed_stock_and_prepared_correction_requires_abandon_ack():void
    {
        [,,$op]=$this->admitted();$service=app(OriginDispatchService::class);$before=StockLedger::count();$this->actor(335,false,false);
        try{$service->executeNative($op,335);$this->fail('Finance-only actor moved stock');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->actor(337);try{$service->executeNative($op,337);$this->fail('Unassigned actor moved stock');}catch(\Illuminate\Auth\Access\AuthorizationException $e){$this->assertNotEmpty($e->getMessage());}
        $this->actor(336);$service->prepareNative($op,336);$changed=$op;$changed['lines'][0]['quantity']='1';
        try{$service->executeNative($changed,336);$this->fail('Prepared command silently changed');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame('abandoned',$service->abandonNative($op,336)['status']);
        try{$service->executeNative($op,336);$this->fail('Abandoned command moved stock');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame($before,StockLedger::count());$this->assertSame(0,Shipment::count());$this->assertSame(0,FinancialOriginOutbox::count());
        $this->assertSame(1,FinancialOriginCommand::count());
    }

    /** Explicit Finance closure projection seam; Stock GRN, inverse ledger and immutable events are native. */
    private function reversedExpenseFixture():GoodsReceipt
    {
        $this->useTenantA();$db=DB::connection('tenant');$schema=$db->getSchemaBuilder();
        if(!$schema->hasTable('expenses'))$schema->create('expenses',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('customer_id')->nullable();$t->unsignedBigInteger('vendor_id')->nullable();});
        if(!$schema->hasTable('finance_document_requests'))$schema->create('finance_document_requests',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->char('source_revision',64);$t->string('command');$t->json('payload');});
        $add=function($table,$name,$callback)use($schema){if(!$schema->hasColumn($table,$name))$schema->table($table,fn($t)=>$callback($t,$name));};
        $add('expenses','status',fn($t,$n)=>$t->string($n)->nullable());
        $add('finance_document_requests','state',fn($t,$n)=>$t->string($n)->nullable());
        foreach(['source_key']as$n)$add('journal_entries',$n,fn($t,$n)=>$t->string($n)->nullable());
        foreach(['posted_at','voided_at','deleted_at']as$n)$add('journal_entries',$n,fn($t,$n)=>$t->timestamp($n)->nullable());
        foreach(['voided_by','reverses_entry_id']as$n)$add('journal_entries',$n,fn($t,$n)=>$t->unsignedBigInteger($n)->nullable());
        if(!$schema->hasTable('users'))$schema->create('users',function($t){$t->id();$t->unsignedBigInteger('central_user_id');});
        if(!$schema->hasTable('action_logs'))$schema->create('action_logs',function($t){$t->id();$t->string('controller');$t->string('method');$t->unsignedBigInteger('user_id');$t->json('data');});
        if(!$schema->hasTable('finance_document_positions'))$schema->create('finance_document_positions',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('request_uuid');$t->uuid('position_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->unsignedBigInteger('source_document_line_id');});
        if(!$schema->hasTable('finance_document_matches'))$schema->create('finance_document_matches',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('request_uuid');$t->uuid('operation_uuid');$t->uuid('position_uuid');$t->uuid('reversal_operation_uuid');$t->unsignedBigInteger('source_document_line_id');$t->unsignedBigInteger('journal_entry_id');$t->unsignedBigInteger('reversal_journal_id');$t->string('state');$t->string('reverse_state');$t->string('closure_state');$t->json('closure_snapshot');});
        $this->tenantTestManager->cleanup();
        [,,$op]=$this->admitted('expense');
        // Tenant re-entry replaces the PDO; never write closure facts through the retired DDL connection.
        $db=DB::connection('tenant');
        $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;
        $meta['transport_enabled_workflows']=array_merge($meta['transport_enabled_workflows'],['grn.posted','grn.reversed']);$setting->meta=$meta;$setting->save();
        app(OriginDispatchService::class)->executeNative($op,336);$this->actor(323,true);
        $r=FinancialOriginRequest::sole();$r->update(['status'=>'cancelled']);
        $db->table('finance_document_requests')->where('request_uuid',$r->request_uuid)->update(['command'=>'cancel','state'=>'cancelled']);
        $db->table('expenses')->where('id',850)->update(['status'=>'draft']);
        $at='2026-10-07 12:00:00';$db->table('journal_entries')->where('id',95)->update(['status'=>'voided','voided_at'=>$at,'voided_by'=>701]);
        $db->table('users')->insert(['id'=>701,'central_user_id'=>323]);
        $db->table('action_logs')->insert(['id'=>701,'controller'=>'ReversalEngine','method'=>'unpostExpense','user_id'=>701,'data'=>json_encode(['document_type'=>'App\\Models\\Expense','document_id'=>850,'voided_journal_entry_id'=>95])]);
        $position=(string)Str::uuid();$match=(string)Str::uuid();$reverse=(string)Str::uuid();
        $db->table('finance_document_positions')->insert(['organization_id'=>14,'request_uuid'=>$r->request_uuid,'position_uuid'=>$position,'side'=>'purchase','source_document_type'=>'expense','source_document_id'=>850,'source_journal_id'=>95,'source_document_line_id'=>851]);
        foreach([96,97]as$id)$db->table('journal_entries')->insert(['id'=>$id,'organization_id'=>14,'source'=>'FINANCIAL-ORIGIN','source_type'=>'App\\Models\\Expense','source_id'=>850,'source_key'=>($id===96?'financial-origin-match:':'financial-origin-match-reversal:').$match,'status'=>'posted','posted_at'=>$at,'reverses_entry_id'=>$id===97?96:null]);
        $snapshot=['phase'=>'expense_unposted_after_value_ack','request_uuid'=>$r->request_uuid,'source_revision'=>$r->source_revision,'reversal_operation_uuid'=>$reverse,
            'original_source_journal_id'=>95,'original_match_journal_id'=>96,'match_inverse_journal_id'=>97,'unpost_audit_id'=>701,'unpost_actor_id'=>701,
            'unpost_central_actor_id'=>323,'unpost_audit_controller'=>'ReversalEngine','unpost_audit_method'=>'unpostExpense','source_document_type'=>'App\\Models\\Expense',
            'source_document_id'=>850,'source_status'=>'draft','source_voided_by'=>701,'source_voided_at'=>$at];
        $db->table('finance_document_matches')->insert(['organization_id'=>14,'request_uuid'=>$r->request_uuid,'operation_uuid'=>$match,'position_uuid'=>$position,
            'reversal_operation_uuid'=>$reverse,'source_document_line_id'=>851,'journal_entry_id'=>96,'reversal_journal_id'=>97,'state'=>'reversed','reverse_state'=>'committed','closure_state'=>'completed','closure_snapshot'=>json_encode($snapshot)]);
        $this->mock(\App\Services\Integration\SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeOriginPhysicalReversal')->andReturnUsing(fn($facts)=>$facts+[
            'allowed'=>true,'actor_id'=>0,'authority_kind'=>'posted_financial_origin_physical_reversal','finance_organization_id'=>14,'central_organization_id'=>$this->mapping->central_organization_id,
            'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'matches'=>[['operation_uuid'=>$match,'position_uuid'=>$position,'closure_state'=>'completed',
                'closure_snapshot_hash'=>\App\Services\Integration\SolaStockJournalContract::payloadHash($snapshot)]]]);
        $grn=GoodsReceipt::sole();
        // This case runs alone in a fresh disposable schema: commit the native fixture baseline
        // so the actual remote-proof phase is genuinely outside every SQL transaction.
        while($db->transactionLevel()>0)$db->commit();
        return $grn;
    }

    public function test_typed_expense_native_inverse_and_repeat_preserve_gross_history_and_emit_no_bill():void
    {
        $grn=$this->reversedExpenseFixture();$before=StockLedger::count();$service=app(\App\Services\Documents\InventoryReversalService::class);
        $inverse=$service->reverseGoodsReceipt($grn,'QA exact typed audited reversal');
        $this->assertSame($inverse->id,$service->reverseGoodsReceipt($grn->fresh(),'QA repeat')->id);
        $this->assertSame($before+1,StockLedger::count());$this->assertSame('20.0000',StockBalance::sole()->on_hand_qty);
        $this->assertSame('cancelled',FinancialOriginRequest::sole()->status);$this->assertSame('2.0000',FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity);
        $this->assertSame(2,FinancialOriginOutbox::count());$this->assertSame(0,\App\Models\Tenant\PurchasingDocumentOutbox::count());
        $event=FinancialOriginOutbox::query()->where('event_type','financial-origin.receipt.reversed')->sole()->payload;
        $this->assertSame(850,$event['source_document_id']);$this->assertSame(95,$event['source_journal_id']);$this->assertSame($inverse->id,$event['reversal']['id']);
        $this->assertNotEmpty($event['original_payload_hash']);$this->assertNotEmpty($event['reversal']['journal_key']);
        $original=FinancialOriginOutbox::query()->where('event_type','financial-origin.receipt.confirmed')->sole()->payload;
        $this->assertSame($original['physical']['lines'][0]['stock_ledger_id'],$event['physical']['lines'][0]['stock_ledger_id']);
        $this->assertSame($original['physical']['lines'][0]['stock_value_base'],$event['physical']['lines'][0]['stock_value_base']);
    }

    public function test_typed_expense_inverse_rejects_uncertain_cost_ack_and_foreign_audit_without_movement():void
    {
        $grn=$this->reversedExpenseFixture();$db=DB::connection('tenant');$before=StockLedger::count();
        $db->table('finance_document_matches')->update(['reverse_state'=>'pending']);
        try{app(\App\Services\Documents\InventoryReversalService::class)->reverseGoodsReceipt($grn,'QA pending');$this->fail('Uncertain valuation allowed reversal');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $db->table('finance_document_matches')->update(['reverse_state'=>'committed']);
        $db->table('action_logs')->where('id',701)->update(['data'=>json_encode(['document_type'=>'App\\Models\\Expense','document_id'=>999,'voided_journal_entry_id'=>95])]);
        try{app(\App\Services\Documents\InventoryReversalService::class)->reverseGoodsReceipt($grn,'QA foreign audit');$this->fail('Foreign audit allowed reversal');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame($before,StockLedger::count());$this->assertNull($grn->fresh()->reversal_id);$this->assertSame(1,FinancialOriginOutbox::count());
    }

    /** Native Shipment/SalesReturn/ledger; persisted Finance match and remote proof are explicit projections. */
    public function test_cash_origin_source_return_preserves_gross_demand_and_native_replay():void
    {
        [,,$op]=$this->admitted('sales_receipt', 'none', true);
        app(OriginDispatchService::class)->executeNative($op,336);$this->actor(323,true);
        $db=DB::connection('tenant');$schema=$db->getSchemaBuilder();
        $add=function($table,$name,$callback)use($schema){if(!$schema->hasColumn($table,$name))$schema->table($table,fn($t)=>$callback($t,$name));};
        $add('sales_receipts','status',fn($t,$n)=>$t->string($n)->nullable());
        foreach(['source_key'] as $name)$add('journal_entries',$name,fn($t,$n)=>$t->string($n)->nullable());
        foreach(['posted_at','voided_at','deleted_at'] as $name)$add('journal_entries',$name,fn($t,$n)=>$t->timestamp($n)->nullable());
        $add('journal_entries','reverses_entry_id',fn($t,$n)=>$t->unsignedBigInteger($n)->nullable());
        if(!$schema->hasTable('finance_document_positions'))$schema->create('finance_document_positions',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('request_uuid');$t->uuid('position_uuid');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->unsignedBigInteger('source_document_line_id');});
        if(!$schema->hasTable('finance_document_matches'))$schema->create('finance_document_matches',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('request_uuid');$t->uuid('operation_uuid');$t->uuid('position_uuid');$t->uuid('physical_mapping_uuid');$t->unsignedBigInteger('physical_document_id');$t->unsignedBigInteger('physical_line_id');$t->unsignedBigInteger('source_document_line_id');$t->unsignedBigInteger('physical_journal_id');$t->unsignedBigInteger('journal_entry_id')->nullable();$t->unsignedBigInteger('reversal_journal_id')->nullable();$t->string('state');$t->string('reverse_state')->nullable();$t->decimal('quantity',24,8);$t->decimal('booked_base',24,6);$t->json('snapshot');});
        if(!$schema->hasTable('finance_document_physical_events'))$schema->create('finance_document_physical_events',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('request_uuid');$t->string('event_type');$t->uuid('physical_mapping_uuid');$t->unsignedBigInteger('physical_document_id');$t->string('state');$t->char('source_hash',64);$t->json('payload');});
        $shipment=Shipment::sole();$r=FinancialOriginRequest::sole();$event=FinancialOriginOutbox::sole();$physical=$event->payload['physical'];
        $position=(string)Str::uuid();$match=(string)Str::uuid();$snapshot=['physical'=>$physical];$at='2026-10-07 12:00:00';
        $db->table('sales_receipts')->where('id',850)->update(['status'=>'posted']);$db->table('journal_entries')->where('id',95)->update(['posted_at'=>$at]);
        $db->table('finance_document_positions')->insert(['organization_id'=>14,'request_uuid'=>$r->request_uuid,'position_uuid'=>$position,'source_document_type'=>'sales_receipt','source_document_id'=>850,'source_journal_id'=>95,'source_document_line_id'=>851]);
        $db->table('journal_entries')->insert(['id'=>96,'organization_id'=>14,'source'=>'SOLASTOCK','source_type'=>'shipment','source_id'=>$shipment->id,'source_key'=>'external-api:'.hash('sha256',$physical['journal_key']),'status'=>'posted','posted_at'=>$at]);
        $db->table('journal_entries')->insert(['id'=>97,'organization_id'=>14,'source'=>'FINANCIAL-ORIGIN','source_type'=>'App\\Models\\SalesReceipt','source_id'=>850,'source_key'=>'financial-origin-match:'.$match,'status'=>'posted','posted_at'=>$at]);
        $db->table('finance_document_matches')->insert(['organization_id'=>14,'request_uuid'=>$r->request_uuid,'operation_uuid'=>$match,'position_uuid'=>$position,'physical_mapping_uuid'=>$physical['mapping_uuid'],'physical_document_id'=>$shipment->id,'physical_line_id'=>$physical['lines'][0]['physical_line_id'],'source_document_line_id'=>851,'physical_journal_id'=>96,'journal_entry_id'=>97,'state'=>'settled','quantity'=>'2','booked_base'=>'14','snapshot'=>json_encode($snapshot)]);
        $db->table('finance_document_physical_events')->insert(['organization_id'=>14,'request_uuid'=>$r->request_uuid,'event_type'=>'financial-origin.shipment.confirmed','physical_mapping_uuid'=>$physical['mapping_uuid'],'physical_document_id'=>$shipment->id,'state'=>'reviewed','source_hash'=>$event->payload_hash,'payload'=>json_encode($event->payload)]);
        $this->mock(\App\Services\Integration\SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeOriginPhysicalReversal')->andReturnUsing(fn($facts)=>$facts+[
            'allowed'=>true,'actor_id'=>0,'authority_kind'=>'posted_financial_origin_physical_reversal','physical_policy'=>'cash_receipt_active_source_only','cash_refund_authorized'=>false,'source_closure_authorized'=>false,
            'finance_organization_id'=>14,'central_organization_id'=>$this->mapping->central_organization_id,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,
            'matches'=>[['operation_uuid'=>$match,'position_uuid'=>$position,'physical_line_id'=>$physical['lines'][0]['physical_line_id'],'match_state'=>$db->table('finance_document_matches')->where('operation_uuid',$match)->value('state'),'recognition_reversal_journal_id'=>($id=$db->table('finance_document_matches')->where('operation_uuid',$match)->value('reversal_journal_id'))?(int)$id:null,'recognition_journal_id'=>97,'quantity'=>'2.00000000','booked_base'=>'14.000000','match_snapshot_hash'=>\App\Services\Integration\SolaStockJournalContract::payloadHash($snapshot)]]]);
        $service=app(\App\Services\Documents\SalesReturnService::class);$return=$service->createDraft(['shipment_id'=>$shipment->id,'reason'=>'Exact cash source inverse','return_date'=>'2026-10-07'],[]);
        while($db->transactionLevel()>0)$db->commit();$before=StockLedger::count();
        // A remote allowed response cannot replace the exact active native recognition fact.
        $db->table('journal_entries')->where('id',97)->update(['status'=>'voided','voided_at'=>$at]);
        try {$service->post($return);$this->fail('Voided native recognition was accepted');}
        catch(HttpException $error){$this->assertSame(409,$error->getStatusCode());}
        $this->assertSame($before,StockLedger::count());$this->assertSame(1,FinancialOriginOutbox::count());
        $db->table('journal_entries')->where('id',97)->update(['status'=>'posted','voided_at'=>null]);
        $result=$service->post($return);
        // Explicit Finance inverse projection: native Stock replay must still succeed after accounting reconciliation.
        $db->table('journal_entries')->insert(['id'=>98,'organization_id'=>14,'source'=>'FINANCIAL-ORIGIN','source_type'=>'App\\Models\\SalesReceipt','source_id'=>850,'source_key'=>'financial-origin-match-reversal:'.$match,'status'=>'posted','posted_at'=>$at,'reverses_entry_id'=>97]);
        $db->table('finance_document_matches')->where('operation_uuid',$match)->update(['state'=>'reversed','reverse_state'=>'committed','reversal_journal_id'=>98]);
        $this->assertSame($result->id,$service->post($result->fresh())->id);
        $this->assertSame($before+1,StockLedger::count());$this->assertSame('20.0000',StockBalance::sole()->on_hand_qty);
        $this->assertSame('2.0000',FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity);
        $this->assertSame(2,FinancialOriginOutbox::count());$this->assertSame(0,SalesDocumentOutbox::count());
        $this->assertSame(850,FinancialOriginOutbox::where('event_type','financial-origin.shipment.reversed')->sole()->payload['source_document_id']);
    }
    public function test_cash_partial_contract_rejects_uncommanded_shipment_without_creating_invoice_or_movement():void
    {
        [$data,$context,$op]=$this->admitted();
        $request=FinancialOriginRequest::sole();
        $shipment=new Shipment(['organization_id'=>$request->organization_id,'sales_order_id'=>$request->sales_order_id,'warehouse_id'=>$this->warehouse->id,'status'=>'draft']);
        $before=StockLedger::count();
        try {
            DB::connection('tenant')->transaction(fn()=>app(\App\Services\FinancialOrigins\OriginPhysicalService::class)->lockAndValidateDocument($shipment));
            $this->fail('Cash source dispatched without its durable typed operation');
        }catch(\Illuminate\Validation\ValidationException $error){$this->assertArrayHasKey('sales_order_id',$error->errors());}
        $this->assertSame($before,StockLedger::count());$this->assertSame(0,Shipment::count());$this->assertSame(0,FinancialOriginCommand::count());
    }
    public function test_cash_partial_contract_prepared_hold_fences_new_dispatch_until_released():void
    {
        [$data,$context,$op]=$this->admitted();
        (require base_path('database/migrations/tenant/2026_10_08_219000_create_cash_refund_demand_holds.php'))->up();
        $request=FinancialOriginRequest::sole();$db=DB::connection('tenant');$before=StockLedger::count();
        $db->table('stock_cash_refund_demands')->insert(['organization_id'=>$request->organization_id,'organization_mapping_uuid'=>$request->organization_mapping_uuid,'request_id'=>$request->id,'request_uuid'=>$request->request_uuid,'source_document_id'=>$request->source_document_id,'source_journal_id'=>$request->source_journal_id,'source_revision'=>$request->source_revision,'refund_receipt_id'=>1991,'operation_uuid'=>(string)Str::uuid(),'actor_id'=>323,'payload_hash'=>str_repeat('b',64),'hold_fingerprint'=>str_repeat('c',64),'payload'=>'{}','state'=>'prepared']);
        try{$db->transaction(fn()=>app(\App\Services\FinancialOrigins\CashRefundDemandService::class)->assertDispatchUnlocked($request));$this->fail('Prepared refund did not fence dispatch');}
        catch(\Illuminate\Validation\ValidationException $error){$this->assertArrayHasKey('workflow',$error->errors());}
        $db->table('stock_cash_refund_demands')->update(['state'=>'abandoned']);
        $db->transaction(fn()=>app(\App\Services\FinancialOrigins\CashRefundDemandService::class)->assertDispatchUnlocked($request));
        $this->assertSame($before,StockLedger::count());$this->assertSame(0,Shipment::count());
    }
    /** Native Stock demand operations; Finance authorization/payout facts are explicit remote projections, not financial posting proof. */
    public function test_cash_partial_contract_prepare_commit_replay_and_void_restore_exact_demand():void
    {
        [$data,$context,$op]=$this->admitted();$db=DB::connection('tenant');$schema=$db->getSchemaBuilder();
        (require base_path('database/migrations/tenant/2026_10_08_219000_create_cash_refund_demand_holds.php'))->up();
        if(!$schema->hasTable('finance_cash_refund_demands'))$schema->create('finance_cash_refund_demands',function(\Illuminate\Database\Schema\Blueprint $t){$t->id();foreach(['organization_id','actor_id','source_document_id','source_journal_id','refund_receipt_id']as$f)$t->unsignedBigInteger($f);$t->uuid('organization_mapping_uuid');$t->uuid('operation_uuid');$t->uuid('request_uuid');$t->char('source_revision',64);$t->char('payload_hash',64);$t->json('payload');$t->string('state');$t->unsignedBigInteger('refund_journal_id')->nullable();$t->unsignedBigInteger('reverse_actor_id')->nullable();});
        $r=FinancialOriginRequest::sole();$uuid=(string)Str::uuid();$payload=['request_uuid'=>$r->request_uuid,'source_revision'=>$r->source_revision,'source_document_id'=>850,'source_journal_id'=>95,'refund_receipt_id'=>991,'operation_uuid'=>$uuid,'lines'=>[['source_document_line_id'=>851,'unfulfilled_quantity'=>'1']],'financial_plan_hash'=>str_repeat('d',64)];
        $db->table('finance_cash_refund_demands')->insert(['organization_id'=>$this->mapping->finance_organization_id,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'operation_uuid'=>$uuid,'request_uuid'=>$r->request_uuid,'source_revision'=>$r->source_revision,'source_document_id'=>850,'source_journal_id'=>95,'refund_receipt_id'=>991,'actor_id'=>323,'payload_hash'=>\App\Services\Integration\SolaStockJournalContract::payloadHash($payload),'payload'=>json_encode($payload),'state'=>'preparing']);
        $fingerprint=null;$phase='prepare';
        $this->mock(\App\Services\Integration\SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeOrigin')->andReturnUsing(function()use($data,$payload,&$phase,&$fingerprint){return['allowed'=>true,'actor_id'=>323,'source_document_type'=>'sales_receipt','source_document_id'=>850,'source_journal_id'=>95,'request_uuid'=>$data['request_uuid'],'request_revision'=>$data['source_revision'],'canonical_payload'=>$data,'command'=>'upsert','command_source_revision'=>$data['source_revision'],'expected_revision'=>$data['source_revision'],'cash_demand'=>$payload+['purpose'=>$phase,'hold_fingerprint'=>$fingerprint]];});
        $input=$context+['source_revision'=>$r->source_revision,'operation_uuid'=>$uuid,'refund_receipt_id'=>991,'purpose'=>'prepare'];unset($input['request_revision']);
        $service=app(\App\Services\FinancialOrigins\CashRefundDemandService::class);$before=StockLedger::count();
        $held=$service->dispatch($input,323);$again=$service->dispatch($input,323);$this->assertSame($held,$again);$fingerprint=$held['hold_fingerprint'];
        $this->assertSame('0.0000',(string)$r->lines()->sole()->cancelled_quantity);$this->assertSame($before,StockLedger::count());
        // A native Stock component may consume only a committed, source-bound remote Finance payout projection.
        if(!$schema->hasTable('refund_receipts'))$schema->create('refund_receipts',function(\Illuminate\Database\Schema\Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->string('status');$t->unsignedBigInteger('journal_entry_id');});
        $db->table('journal_entries')->insert(['id'=>96,'organization_id'=>$this->mapping->finance_organization_id,'source'=>'RF','source_type'=>'App\\Models\\RefundReceipt','source_id'=>991,'status'=>'posted']);
        $projection=['id'=>991,'organization_id'=>$this->mapping->finance_organization_id,'status'=>'posted','journal_entry_id'=>96,'number'=>'QA-REMOTE-RF-991','date'=>'2026-10-07','amount'=>'7'];
        $db->table('refund_receipts')->insert(array_filter($projection,fn($key)=>$schema->hasColumn('refund_receipts',$key),ARRAY_FILTER_USE_KEY));
        if(!$schema->hasColumn('journal_entries','voided_at'))$schema->table('journal_entries',fn(\Illuminate\Database\Schema\Blueprint$t)=>$t->timestamp('voided_at')->nullable());
        $db->table('finance_cash_refund_demands')->where('operation_uuid',$uuid)->update(['state'=>'commit_pending','refund_journal_id'=>96]);$phase='commit';$input['purpose']=$phase;$input['hold_fingerprint']=$fingerprint;
        $committed=$service->dispatch($input,323);$this->assertSame('committed',$committed['state']);$this->assertSame($committed,$service->dispatch($input,323));$this->assertSame('1.0000',(string)$r->lines()->sole()->cancelled_quantity);$this->assertSame($before,StockLedger::count());$this->assertSame(0,Shipment::count());
        $db->table('refund_receipts')->where('id',991)->update(['status'=>'void']);$db->table('journal_entries')->where('id',96)->update(['status'=>'voided','voided_at'=>now()]);
        $db->table('finance_cash_refund_demands')->where('operation_uuid',$uuid)->update(['state'=>'reverse_pending','reverse_actor_id'=>323]);$phase='reverse';$input['purpose']=$phase;
        $reversed=$service->dispatch($input,323);$this->assertSame('reversed',$reversed['state']);$this->assertSame($reversed,$service->dispatch($input,323));$this->assertSame('0.0000',(string)$r->lines()->sole()->cancelled_quantity);$this->assertSame($before,StockLedger::count());$this->assertSame(0,Shipment::count());
    }
}
