<?php
namespace Tests\Unit\Consumption;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Services\Integration\AccountingJournalBuilder;
use PHPUnit\Framework\TestCase;
final class ConsumptionProjectAttributionTest extends TestCase {
 private function lines(string $eventType,?int $project):array {
  $event=new IntegrationOutboxEvent();$event->setRawAttributes(['event_type'=>$eventType,'aggregate_number'=>'IC-test','payload'=>json_encode(['lines'=>[['expense_account_id'=>141,'inventory_account_id'=>52,'total_cost'=>'60.00','project_id'=>$project]]])]);
  $m=new \ReflectionMethod(AccountingJournalBuilder::class,'consumption');$m->setAccessible(true);return $m->invoke(new AccountingJournalBuilder(),$event);
 }
 public function test_source_project_is_preserved_on_issue_and_return_with_original_cost():void {
  $issue=$this->lines('internal_consumption.posted',7);$return=$this->lines('internal_consumption.returned',7);
  self::assertSame([7,7],array_column($issue,'project_id'));self::assertSame([7,7],array_column($return,'project_id'));
  self::assertSame('60.00',$issue[0]['debit']);self::assertSame('60.00',$return[0]['credit']);self::assertSame('60.00',$return[1]['debit']);
 }
 public function test_unassigned_cost_is_not_arbitrarily_attributed():void {foreach($this->lines('internal_consumption.posted',null) as $line)self::assertArrayNotHasKey('project_id',$line);}
}
