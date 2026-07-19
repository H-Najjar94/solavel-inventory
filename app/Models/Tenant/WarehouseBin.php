<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\BelongsToWarehouseScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseBin extends Model
{
    use BelongsToOrganization;
    use BelongsToWarehouseScope;
    use SoftDeletes;

    protected $table = 'warehouse_bins';

    protected $guarded = ['id'];

    protected $casts = [
        'coords' => 'array',
        'capacity' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
