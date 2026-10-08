<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A warehouse image (private media). Org-scoped; one primary per warehouse. The
 * file lives on the PRIVATE disk — only the path is stored here.
 */
class WarehouseImage extends Model
{
    use BelongsToOrganization;

    protected $table = 'warehouse_images';

    protected $guarded = ['id'];
}
