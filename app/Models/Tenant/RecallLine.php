<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecallLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'recall_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'on_hand_qty' => 'decimal:4',
        'reserved_qty' => 'decimal:4',
        'shipped_qty' => 'decimal:4',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(SerialNumber::class, 'serial_id');
    }
}
