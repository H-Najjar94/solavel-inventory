<?php

namespace App\Services\FinancialOrigins;

use Illuminate\Support\Facades\Validator;

/** A financial origin identifies the real document; aliases never manufacture an Invoice or Bill. */
final readonly class FinancialOrigin
{
    private function __construct(public string $type, public int $documentId, public int $journalId, public string $number) {}

    public static function fromPayload(array $payload): self
    {
        Validator::make($payload, [
            'source_document_type' => 'required|in:invoice,sales_receipt,expense',
            'source_document_id' => 'required|integer|min:1',
            'source_document_number' => 'nullable|string|max:191',
            'source_journal_id' => 'required|integer|min:1',
        ])->validate();
        $type = $payload['source_document_type'];
        foreach (['source_invoice_id' => 'invoice', 'source_bill_id' => 'bill', 'posted_invoice_journal_id' => 'invoice', 'posted_bill_journal_id' => 'bill'] as $alias => $allowed) {
            if (!array_key_exists($alias, $payload)) continue;
            abort_unless($type === $allowed, 422, 'Financial origin aliases do not match the source document.');
            $expected = str_contains($alias, 'journal') ? $payload['source_journal_id'] : $payload['source_document_id'];
            abort_unless((int) $payload[$alias] === (int) $expected, 422, 'Financial origin aliases do not match the source document.');
        }
        return new self($type, (int) $payload['source_document_id'], (int) $payload['source_journal_id'], (string)($payload['source_document_number']??''));
    }

    public function documentTable(): string { return match ($this->type) { 'invoice' => 'invoices', 'sales_receipt' => 'sales_receipts', 'expense' => 'expenses' }; }
    public function lineTable(): string { return match ($this->type) { 'invoice' => 'invoice_lines', 'sales_receipt' => 'sales_receipt_lines', 'expense' => 'expense_lines' }; }
    public function lineParent(): string { return match ($this->type) { 'invoice' => 'invoice_id', 'sales_receipt' => 'sales_receipt_id', 'expense' => 'expense_id' }; }
    public function modelClass(): string { return match ($this->type) { 'invoice' => 'App\\Models\\Invoice', 'sales_receipt' => 'App\\Models\\SalesReceipt', 'expense' => 'App\\Models\\Expense' }; }
    public function journalSource(): string { return match ($this->type) { 'invoice' => 'AR', 'sales_receipt' => 'SR', 'expense' => 'AP-EXPENSE' }; }
    public function domain(): string { return $this->type === 'expense' ? 'acquisition' : 'sales'; }
    public function key(): string { return $this->type.':'.$this->documentId.':journal:'.$this->journalId; }
}
