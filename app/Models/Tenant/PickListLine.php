<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickListLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'pick_list_lines';

    protected $guarded = ['id'];

    public function pickList(): BelongsTo
    {
        return $this->belongsTo(PickList::class, 'pick_list_id');
    }
}
