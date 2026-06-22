<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;
    use LocksWhenPosted;

    protected $table = 'shipments';

    protected $guarded = ['id'];

    protected $casts = ['ship_date'=>'date','posted_at'=>'datetime'];

    public function lines()
    {
        return $this->hasMany(ShipmentLine::class, 'shipment_id');
    }
}
