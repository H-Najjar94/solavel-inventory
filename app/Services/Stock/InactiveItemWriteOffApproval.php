<?php

namespace App\Services\Stock;

use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\StockAdjustment;
use App\Services\Access\InventoryPermissionService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Q12.6 (owner decision 2026-10-18, Finance NF-1198-03 counterpart): the ONE controlled workflow that reduces /
 * writes off existing stock of an INACTIVE item in SolaStock.
 *
 *   1. draft stock adjustment with DECREASE lines (InactiveItemPolicy::guardDocumentLine: within positive stock);
 *   2. approve(): a second person holding inventory.adjustments.write_off_inactive (owner / SolaStock
 *      Administrator by default: only full app authority holds it unless an owner grants it in a custom role)
 *      records an immutable approval (inventory_audit_logs "stock_adjustment.inactive_write_off_approved") bound to
 *      a sha256 snapshot of the draft's lines; editing the draft afterwards voids it. The approver can never be
 *      the person who created the draft;
 *   3. post(): the poster also needs inventory.adjustments.write_off_inactive, must not be the approver, and the
 *      ledger (StockLedgerService::applyMovement -> InactiveItemPolicy) re-checks, under the balance lock, a
 *      matching approval, positive stock at the coordinate, no resulting negative and NO negative balance of the
 *      item anywhere (a write-off may not disguise an existing negative-stock problem), then audits
 *      actor / organization / operation id / approval / before / after.
 *
 * Every write-off path ends in the ledger check, so the HTTP route, the signed Finance workspace action and any
 * direct service caller are held to the same rule. Same-direction Finance approval target:
 * inventory.inactive_item_write_off (Finance-side only; SolaStock has no generic approval engine).
 */
final class InactiveItemWriteOffApproval
{
    public const PERMISSION = 'inventory.adjustments.write_off_inactive';

    public const APPROVED_ACTION = 'stock_adjustment.inactive_write_off_approved';

    public function __construct(private OrganizationContext $context) {}

