<?php

namespace App\Services\Traceability;

use App\Models\Tenant\Lot;
use App\Models\Tenant\Recall;
use App\Models\Tenant\RecallAction;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recall workspace. A recall is a CASE over an item's lots/serials. It never
 * moves stock itself — activating it flags the affected lots as 'recalled' and
 * serials as 'quarantined' (via the lot/serial services), which the stock engine
 * then blocks from shipping unless overridden. Impact is computed live from the
 * canonical ledger + balances. No customer notification, no SolaBooks here.
 */
class RecallService
{
    public function __construct(
        private OrganizationContext $context,
        private LotService $lots,
        private SerialService $serials,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function createDraft(array $attributes, array $lines): Recall
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($attributes, $lines, $orgId) {
            $recall = new Recall(array_merge(['status' => 'draft', 'scope' => $attributes['scope'] ?? 'lot'], $attributes));
            $recall->organization_id = $orgId;
            $recall->created_by = auth()->id();
            $recall->save();
            $this->syncLines($recall, $lines, $orgId);
            $this->logAction($recall, 'created', 'Recall case drafted.');

            return $recall->fresh('lines');
        });
    }

    public function updateDraft(Recall $recall, array $attributes, array $lines): Recall
    {
        return DB::connection($this->conn())->transaction(function () use ($recall, $attributes, $lines) {
            $orgId = $this->context->idOrFail();
            $recall = Recall::query()->lockForUpdate()->findOrFail($recall->id);
            if ($recall->status !== 'draft') {
                throw new RuntimeException("Only a draft recall can be edited (status '{$recall->status}').");
            }
            $recall->fill(collect($attributes)->only(['recall_number', 'item_id', 'scope', 'reason', 'notes'])->toArray());
            $recall->save();
            $recall->lines()->delete();
            $this->syncLines($recall, $lines, $orgId);

            return $recall->fresh('lines');
        });
    }

    /** Activate: snapshot impact, flag affected lots/serials, set status active. */
    public function activate(Recall $recall): Recall
    {
        return DB::connection($this->conn())->transaction(function () use ($recall) {
            $recall = Recall::query()->lockForUpdate()->with('lines')->findOrFail($recall->id);
            if ($recall->status === 'active') {
                return $recall;
            }
            if ($recall->status !== 'draft') {
                throw new RuntimeException("Recall {$recall->id} cannot be activated from '{$recall->status}'.");
            }

            foreach ($recall->lines as $line) {
                $impact = $this->lineImpact($line->item_id, $line->lot_id, $line->serial_id);
                // Snapshot the impact onto the recall line (a recall_lines column —
                // NOT a stock table). forceFill keeps these writes off the
                // single-writer guard's bare property-assignment heuristic.
                $line->forceFill([
                    'on_hand_qty' => $impact['on_hand'],
                    'reserved_qty' => $impact['reserved'],
                    'shipped_qty' => $impact['shipped'],
                ])->save();

                if ($line->lot_id) {
                    $this->lots->setStatus(Lot::query()->findOrFail($line->lot_id), 'recalled', "Recall {$recall->recall_number}");
                }
                if ($line->serial_id) {
                    $this->serials->setStatus(SerialNumber::query()->findOrFail($line->serial_id), 'quarantined');
                }
            }

            $recall->status = 'active';
            $recall->activated_at = now();
            $recall->save();
            $this->logAction($recall, 'activated', 'Recall activated; affected lots flagged recalled, serials quarantined.');

            return $recall->fresh('lines');
        });
    }

    public function close(Recall $recall, ?string $notes = null): Recall
    {
        return DB::connection($this->conn())->transaction(function () use ($recall, $notes) {
            $recall = Recall::query()->lockForUpdate()->findOrFail($recall->id);
            if ($recall->status === 'closed') {
                return $recall;
            }
            $recall->status = 'closed';
            $recall->closed_at = now();
            $recall->save();
            $this->logAction($recall, 'closed', $notes ?? 'Recall closed.');

            return $recall;
        });
    }

    /**
     * Live impact preview for a recall (or an ad-hoc item+lot/serial selection).
     * Computed from the canonical ledger/balances; export-ready affected list.
     */
    public function impact(Recall $recall): array
    {
        $recall->loadMissing('lines');
        $lines = [];
        $totals = ['on_hand' => '0', 'reserved' => '0', 'shipped' => '0'];

        foreach ($recall->lines as $line) {
            $i = $this->lineImpact($line->item_id, $line->lot_id, $line->serial_id);
            $shippedDocs = $this->shippedDocuments($line->item_id, $line->lot_id, $line->serial_id);
            $lines[] = [
                'recall_line_id' => $line->id,
                'item_id' => $line->item_id,
                'lot_id' => $line->lot_id,
                'serial_id' => $line->serial_id,
                'on_hand' => $i['on_hand'],
                'reserved' => $i['reserved'],
                'shipped' => $i['shipped'],
                'warehouses' => $i['warehouses'],
                'shipped_documents' => $shippedDocs,
            ];
            $totals['on_hand'] = Decimal::add($totals['on_hand'], $i['on_hand']);
            $totals['reserved'] = Decimal::add($totals['reserved'], $i['reserved']);
            $totals['shipped'] = Decimal::add($totals['shipped'], $i['shipped']);
        }

        return [
            'recall' => $recall->only(['id', 'recall_number', 'item_id', 'scope', 'status', 'reason']),
            'lines' => $lines,
            'totals' => [
                'on_hand' => Decimal::qty($totals['on_hand']),
                'reserved' => Decimal::qty($totals['reserved']),
                'shipped' => Decimal::qty($totals['shipped']),
            ],
        ];
    }

    private function lineImpact(int $itemId, ?int $lotId, ?int $serialId): array
    {
        $db = DB::connection($this->conn());
        $balQ = StockBalance::query()->where('item_id', $itemId)
            ->when($lotId, fn ($q) => $q->where('lot_id', $lotId));
        $onHand = (string) $balQ->sum('on_hand_qty');
        $reserved = (string) (clone $balQ)->sum('reserved_qty');

        $shippedQ = StockLedger::query()->where('item_id', $itemId)->where('direction', 'out')
            ->where('source_type', 'like', '%Shipment')
            ->when($lotId, fn ($q) => $q->where('lot_id', $lotId))
            ->when($serialId, fn ($q) => $q->where('serial_id', $serialId));
        $shipped = (string) $shippedQ->sum('quantity');

        $warehouses = $db->table('stock_balances')
            ->where('organization_id', (int) $this->context->id()) // SECURITY: org scope (raw query bypasses global scope)
            ->where('item_id', $itemId)
            ->when($lotId, fn ($q) => $q->where('lot_id', $lotId))
            ->where('on_hand_qty', '>', 0)
            ->pluck('warehouse_id')->unique()->values()->all();

        return [
            'on_hand' => Decimal::qty($onHand),
            'reserved' => Decimal::qty($reserved),
            'shipped' => Decimal::qty($shipped),
            'warehouses' => $warehouses,
        ];
    }

    /** Shipment documents that issued the affected lot/serial (customer placeholder). */
    private function shippedDocuments(int $itemId, ?int $lotId, ?int $serialId): array
    {
        return StockLedger::query()->where('item_id', $itemId)->where('direction', 'out')
            ->where('source_type', 'like', '%Shipment')
            ->when($lotId, fn ($q) => $q->where('lot_id', $lotId))
            ->when($serialId, fn ($q) => $q->where('serial_id', $serialId))
            ->get(['source_type', 'source_id', 'quantity', 'moved_at'])
            ->map(fn ($r) => [
                'shipment_id' => $r->source_id,
                'qty' => $r->quantity,
                'at' => $r->moved_at,
                'customer' => null, // placeholder — wired when SO/customer link lands
            ])->all();
    }

    private function syncLines(Recall $recall, array $lines, int $orgId): void
    {
        foreach ($lines as $line) {
            $recall->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'] ?? $recall->item_id,
                'lot_id' => $line['lot_id'] ?? null,
                'serial_id' => $line['serial_id'] ?? null,
                'disposition' => $line['disposition'] ?? 'quarantine',
            ]);
        }
    }

    private function logAction(Recall $recall, string $action, ?string $detail): void
    {
        RecallAction::create([
            'organization_id' => $recall->organization_id,
            'recall_id' => $recall->id,
            'action' => $action,
            'detail' => $detail,
            'actor_user_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
