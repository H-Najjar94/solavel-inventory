<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'inventory_customers';

    protected $guarded = ['id'];

    protected $casts = ['contact' => 'array', 'is_active' => 'boolean'];
}
