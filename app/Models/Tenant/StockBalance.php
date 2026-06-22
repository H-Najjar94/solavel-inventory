<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_balances';

    protected $guarded = ['id'];
}
