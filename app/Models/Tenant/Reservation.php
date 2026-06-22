<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use BelongsToOrganization;

    protected $table = 'reservations';

    protected $guarded = ['id'];
}
