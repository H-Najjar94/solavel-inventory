<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Pack extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'packs';

    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(PackLine::class, 'pack_id');
    }
}
