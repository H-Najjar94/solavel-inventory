<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class CostLayer extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::addGlobalScope('active_fifo_projection', function ($query) {
            if (\Illuminate\Support\Facades\Schema::connection($query->getModel()->getConnectionName())->hasColumn($query->getModel()->getTable(), 'superseded_fifo_correction_id')) {
                $query->whereNull('superseded_fifo_correction_id');
            }
        });
    }

    protected $table = 'cost_layers';

    protected $guarded = ['id'];
}
