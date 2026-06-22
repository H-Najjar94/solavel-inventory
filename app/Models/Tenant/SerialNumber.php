<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerialNumber extends Model
{
    use BelongsToOrganization;

    protected $table = 'serial_numbers';

    protected $guarded = ['id'];

    protected $casts = [
        'warranty_until' => 'date',
    ];

    /** Canonical lifecycle states exposed to the UI/reports. */
    public const STATUSES = ['available', 'reserved', 'picked', 'packed', 'shipped', 'returned', 'damaged', 'quarantined', 'retired'];

    /**
     * The stock engine historically wrote legacy enum values; map them to the
     * canonical lifecycle for display so old and new rows read consistently.
     */
    private const LEGACY_MAP = [
        'pending' => 'available',
        'in_stock' => 'available',
        'sold' => 'shipped',
        'scrapped' => 'retired',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    /** Normalized status (legacy engine values folded into the canonical set). */
    public function lifecycleStatus(): string
    {
        return self::LEGACY_MAP[$this->status] ?? $this->status;
    }

    /** In stock and free to allocate. Treats engine 'in_stock' as available. */
    public function isAvailable(): bool
    {
        return in_array($this->status, ['available', 'in_stock', 'returned'], true);
    }

    public function isShippable(): bool
    {
        return ! in_array($this->lifecycleStatus(), ['damaged', 'quarantined', 'retired', 'shipped'], true);
    }
}
