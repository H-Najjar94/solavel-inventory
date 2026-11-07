<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whitelisted column sorting for paged list endpoints (`?sort=<key>&dir=asc|desc`).
 *
 * The map is public key => SQL column, or a spec from sortByRelated() /
 * sortByExpression(). Unknown keys are ignored, and a mapping whose columns
 * cannot be verified in the schema falls back to the endpoint's default order
 * (a sort request must never cause a 500). Raw input never reaches SQL.
 */
trait SortsList
{
    /** @var array<string, bool> */
    private static array $sortColumnCache = [];

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

    protected function sortColumnExists(string $connection, string $table, string $column): bool
    {
        $cacheKey = "{$connection}.{$table}.{$column}";
        if (! array_key_exists($cacheKey, self::$sortColumnCache)) {
            try {
                self::$sortColumnCache[$cacheKey] = Schema::connection($connection)->hasColumn($table, $column);
            } catch (Throwable) {
                self::$sortColumnCache[$cacheKey] = false;
            }
        }

        return self::$sortColumnCache[$cacheKey];
    }

    /**
     * Replace the default order with the requested one (NULLs last, stable
     * tiebreaker). The query is left untouched unless a valid, verifiable sort applies.
     */
    protected function applyListSort($query, Request $request, array $map, string $tiebreaker = 'id')
    {
        $resolved = $this->resolveListSort($request, $map);
        if ($resolved === null) {
            return $query;
        }
        [$key, $dir] = $resolved;
        $target = $map[$key];

        try {
            $connection = $query->getQuery()->getConnection()->getName();
            $table = $query->getModel()->getTable();
            $grammar = $query->getQuery()->getGrammar();
            if (! $this->sortColumnExists($connection, $table, $tiebreaker)) {
                return $query;
            }

            if (is_string($target)) {
                if (! $this->sortColumnExists($connection, $table, $target)) {
                    return $query;
                }
                $orders = [[$grammar->wrap($table.'.'.$target).' is null', []], [$table.'.'.$target, $dir]];
            } elseif (isset($target['related'])) {
                [$relTable, $relColumn, $fk] = $target['related'];
                foreach ([[$table, $fk], [$relTable, 'id'], [$relTable, $relColumn]] as [$t, $c]) {
                    if (! $this->sortColumnExists($connection, $t, $c)) {
                        return $query;
                    }
                }
                $sub = DB::connection($connection)->table($relTable)->select($relColumn)
                    ->whereColumn("{$relTable}.id", "{$table}.{$fk}")->limit(1);
                $orders = [['('.$sub->toSql().') is null', $sub->getBindings()], [$sub, $dir]];
            } elseif (isset($target['expr'])) {
                foreach ($target['columns'] as $column) {
                    if (! $this->sortColumnExists($connection, $table, $column)) {
                        return $query;
                    }
                }
                $orders = [["({$target['expr']}) is null", []], [DB::raw("({$target['expr']})"), $dir]];
            } else {
                return $query;
            }
        } catch (Throwable) {
            return $query;
        }

        $query->reorder();
        foreach ($orders as [$by, $arg]) {
            if (is_string($by) && is_array($arg)) {
                if (str_ends_with($by, ' is null')) {
                    $query->orderByRaw($by.' asc', $arg);
                    continue;
                }
                $query->orderBy($by, $arg);
                continue;
            }
            $query->orderBy($by, $arg);
        }
        $query->orderBy($table.'.'.$tiebreaker, $dir);

        return $query;
    }

    /** Whitelist entry ordering by a column of a related table (e.g. warehouse name via warehouse_id). */
    protected function sortByRelated(string $table, string $column, string $foreignKey): array
    {
        return ['related' => [$table, $column, $foreignKey]];
    }

    /** Whitelist entry ordering by a developer-written SQL expression over listed base-table columns. */
    protected function sortByExpression(string $sql, array $columns): array
    {
        return ['expr' => $sql, 'columns' => $columns];
    }
}
