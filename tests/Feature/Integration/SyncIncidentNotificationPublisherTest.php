<?php
namespace Tests\Feature\Integration;
use App\Models\Tenant\{IntegrationOrganizationMapping,IntegrationSetting,Supplier};
use App\Services\Integration\{ContinuousPartySync,SyncIncidentNotificationPublisher};
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;
/** Party/catalog sync incidents use the durable document-incident outbox: one row per transition, episodes, no sync side effects. */
final class SyncIncidentNotificationPublisherTest extends TestCase {
 use TenantAware;
 private $mapping;
 protected function setUp():void {
  parent::setUp();$this->useTenantA();
  foreach(['integration_document_notification_outbox','integration_party_sync_states','integration_catalog_sync_states'] as $table)$this->assertTrue(Schema::connection('tenant')->hasTable($table),$table.' must exist in the reserved tenant test database.');
  DB::connection('tenant')->table('organizations')->insert(['id'=>14,'central_org_id'=>TenantTestManager::ORG_A,'setup_status'=>'complete']);
  $this->mapping=IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,'tenant_database_identity'=>DB::connection('tenant')->getDatabaseName(),'finance_organization_id'=>14,'solastock_organization_id'=>TenantTestManager::ORG_A,'contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','base_currency_code'=>'JOD','verified_at'=>now()]);
  IntegrationSetting::create(['integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>14]);
 }
 private function source(int $id=901,string $name='New canonical supplier'):void {
  DB::connection('tenant')->table('suppliers')->insert(['id'=>$id,'organization_id'=>14,'name'=>$name,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
 }
 private function ensure(int $id=901):array {return app(ContinuousPartySync::class)->materialize($this->mapping,'finance','supplier',$id);}
 private function publisher():SyncIncidentNotificationPublisher {return app(SyncIncidentNotificationPublisher::class);}
 private function outbox(?string $kind=null):\Illuminate\Support\Collection {
  return DB::connection('tenant')->table('integration_document_notification_outbox')->when($kind,fn($q)=>$q->where('document_kind',$kind))->orderBy('id')->get()
   ->map(function($row){$row->facts=json_decode($row->facts,true);return $row;});
 }
 private function partyState():object {return DB::connection('tenant')->table('integration_party_sync_states')->where('source_app','finance')->where('source_id',901)->sole();}
 private function catalogState(string $type,int $sourceId,string $state,?string $error,string $name='Steel bolt'):int {
  return (int)DB::connection('tenant')->table('integration_catalog_sync_states')->insertGetId(['organization_id'=>TenantTestManager::ORG_A,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,
   'entity_type'=>$type,'source_id'=>$sourceId,'source_uuid'=>(string)Str::uuid(),'actor_id'=>990001,'source_revision'=>str_repeat('a',64),
   'source_snapshot'=>json_encode(['entity_type'=>$type,'source_id'=>$sourceId,'name'=>$name]),'state'=>$state,'attempts'=>4,'last_error'=>$error,'created_at'=>now(),'updated_at'=>now()]);
 }

 public function test_party_intervention_is_queued_once_and_resolution_closes_the_episode():void {
  $this->source();Supplier::create(['code'=>'LOCAL','name'=>'New canonical supplier','is_active'=>true]);
  $this->assertSame('intervention',$this->ensure()['status']);$state=$this->partyState();
  $this->publisher()->queue('party',(int)$state->id,$this->mapping->mapping_uuid);
  // Replays and further attempts are the same transition (attempts are not part of the identity).
  $this->ensure();$this->publisher()->queue('party',(int)$state->id,$this->mapping->mapping_uuid);$this->publisher()->reconcile($this->mapping);
  $rows=$this->outbox('party');$this->assertCount(1,$rows);$facts=$rows[0]->facts;
  $this->assertSame(['stock-sync-incident.v1','party','supplier','finance',901,'New canonical supplier','intervention','party_identity_review_required',1,(int)$state->id],
   [$facts['version'],$facts['document_kind'],$facts['entity_type'],$facts['source_app'],$facts['source_id'],$facts['display_name'],$facts['state'],$facts['reason'],$facts['episode'],$facts['outbox_id']]);
  $this->assertSame(TenantTestManager::ORG_A,(int)$rows[0]->organization_id);$this->assertSame($this->mapping->mapping_uuid,$rows[0]->organization_mapping_uuid);
  $this->assertSame((int)$state->id,(int)$rows[0]->document_outbox_id);$this->assertSame('pending',$rows[0]->state);$this->assertSame(hash('sha256',json_encode($facts)),$rows[0]->transition_fingerprint);
  $this->assertSame(1,Supplier::query()->count(),'Notification intent never changes master data.');

  // Resolution (for example an explicit identity review) is told once and closes the episode.
  DB::connection('tenant')->table('integration_party_sync_states')->where('id',$state->id)->update(['status'=>'synced','last_error'=>null]);
  $this->publisher()->queue('party',(int)$state->id,$this->mapping->mapping_uuid);$this->publisher()->reconcile($this->mapping);$this->publisher()->reconcile($this->mapping);
  $rows=$this->outbox('party');$this->assertCount(2,$rows);$this->assertSame('resolved',$rows[1]->facts['state']);$this->assertNull($rows[1]->facts['reason']);$this->assertSame(1,$rows[1]->facts['episode']);

  // A later failure is a new episode, so it is told again.
  DB::connection('tenant')->table('integration_party_sync_states')->where('id',$state->id)->update(['status'=>'intervention','last_error'=>'party_source_unavailable']);
  $this->publisher()->reconcile($this->mapping);$this->publisher()->queue('party',(int)$state->id,$this->mapping->mapping_uuid);
  $rows=$this->outbox('party');$this->assertCount(3,$rows);$this->assertSame(2,$rows[2]->facts['episode']);$this->assertSame('party_source_unavailable',$rows[2]->facts['reason']);
 }

 public function test_only_needs_intervention_party_states_are_incidents():void {
  $this->source();$this->assertSame('synced',$this->ensure()['status']);$state=$this->partyState();
  $this->publisher()->queue('party',(int)$state->id,$this->mapping->mapping_uuid);
  $this->assertCount(0,$this->outbox(),'A first-time success is not a resolution of anything.');
  DB::connection('tenant')->table('integration_party_sync_states')->where('id',$state->id)->update(['status'=>'held','last_error'=>'party_connection_pending']);
  $this->publisher()->queue('party',(int)$state->id,$this->mapping->mapping_uuid);$this->assertCount(0,$this->outbox(),'A paused connection retries on its own.');
  DB::connection('tenant')->table('integration_party_sync_states')->where('id',$state->id)->update(['status'=>'held','last_error'=>'party_approval_required']);
  $this->publisher()->queue('party',(int)$state->id,$this->mapping->mapping_uuid);
  $this->assertCount(1,$this->outbox());$this->assertSame('party_approval_required',$this->outbox()[0]->facts['reason']);
 }

 public function test_catalog_terminal_states_are_queued_per_kind_and_delivery_resolves_them():void {
  $item=$this->catalogState('item',55,'intervention_required','catalog_field_conflict',"Steel\n<b>bolt</b>");
  $unit=$this->catalogState('unit',56,'intervention_required','catalog_projection_delivery_failed','Box');
  $this->catalogState('category',57,'retrying','catalog_dependency_pending','Hardware');
  $this->publisher()->reconcile($this->mapping);$this->publisher()->reconcile($this->mapping);
  $this->assertSame(['item','unit'],$this->outbox()->pluck('document_kind')->all());
  $facts=$this->outbox('item')[0]->facts;$this->assertSame('catalog_field_conflict',$facts['reason']);$this->assertSame('Steel bolt',$facts['display_name']);$this->assertSame($item,$facts['outbox_id']);
  $this->assertSame('retry_exhausted',$this->outbox('unit')[0]->facts['reason']);

  DB::connection('tenant')->table('integration_catalog_sync_states')->where('id',$item)->update(['state'=>'delivered','last_error'=>null,'target_id'=>9]);
  $this->publisher()->reconcile($this->mapping);$this->publisher()->queue('item',$item,$this->mapping->mapping_uuid);
  $this->assertSame(['intervention','resolved'],$this->outbox('item')->pluck('facts.state')->all());
  $this->assertCount(1,$this->outbox('unit'));$this->assertCount(0,$this->outbox('category'));
  $this->assertSame('delivered',DB::connection('tenant')->table('integration_catalog_sync_states')->where('id',$item)->value('state'));
  $this->assertSame('intervention_required',DB::connection('tenant')->table('integration_catalog_sync_states')->where('id',$unit)->value('state'),'Publishing never changes sync state.');
 }

 public function test_mismatched_kind_and_inactive_connection_queue_nothing():void {
  $item=$this->catalogState('item',55,'intervention_required','catalog_identity_conflict');
  $this->publisher()->queue('unit',$item,$this->mapping->mapping_uuid);$this->publisher()->queue('party',$item,$this->mapping->mapping_uuid);
  $this->assertCount(0,$this->outbox());
  DB::connection('tenant')->table('integration_organization_mappings')->where('id',$this->mapping->id)->update(['activation_state'=>'paused']);
  $this->publisher()->queue('item',$item,$this->mapping->mapping_uuid);$this->assertCount(0,$this->outbox());
 }
}
