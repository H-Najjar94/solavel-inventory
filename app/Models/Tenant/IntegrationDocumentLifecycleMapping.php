<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IntegrationDocumentLifecycleMapping extends Model
{
    /** Lifecycle identities are tenant-owned even in non-HTTP workers. */
    protected $connection = 'tenant';

    protected $table = 'integration_document_lifecycle_mappings';

    protected $guarded = ['id'];

    protected $casts = [
        'central_client_id' => 'integer',
        'central_organization_id' => 'integer',
        'finance_organization_id' => 'integer',
        'solastock_organization_id' => 'integer',
        'finance_journal_id' => 'integer',
        'ordered_qty' => 'decimal:4',
        'reserved_qty' => 'decimal:4',
        'received_qty' => 'decimal:4',
        'billed_qty' => 'decimal:4',
        'shipped_qty' => 'decimal:4',
        'invoiced_qty' => 'decimal:4',
        'returned_qty' => 'decimal:4',
        'exchange_rate' => 'decimal:12',
        'exchange_rate_date' => 'date',
        'transaction_subtotal' => 'decimal:6',
        'transaction_tax' => 'decimal:6',
        'transaction_total' => 'decimal:6',
        'base_subtotal' => 'decimal:6',
        'base_tax' => 'decimal:6',
        'base_total' => 'decimal:6',
        'inventory_valuation_effect' => 'decimal:6',
        'error_state' => 'array',
        'safe_metadata' => 'array',
        'last_verified_at' => 'datetime',
    ];

    private const IMMUTABLE = [
        'mapping_uuid',
        'organization_mapping_uuid',
        'central_client_id',
        'central_organization_id',
        'tenant_database_identity',
        'finance_organization_id',
        'solastock_organization_id',
        'source_application',
        'source_document_type',
        'source_document_id',
        'document_version',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $organization = IntegrationOrganizationMapping::query()
                ->where('mapping_uuid', $mapping->organization_mapping_uuid)
                ->where('central_client_id', $mapping->central_client_id)
                ->where('central_organization_id', $mapping->central_organization_id)
                ->where('finance_organization_id', $mapping->finance_organization_id)
                ->where('solastock_organization_id', $mapping->solastock_organization_id)
                ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
                ->first();
            if (! $organization) {
                throw ValidationException::withMessages([
                    'document_mapping' => 'Workflow document mapping scope does not match its immutable organization mapping.',
                ]);
            }
        });
        static::updating(function (Model $mapping): void {
            if ($mapping->isDirty(self::IMMUTABLE)) {
                throw ValidationException::withMessages([
                    'document_mapping' => 'Immutable workflow document identity fields cannot be changed.',
                ]);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages([
            'document_mapping' => 'Workflow document mappings are permanent audit evidence and cannot be deleted.',
        ]));
    }
}
