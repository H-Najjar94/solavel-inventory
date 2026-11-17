<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use App\Models\Tenant\ItemAttachment;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Services\Access\WarehouseAccessService;
use App\Services\Consumption\ConsumptionAccounts;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\StockLedgerService;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Atomic, bounded quantity-unit reclassification. Never changes old document/ledger rows. */
final class ItemBaseUnitConversion
{
    public const TABLE = 'item_base_unit_conversions';

    private function factor(string $numerator, string $denominator): string
    {
        foreach ([$numerator, $denominator] as $part) {
            if (! preg_match('/^[1-9][0-9]{0,8}$/D', $part)) {
                $this->issue('Choose positive integer conversion numerator and denominator (maximum 999999999).');
            }
        }

        return bcdiv($numerator, $denominator, 12);
    }

    public function assertMovementDate(int $itemId, string $date): void
    {
        $db = DB::connection('tenant');
        if (! $db->getSchemaBuilder()->hasTable(self::TABLE)) {
            return;
        }
        $latest = $db->table(self::TABLE)->where('organization_id', app(OrganizationContext::class)->idOrFail())->where('item_id', $itemId)->max('effective_date');
        if ($latest && substr($date, 0, 10) < $latest) {
            throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.closed_epoch', ['date' => $latest])]);
        }
    }

    private function blockerMessage(string $code): string
    {
        $key = 'inventory.base_unit.conversion_blockers.'.$code;
        $message = __($key);

        return $message === $key ? __('inventory.base_unit.dependency_blocked') : $message;
    }

    private function db(Item $item)
    {
        return $item->getConnection();
    }

    private function issue(string $reason): never
    {
        throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.conversion_error')]);
    }

