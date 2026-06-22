<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class DashboardLayout extends Model
{
    use BelongsToOrganization;

    protected $table = 'dashboard_layouts';

    protected $guarded = ['id'];

    protected $casts = ['layout' => 'array'];
}
