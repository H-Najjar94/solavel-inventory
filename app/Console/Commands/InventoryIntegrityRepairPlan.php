<?php

namespace App\Console\Commands;

use App\Services\Stock\IntegrityRepairService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryIntegrityRepairPlan extends Command
{
    protected $signature = 'inventory:integrity-repair-plan
        {--org= : Organization id}
        {--database= : Explicit tenant database override}
        {--apply : Apply safe balance recalculations only}
        {--backup-verified : Required with --apply}
        {--json : Print machine-readable JSON}';

    protected $description = 'Dry-run inventory integrity repair plan for one tenant/org';

    public function handle(TenantManager $tenants, OrganizationContext $context, IntegrityRepairService $repairs): int
    {
        $org = (int) $this->option('org');
        if ($org <= 0) {
            $this->error('Provide --org=<organization id>.');

            return self::FAILURE;
        }

        $connection = (string) config('tenancy.tenant_connection', 'tenant');
        $database = $this->option('database') ?: null;
        $currentDatabase = (string) DB::connection($connection)->getDatabaseName();
        $alreadyUsingTenant = $database
            && $currentDatabase === $database
            && $context->has()
            && (int) $context->id() === $org;

        if (! $alreadyUsingTenant) {
            $tenants->useTenant($org, $database);
        }

        $plan = $this->buildPlan($connection, $org);

        if ($this->option('apply')) {
            if (! $this->option('backup-verified')) {
                $this->error('Refusing --apply without --backup-verified.');

                return self::FAILURE;
            }

            $plan['applied'] = $repairs->applySafeBalanceRepairs($connection, $org, $plan);
        }

        if ($this->option('json')) {
            $this->line(json_encode($plan, JSON_PRETTY_PRINT));
        } else {
            $this->renderPlan($plan);
        }

        return ($plan['summary']['blocking_issue_count'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function buildPlan(string $connection, int $org): array
    {
        $ledger = $this->ledgerCoordinates($connection, $org);
        $missingBalances = [];
        $balanceRecalculations = [];

        foreach ($ledger as $row) {
            $balance = $this->balanceFor($connection, $org, $row);
            $itemExists = DB::connection($connection)->table('items')
                ->where('organization_id', $org)
                ->where('id', $row->item_id)
                ->exists();
            $warehouseExists = $row->warehouse_id === null || DB::connection($connection)->table('warehouses')
                ->where('organization_id', $org)
                ->where('id', $row->warehouse_id)
                ->exists();

            $record = [
                'item_id' => (int) $row->item_id,
                'warehouse_id' => $row->warehouse_id !== null ? (int) $row->warehouse_id : null,
                'variant_id' => (int) $row->variant_key ?: null,
                'lot_id' => (int) $row->lot_key ?: null,
                'bin_id' => (int) $row->bin_key ?: null,
                'ledger_qty' => (string) $row->net_qty,
                'ledger_value' => (string) $row->net_value,
                'item_exists' => $itemExists,
                'warehouse_exists' => $warehouseExists,
            ];

            if (! $balance) {
                if (abs((float) $row->net_qty) > 0.0001) {
                    $record['applyable'] = $itemExists && $warehouseExists;
                    $record['action'] = $record['applyable']
                        ? 'create_stock_balance_from_ledger'
                        : 'blocked_missing_item_or_warehouse';
                    $missingBalances[] = $record;
                }

                continue;
            }

            $qtyDiff = (float) $row->net_qty - (float) $balance->on_hand_qty;
            $valueDiff = (float) $row->net_value - (float) $balance->total_value;
            if (abs($qtyDiff) > 0.0001 || abs($valueDiff) > 0.01) {
                $record += [
                    'balance_id' => (int) $balance->id,
                    'balance_qty' => (string) $balance->on_hand_qty,
                    'balance_value' => (string) $balance->total_value,
                    'qty_diff' => round($qtyDiff, 4),
                    'value_diff' => round($valueDiff, 4),
                    'applyable' => $itemExists && $warehouseExists,
                    'action' => $itemExists && $warehouseExists
                        ? 'recalculate_stock_balance_from_ledger'
                        : 'blocked_missing_item_or_warehouse',
                ];
                $balanceRecalculations[] = $record;
            }
        }

        $orphanLedger = DB::connection($connection)->table('stock_ledger as l')
            ->leftJoin('items as i', fn ($join) => $join->on('i.id', '=', 'l.item_id')->on('i.organization_id', '=', 'l.organization_id'))
            ->leftJoin('warehouses as w', fn ($join) => $join->on('w.id', '=', 'l.warehouse_id')->on('w.organization_id', '=', 'l.organization_id'))
            ->where('l.organization_id', $org)
            ->where(fn ($query) => $query
                ->whereNull('i.id')
                ->orWhere(fn ($q) => $q->whereNotNull('l.warehouse_id')->whereNull('w.id')))
            ->get(['l.id as ledger_id', 'l.item_id', 'l.warehouse_id', 'l.direction', 'l.quantity', 'l.source_type', 'l.source_id'])
            ->map(fn ($row) => (array) $row)
            ->all();

        $invalidLines = DB::connection($connection)->select($this->invalidLineSql(), [$org]);
        $invalidPoRefs = DB::connection($connection)->select(
            'select po.id purchase_order_id, po.po_number, po.supplier_id, po.warehouse_id
             from inventory_purchase_orders po
             left join inventory_suppliers s on s.id = po.supplier_id and s.organization_id = po.organization_id
             left join warehouses w on w.id = po.warehouse_id and w.organization_id = po.organization_id
             where po.organization_id = ? and (s.id is null or (po.warehouse_id is not null and w.id is null))',
            [$org]
        );

        $fifoLayerRecalculations = DB::connection($connection)->select(
            'select cl.id cost_layer_id, cl.item_id, cl.warehouse_id, cl.original_qty,
                    coalesce(c.consumed_qty, 0) consumed_qty, cl.remaining_qty,
                    (cl.original_qty - coalesce(c.consumed_qty, 0) - cl.remaining_qty) diff
             from cost_layers cl
             left join (
                select cost_layer_id, sum(qty) consumed_qty
                from cost_layer_consumptions
                where organization_id = ?
                group by cost_layer_id
             ) c on c.cost_layer_id = cl.id
             where cl.organization_id = ?
             having abs(diff) > 0.0001',
            [$org, $org]
        );

        $valuationMismatches = DB::connection($connection)->select(
            'select b.id balance_id, b.item_id, b.warehouse_id, i.costing_method,
                    b.on_hand_qty, coalesce(cl.layer_remaining, 0) layer_remaining,
                    b.total_value, coalesce(cl.layer_value, 0) layer_value,
                    (b.on_hand_qty - coalesce(cl.layer_remaining, 0)) qty_diff,
                    (b.total_value - coalesce(cl.layer_value, 0)) value_diff
             from stock_balances b
             left join items i on i.id = b.item_id and i.organization_id = b.organization_id
             left join (
                select item_id, warehouse_id, sum(remaining_qty) layer_remaining, sum(remaining_qty * unit_cost) layer_value
                from cost_layers
                where organization_id = ?
                group by item_id, warehouse_id
             ) cl on cl.item_id = b.item_id and ((cl.warehouse_id = b.warehouse_id) or (cl.warehouse_id is null and b.warehouse_id is null))
             where b.organization_id = ?
             having abs(qty_diff) > 0.0001 or abs(value_diff) > 0.01',
            [$org, $org]
        );

        $blocking = count($orphanLedger) + count($invalidLines) + count($invalidPoRefs) + count($fifoLayerRecalculations);

        return [
            'database' => DB::connection($connection)->getDatabaseName(),
            'organization_id' => $org,
            'mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'summary' => [
                'missing_balance_rows' => count($missingBalances),
                'balance_recalculations' => count($balanceRecalculations),
                'orphan_ledger_refs' => count($orphanLedger),
                'invalid_document_item_refs' => count($invalidLines),
                'invalid_purchase_order_refs' => count($invalidPoRefs),
                'fifo_layer_recalculations' => count($fifoLayerRecalculations),
                'valuation_mismatches' => count($valuationMismatches),
                'blocking_issue_count' => $blocking,
            ],
            'missing_balance_rows' => $missingBalances,
            'balance_recalculations' => $balanceRecalculations,
            'orphan_ledger_refs' => $orphanLedger,
            'invalid_document_item_refs' => array_map(fn ($row) => (array) $row, $invalidLines),
            'invalid_purchase_order_refs' => array_map(fn ($row) => (array) $row, $invalidPoRefs),
            'fifo_layer_recalculations' => array_map(fn ($row) => (array) $row, $fifoLayerRecalculations),
            'valuation_mismatches' => array_map(fn ($row) => (array) $row, $valuationMismatches),
        ];
    }

    private function ledgerCoordinates(string $connection, int $org)
    {
        return DB::connection($connection)->table('stock_ledger')
            ->where('organization_id', $org)
            ->selectRaw(
                'item_id, coalesce(variant_id, 0) variant_key, warehouse_id, coalesce(lot_id, 0) lot_key, coalesce(bin_id, 0) bin_key,
                 sum(case when direction = "in" then quantity else -quantity end) net_qty,
                 sum(case when direction = "in" then total_cost else -total_cost end) net_value'
            )
            ->groupBy('item_id', 'variant_key', 'warehouse_id', 'lot_key', 'bin_key')
            ->get();
    }

    private function balanceFor(string $connection, int $org, object $row): ?object
    {
        return DB::connection($connection)->table('stock_balances')
            ->where('organization_id', $org)
            ->where('item_id', $row->item_id)
            ->whereRaw('coalesce(variant_id, 0) = ?', [$row->variant_key])
            ->where('warehouse_id', $row->warehouse_id)
            ->whereRaw('coalesce(lot_id, 0) = ?', [$row->lot_key])
            ->whereRaw('coalesce(bin_id, 0) = ?', [$row->bin_key])
            ->first();
    }

    private function invalidLineSql(): string
    {
        return 'select x.src, x.id, x.parent_id, x.item_id
            from (
                select "po" src, id, purchase_order_id parent_id, item_id, organization_id from purchase_order_lines
                union all select "grn", id, goods_receipt_id, item_id, organization_id from goods_receipt_lines
                union all select "opening", id, opening_stock_entry_id, item_id, organization_id from opening_stock_entry_lines
                union all select "transfer", id, stock_transfer_id, item_id, organization_id from stock_transfer_lines
                union all select "adjustment", id, stock_adjustment_id, item_id, organization_id from stock_adjustment_lines
                union all select "count", id, stock_count_id, item_id, organization_id from stock_count_lines
                union all select "so", id, sales_order_id, item_id, organization_id from sales_order_lines
            ) x
            left join items i on i.id = x.item_id and i.organization_id = x.organization_id
            where x.organization_id = ? and i.id is null';
    }

    private function renderPlan(array $plan): void
    {
        $this->info("Inventory integrity repair plan for {$plan['database']} / org {$plan['organization_id']} ({$plan['mode']})");
        foreach ($plan['summary'] as $key => $value) {
            $this->line("{$key}: {$value}");
        }

        if (($plan['summary']['blocking_issue_count'] ?? 0) > 0) {
            $this->warn('Blocking references exist. Do not apply balance repair until orphan/invalid references are resolved.');
        }
    }
}