    private function snapshot(Item $item): array
    {
        return ['item' => $item->getAttributes(),
            'balances' => StockBalance::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->orderBy('id')->get()->map->getAttributes()->all(),
            'reorder_rules' => $this->db($item)->table('warehouse_reorder_rules')->where('organization_id', $item->organization_id)->where('item_id', $item->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'layers' => $this->db($item)->table('cost_layers')->where('organization_id', $item->organization_id)->where('item_id', $item->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'last_ledger_id' => (int) StockLedger::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->max('id')];
    }

    public function preview(Item $item, int $target, string $numerator, string $denominator, string $effectiveDate): array
    {
        if ((int) auth()->id() > 0 && app(WarehouseAccessService::class)->allowedIds() !== null) {
            throw new AuthorizationException(__('inventory.base_unit.all_warehouse_authority'));
        }
        $factor = $this->factor($numerator, $denominator);
        $item = $item->fresh();
        $db = $this->db($item);
        $reasons = [];
        if (! $db->getSchemaBuilder()->hasTable(self::TABLE)) {
            $reasons[] = 'conversion_schema_unavailable';
        }
        if ($item->item_type !== 'inventory' || ! in_array($item->effectiveCostingMethod(), ['average', 'fifo'], true) || $item->tracking_type !== 'none') {
            $reasons[] = 'only_average_or_unconsumed_fifo_untracked_supported';
        }
        if (! $item->base_unit_id || ! Unit::withTrashed()->whereKey($item->base_unit_id)->exists()) {
            $reasons[] = 'source_unit_missing';
        }
        if (! Unit::query()->whereKey($target)->where('is_active', true)->exists() || $target === (int) $item->base_unit_id) {
            $reasons[] = 'choose_different_active_unit';
        }
        if (! is_numeric($factor) || bccomp($factor, '0', 12) <= 0) {
            $reasons[] = 'positive_factor_required';
        }
        $mapping = null;
        try {
            $mapping = app(StockUnitProjection::class)->mapping($item, $target);
        } catch (\RuntimeException $e) {
            $reasons[] = $e->getMessage();
        }
        if ($mapping) {
            try {
                app(ConsumptionAccounts::class)->assertDate((int) $item->organization_id, $effectiveDate);
            } catch (ValidationException $e) {
                $reasons[] = 'connected_period_closed';
            }
        }
        try {
            app(StockUnitProjection::class)->assertReady($item->id);
        } catch (ValidationException $e) {
            $reasons[] = 'unit_projection_pending';
        }
        // Only immutable opening balances and prior conversions are supported initially.
        if (StockLedger::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->whereNotIn('source_type', [OpeningStockEntry::class, self::class])->exists()) {
            $reasons[] = 'other_movement_history_requires_reconciliation';
        }
        foreach (app(ItemBaseUnitGuard::class)->blockers($item) as $reason) {
            if (! in_array($reason, array_merge(['stock_history', 'stock_balance_or_value', 'opening_stock_entry_lines', 'cost_layers', 'warehouse_reorder_rules'], $mapping ? ['finance_catalog_mapping', 'finance_catalog_sync'] : []), true)) {
                $reasons[] = $reason;
            }
        }
        $schema = $db->getSchemaBuilder();
        if ($schema->hasTable('opening_stock_entry_lines') && $db->table('opening_stock_entry_lines as l')->join('opening_stock_entries as h', 'h.id', '=', 'l.opening_stock_entry_id')->where('l.organization_id', $item->organization_id)->where('l.item_id', $item->id)->where('h.status', 'draft')->exists()) {
            $reasons[] = 'draft_opening_document';
        }
        if (StockLedger::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->where(fn ($q) => $q->whereNotNull('bin_id')->orWhereNotNull('variant_id')->orWhereNotNull('lot_id')->orWhereNotNull('serial_id'))->exists()) {
            $reasons[] = 'tracked_or_bin_coordinate_requires_reconciliation';
        }
        $latest = StockLedger::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->max('moved_at');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $effectiveDate) || ($latest && $effectiveDate < substr((string) $latest, 0, 10))) {
            $reasons[] = 'effective_date_before_latest_stock';
        }
        foreach (StockLedger::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->where('source_type', OpeningStockEntry::class)->get() as $original) {
            $original = $this->normalizeOriginal($original);
            $q = bcdiv(bcmul($original->quantity, $numerator, 24), $denominator, 18);
            $cost = bcdiv(bcmul($original->unit_cost, $denominator, 24), $numerator, 18);
            if (bccomp($cost, bcadd($cost, '0', 6), 18) !== 0) {
                $reasons[] = 'historical_source_cost_precision_loss';
            }
            if (bccomp($q, bcadd($q, '0', 6), 18) !== 0) {
                $reasons[] = 'historical_source_quantity_precision_loss';
            }
        }
        if (StockLedger::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->where('source_type', OpeningStockEntry::class)->where('costing_method', '<>', $item->effectiveCostingMethod())->exists()) {
            $reasons[] = 'mixed_costing_history';
        }
        if ($effectiveDate > now()->toDateString()) {
            $reasons[] = 'future_conversion_date';
        }
        if ($item->is_variant_parent || $db->table('item_variants')->where('organization_id', $item->organization_id)->where('item_id', $item->id)->exists()) {
            $reasons[] = 'variant_metadata_requires_review';
        }
        foreach (['length', 'width', 'height'] as $dimension) {
            if (! empty($item->{$dimension}) && bccomp((string) $item->{$dimension}, '0', 6) !== 0) {
                $reasons[] = 'dimensions_require_review';
            }
        }
        $snapshot = $this->snapshot($item);
        $plan = [];
        if (is_numeric($factor) && bccomp($factor, '0', 12) > 0) {
            foreach ($snapshot['balances'] as $b) {
                $old = (string) $b['on_hand_qty'];
                $value = (string) $b['total_value'];
                if (bccomp((string) $b['reserved_qty'], '0', 6) !== 0 || bccomp($old, '0', 6) < 0) {
                    $reasons[] = 'reserved_or_negative_balance';

                    continue;
                }
                if (bccomp($old, '0', 6) === 0) {
                    if (bccomp($value, '0', 6) !== 0) {
                        $reasons[] = 'value_without_quantity';
                    }
                    $newCost = bcdiv(bcmul((string) $b['average_cost'], $denominator, 24), $numerator, 18);
                    if (bccomp($newCost, bcadd($newCost, '0', 6), 18) !== 0) {
                        $reasons[] = 'value_precision_loss';
                    }
                    $plan[] = ['balance_id' => (int) $b['id'], 'warehouse_id' => (int) $b['warehouse_id'], 'old_quantity' => $old, 'new_quantity' => '0.000000', 'value' => $value, 'new_unit_cost' => bcadd($newCost, '0', 6)];

                    continue;
                }
                $exact = bcdiv(bcmul($old, $numerator, 24), $denominator, 18);
                $qty = bcadd($exact, '0', 6);
                if (bccomp($exact, $qty, 18) !== 0 || bccomp($qty, '0', 6) <= 0) {
                    $reasons[] = 'quantity_precision_loss';

                    continue;
                }
                $cost = bcdiv($value, $qty, 6);
                if (bccomp(bcmul($qty, $cost, 12), $value, 6) !== 0) {
                    $reasons[] = 'value_precision_loss';

                    continue;
                }
                if (! empty($b['bin_id']) || ! empty($b['variant_id']) || ! empty($b['lot_id'])) {
                    $reasons[] = 'tracked_or_bin_coordinate_requires_reconciliation';

                    continue;
                }
                $plan[] = ['balance_id' => (int) $b['id'], 'warehouse_id' => (int) $b['warehouse_id'], 'warehouse_name' => Warehouse::withoutGlobalScopes()->where('organization_id', $item->organization_id)->whereKey($b['warehouse_id'])->value('name'), 'old_quantity' => $old, 'new_quantity' => $qty, 'value' => $value, 'new_unit_cost' => $cost];
            }
        }
        $layerPlan = [];
        foreach ($snapshot['layers'] as $layer) {
            if (! empty($layer['superseded_fifo_correction_id']) || bccomp((string) $layer['original_qty'], (string) $layer['remaining_qty'], 6) !== 0 || $db->table('cost_layer_consumptions')->where('organization_id', $item->organization_id)->where('cost_layer_id', $layer['id'])->exists()) {
                $reasons[] = 'consumed_or_superseded_fifo_layer';

                continue;
            }
            $quantity = bcdiv(bcmul((string) $layer['remaining_qty'], $numerator, 24), $denominator, 18);
            $cost = bcdiv(bcmul((string) $layer['unit_cost'], $denominator, 24), $numerator, 18);
            if (bccomp($quantity, bcadd($quantity, '0', 6), 18) !== 0 || bccomp($cost, bcadd($cost, '0', 6), 18) !== 0 || bccomp(bcmul($quantity, $cost, 18), bcmul((string) $layer['remaining_qty'], (string) $layer['unit_cost'], 18), 12) !== 0) {
                $reasons[] = 'fifo_layer_precision_loss';

                continue;
            }
            $layerPlan[] = ['id' => (int) $layer['id'], 'quantity' => bcadd($quantity, '0', 6), 'unit_cost' => bcadd($cost, '0', 6)];
        }
        $metadata = [];
        $rules = [];
        foreach (['reorder_point', 'reorder_qty', 'min_stock', 'max_stock', 'safety_stock'] as $field) {
            if ($item->{$field} !== null) {
                $value = bcdiv(bcmul((string) $item->{$field}, $numerator, 24), $denominator, 18);
                if (bccomp($value, bcadd($value, '0', 6), 18) !== 0) {
                    $reasons[] = 'catalog_quantity_precision_loss';
                } else {
                    $metadata[$field] = bcadd($value, '0', 6);
                }
            }
        }
        foreach (['purchase_price', 'sales_price', 'weight'] as $field) {
            if ($item->{$field} !== null) {
                $value = bcdiv(bcmul((string) $item->{$field}, $denominator, 24), $numerator, 18);
                if (bccomp($value, bcadd($value, '0', 4), 18) !== 0) {
                    $reasons[] = 'catalog_price_precision_loss';
                } else {
                    $metadata[$field] = bcadd($value, '0', 4);
                }
            }
        }
        foreach ($snapshot['reorder_rules'] as $rule) {
            $row = ['id' => $rule['id']];
            foreach (['reorder_point', 'reorder_qty', 'min_stock', 'max_stock', 'safety_stock'] as $field) {
                if ($rule[$field] !== null) {
                    $value = bcdiv(bcmul((string) $rule[$field], $numerator, 24), $denominator, 18);
                    if (bccomp($value, bcadd($value, '0', 6), 18) !== 0) {
                        $reasons[] = 'catalog_quantity_precision_loss';
                    } else {
                        $row[$field] = bcadd($value, '0', 6);
                    }
                }
            } $rules[] = $row;
        }
        if (array_filter($plan, fn ($b) => bccomp($b['old_quantity'], '0', 6) > 0) === []) {
            $reasons[] = 'positive_existing_stock_required';
        }

        return ['supported' => $reasons === [], 'blockers' => array_values(array_unique($reasons)), 'blocker_messages' => array_map(fn ($code) => $this->blockerMessage($code), array_values(array_unique($reasons))), 'from_unit_id' => (int) $item->base_unit_id, 'to_unit_id' => $target, 'factor' => $factor, 'factor_numerator' => $numerator, 'factor_denominator' => $denominator, 'effective_date' => $effectiveDate, 'revision_sha256' => hash('sha256', json_encode([$snapshot, $target, $numerator, $denominator, $effectiveDate], JSON_THROW_ON_ERROR)), 'snapshot' => $snapshot, 'balances' => $plan, 'layers' => $layerPlan, 'metadata' => $metadata, 'reorder_rules' => $rules, 'organization_mapping_uuid' => $mapping?->mapping_uuid];
    }

