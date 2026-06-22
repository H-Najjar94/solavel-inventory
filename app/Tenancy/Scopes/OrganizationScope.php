<?php

namespace App\Tenancy\Scopes;

use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query on a tenant-owned model to the active
 * organization. If there is no active organization, the scope short-circuits the
 * query to return nothing (1=0) rather than leaking all rows — fail closed.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(OrganizationContext::class);
        $column = $model->getQualifiedOrganizationColumn();

        if (! $context->has()) {
            // No tenant active → expose nothing.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $context->id());
    }
}
