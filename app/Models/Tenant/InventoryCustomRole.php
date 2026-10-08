<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCustomRole extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_custom_roles';

    protected $guarded = ['id'];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(InventoryUserRoleAssignment::class, 'role_id');
    }
}
