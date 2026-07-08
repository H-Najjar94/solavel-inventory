<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use BelongsToOrganization;

    protected $table = 'reservations';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
