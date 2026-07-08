<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;
    use LocksWhenPosted;

    protected $table = 'sales_returns';

    protected $guarded = ['id'];

    protected $casts = [
        'return_date' => 'date',
        'authorized_at' => 'datetime',
        'inspected_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class, 'sales_return_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
