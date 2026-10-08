<?php

namespace App\Tenancy\Concerns;

use App\Tenancy\OrganizationContext;
use App\Tenancy\Scopes\OrganizationScope;
use RuntimeException;

/**
 * Apply to every tenant-owned model. Provides:
 *   - automatic organization global scope (read isolation, fail-closed)
 *   - automatic organization_id stamping on create (write isolation)
 *   - a guard that rejects creating a row for a different org than is active
 *
 * Models using this trait live on the `tenant` connection by default.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            $context = app(OrganizationContext::class);
            $column = $model->getOrganizationColumn();

            $active = $context->idOrFail();

            $current = $model->getAttribute($column);
            if ($current === null) {
                $model->setAttribute($column, $active);
            } elseif ((int) $current !== $active) {
                // Defense in depth: never let code persist a row for another org.
                throw new RuntimeException(
                    "BelongsToOrganization: attempted to create a ".static::class
                    ." for organization {$current} while organization {$active} is active."
                );
            }
        });

        static::updating(function ($model) {
            // organization_id is IMMUTABLE after creation. Every tenant model uses
            // $guarded = ['id'], so organization_id is otherwise mass-assignable —
            // a mass-assigned update (e.g. ->update([...]) or ->fill(...)->save())
            // could re-stamp a row to another org. The read scope only limits WHICH
            // rows you can load (your active org), not what you may SET, so without
            // this guard you could move your own row to a different org. Fail
            // closed: reject any change to organization_id.
            $column = $model->getOrganizationColumn();
            if ($model->isDirty($column)) {
                throw new RuntimeException(
                    'BelongsToOrganization: organization_id is immutable; attempted to change '
                    .static::class.' #'.$model->getKey().' from '
                    .var_export($model->getOriginal($column), true).' to '
                    .var_export($model->getAttribute($column), true).'.'
                );
            }
        });
    }

    public function initializeBelongsToOrganization(): void
    {
        // Only register organization_id as fillable when the model ALREADY uses a
        // fillable allow-list. Models that use $guarded (the common case here) must
        // be left in guarded mode — pushing onto an otherwise-empty $fillable would
        // flip the model to allow-list mode and silently drop every other attribute
        // on mass-assignment (e.g. entry_number, status). The creating() hook below
        // stamps organization_id regardless, so it never needs to be fillable.
        if (! empty($this->fillable) && ! in_array('organization_id', $this->fillable, true)) {
            $this->fillable[] = 'organization_id';
        }
    }

    public function getOrganizationColumn(): string
    {
        return 'organization_id';
    }

    public function getQualifiedOrganizationColumn(): string
    {
        return $this->getTable().'.'.$this->getOrganizationColumn();
    }

    /** Default tenant-owned models to the tenant connection. */
    public function getConnectionName()
    {
        return $this->connection ?? config('tenancy.tenant_connection', 'tenant');
    }
}
