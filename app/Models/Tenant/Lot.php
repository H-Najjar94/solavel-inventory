<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lot extends Model
{
    use BelongsToOrganization;

    protected $table = 'lots';

    protected $guarded = ['id'];

    protected $casts = [
        'mfg_date' => 'date',
        'expiry_date' => 'date',
        'received_date' => 'date',
        'attributes' => 'array',
    ];

    public const STATUSES = ['active', 'expired', 'quarantined', 'consumed', 'recalled'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** A lot is expired when it has an expiry date in the past. */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /** Effective trace status, treating a past expiry as expired even if not yet flagged. */
    public function effectiveStatus(): string
    {
        if ($this->status === 'recalled') {
            return 'recalled';
        }
        if ($this->status === 'quarantined') {
            return 'quarantined';
        }
        if ($this->isExpired()) {
            return 'expired';
        }

        return $this->status ?? 'active';
    }

    /** Can this lot move OUT (pick/ship)? Blocked when expired/quarantined/recalled. */
    public function isShippable(): bool
    {
        return ! in_array($this->effectiveStatus(), ['expired', 'quarantined', 'recalled'], true);
    }
}
