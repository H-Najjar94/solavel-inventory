<?php

namespace App\Tenancy\Concerns;

use App\Services\Access\WarehouseAccessService;
use Illuminate\Database\Eloquent\Builder;

/** Adds the current user's explicit warehouse restriction to tenant models. */
trait BelongsToWarehouseScope
{
    public static function bootBelongsToWarehouseScope(): void
    {
        static::addGlobalScope('warehouse_access', function (Builder $builder): void {
            if (app()->bound(WarehouseAccessService::class)) {
                $model = $builder->getModel();
                $column = method_exists($model, 'getWarehouseScopeColumn')
                    ? $model->getWarehouseScopeColumn()
                    : 'warehouse_id';
                app(WarehouseAccessService::class)->scope($builder, $column);
            }
        });
    }
}
