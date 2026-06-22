<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'warehouses';

    protected $guarded = ['id'];

    protected $casts = [
        // `address` is a structured object from the form ({line1, city, country})
        // stored as JSON in a longtext column. Without this cast, Eloquent writes
        // the PHP array straight to the string column → "Array to string
        // conversion" and warehouse create/edit fails. Casting json-encodes on
        // write and decodes on read.
        'address' => 'array',
        'is_active' => 'boolean',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(WarehouseImage::class, 'warehouse_id');
    }

    /** The primary banner image, if any (list/detail hero). */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(WarehouseImage::class, 'warehouse_id')->where('is_primary', true);
    }
}
