<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IntegrationMasterDataMapping extends Model
{
    /** Stable master-data identities belong to the current tenant database. */
    protected $connection = 'tenant';

    protected $table = 'integration_master_data_mappings';

    protected $guarded = ['id'];

    protected $casts = [
        'central_client_id' => 'integer',
        'central_organization_id' => 'integer',
        'finance_organization_id' => 'integer',
        'solastock_organization_id' => 'integer',
        'last_verified_at' => 'datetime',
        'error_state' => 'array',
        'solastock_archived' => 'boolean',
        'solabooks_archived' => 'boolean',
    ];

    private const IMMUTABLE = [
        'mapping_uuid',
        'organization_mapping_uuid',
        'central_client_id',
        'central_organization_id',
        'finance_organization_id',
        'solastock_organization_id',
        'entity_type',
        'solastock_record_id',
        'solabooks_record_id',
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
                    'mapping' => 'Master-data mapping scope does not match its immutable organization mapping.',
                ]);
            }
        });
        static::updating(function (Model $mapping): void {
            if ($mapping->isDirty(self::IMMUTABLE)) {
                throw ValidationException::withMessages([
                    'mapping' => 'Immutable master-data mapping scope cannot be changed.',
                ]);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages([
            'mapping' => 'Master-data mapping history cannot be deleted.',
        ]));
    }
}
