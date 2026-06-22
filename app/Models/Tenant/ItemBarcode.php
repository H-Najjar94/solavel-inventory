<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class ItemBarcode extends Model
{
    use BelongsToOrganization;

    protected $table = 'item_barcodes';

    protected $guarded = ['id'];
}
