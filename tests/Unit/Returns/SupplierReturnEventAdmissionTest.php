<?php
namespace Tests\Unit\Returns;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 3).'/app/Services/Integration/IntegrationEvents.php';
require_once dirname(__DIR__, 3).'/app/Services/Integration/AccountRolePolicy.php';
use App\Services\Integration\{IntegrationEvents, AccountRolePolicy};
final class SupplierReturnEventAdmissionTest extends TestCase
{
    public function test_physical_events_use_return_clearing_without_recognizing_supplier_liability_or_tax(): void
    {
        foreach (['supplier_return.posted','supplier_return.reversed'] as $event) {
            self::assertTrue(IntegrationEvents::exists($event));
            self::assertTrue(IntegrationEvents::postsJournal($event));
            self::assertSame(['inventory_asset','supplier_return_clearing'], AccountRolePolicy::forOperations([$event]));
        }
        self::assertSame([['supplier_return_clearing','debit'],['inventory_asset','credit']], AccountRolePolicy::workflowTemplate('supplier_return.posted'));
        self::assertSame([['inventory_asset','debit'],['supplier_return_clearing','credit']], AccountRolePolicy::workflowTemplate('supplier_return.reversed'));
        self::assertSame(['suggested_debit_account_mapping'=>'supplier_return_clearing','suggested_credit_account_mapping'=>'inventory_asset'],IntegrationEvents::suggestedAccounts('supplier_return.posted'));
    }
}
