<?php
namespace Tests\Unit\Integration;
use App\Services\Integration\SyncIncidentFacts;
use PHPUnit\Framework\TestCase;
final class SyncIncidentFactsTest extends TestCase {
 private function party(array $change=[]):object {return (object)array_replace(['id'=>12,'entity_type'=>'supplier','source_app'=>'finance','source_id'=>901,'source_fields'=>json_encode(['name'=>'QA supplier','contact'=>['email'=>'secret@example.com']]),'status'=>'intervention','last_error'=>'party_identity_review_required','attempts'=>3,'state_version'=>2,'updated_at'=>'now'],$change);}
 private function catalog(array $change=[]):object {return (object)array_replace(['id'=>44,'entity_type'=>'item','source_id'=>55,'source_snapshot'=>json_encode(['entity_type'=>'item','source_id'=>55,'name'=>"Steel\x07 <b>bolt</b>",'sku'=>'SKU-1']),'state'=>'intervention_required','last_error'=>'catalog_field_conflict','attempts'=>4,'state_version'=>3],$change);}
 public function test_retries_and_versions_do_not_change_the_incident_fingerprint():void {
  $one=SyncIncidentFacts::fromRow('party',$this->party(),'mapping-one',1);$two=SyncIncidentFacts::fromRow('party',$this->party(['attempts'=>39,'state_version'=>9,'updated_at'=>'later']),'mapping-one',1);
  $this->assertSame(SyncIncidentFacts::fingerprint($one),SyncIncidentFacts::fingerprint($two));
  $this->assertSame(['version'=>'stock-sync-incident.v1','organization_mapping_uuid'=>'mapping-one','document_kind'=>'party','outbox_id'=>12,'entity_type'=>'supplier','source_app'=>'finance','source_id'=>901,'display_name'=>'QA supplier','state'=>'intervention','reason'=>'party_identity_review_required','episode'=>1],$one);
 }
 public function test_reason_state_mapping_and_episode_are_distinct_transitions():void {
  $base=SyncIncidentFacts::fingerprint(SyncIncidentFacts::fromRow('party',$this->party(),'m',1));
  $this->assertNotSame($base,SyncIncidentFacts::fingerprint(SyncIncidentFacts::fromRow('party',$this->party(['last_error'=>'party_source_unavailable']),'m',1)));
  $this->assertNotSame($base,SyncIncidentFacts::fingerprint(SyncIncidentFacts::fromRow('party',$this->party(),'m',2)));
  $this->assertNotSame($base,SyncIncidentFacts::fingerprint(SyncIncidentFacts::fromRow('party',$this->party(),'other-mapping',1)));
  $resolved=SyncIncidentFacts::fromRow('party',$this->party(['status'=>'synced','last_error'=>null]),'m',1);$this->assertSame('resolved',$resolved['state']);$this->assertNull($resolved['reason']);
 }
 public function test_only_terminal_or_needs_intervention_states_are_notifiable():void {
  $this->assertSame('intervention',SyncIncidentFacts::status('party',$this->party(['status'=>'held','last_error'=>'party_approval_required'])));
  $this->assertSame('pending',SyncIncidentFacts::status('party',$this->party(['status'=>'held','last_error'=>'party_connection_pending'])));
  $this->assertSame('pending',SyncIncidentFacts::status('party',$this->party(['status'=>'pending'])));
  $this->assertSame('intervention',SyncIncidentFacts::status('item',$this->catalog()));
  $this->assertSame('resolved',SyncIncidentFacts::status('item',$this->catalog(['state'=>'delivered'])));
  foreach(['pending','retrying','unknown_outcome','delivering'] as $state)$this->assertSame('pending',SyncIncidentFacts::status('item',$this->catalog(['state'=>$state])));
 }
 public function test_catalog_facts_are_sanitized_and_unknown_codes_are_closed():void {
  $facts=SyncIncidentFacts::fromRow('item',$this->catalog(),'m',1);
  $this->assertSame('Steel bolt',$facts['display_name']);$this->assertSame('item',$facts['entity_type']);$this->assertSame('stock',$facts['source_app']);$this->assertSame('catalog_field_conflict',$facts['reason']);
  $this->assertArrayNotHasKey('sku',$facts);$this->assertStringNotContainsString('secret',json_encode(SyncIncidentFacts::fromRow('party',$this->party(),'m',1)));
  $this->assertSame('retry_exhausted',SyncIncidentFacts::fromRow('item',$this->catalog(['last_error'=>'catalog_projection_delivery_failed']),'m',1)['reason']);
  $this->assertSame('sync_review_required',SyncIncidentFacts::fromRow('item',$this->catalog(['last_error'=>'raw secret token']),'m',1)['reason']);
  $this->assertSame('party_retry_exhausted',SyncIncidentFacts::fromRow('party',$this->party(['last_error'=>'raw secret']),'m',1)['reason']);
  $this->assertSame(80,mb_strlen(SyncIncidentFacts::fromRow('item',$this->catalog(['source_snapshot'=>json_encode(['name'=>str_repeat('ب',120)])]),'m',1)['display_name']));
 }
 public function test_kind_must_match_the_state_row():void {
  $this->expectException(\InvalidArgumentException::class);SyncIncidentFacts::fromRow('unit',$this->catalog(),'m',1);
 }
}
