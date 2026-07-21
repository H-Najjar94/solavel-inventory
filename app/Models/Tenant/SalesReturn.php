<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReturn extends Model
{
    use BelongsToOrganization;
    use LocksWhenPosted;
    use SoftDeletes;

    protected $table = 'sales_returns';

    protected $guarded = ['id'];

    protected $casts = [
        'return_date' => 'date',
        'authorized_at' => 'datetime',
        'inspected_at' => 'datetime',
        'posted_at' => 'datetime',
        'is_source_reversal' => 'boolean',
    ];

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class, 'sales_return_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
