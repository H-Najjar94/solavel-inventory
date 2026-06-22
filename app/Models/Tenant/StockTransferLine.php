<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_transfer_lines';

    protected $guarded = ['id'];

    protected $casts = ['quantity'=>'decimal:4','received_qty'=>'decimal:4'];
}
