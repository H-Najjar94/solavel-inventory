<?php

namespace App\Services\FinancialOrigins;

use App\Models\Tenant\{GoodsReceipt, IntegrationDocumentLifecycleMapping, IntegrationMasterDataMapping, IntegrationOrganizationMapping, IntegrationOutboxEvent};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Construction is restricted to independently verified, locked Expense/receipt facts. */
final readonly class OriginReceiptCostAuthority
{
    private function __construct(private array $snapshot, private array $proof, private array $allocations, private int $org, private string $mapping, private string $operation, private int $expense, private int $sourceJournal, private ?array $quote, private ?array $forwardQuote, private ?OriginReceiptReverseGeneration $reverseGeneration = null) {}

    public static function fromLockedNativeProvenance(array $identity, array $proof, IntegrationOrganizationMapping $mapping, string $action, int $actor): self
    {
        $db = DB::connection('tenant');
        $org = app(OrganizationContext::class)->idOrFail();
        $direction = $identity['direction'] ?? 'forward';
        abort_unless($db->transactionLevel() > 0 && in_array($action, ['prepare', 'apply', 'reverse', 'status', 'release'], true) && in_array($direction, ['forward', 'reverse'], true), 403);
        abort_unless(($proof['allowed'] ?? false) === true && ($proof['schema_version'] ?? null) === 'financial-origin.v1' && ($proof['authority_kind'] ?? null) === 'posted_financial_origin_settlement' && ($proof['source_document_type'] ?? null) === 'expense', 403);
        foreach (['source_document_id', 'source_journal_id', 'operation_uuid', 'position_uuid', 'request_uuid', 'source_revision'] as $key) abort_unless(isset($identity[$key]) && (string) ($proof[$key] ?? '') === (string) $identity[$key], 403);
        abort_unless((int) ($proof['finance_organization_id'] ?? 0) === (int) $mapping->finance_organization_id && (int) ($proof['central_organization_id'] ?? 0) === $org && ($proof['organization_mapping_uuid'] ?? null) === $mapping->mapping_uuid, 403);
        abort_unless(($proof['operation'] ?? null) === $action && ($proof['direction'] ?? 'forward') === $direction && array_key_exists('actor_id', $proof) && (int) $proof['actor_id'] === $actor, 403);
        abort_unless($actor === 0 || ($direction === 'reverse' && $actor > 0), 403);
        $expense = $db->table('expenses')->where('organization_id', $mapping->finance_organization_id)->where('id', $identity['source_document_id'])->lockForUpdate()->first();
        abort_unless($expense, 409);
        $request = $db->table('finance_document_requests')->where('organization_id', $mapping->finance_organization_id)->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('request_uuid', $identity['request_uuid'])->where('source_document_type', 'expense')->where('source_document_id', $expense->id)->where('source_journal_id', $identity['source_journal_id'])->where('side', 'purchase')->lockForUpdate()->first();
        abort_unless($request && hash_equals($request->source_revision, (string) ($proof['source_revision'] ?? '')), 409);
        $position = $db->table('finance_document_positions')->where('organization_id', $mapping->finance_organization_id)->where('request_uuid', $request->request_uuid)->where('position_uuid', $identity['position_uuid'])->where('source_document_type', 'expense')->where('source_document_id', $expense->id)->where('source_journal_id', $request->source_journal_id)->where('side', 'purchase')->lockForUpdate()->first();
        abort_unless($position, 409);
        $financialLine = $db->table('expense_lines')->where('expense_id', $expense->id)->where('id', $position->source_document_line_id)->lockForUpdate()->first();
        $requestPayload = json_decode($request->payload, true, 512, JSON_THROW_ON_ERROR);
        $requestedLine = collect($requestPayload['lines'] ?? [])->first(fn ($line) => (int) ($line['source_document_line_id'] ?? 0) === (int) $position->source_document_line_id);
        abort_unless($financialLine && $financialLine->item_usage === 'inventory' && (int) $financialLine->inventory_item_id === (int) $position->inventory_item_id && Decimal::cmp((string) $financialLine->qty, (string) $position->quantity) === 0 && $requestedLine && (int) $requestedLine['item_external_id'] === (int) $position->inventory_item_id && (int) $requestedLine['unit_external_id'] === (int) $position->entered_unit_id && Decimal::cmp((string) $requestedLine['quantity'], (string) $position->quantity) === 0, 409);
        $match = $db->table('finance_document_matches')->where('organization_id', $mapping->finance_organization_id)->where('request_uuid', $request->request_uuid)->where('position_uuid', $position->position_uuid)->where('operation_uuid', $identity['operation_uuid'])->where('source_document_line_id', $position->source_document_line_id)->lockForUpdate()->first();
        abort_unless($match && $match->state === ($proof['match_state'] ?? null), 409);
        abort_unless(Decimal::gt((string) $match->quantity, '0') && !Decimal::gt((string) $match->quantity, (string) $position->quantity), 409);
        $reserved = $db->table('finance_document_matches')->where('organization_id', $mapping->finance_organization_id)->where('request_uuid', $request->request_uuid)->where('position_uuid', $position->position_uuid)->whereNotIn('state', ['abandoned', 'reversed'])->sum('quantity');
        abort_unless(!Decimal::gt((string) $reserved, (string) $position->quantity), 409);
        $snapshot = json_decode($match->snapshot, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(hash_equals(SolaStockJournalContract::payloadHash($snapshot), (string) ($proof['snapshot_hash'] ?? '')), 409);
        $scale = $snapshot['finance_money_scale'] ?? null;
        abort_unless(is_int($scale) && $scale >= 0 && $scale <= 6 && $scale === ($proof['finance_money_scale'] ?? null), 409);
        $source = $snapshot['source'] ?? []; $receipt = $snapshot['receipt'] ?? []; $price = $snapshot['price'] ?? [];
        foreach (['type' => 'expense', 'id' => $expense->id, 'journal_id' => $request->source_journal_id, 'line_id' => $position->source_document_line_id] as $key => $value) abort_unless((string) ($source[$key] ?? '') === (string) $value, 409);
        abort_unless((int) ($proof['source_document_line_id'] ?? 0) === (int) $position->source_document_line_id, 403);
        $lockedMapping = IntegrationOrganizationMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
        abort_unless($lockedMapping->status === 'verified' && $lockedMapping->activation_state === 'active' && $lockedMapping->mapping_uuid === $mapping->mapping_uuid && (int) $lockedMapping->solastock_organization_id === $org && (int) $lockedMapping->central_organization_id === $org && (int) $lockedMapping->central_client_id === (int) $mapping->central_client_id && (int) $lockedMapping->finance_organization_id === (int) $mapping->finance_organization_id && $lockedMapping->tenant_database_identity === $db->getDatabaseName(), 409);
        $sourceJE = $db->table('journal_entries')->where('organization_id', $mapping->finance_organization_id)->where('id', $request->source_journal_id)->where('source', 'AP-EXPENSE')->where('source_type', 'App\\Models\\Expense')->where('source_id', $expense->id)->lockForUpdate()->first();
        abort_unless($sourceJE, 409);
        // Cost inverse completes before the native Expense unpost; it never borrows a voided source.
        self::activeJournal($sourceJE);
        abort_unless((int) ($expense->journal_entry_id ?? 0) === (int) $request->source_journal_id && !empty($expense->posted_at) && $expense->status !== 'draft', 409);
        abort_unless((string) ($receipt['mapping_uuid'] ?? '') === (string) $match->physical_mapping_uuid && (int) ($receipt['id'] ?? 0) === (int) $match->physical_document_id && (int) ($receipt['line_id'] ?? 0) === (int) $match->physical_line_id && (int) ($receipt['imported_journal_id'] ?? 0) === (int) $match->physical_journal_id, 409);
        IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('mapping_uuid', $match->physical_mapping_uuid)->where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $match->physical_document_id)->firstOrFail();
        $stockRequest = $db->table('stock_financial_origin_requests')->where('organization_id', $org)->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('request_uuid', $request->request_uuid)->where('source_document_type', 'expense')->where('source_document_id', $expense->id)->where('source_journal_id', $request->source_journal_id)->lockForUpdate()->first();
        abort_unless($stockRequest && $stockRequest->source_revision === $request->source_revision, 409);
        $command = $db->table('stock_financial_origin_commands')->where('organization_id', $org)->where('request_uuid', $request->request_uuid)->where('source_document_type', 'expense')->where('source_document_id', $expense->id)->where('source_journal_id', $request->source_journal_id)->where('goods_receipt_id', $match->physical_document_id)->where('status', 'completed')->lockForUpdate()->first();
        abort_unless($command, 409);
        $grn = GoodsReceipt::withoutGlobalScope('warehouse_access')->where('organization_id', $org)->whereKey($match->physical_document_id)->lockForUpdate()->firstOrFail();
        abort_unless($grn->status === 'posted' && !$grn->reversal_id, 409);
        $line = $grn->lines()->whereKey($match->physical_line_id)->lockForUpdate()->firstOrFail();
        abort_unless((int) ($expense->vendor_id ?? 0) > 0 && IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('central_client_id', $mapping->central_client_id)->where('central_organization_id', $org)->where('finance_organization_id', $mapping->finance_organization_id)->where('solastock_organization_id', $org)->where('entity_type', 'supplier')->where('solastock_record_id', (string) $grn->supplier_id)->where('solabooks_record_id', (string) $expense->vendor_id)->where('status', 'verified')->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived', false)->where('solabooks_archived', false)->exists(), 403);

        $links = json_decode($command->native_line_links ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $link = collect($links)->first(fn ($link) => (int) $link['physical_line_id'] === (int) $line->id && (int) $link['source_document_line_id'] === (int) $position->source_document_line_id);
        abort_unless($link, 409);
        foreach (['item' => [$line->item_id, $position->inventory_item_id], 'unit' => [$line->entered_unit_id, $position->entered_unit_id]] as $type => $pair) abort_unless(IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('central_client_id', $mapping->central_client_id)->where('central_organization_id', $org)->where('finance_organization_id', $mapping->finance_organization_id)->where('solastock_organization_id', $org)->where('entity_type', $type)->where('solastock_record_id', (string) $pair[0])->where('solabooks_record_id', (string) $pair[1])->where('status', 'verified')->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived', false)->where('solabooks_archived', false)->exists(), 403);
        $journal = IntegrationOutboxEvent::query()->where('organization_id', $org)->where('event_type', 'grn.posted')->where('aggregate_id', $grn->id)->where('idempotency_key', $receipt['journal_key'] ?? '')->where('event_uuid', $receipt['journal_event_uuid'] ?? '')->firstOrFail();
        abort_unless(OriginDocumentBuilder::journalHashMatches($journal, (string) ($receipt['journal_payload_hash'] ?? '')), 409);
        $imported = $db->table('journal_entries')->where('organization_id', $mapping->finance_organization_id)->where('id', $match->physical_journal_id)->where('source_key', 'external-api:'.hash('sha256', $receipt['journal_key']))->lockForUpdate()->first(); self::activeJournal($imported);
        $factor = (string) $line->unit_conversion_factor;
        abort_unless(Decimal::gt($factor, '0') && Decimal::cmp($factor, (string) $match->unit_conversion_factor, 12) === 0 && Decimal::cmp($factor, (string) ($receipt['unit_conversion_factor'] ?? '0'), 12) === 0 && hash_equals((string) $line->unit_conversion_hash, (string) $match->unit_conversion_hash) && hash_equals((string) $line->unit_conversion_hash, (string) ($receipt['unit_conversion_hash'] ?? '')), 409);
        $baseQty = Decimal::mul((string) $match->quantity, $factor, 8);
        abort_unless(Decimal::gt($baseQty, '0') && !Decimal::gt($baseQty, (string) $line->accepted_qty) && Decimal::cmp($baseQty, (string) $match->base_quantity, 8) === 0 && Decimal::cmp($baseQty, (string) ($receipt['base_quantity'] ?? '0'), 8) === 0, 409);
        abort_unless(Decimal::cmp((string) data_get($journal->payload, 'currency.exchange_rate', '0'), (string) ($receipt['exchange_rate'] ?? '0'), 12) === 0 && ($receipt['currency_code'] ?? null) === data_get($journal->payload, 'currency.code') && ($receipt['base_currency_code'] ?? null) === $mapping->base_currency_code, 409);
        abort_unless(($source['currency_code'] ?? null) === ($receipt['currency_code'] ?? null) && ($source['base_currency_code'] ?? null) === $mapping->base_currency_code && Decimal::gt((string) ($source['exchange_rate'] ?? '0'), '0') && Decimal::gt((string) ($receipt['exchange_rate'] ?? '0'), '0'), 409);
        abort_unless(isset($price['price_delta_base'], $price['desired_acquisition_at_receipt_base'], $receipt['original_receipt_base_amount']) && Decimal::cmp(Decimal::sub($price['desired_acquisition_at_receipt_base'], $receipt['original_receipt_base_amount'], 8), $price['price_delta_base'], $scale) === 0, 409);
        abort_unless(Decimal::gt((string) ($source['quantity'] ?? '0'), '0') && Decimal::cmp((string) $position->quantity, (string) $source['quantity'], 8) === 0 && Decimal::cmp((string) $position->booked_acquisition_base, (string) ($source['booked_acquisition_base'] ?? ''), $scale) === 0, 409);
        abort_unless(Decimal::cmp((string) $match->quantity, (string) ($receipt['quantity'] ?? '0'), 8) === 0 && Decimal::cmp((string) $line->unit_cost, Decimal::div((string) $receipt['unit_cost'], $factor, 8), 8) === 0, 409);
        $sourceAcquisitionUnit = Decimal::div(Decimal::add((string) $source['net_amount'], (string) $source['nonrecoverable_tax_amount'], 8), (string) $source['quantity'], 12);
        $expectedAtReceipt = Decimal::round(Decimal::div(Decimal::mul($sourceAcquisitionUnit, (string) $match->quantity, 12), (string) $receipt['exchange_rate'], 12), $scale);
        $ledger = $db->table('stock_ledger')->where('organization_id', $org)->where('id', $receipt['stock_ledger_id'] ?? 0)->where('source_type', GoodsReceipt::class)->where('source_id', $grn->id)->where('source_line_id', $line->id)->where('direction', 'in')->lockForUpdate()->first();
        abort_unless($ledger && (int) $ledger->item_id === (int) $line->item_id && (int) $ledger->warehouse_id === (int) $grn->warehouse_id && ($receipt['stock_money_scale'] ?? null) === Decimal::MONEY_SCALE, 409);
        // Each accepted typed GRN line is matched whole. Partial ledger allocation needs its own cumulative residual contract.
        abort_unless(Decimal::cmp((string) $ledger->quantity, $baseQty, 8) === 0 && Decimal::cmp((string) $ledger->quantity, (string) ($receipt['source_quantity_base'] ?? '0'), 8) === 0 && Decimal::cmp((string) $ledger->total_cost, (string) ($receipt['source_value_base'] ?? '-1'), 8) === 0, 409);
        $nativeReceiptBase = (string) $ledger->total_cost;
        abort_unless(Decimal::cmp($expectedAtReceipt, (string) $price['desired_acquisition_at_receipt_base'], 8) === 0 && Decimal::cmp($nativeReceiptBase, (string) $receipt['original_receipt_base_amount'], 8) === 0 && Decimal::cmp((string) $match->physical_cost_base, $nativeReceiptBase, 8) === 0, 409);
        abort_unless(isset($price['booked_fx_difference_base']) && Decimal::cmp(Decimal::sub((string) $match->booked_base, $expectedAtReceipt, 8), (string) $price['booked_fx_difference_base'], $scale) === 0, 409);
        $plan = json_decode($match->value_plan ?? 'null', true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($plan === null && ($proof['value_plan_hash'] ?? null) === null) || ($plan !== null && hash_equals(SolaStockJournalContract::payloadHash($plan), (string) ($proof['value_plan_hash'] ?? ''))), 409);
        $generation = $direction === 'reverse' && ($match->reversal_generation ?? null) !== null
            ? OriginReceiptReverseGeneration::lock($db, $match, $request, $identity, $proof, $action) : null;
        if ($generation) $generation->assertPredecessorsReleased($db, $match, $request, $org, $mapping->mapping_uuid, (int) $line->item_id, (int) $grn->warehouse_id, (int) $grn->id);
        $quote = $direction === 'reverse' ? ($generation ? $generation->quote() : ($plan['reverse_plan'] ?? null)) : $plan;
        if ($generation) $match = $generation->lifecycleMatch($match);
        if ($quote) abort_unless(hash_equals((string) $quote['plan_fingerprint'], (string) ($proof['plan_fingerprint'] ?? '')), 409);
        // Inverse and release require audited directional intents, independently checked below.
        self::assertFinancialLifecycle($db, $mapping, $expense, $sourceJE, $request, $match, $action, $direction, $actor, $proof, $quote);
        return new self($snapshot, $proof, [[ 'receipt_id' => (int) $grn->id, 'receipt_line_id' => (int) $line->id, 'request_line_id' => (int) $link['request_line_id'], 'position_uuid' => $position->position_uuid, 'quantity_base' => $baseQty, 'price_delta_base' => (string) $price['price_delta_base'], 'item_id' => (int) $line->item_id, 'warehouse_id' => (int) $grn->warehouse_id ]], $org, $mapping->mapping_uuid, $match->operation_uuid, (int) $expense->id, (int) $request->source_journal_id, $quote, $plan, $generation);
    }

    private static function assertFinancialLifecycle($db, $mapping, $expense, $sourceJE, $request, $match, string $action, string $direction, int $actor, array $proof, ?array $quote): void
    {
        if ($action === 'prepare' && $direction === 'forward') abort_unless(!$match->journal_entry_id && !in_array($match->state, ['abandoned', 'reversed'], true), 409);
        if ($action === 'apply' || $direction === 'reverse') {
            $original = $db->table('journal_entries')->where('organization_id', $mapping->finance_organization_id)->where('id', $match->journal_entry_id)->where('source', 'FINANCIAL-ORIGIN')->where('source_type', 'App\\Models\\Expense')->where('source_id', $expense->id)->where('source_key', 'financial-origin-match:'.$match->operation_uuid)->lockForUpdate()->first(); self::activeJournal($original);
            abort_unless((int) ($proof['finance_journal_id'] ?? 0) === (int) $match->journal_entry_id, 409);
        }
        if ($direction === 'reverse' && $action !== 'release') {
            abort_unless($actor > 0 && (int) ($match->reverse_central_actor_id ?? 0) === $actor && ($match->closure_permission ?? null) === 'unpost' && ($proof['closure_permission'] ?? null) === 'unpost', 403);
            // The cancellation/closure actor is a separate durable command identity;
            // the original posting actor is immutable and grants no reversal authority.
            abort_unless((int) ($request->command_central_actor_id ?? 0) === $actor
                && (int) ($request->command_actor_id ?? 0) === (int) ($match->reverse_actor_id ?? 0)
                && (int) ($request->command_actor_id ?? 0) > 0
                && ($request->closure_permission ?? null) === 'unpost'
                && (int) ($request->closing_source_journal_id ?? 0) === (int) $sourceJE->id, 403);
            $reverse = json_decode($match->reversal_snapshot ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($reverse['phase'] ?? null) === 'match_inverse_before_expense_unpost' && (int) ($reverse['original_source_journal_id'] ?? 0) === (int) $sourceJE->id && (int) ($reverse['original_match_journal_id'] ?? 0) === (int) $match->journal_entry_id && ($reverse['reversal_operation_uuid'] ?? null) === ($match->reversal_operation_uuid ?? null) && ($reverse['request_uuid'] ?? null) === $request->request_uuid && ($reverse['source_revision'] ?? null) === $request->source_revision && (int) ($reverse['reverse_actor_id'] ?? 0) === (int) $match->reverse_actor_id && (int) ($reverse['reverse_central_actor_id'] ?? 0) === $actor && ($reverse['closure_permission'] ?? null) === 'unpost', 409);
            $cancel = json_decode($request->response ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            abort_unless($request->command === 'cancel' && $request->state === 'cancelled' && ($reverse['cancel_request_uuid'] ?? null) === $request->request_uuid && ($reverse['cancel_source_revision'] ?? null) === $request->source_revision && ($reverse['cancel_state'] ?? null) === 'cancelled' && ($reverse['cancel_command'] ?? null) === 'cancel' && ($cancel['status'] ?? null) === 'cancelled' && ($cancel['request_uuid'] ?? null) === $request->request_uuid && ($cancel['source_revision'] ?? null) === $request->source_revision && (int) ($cancel['source_document_id'] ?? 0) === (int) $expense->id && (int) ($cancel['source_journal_id'] ?? 0) === (int) $sourceJE->id, 409);
            if ($action === 'reverse') {
                abort_unless($quote && $sourceJE->status === 'posted' && empty($sourceJE->voided_at) && $expense->status !== 'draft', 409);
                $inverse = $db->table('journal_entries')->where('organization_id', $mapping->finance_organization_id)->where('id', $match->reversal_journal_id)->where('source', 'FINANCIAL-ORIGIN')->where('source_type', 'App\\Models\\Expense')->where('source_id', $expense->id)->where('source_key', 'financial-origin-match-reversal:'.$match->operation_uuid)->where('reverses_entry_id', $match->journal_entry_id)->lockForUpdate()->first(); self::activeJournal($inverse);
                abort_unless((int) ($proof['reversal_journal_id'] ?? 0) === (int) $match->reversal_journal_id && (int) ($reverse['match_inverse_journal_id'] ?? 0) === (int) $match->reversal_journal_id, 409);
            }
        }
        if ($action === 'release') {
            $release = json_decode($match->release_snapshot ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($release['purpose'] ?? null) === 'abandon_match' && ($release['direction'] ?? null) === $direction && ($release['operation_uuid'] ?? null) === $match->operation_uuid && ($release['source_revision'] ?? null) === $request->source_revision && ($match->release_state ?? null) === 'pending' && ($release['release_operation_uuid'] ?? null) === ($match->release_operation_uuid ?? null) && (int) ($release['source_journal_id'] ?? 0) === (int) $sourceJE->id && !empty($release['abandoned_at']) && data_get($release, 'quote.abandoned') === true, 409);
            abort_unless(($release['quote']['native_plan'] ?? null) === ($quote['native_plan'] ?? null) && is_array($quote['native_plan'] ?? null) && hash_equals((string) ($quote['plan_fingerprint'] ?? ''), (string) data_get($release, 'quote.plan_fingerprint', '')), 409);
            $fingerprint = $direction === 'reverse' ? ($release['reverse_hold_fingerprint'] ?? '') : ($release['hold_fingerprint'] ?? '');
            abort_unless(hash_equals((string) ($quote['plan_fingerprint'] ?? ''), (string) $fingerprint), 409);
            if ($direction === 'forward') abort_unless($match->state === 'abandoned' && !$match->journal_entry_id && !$match->reversal_journal_id, 409);
            else abort_unless($match->state === 'settled' && ($match->reverse_state ?? null) === 'abandoned' && !$match->reversal_journal_id && (int) ($release['original_match_journal_id'] ?? 0) === (int) $match->journal_entry_id && ($release['reversal_operation_uuid'] ?? null) === ($match->reversal_operation_uuid ?? null) && ($release['unpost_audit_id'] ?? null) === null, 409);

        }
    }

    private static function activeJournal(?object $journal): void { abort_unless($journal && $journal->status === 'posted' && !empty($journal->posted_at) && empty($journal->voided_at) && empty($journal->deleted_at), 409); }
    public function organizationId(): int { return $this->org; }
    public function mappingUuid(): string { return $this->mapping; }
    public function operationUuid(): string { return $this->operation; }
    public function sourceDocumentId(): int { return $this->expense; }
    public function sourceJournalId(): int { return $this->sourceJournal; }
    public function sourceAllocations(): array { return $this->allocations; }
    public function moneyScale(): int { return $this->snapshot['finance_money_scale']; }
    public function currencyCode(): string { return $this->snapshot['receipt']['currency_code']; }
    public function baseCurrencyCode(): string { return $this->snapshot['receipt']['base_currency_code']; }
    public function exchangeRate(): string { return (string) $this->snapshot['receipt']['exchange_rate']; }
    public function action(): string { return $this->proof['operation']; }
    public function direction(): string { return $this->proof['direction'] ?? 'forward'; }
    public function reverse(): bool { return $this->direction() === 'reverse'; }
    public function fingerprint(): string { return hash('sha256', 'financial-origin|'.$this->mapping.'|'.$this->operation.'|'.$this->sourceJournal.'|'.$this->proof['snapshot_hash']); }
    public function financeJournalId(): ?int { return !empty($this->proof['finance_journal_id']) ? (int) $this->proof['finance_journal_id'] : null; }
    public function reversalJournalId(): ?int { return !empty($this->proof['reversal_journal_id']) ? (int) $this->proof['reversal_journal_id'] : null; }
    public function financeJournalKey(): string { return 'financial-origin-match:'.$this->operation; }
    public function reversalJournalKey(): string { return 'financial-origin-match-reversal:'.$this->operation; }
    public function planRevision(): int { return 1; }
    public function planFingerprint(): ?string { return $this->proof['plan_fingerprint'] ?? null; }
    public function storedQuote(): ?array { return $this->quote; }
    public function forwardQuote(): ?array { return $this->forwardQuote; }
    public function reversalGeneration(): int { return $this->reverseGeneration?->number() ?? 0; }
    public function reversalOperationUuid(): ?string { return $this->reverseGeneration?->operationUuid(); }
    public function holdUuid(int $item, int $warehouse, string $direction = 'apply'): string { return Uuid::uuid5(Uuid::NAMESPACE_URL, 'financial-origin|'.$this->mapping.'|'.($direction === 'reverse' && $this->reverseGeneration ? $this->reverseGeneration->operationUuid() : $this->operation).'|'.$item.'|'.$warehouse.'|'.$direction)->toString(); }
}
