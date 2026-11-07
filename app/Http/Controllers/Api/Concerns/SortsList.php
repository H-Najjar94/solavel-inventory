<?php

namespace App\Http\Controllers\Api\Concerns;

use Closure;
use Illuminate\Http\Request;

/**
 * Whitelisted column sorting for paged list endpoints (`?sort=<key>&dir=asc|desc`).
 *
 * The map is public column key => SQL column (e.g. 'items.name') or a Closure
 * receiving ($query, $direction) for ordering that needs a subquery. Unknown
 * keys are ignored so the endpoint keeps its default order. Raw input is never
 * interpolated: only the whitelisted value reaches the query.
 */
trait SortsList
{
    /** Resolve a validated [key, direction] pair, or null for the default order. */
    protected function resolveListSort(Request $request, array $map): ?array
    {
        $key = $request->query('sort');
        if (! is_string($key) || $key === '' || ! array_key_exists($key, $map)) {
            return null;
        }
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        return [$key, $dir];
    }

    /**
     * Replace the default order with the requested one. NULLs sort last and the
     * tiebreaker keeps paging deterministic.
     */
    protected function applyListSort($query, Request $request, array $map, string $tiebreaker = 'id')
    {
        $resolved = $this->resolveListSort($request, $map);
        if ($resolved === null) {
            return $query;
        }
        [$key, $dir] = $resolved;
        $target = $map[$key];

        $query->reorder();
        if ($target instanceof Closure) {
            $target($query, $dir);
        } else {
            $wrapped = $query->getQuery()->getGrammar()->wrap($target);
            $query->orderByRaw("({$wrapped} is null) asc")->orderBy($target, $dir);
        }
        $query->orderBy($tiebreaker, $dir);

        return $query;
    }

    /** Whitelist entry ordering by a column of a related table (e.g. warehouse name via warehouse_id). */
    protected function sortByRelated(string $table, string $column, string $foreignKey): Closure
    {
        return function ($query, string $dir) use ($table, $column, $foreignKey): void {
            $sub = \Illuminate\Support\Facades\DB::table($table)->select($column)
                ->whereColumn("{$table}.id", $query->getModel()->getTable().'.'.$foreignKey)->limit(1);
            $query->orderByRaw('('.$sub->toSql().') is null', $sub->getBindings())->orderBy($sub, $dir);
        };
    }

    /** Whitelist entry ordering by a raw, developer-written SQL expression (never request input). */
    protected function sortByExpression(string $sql): Closure
    {
        return function ($query, string $dir) use ($sql): void {
            $query->orderByRaw("({$sql}) is null asc")->orderByRaw("({$sql}) ".($dir === 'desc' ? 'desc' : 'asc'));
        };
    }
}
