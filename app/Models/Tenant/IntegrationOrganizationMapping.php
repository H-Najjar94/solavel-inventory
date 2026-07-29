<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class IntegrationOrganizationMapping extends Model
{
    protected $table = 'integration_organization_mappings';

    protected $guarded = ['id'];

    protected $casts = [
        'central_client_id' => 'integer',
        'central_organization_id' => 'integer',
        'finance_organization_id' => 'integer',
        'solastock_organization_id' => 'integer',
        'current_v2_signing_key_id' => 'integer',
        'currency_verified_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    private const IMMUTABLE = [
        'mapping_uuid',
        'central_client_id',
        'central_organization_id',
        'tenant_database_identity',
        'finance_organization_id',
        'solastock_organization_id',
        'integration',
        'contract_version',
    ];

    protected static function booted(): void
    {
        static::updating(function (Model $mapping): void {
            if ($mapping->isDirty(self::IMMUTABLE)) {
                throw ValidationException::withMessages([
                    'mapping' => 'Immutable integration identity fields cannot be changed.',
                ]);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages([
            'mapping' => 'Integration organization mappings are permanent audit evidence and cannot be deleted.',
        ]));
    }
}
