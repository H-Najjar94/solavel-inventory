<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Central tenant registry. Lives on the landlord connection. NOT tenant-scoped
 * (it IS the tenant list).
 */
class Organization extends Model
{
    use SoftDeletes;

    protected $table = 'organizations';

    protected $fillable = [
        'central_organization_id', 'name', 'slug', 'database_name',
        'base_currency', 'settings', 'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public function getConnectionName()
    {
        return config('tenancy.central_connection', 'mysql');
    }
}