    public function apply(Item $item, int $target, string $numerator, string $denominator, string $revision, string $request, string $reason, string $effectiveDate, array $evidence): array
    {
        if (is_file('/etc/solavel/maintenance/unit-conversions-paused')) {
            $this->issue('Unit conversions are temporarily paused for verified release activation.');
        }
        $factor = $this->factor($numerator, $denominator);
        if (empty($evidence['reference']) || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($evidence['sha256'] ?? ''))) {
            $this->issue('Verified conversion evidence reference and SHA256 are required.');
        }
        if (! Str::isUuid($request) || trim($reason) === '') {
            $this->issue('A unique request and documented reason are required.');
        }

        return $this->db($item)->transaction(function () use ($item, $target, $factor, $numerator, $denominator, $revision, $request, $reason, $effectiveDate, $evidence) {
            $item = Item::query()->lockForUpdate()->findOrFail($item->id);
            $db = $this->db($item);
            $existing = $db->table(self::TABLE)->where('organization_id', $item->organization_id)->where('request_uuid', $request)->first();
            if ($existing) {
                if ($existing->revision_sha256 !== $revision || $existing->reason !== $reason || SolaStockJournalContract::payloadHash(json_decode($existing->evidence, true, 512, JSON_THROW_ON_ERROR)) !== SolaStockJournalContract::payloadHash($evidence)) {
                    $this->issue('Idempotency documentary input conflict.');
                }
                if ((int) $existing->item_id !== $item->id || (int) $existing->to_unit_id !== $target || (string) $existing->factor_numerator !== $numerator || (string) $existing->factor_denominator !== $denominator || $existing->effective_date !== $effectiveDate) {
                    $this->issue('Idempotency request conflicts with its original conversion.');
                }

                $result = json_decode($existing->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $result['state'] = $existing->state === 'ready' ? 'ready' : 'finance_pending';

                return $result;
            }
            if (! empty($evidence['attachment_id'])) {
                $attachment = ItemAttachment::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->lockForUpdate()->find($evidence['attachment_id']);
                if (! $attachment || ! Storage::disk('local')->exists($attachment->path) || ! hash_equals($evidence['sha256'], hash('sha256', Storage::disk('local')->get($attachment->path)))) {
                    $this->issue('evidence_mismatch');
                }
            }
            StockBalance::withoutGlobalScopes()->where('organization_id', $item->organization_id)->where('item_id', $item->id)->orderBy('id')->lockForUpdate()->get();
            $db->table('cost_layers')->where('organization_id', $item->organization_id)->where('item_id', $item->id)->orderBy('id')->lockForUpdate()->get();
            $db->table('warehouse_reorder_rules')->where('organization_id', $item->organization_id)->where('item_id', $item->id)->orderBy('id')->lockForUpdate()->get();
            $plan = $this->preview($item, $target, $numerator, $denominator, $effectiveDate);
            if (! $plan['supported']) {
                throw ValidationException::withMessages(['base_unit_conversion' => implode(' ', $plan['blocker_messages'])]);
            }
            if (! hash_equals($plan['revision_sha256'], $revision)) {
                $this->issue('Stock changed after preview. Review the conversion again.');
            }
            if ($plan['organization_mapping_uuid'] && ! (int) auth()->id()) {
                $this->issue('An authorized connected actor is required.');
            }
            $sourceBefore = app(CatalogSourceSnapshot::class)->capture('item', $item->id, $item->organization_id);
            $id = $db->table(self::TABLE)->insertGetId(['organization_id' => $item->organization_id, 'item_id' => $item->id, 'request_uuid' => $request, 'from_unit_id' => $item->base_unit_id, 'to_unit_id' => $target, 'factor' => $factor, 'factor_numerator' => $numerator, 'factor_denominator' => $denominator, 'effective_date' => $effectiveDate, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'prior_ledger_id' => $plan['snapshot']['last_ledger_id'], 'revision_sha256' => $revision, 'before_snapshot' => json_encode($plan['snapshot'], JSON_THROW_ON_ERROR), 'created_by' => auth()->id(), 'reason' => $reason, 'created_at' => now()]);
            $ids = app(StockLedgerService::class)->reclassifyBaseUnit($item, $plan, $id, $effectiveDate);
            // Owner-only mutation, locked together with all current projections.
            $db->table('items')->where('organization_id', $item->organization_id)->where('id', $item->id)->update(['base_unit_id' => $target, 'updated_at' => now()] + $plan['metadata']);
            foreach ($plan['reorder_rules'] as $rule) {
                $id = $rule['id'];
                unset($rule['id']);
                if ($rule) {
                    $db->table('warehouse_reorder_rules')->where('organization_id', $item->organization_id)->where('item_id', $item->id)->where('id', $id)->update($rule);
                }
            }
            $result = ['event_id' => $id, 'request_uuid' => $request, 'from_unit_id' => $plan['from_unit_id'], 'to_unit_id' => $target, 'factor' => $factor, 'ledger_ids' => $ids, 'snapshot' => $this->snapshot($item->fresh())];
            $changes = ['after_snapshot' => json_encode($result, JSON_THROW_ON_ERROR), 'ledger_ids' => json_encode($ids, JSON_THROW_ON_ERROR)];
            if ($plan['organization_mapping_uuid']) {
                $sourceAfter = app(CatalogSourceSnapshot::class)->capture('item', $item->id, $item->organization_id);
                $payload = ['schema_version' => 'stock.base_unit_conversion.v1', 'event_uuid' => $request, 'organization_mapping_uuid' => $plan['organization_mapping_uuid'], 'source_item_id' => $item->id, 'from_unit_id' => $plan['from_unit_id'], 'to_unit_id' => $target, 'ratio_numerator' => $numerator, 'ratio_denominator' => $denominator, 'effective_date' => $effectiveDate, 'revision_before' => CatalogSourceSnapshot::revision($sourceBefore), 'revision_after' => CatalogSourceSnapshot::revision($sourceAfter), 'before' => $sourceBefore, 'after' => $sourceAfter, 'evidence' => $evidence, 'boundary_ledger_ids' => $ids];
                $payload['snapshot_hash'] = SolaStockJournalContract::payloadHash($payload);
                $changes += ['organization_mapping_uuid' => $plan['organization_mapping_uuid'], 'state' => 'finance_pending', 'finance_payload' => json_encode($payload, JSON_THROW_ON_ERROR)];
                // Freeze ordinary catalog delivery until the typed epoch projection is acknowledged.
                $db->table(DurableCatalogSync::TABLE)->where('organization_mapping_uuid', $plan['organization_mapping_uuid'])->where('entity_type', 'item')->where('source_id', $item->id)->update(['state' => 'unit_transition_pending', 'updated_at' => now()]);
                $result['state'] = 'finance_pending';
                $changes['after_snapshot'] = json_encode($result, JSON_THROW_ON_ERROR);
            } else {
                $result['state'] = 'ready';
                $changes['after_snapshot'] = json_encode($result, JSON_THROW_ON_ERROR);
            }
            $db->table(self::TABLE)->where('id', $id)->update($changes);

            return $result;
        });
    }

