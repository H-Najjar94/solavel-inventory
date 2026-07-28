<?php

namespace App\Tenancy\Scopes;

use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

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

        $organizationIds = [$context->id()];

        // SolaBooks historically stamped the tenant-local organizations.id,
        // while SolaStock now stamps the central organization id carried by the
        // signed SSO handoff. Keep pre-convergence inventory visible by accepting
        // the one tenant-local id explicitly mapped to this central org. The
        // lookup stays inside the already-selected tenant DB, so it cannot widen
        // access to another tenant or another organization.
        try {
            $connection = $model->getConnectionName() ?: config('tenancy.tenant_connection', 'tenant');
            $database = DB::connection($connection)->getDatabaseName();
            $cacheKey = $connection.'|'.$database.'|'.$context->id();
            static $localIdCache = [];
            if (! array_key_exists($cacheKey, $localIdCache)) {
                $localIdCache[$cacheKey] = (int) (DB::connection($connection)->table('organizations')
                    ->where('central_org_id', $context->id())
                    ->value('id') ?? 0);
            }
            $localId = $localIdCache[$cacheKey];
            if ($localId > 0 && $localId !== $context->id()) {
                $organizationIds[] = $localId;
            }
        } catch (\Throwable) {
            // SolaStock-only/test schemas may not carry Finance organizations.
        }

        $builder->whereIn($column, array_values(array_unique($organizationIds)));
    }
}
