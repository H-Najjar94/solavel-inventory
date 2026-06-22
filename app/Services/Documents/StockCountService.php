<?php

namespace App\Services\Documents;

use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockCount;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock Count (cycle / full). Counting itself moves no stock. Posting variances
 * goes through the ONE adjustment path: it builds a StockAdjustment (increase for
 * positive variance, decrease for negative) and posts it via
 * StockAdjustmentService → StockLedgerService. The count records the resulting
 * adjustment id for provenance. No direct stock writes here.
 */
class StockCountService
{
    public function __construct(
        private OrganizationContext $context,
        private StockAdjustmentService $adjustments,
        private \App\Services\Integration\IntegrationOutboxService $outbox,
    ) {}

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function createDraft(array $attributes, array $lines): StockCount
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($attributes, $lines, $orgId) {
            $count = new StockCount(array_merge(['status' => 'draft'], $attributes));
            $count->organization_id = $orgId;
            $count->save();

            foreach ($lines as $line) {
                $system = Decimal::qty((string) ($line['system_qty'] ?? '0'));
                $counted = isset($line['counted_qty']) ? Decimal::qty((string) $line['counted_qty']) : null;
                $variance = $counted !== null ? Decimal::sub($counted, $system) : '0';

                $count->lines()->create([
                    'organization_id' => $orgId,
                    'item_id' => $line['item_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'lot_id' => $line['lot_id'] ?? null,
                    'serial_id' => $line['serial_id'] ?? null,
                    'bin_id' => $line['bin_id'] ?? null,
                    'system_qty' => $system,
                    'counted_qty' => $counted,
                    'variance_qty' => Decimal::qty($variance),
                ]);
            }

            return $count->fresh('lines');
        });
    }

    /** Update a DRAFT count: replace header + lines (recomputing variances). */
    public function updateDraft(StockCount $count, array $attributes, array $lines): StockCount
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($count, $attributes, $lines, $orgId) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'draft') {
                throw new RuntimeException("Only a draft count can be edited (status '{$count->status}').");
            }

            $count->fill(collect($attributes)->only(['count_number', 'count_type', 'warehouse_id', 'zone_id', 'notes'])->toArray());
            $count->lines()->delete();
            foreach ($lines as $line) {
                $system = Decimal::qty((string) ($line['system_qty'] ?? '0'));
                $counted = isset($line['counted_qty']) && $line['counted_qty'] !== '' ? Decimal::qty((string) $line['counted_qty']) : null;
                $variance = $counted !== null ? Decimal::sub($counted, $system) : '0';
                $count->lines()->create([
                    'organization_id' => $orgId,
                    'item_id' => $line['item_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'lot_id' => $line['lot_id'] ?? null,
                    'serial_id' => $line['serial_id'] ?? null,
                    'bin_id' => $line['bin_id'] ?? null,
                    'system_qty' => $system,
                    'counted_qty' => $counted,
                    'variance_qty' => Decimal::qty($variance),
                ]);
            }
            $count->save();

            return $count->fresh('lines');
        });
    }

    /** Post variances through a single StockAdjustment. */
    public function post(StockCount $count): StockCount
    {
        return DB::connection($this->connection())->transaction(function () use ($count) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status === 'posted') {
                return $count; // idempotent
            }
            if (! in_array($count->status, ['review', 'counting', 'draft'], true)) {
                throw new RuntimeException("Count {$count->id} cannot be posted from status '{$count->status}'.");
            }

            $count->loadMissing('lines');
            $whId = (int) $count->warehouse_id;
            $adjLines = [];
            foreach ($count->lines as $line) {
                if ($line->counted_qty === null) {
                    continue; // line was never counted → no variance to post
                }

                // TOCTOU FIX: recompute the variance against the LIVE on-hand at
                // POST time (held FOR UPDATE), never the client-supplied system_qty
                // captured at draft time — stock can move between counting and
                // posting. The global OrganizationScope keeps this org-scoped.
                $liveOnHand = (string) (StockBalance::query()
                    ->where('item_id', $line->item_id)
                    ->where('warehouse_id', $whId)
                    ->when($line->variant_id !== null, fn ($q) => $q->where('variant_id', $line->variant_id), fn ($q) => $q->whereNull('variant_id'))
                    ->when($line->lot_id !== null, fn ($q) => $q->where('lot_id', $line->lot_id), fn ($q) => $q->whereNull('lot_id'))
                    ->when($line->bin_id !== null, fn ($q) => $q->where('bin_id', $line->bin_id), fn ($q) => $q->whereNull('bin_id'))
                    ->lockForUpdate()
                    ->value('on_hand_qty') ?? '0');

                $variance = Decimal::sub((string) $line->counted_qty, $liveOnHand);

                // Persist the authoritative system_qty + variance back onto the line
                // for the audit trail (overwrites the stale draft-time values).
                $line->system_qty = Decimal::qty($liveOnHand);
                $line->variance_qty = Decimal::qty($variance);
                $line->save();

                if (Decimal::isZero($variance)) {
                    continue;
                }
                $isIncrease = Decimal::gt($variance, '0');
                $adjLines[] = [
                    'item_id' => $line->item_id,
                    'variant_id' => $line->variant_id,
                    'direction' => $isIncrease ? 'increase' : 'decrease',
                    'quantity' => ltrim((string) Decimal::qty($variance), '-'),
                    'lot_id' => $line->lot_id,
                    'serial_id' => $line->serial_id,
                    'bin_id' => $line->bin_id,
                ];
            }

            if ($adjLines !== []) {
                $adj = $this->adjustments->createDraft([
                    'adjustment_number' => 'COUNT-'.$count->count_number,
                    'adjustment_date' => now()->toDateString(),
                    'warehouse_id' => $count->warehouse_id,
                    'reason_code' => 'cycle_count',
                    'notes' => "Variance from stock count {$count->count_number}",
                ], $adjLines);

                $this->adjustments->post($adj);
                $count->adjustment_id = $adj->id;
            }

            $count->status = 'posted';
            $count->posted_at = now();
            $count->posted_by = auth()->id();
            $count->posted_guard_key = 'stock_count:'.$count->id.':post';
            $count->markSystemTransition()->save();

            $this->outbox->record('stock_count.posted', $count, 'stock_count', $count->count_number, (string) now()->toDateString());

            return $count;
        });
    }
}
