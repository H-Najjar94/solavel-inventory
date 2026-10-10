<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
class InternalConsumptionLine extends Model {
    use BelongsToOrganization;
    protected $guarded = ['id'];
    protected $casts = ['conversion'=>'array', 'quantity'=>'decimal:4', 'unit_cost'=>'decimal:6', 'total_cost'=>'decimal:2'];
    public function item() { return $this->belongsTo(Item::class)->withTrashed(); }
    public function getAttribute($key) {
        if (in_array($key,['entered_qty','entered_unit_id','base_unit_id','unit_conversion_id','unit_conversion_factor','unit_conversion_version','unit_conversion_hash','unit_conversion_precision','unit_conversion_rounding_mode'],true)) {
            return ($this->conversion ?? [])[$key] ?? null;
        }
        return parent::getAttribute($key);
    }
    protected static function booted(): void {
        static::saving(fn ($line) => $line->assertDraft());
        static::deleting(fn ($line) => $line->assertDraft());
    }
    private function assertDraft(): void {
        $parent = InternalConsumption::query()->findOrFail($this->internal_consumption_id);
        if ($parent->status !== 'draft') throw new \RuntimeException('consumption_document_locked');
    }
}
