<?php

namespace App\Services\Portal;

use App\Services\Access\CentralPermissionConstraints;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Explicit organization predicates, never ambient session IDs or numeric fallback mappings. */
final class OrganizationSummary
{
    public function mappedOrganization(int $clientId, int $centralOrg, int $actorId): int
    {
        $db = DB::connection((string) config('tenancy.central_connection', 'mysql'));
        abort_unless($db->table('organizations')->where('id', $centralOrg)->where('client_id', $clientId)
            ->where('is_active', true)->whereNull('deleted_at')->exists(), 403);
        abort_unless($db->table('clients')->where('id', $clientId)->where('is_active', true)->whereNull('deleted_at')->exists(), 403);
        abort_unless($db->table('user_organizations as ou')->join('users as u', 'u.id', '=', 'ou.user_id')
            ->where('ou.organization_id', $centralOrg)->where('ou.user_id', $actorId)
            ->where('u.status', 'active')->whereNull('u.deleted_at')->whereIn('ou.role', ['owner', 'client_owner'])
            ->where(fn ($q) => $q->where('ou.status', 'active')->orWhereNull('ou.status'))->count() === 1, 403);
        // Stock records use Central IDs, NOT Finance's tenant-local organization IDs.
        return $centralOrg;
    }

    private function scoped(string $table, int $orgId): Builder
    {
        $db = DB::connection('tenant');
        $columns = $db->getSchemaBuilder()->getColumnListing($table);
        abort_unless(in_array('organization_id', $columns, true), 503);
        $query = $db->table($table)->where($table.'.organization_id', $orgId);
        if (in_array('deleted_at', $columns, true)) $query->whereNull($table.'.deleted_at');
        return $query;
    }

    public function counts(int $orgId, array $access): array
    {
        return [
            'items'=>$this->visible($access, 'inventory.view_items', fn () => $this->scoped('items', $orgId)->count()),
            'warehouses'=>$this->visible($access, 'inventory.view_warehouses', fn () => $this->scoped('warehouses', $orgId)->count()),
            'low_stock_items'=>$this->visible($access, 'inventory.view_reports', fn () => $this->lowStock($orgId)),
        ];
    }

    private function lowStock(int $orgId): int
    {
        $db = DB::connection('tenant');
        // Sum bins/lots/variants before comparing the warehouse's effective reorder point.
        $balances = $db->table('stock_balances')->where('organization_id', $orgId)
            ->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, SUM(on_hand_qty - reserved_qty) AS available');
        $low = $this->scoped('items', $orgId)->where('items.is_active', true)
            ->join('warehouses as w', function ($join) use ($orgId) {
                $join->where('w.organization_id', $orgId)->where('w.is_active', true)->whereNull('w.deleted_at');
            })
            ->leftJoinSub($balances, 'b', fn ($j) => $j->on('b.item_id', '=', 'items.id')->on('b.warehouse_id', '=', 'w.id'))
            ->leftJoin('warehouse_reorder_rules as rr', function ($join) use ($orgId) {
                $join->on('rr.item_id', '=', 'items.id')->on('rr.warehouse_id', '=', 'w.id')
                    ->where('rr.organization_id', $orgId)->where('rr.is_active', true);
            })
            ->whereNotNull($db->raw('COALESCE(rr.reorder_point, items.reorder_point)'))
            ->whereRaw('COALESCE(b.available, 0) <= COALESCE(rr.reorder_point, items.reorder_point)')
            ->select('items.id')->distinct();
        return $db->query()->fromSub($low, 'low_items')->count();
    }

    private function visible(array $access, string $permission, \Closure $count): ?int
    {
        // Aggregate data cannot ignore resource-scoped explicit denials.
        return CentralPermissionConstraints::denied($access, $permission) ? null : (int) $count();
    }
}
