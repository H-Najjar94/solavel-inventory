<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class PickListLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'pick_list_lines';

    protected $guarded = ['id'];
}