    public function isBoundaryRow(StockLedger $row): bool
    {
        if ($row->source_type !== self::class) {
            return false;
        }
        $event = $row->getConnection()->table(self::TABLE)->where('organization_id', $row->organization_id)->where('item_id', $row->item_id)->where('id', $row->source_id)->first();

        return $event && in_array((int) $row->id, json_decode($event->ledger_ids ?? '[]', true, 512, JSON_THROW_ON_ERROR), true);
    }

    public function unitAtDate(int $item, int $org, string $date): ?string
    {
        $db = DB::connection('tenant');
        $unit = $db->table('items')->where('organization_id', $org)->where('id', $item)->value('base_unit_id');
        if ($db->getSchemaBuilder()->hasTable(self::TABLE)) {
            $next = $db->table(self::TABLE)->where('organization_id', $org)->where('item_id', $item)->where('effective_date', '>', $date)->orderBy('id')->first();
            if ($next) {
                $unit = $next->from_unit_id;
            }
        }

        return Unit::withoutGlobalScopes()->where('organization_id', $org)->whereKey($unit)->value('name');
    }

    public function hasLaterConversion(StockLedger $row): bool
    {
        $db = $row->getConnection();

        return $db->getSchemaBuilder()->hasTable(self::TABLE) && $db->table(self::TABLE)->where('organization_id', $row->organization_id)->where('item_id', $row->item_id)->where('prior_ledger_id', '>=', $row->id)->exists();
    }

