<?php
namespace Tests\Unit\Returns;
use PHPUnit\Framework\TestCase;

/** Source contract checks only: no database or signed transport qualification. */
final class SupplierReturnContractParityTest extends TestCase
{
    public function test_native_provenance_and_reversal_use_the_existing_purchasing_channel(): void
    {
        $root = dirname(__DIR__, 3);
        $source = file_get_contents($root.'/app/Services/Sales/SupplierReturnDocumentBuilder.php');
        foreach (['purchasing.v1', 'purchasing.return.confirmed', 'purchasing.return.reversed', 'source_receipt_line_id', 'source_stock_ledger_id', 'actual_out_base', 'receipt_mapping_uuid', 'receipt_event_uuid', 'journal_idempotency_key', 'original_event_uuid', 'PurchasingDocumentOutbox::create'] as $field) {
            self::assertStringContainsString($field, $source);
        }
        self::assertStringContainsString("'source_receipt_line_id'=>(string)\$source->id", $source);
        self::assertStringContainsString("'actual_out_base'=>(string)\$line->actual_return_cost_base", $source);
    }

    public function test_inverse_requires_existing_190_and_193_native_financial_proof(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Services/Returns/SupplierReturnFinancialReversalGuard.php');
        foreach (['finance_supplier_return_credit_allocations', 'finance_supplier_return_reversals', "\$a->state!=='voided'", "\$a->branch!=='matched_physical'", 'actual_out_base', 'voidDebitNote', 'debit-note-void', 'debit_allocations', 'supplier_refunds'] as $proof) {
            self::assertStringContainsString($proof, $source);
        }
        self::assertStringNotContainsString('203', $source);
    }

    public function test_connected_activation_stays_closed_before_native_qualification(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Services/Documents/SupplierReturnService.php');
        self::assertStringContainsString('reviewed_supplier_returns_v1', $source);
        self::assertStringContainsString('Supplier-return accounting must be configured before goods can be returned.', $source);
        self::assertStringContainsString('SupplierReturnDocumentBuilder::class)->record($return)', $source);
    }
}
