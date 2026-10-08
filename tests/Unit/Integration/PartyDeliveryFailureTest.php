<?php
namespace Tests\Unit\Integration;
use App\Services\Integration\PartyDeliveryFailure;
use PHPUnit\Framework\TestCase;
final class PartyDeliveryFailureTest extends TestCase
{
 public function test_transport_scope_and_stable_code_are_preserved_without_body():void {
  $f=new PartyDeliveryFailure(403,PartyDeliveryFailure::code('signature_scope_mismatch'),'supplier',12);
  self::assertSame(403,$f->httpStatus);self::assertSame('signature_scope_mismatch',$f->getMessage());
  self::assertSame('supplier',$f->entityType);self::assertSame(12,$f->sourceId);
 }
 public function test_unstructured_or_sensitive_error_strings_are_not_retained():void {
  foreach([null,[], 'secret=abc', 'Supplier Name',str_repeat('a',100)] as $value)
   self::assertSame('party_connection_pending',PartyDeliveryFailure::code($value));
 }
}
