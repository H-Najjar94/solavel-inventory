<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class PurchaseValuationHold extends Model
{
    use BelongsToOrganization;

    protected $table = 'purchase_valuation_holds';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(function (self $hold): void {
            if ($hold->isDirty(['organization_id', 'settlement_uuid', 'plan_revision', 'purpose', 'item_id',
                'warehouse_id', 'receipt_id', 'source_bill_id', 'source_document_type', 'source_document_id', 'source_journal_id', 'plan_fingerprint'])) {
                throw ValidationException::withMessages(['settlement_uuid' => __('receiving.valuation_changed')]);
            }
            if ($hold->getOriginal('state') === 'released' && $hold->state !== 'released') {
                throw ValidationException::withMessages(['settlement_uuid' => __('receiving.valuation_changed')]);
            }
        });
        self::deleting(fn () => throw ValidationException::withMessages([
            'settlement_uuid' => __('receiving.valuation_changed'),
        ]));
    }
}
