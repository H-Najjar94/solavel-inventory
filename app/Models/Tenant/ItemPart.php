<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Catalog link: parent item -> part item. Never affects stock, cost, tax or accounting. */
class ItemPart extends Model
{
    use BelongsToOrganization;

    protected $table = 'item_parts';

    protected $guarded = ['id'];
}