    public function movementUnit(StockLedger $row): array
    {
        $db = $row->getConnection();
        $unit = (int) ($row->item?->base_unit_id ?? 0);
        if ($db->getSchemaBuilder()->hasTable(self::TABLE)) {
            if ($row->source_type === self::class) {
                $event = $db->table(self::TABLE)->where('organization_id', $row->organization_id)->where('item_id', $row->item_id)->where('id', $row->source_id)->first();
                if ($event) {
                    $unit = (int) ($row->direction === 'out' ? $event->from_unit_id : $event->to_unit_id);
                }
            } else {
                $next = $db->table(self::TABLE)->where('organization_id', $row->organization_id)->where('item_id', $row->item_id)->where('prior_ledger_id', '>=', $row->id)->orderBy('id')->first();
                if ($next) {
                    $unit = (int) $next->from_unit_id;
                }
            }
        }

        return ['quantity_unit_id' => $unit, 'quantity_unit_name' => Unit::withTrashed()->find($unit)?->name, 'unit_conversion_boundary' => $row->source_type === self::class];
    }

    public function normalizeOriginal(StockLedger $original): StockLedger
    {
        $db = $original->getConnection();
        if (! $db->getSchemaBuilder()->hasTable(self::TABLE)) {
            return $original;
        }
        $events = $db->table(self::TABLE)->where('organization_id', $original->organization_id)->where('item_id', $original->item_id)->where('prior_ledger_id', '>=', $original->id)->orderBy('id')->get();
        if ($events->isEmpty()) {
            return $original;
        }
        if ($original->source_type !== OpeningStockEntry::class || $original->direction !== 'in') {
            $this->issue('This historical source requires a reviewed unit-aware reversal.');
        }
        $copy = clone $original;
        foreach ($events as $e) {
            $copy->quantity = bcmul($copy->quantity, $e->factor, 6);
            $copy->unit_cost = bcdiv($copy->unit_cost, $e->factor, 6);
        }

        return $copy;
    }
}
