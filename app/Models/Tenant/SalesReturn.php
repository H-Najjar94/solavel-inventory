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

    protected $casts = ['return_date'=>'date','posted_at'=>'datetime'];

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class, 'sales_return_id');
    }
}
