<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryUserRoleAssignment extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_user_role_assignments';

    protected $guarded = ['id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(InventoryCustomRole::class, 'role_id');
    }
}
