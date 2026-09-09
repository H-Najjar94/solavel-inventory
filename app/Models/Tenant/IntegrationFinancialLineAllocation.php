<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IntegrationFinancialLineAllocation extends Model
{
    protected $connection = 'tenant';
    protected $table = 'integration_financial_line_allocations';
    protected $guarded = ['id'];
    protected $casts = [
        'reserved_until' => 'datetime', 'posted_at' => 'datetime', 'released_at' => 'datetime',
        'reversed_at' => 'datetime', 'safe_metadata' => 'array',
    ];

    private const IMMUTABLE = [
        'allocation_uuid', 'organization_mapping_uuid', 'central_client_id', 'central_organization_id',
        'tenant_database_identity', 'finance_organization_id', 'solastock_organization_id',
        'source_document_mapping_uuid', 'source_document_type', 'source_document_id', 'source_line_id',
        'destination_document_type', 'destination_document_id', 'destination_line_id', 'allocation_kind',
        'entered_quantity', 'entered_unit_id', 'base_quantity', 'base_unit_id',
        'destination_quantity', 'destination_unit_id', 'unit_conversion_id',
        'unit_conversion_factor', 'unit_conversion_version', 'unit_conversion_hash',
        'unit_conversion_precision', 'unit_conversion_rounding_mode',
        'source_unit_price', 'destination_unit_price', 'source_gross', 'destination_gross',
        'line_discount_allocated', 'document_discount_allocated', 'source_net', 'destination_net',
        'price_difference', 'currency_code', 'base_currency_code', 'exchange_rate',
        'destination_revision', 'source_fingerprint', 'destination_fingerprint', 'idempotency_key',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $allocation): void {
            $mapping = IntegrationOrganizationMapping::query()
                ->where('mapping_uuid', $allocation->organization_mapping_uuid)
                ->where('central_client_id', $allocation->central_client_id)
                ->where('central_organization_id', $allocation->central_organization_id)
                ->where('finance_organization_id', $allocation->finance_organization_id)
                ->where('solastock_organization_id', $allocation->solastock_organization_id)
                ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->exists();
            if (! $mapping) {
                throw ValidationException::withMessages(['allocation' => 'Financial allocation scope does not match its organization mapping.']);
            }
        });
        static::updating(function (self $allocation): void {
            if ($allocation->isDirty(self::IMMUTABLE)) {
                throw ValidationException::withMessages(['allocation' => 'Posted financial allocation evidence is immutable.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages([
            'allocation' => 'Financial allocation evidence is released or reversed, never deleted.',
        ]));
    }
}
