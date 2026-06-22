<?php

namespace App\Services\Documents;

use App\Models\Tenant\OpeningStockEntry;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening Stock document: draft → posted → reversed.
 *
 * Posting emits inbound movements to the canonical ledger via StockLedgerService
 * (the only stock writer). This service NEVER touches stock_ledger / stock_balances
 * / cost_layers itself — it only manages the document header/lines and delegates.
 *
 * Idempotency: the document's posted_guard_key drives a stable ledger namespace,
 * so posting twice does not duplicate stock.
 */
class OpeningStockService
{
    use \App\Services\Documents\Concerns\CapturesTraceability;

    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private \App\Services\Integration\IntegrationOutboxService $outbox,
        private \App\Services\Traceability\LotService $lots,
        private \App\Services\Traceability\SerialService $serials,
    ) {}

    protected function lotService(): \App\Services\Traceability\LotService
    {
        return $this->lots;
    }

    protected function serialService(): \App\Services\Traceability\SerialService
    {
        return $this->serials;
    }

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /** Ledger idempotency namespace for this document's posting. */
    private function postNamespace(OpeningStockEntry $entry): string
    {
        return 'opening_stock:'.$entry->id.':post';
    }

    private function reverseNamespace(OpeningStockEntry $entry): string
    {
        return 'opening_stock:'.$entry->id.':reverse';
    }

    /** Create a draft opening-stock entry with lines. */
    public function createDraft(array $attributes, array $lines): OpeningStockEntry
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($attributes, $lines, $orgId) {
            // Server-issued document number when none was supplied (users don't
            // type technical numbers). Generated inside the transaction.
            if (empty($attributes['entry_number'])) {
                $attributes['entry_number'] = \App\Services\Documents\Support\DocumentNumber::next(
                    'OS', OpeningStockEntry::class, 'entry_number', $orgId, $this->connection()
                );
            }
            // opening_date is NOT NULL. Resolve it in $attributes BEFORE the merge
            // so a blank/null value can't override the default back to null.
            $attributes['opening_date'] = $attributes['opening_date'] ?? now()->toDateString();

            $entry = new OpeningStockEntry(array_merge([
                'status' => 'draft',
            ], $attributes));
            $entry->organization_id = $orgId;
            $entry->save();

            $entry->total_value = $this->buildLines($entry, $lines, $orgId);
            $entry->markSystemTransition()->save();

            return $entry->fresh('lines');
        });
    }

    /** Update a DRAFT entry: replace header + lines. Posted/reversed are immutable. */
    public function updateDraft(OpeningStockEntry $entry, array $attributes, array $lines): OpeningStockEntry
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($entry, $attributes, $lines, $orgId) {
            $entry = OpeningStockEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== 'draft') {
                throw new RuntimeException("Only a draft opening stock entry can be edited (status '{$entry->status}').");
            }

            $entry->fill(collect($attributes)->only(['entry_number', 'opening_date', 'warehouse_id', 'notes'])->toArray());

            $entry->lines()->delete();
            $entry->total_value = $this->buildLines($entry, $lines, $orgId);
            $entry->markSystemTransition()->save();

            return $entry->fresh('lines');
        });
    }

    /**
     * Create entry lines from raw input, resolving lot/serial capture and
     * expanding serial captures into qty-1 lines. Returns the total value.
     */
    private function buildLines(OpeningStockEntry $entry, array $lines, int $orgId): string
    {
        $totalValue = '0';
        foreach ($lines as $line) {
            $cap = $this->resolveCapture($line, $orgId, OpeningStockEntry::class, (int) $entry->id);
            $unitCost = Decimal::cost((string) ($line['unit_cost'] ?? '0'));

            // Serial capture → one qty-1 line per serial.
            if ($cap['serial_ids'] !== []) {
                foreach ($cap['serial_ids'] as $sid) {
                    $totalValue = Decimal::add($totalValue, $unitCost);
                    $entry->lines()->create([
                        'organization_id' => $orgId,
                        'item_id' => $line['item_id'],
                        'variant_id' => $line['variant_id'] ?? null,
                        'lot_id' => $cap['lot_id'],
                        'serial_id' => $sid,
                        'bin_id' => $line['bin_id'] ?? null,
                        'quantity' => '1.0000',
                        'unit_cost' => $unitCost,
                        'total_cost' => Decimal::money($unitCost),
                        'notes' => $line['notes'] ?? null,
                    ]);
                }

                continue;
            }

            $qty = Decimal::qty((string) $line['quantity']);
            if (! Decimal::gt($qty, '0')) {
                throw new RuntimeException('Opening stock line quantity must be > 0.');
            }
            $lineTotal = Decimal::money(Decimal::mul($qty, $unitCost));
            $totalValue = Decimal::add($totalValue, $lineTotal);
            $entry->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'lot_id' => $cap['lot_id'],
                'serial_id' => $line['serial_id'] ?? null,
                'bin_id' => $line['bin_id'] ?? null,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $lineTotal,
                'notes' => $line['notes'] ?? null,
            ]);
        }

        return Decimal::money($totalValue);
    }

    /**
     * Post a draft entry: emit inbound ledger movements and lock the document.
     * Idempotent: re-posting a posted entry returns it without duplicating stock.
     */
    public function post(OpeningStockEntry $entry): OpeningStockEntry
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($entry, $orgId) {
            $entry = OpeningStockEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->isPosted()) {
                return $entry; // idempotent
            }
            if ($entry->status !== 'draft') {
                throw new RuntimeException("Opening stock {$entry->id} cannot be posted from status '{$entry->status}'.");
            }

            $entry->loadMissing('lines');
            $movements = [];
            foreach ($entry->lines as $line) {
                $movements[] = new StockMovement(
                    direction: 'in',
                    itemId: (int) $line->item_id,
                    warehouseId: (int) $entry->warehouse_id,
                    quantity: (string) $line->quantity,
                    sourceType: OpeningStockEntry::class,
                    sourceId: (int) $entry->id,
                    sourceLineId: (int) $line->id,
                    variantId: $line->variant_id ? (int) $line->variant_id : null,
                    binId: $line->bin_id ? (int) $line->bin_id : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    serialId: $line->serial_id ? (int) $line->serial_id : null,
                    unitCost: (string) $line->unit_cost,
                    movedAt: $entry->opening_date?->toDateTimeString() ?? now()->toDateTimeString(),
                );
            }

            $this->ledger->post($movements, $this->postNamespace($entry), [
                'action' => 'opening_stock.post',
                'entity_type' => 'opening_stock_entry',
                'entity_id' => $entry->id,
                'document_ref' => $entry->entry_number,
            ]);

            $entry->status = 'posted';
            $entry->posted_at = now();
            $entry->posted_by = auth()->id();
            $entry->posted_guard_key = $this->postNamespace($entry);
            $entry->markSystemTransition()->save();

            // Record the SolaBooks integration event in the SAME transaction.
            $this->outbox->record('opening_stock.posted', $entry, 'opening_stock', $entry->entry_number, (string) $entry->opening_date);

            return $entry;
        });
    }

    /** Reverse a posted entry: emit opposite ledger movements and lock as reversed. */
    public function reverse(OpeningStockEntry $entry): OpeningStockEntry
    {
        return DB::connection($this->connection())->transaction(function () use ($entry) {
            $entry = OpeningStockEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->isReversed()) {
                return $entry; // idempotent
            }
            if (! $entry->isPosted()) {
                throw new RuntimeException("Only a posted opening stock entry can be reversed (status '{$entry->status}').");
            }

            $this->ledger->reverse($this->postNamespace($entry), $this->reverseNamespace($entry), [
                'action' => 'opening_stock.reverse',
                'entity_type' => 'opening_stock_entry',
                'entity_id' => $entry->id,
                'document_ref' => $entry->entry_number,
            ]);

            $entry->status = 'reversed';
            $entry->reversed_at = now();
            $entry->reversed_by = auth()->id();
            $entry->markSystemTransition()->save();

            $this->outbox->record('opening_stock.reversed', $entry, 'opening_stock', $entry->entry_number, (string) $entry->opening_date);

            return $entry;
        });
    }
}
