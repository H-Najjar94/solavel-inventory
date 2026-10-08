<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class PackLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'pack_lines';

    protected $guarded = ['id'];
}