    /** Inactive-item decrease lines of the adjustment (empty = an ordinary adjustment, no approval needed). */
    public function inactiveLines(StockAdjustment $adj): array
    {
        return DB::connection('tenant')->table('stock_adjustment_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('l.stock_adjustment_id', $adj->id)->where('l.direction', 'decrease')->where('i.is_active', false)
            ->orderBy('l.id')->get(['l.id', 'l.item_id', 'l.variant_id', 'l.lot_id', 'l.bin_id', 'l.serial_id', 'l.quantity'])
            ->map(fn ($l) => (array) $l)->all();
    }

    /** sha256 of what is being approved: warehouse + every line (all directions), order independent. */
    public function snapshotHash(StockAdjustment $adj): string
    {
        $lines = DB::connection('tenant')->table('stock_adjustment_lines')->where('stock_adjustment_id', $adj->id)
            ->get(['item_id', 'variant_id', 'lot_id', 'bin_id', 'serial_id', 'direction', 'quantity'])
            ->map(fn ($l) => implode('|', [$l->item_id, $l->variant_id, $l->lot_id, $l->bin_id, $l->serial_id, $l->direction, Decimal::qty((string) $l->quantity)]))
            ->sort()->values()->all();

        return hash('sha256', json_encode([(int) $adj->id, (int) $adj->warehouse_id, $lines]));
    }

    /** Warnings + before/after per inactive line, shown on the draft and stored with the approval. */
    public function preview(StockAdjustment $adj): array
    {
        $lines = [];
        $warnings = [];
        foreach ($this->inactiveLines($adj) as $line) {
            $onHand = $this->onHand((int) $line['item_id'], (int) $adj->warehouse_id, $line);
            $lines[] = ['line_id' => (int) $line['id'], 'item_id' => (int) $line['item_id'], 'warehouse_id' => (int) $adj->warehouse_id,
                'quantity' => Decimal::qty((string) $line['quantity']), 'on_hand_before' => Decimal::qty($onHand),
                'on_hand_after' => Decimal::qty(Decimal::sub($onHand, (string) $line['quantity']))];
            $warnings[] = __('inventory.catalog.inactive_write_off_warning', ['item' => $line['item_id'], 'qty' => Decimal::qty((string) $line['quantity']), 'before' => Decimal::qty($onHand)]);
            if ($this->itemHasNegativeBalance((int) $line['item_id'])) {
                $warnings[] = __('inventory.catalog.inactive_write_off_negative_elsewhere', ['item' => $line['item_id']]);
            }
        }

        return ['requires_approval' => $lines !== [], 'permission' => self::PERMISSION, 'lines' => $lines, 'warnings' => $warnings,
            'snapshot_hash' => $lines === [] ? null : $this->snapshotHash($adj), 'approval' => $lines === [] ? null : $this->currentApproval($adj)];
    }

    /** Second-person approval of a draft write-off. Returns the preview including the new approval. */
    public function approve(StockAdjustment $adj, ?string $note = null): array
    {
        $orgId = $this->context->idOrFail();

        return DB::connection('tenant')->transaction(function () use ($adj, $note, $orgId) {
            $adj = StockAdjustment::query()->lockForUpdate()->findOrFail($adj->id);
            $user = self::actor();
            self::assertPermitted($user);
            if ($adj->status !== 'draft') {
                throw self::invalid('inactive_write_off_not_draft');
            }
            $lines = $this->inactiveLines($adj);
            if ($lines === []) {
                throw self::invalid('inactive_write_off_not_required');
            }
            $creator = $this->creatorId($adj);
            if ($creator !== null && $creator === (int) $user->id) {
                abort(403, 'inactive_write_off_self_approval_forbidden');
            }
            foreach ($lines as $line) {
                if ($this->itemHasNegativeBalance((int) $line['item_id'])) {
                    throw self::negativeElsewhere((int) $line['item_id']);
                }
                $onHand = $this->onHand((int) $line['item_id'], (int) $adj->warehouse_id, $line, true);
                if (! Decimal::gt($onHand, '0') || Decimal::lt(Decimal::sub($onHand, (string) $line['quantity']), '0')) {
                    throw ValidationException::withMessages(['item_id' => [__('inventory.catalog.inactive_write_off_exceeds_stock')], 'inactive_item_ids' => [(int) $line['item_id']]]);
                }
            }
            $preview = $this->preview($adj);
            InventoryAuditLog::create([
                'organization_id' => $orgId,
                'actor_user_id' => (int) $user->id,
                'action' => self::APPROVED_ACTION,
                'entity_type' => 'stock_adjustment',
                'entity_id' => $adj->id,
                'document_ref' => $adj->adjustment_number,
                'before' => ['status' => 'draft', 'requested_by' => $creator, 'lines' => $preview['lines']],
                'after' => ['operation_id' => (string) Str::uuid(), 'snapshot_hash' => $preview['snapshot_hash'], 'approved_by' => (int) $user->id,
                    'warnings' => $preview['warnings'], 'note' => $note === null ? null : mb_substr(trim($note), 0, 500)],
                'ip' => request()?->ip(),
                'created_at' => now(),
            ]);

            return $this->preview($adj);
        });
    }

    /**
     * Ledger-time check (called by InactiveItemPolicy for a write_off movement, inside the posting transaction
     * after the balance lock): permitted poster, valid approval by another person, no negative balance of the item.
     */
    public function assertPostable(int $adjustmentId, int $itemId): array
    {
        $user = self::actor();
        self::assertPermitted($user);
        if ($this->itemHasNegativeBalance($itemId)) {
            throw self::negativeElsewhere($itemId);
        }
        $adj = StockAdjustment::query()->withoutGlobalScopes()->findOrFail($adjustmentId);
        $approval = $this->currentApproval($adj);
        if ($approval === null) {
            abort(403, 'inactive_write_off_approval_required');
        }
        if ((int) $approval['approved_by'] === (int) $user->id) {
            abort(403, 'inactive_write_off_self_approval_forbidden');
        }

        return $approval;
    }

    /** UI hint (the server re-checks on approve): permitted, not the draft's creator, no valid approval yet. */
    public function canApprove(StockAdjustment $adj, ?object $user): bool
    {
        if ($user === null || $adj->status !== 'draft' || $this->currentApproval($adj) !== null
            || ! app(InventoryPermissionService::class)->can($user, self::PERMISSION)) {
            return false;
        }

        return $this->creatorId($adj) !== (int) $user->id;
    }

    /** Latest approval whose snapshot still matches the draft, or null. */
    public function currentApproval(StockAdjustment $adj): ?array
    {
        $row = InventoryAuditLog::query()->withoutGlobalScopes()->where('organization_id', $adj->organization_id)
            ->where('action', self::APPROVED_ACTION)->where('entity_type', 'stock_adjustment')->where('entity_id', $adj->id)
            ->orderByDesc('id')->first();
        if (! $row || ($row->after['snapshot_hash'] ?? null) !== $this->snapshotHash($adj)) {
            return null;
        }
        $creator = $this->creatorId($adj);
        if ($creator !== null && $creator === (int) $row->actor_user_id) {
            return null;
        }

        return ['audit_id' => (int) $row->id, 'operation_id' => $row->after['operation_id'] ?? null,
            'approved_by' => (int) $row->actor_user_id, 'approved_at' => $row->created_at?->toIso8601String(), 'snapshot_hash' => $row->after['snapshot_hash']];
    }

    private function creatorId(StockAdjustment $adj): ?int
    {
        $id = InventoryAuditLog::query()->withoutGlobalScopes()->where('organization_id', $adj->organization_id)
            ->where('action', 'stock_adjustment.create')->where('entity_type', 'stock_adjustment')->where('entity_id', $adj->id)
            ->orderBy('id')->value('actor_user_id') ?? $adj->created_by;

        return $id === null ? null : (int) $id;
    }

    private function onHand(int $itemId, int $warehouseId, array $line, bool $lock = false): string
    {
        $query = DB::connection('tenant')->table('stock_balances')->where('item_id', $itemId)->where('warehouse_id', $warehouseId);
        foreach (['variant_id', 'lot_id', 'bin_id'] as $column) {
            if (($line[$column] ?? null) !== null) {
                $query->where($column, $line[$column]);
            }
        }
        if ($lock) {
            $query->sharedLock();
        }

        return (string) ($query->sum('on_hand_qty') ?? '0');
    }

    public function itemHasNegativeBalance(int $itemId): bool
    {
        return DB::connection('tenant')->table('stock_balances')->where('item_id', $itemId)->where('on_hand_qty', '<', 0)->exists();
    }

    private static function actor(): object
    {
        $user = request()?->user() ?? auth()->user();
        abort_if($user === null, 403, 'inactive_write_off_permission_required');

        return $user;
    }

    private static function assertPermitted(object $user): void
    {
        abort_unless(app(InventoryPermissionService::class)->can($user, self::PERMISSION), 403, 'inactive_write_off_permission_required');
    }

    private static function invalid(string $key): ValidationException
    {
        return ValidationException::withMessages(['stock_adjustment' => [__('inventory.catalog.'.$key)]]);
    }

    private static function negativeElsewhere(int $itemId): ValidationException
    {
        return ValidationException::withMessages(['item_id' => [__('inventory.catalog.inactive_write_off_negative_elsewhere', ['item' => $itemId])], 'inactive_item_ids' => [$itemId]]);
    }
}
