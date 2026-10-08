<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recall extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'recalls';

    protected $guarded = ['id'];

    protected $casts = [
        'activated_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'active', 'closed'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecallLine::class, 'recall_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RecallAction::class, 'recall_id');
    }
}
