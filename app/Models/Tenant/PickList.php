<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class PickList extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'pick_lists';

    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(PickListLine::class, 'pick_list_id');
    }
}
