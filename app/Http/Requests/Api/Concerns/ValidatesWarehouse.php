<?php

namespace App\Http\Requests\Api\Concerns;

use App\Models\Tenant\Warehouse;
use Illuminate\Validation\Validator;

/**
 * Shared warehouse validation: org-scoped `code` uniqueness. A warehouse code
 * must be unique WITHIN the organization (different orgs may reuse the same
 * code). Mirrors the item-SKU rule. Catching this in validation turns the DB
 * unique-constraint (warehouses_organization_id_code_unique) into a clear inline
 * field error instead of a generic 500 "save failed".
 */
trait ValidatesWarehouse
{
    protected function warehouseId(): ?int
    {
        $route = $this->route('warehouse');

        return $route instanceof Warehouse ? (int) $route->id : (is_numeric($route) ? (int) $route : null);
    }

    protected function applyWarehouseDomainRules(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $orgId = app(\App\Tenancy\OrganizationContext::class)->id();
            $conn = config('tenancy.tenant_connection', 'tenant');
            $id = $this->warehouseId();
            $code = $this->input('code');

            if ($code && $orgId) {
                $exists = Warehouse::on($conn)->where('organization_id', $orgId)->where('code', $code)
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                if ($exists) {
                    $v->errors()->add('code', 'Warehouse code must be unique within the organization.');
                }
            }
        });
    }
}
