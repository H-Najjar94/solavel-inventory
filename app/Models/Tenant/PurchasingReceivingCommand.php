<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class PurchasingReceivingCommand extends Model
{
    use BelongsToOrganization;

    protected $table = 'purchasing_receiving_commands';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        self::updating(function (self $command): void {
            if ($command->isDirty(['organization_id', 'operation_uuid', 'receiving_request_id',
                'source_bill_id', 'actor_id', 'payload_hash', 'payload'])) {
                throw ValidationException::withMessages(['operation_uuid' => 'Receiving operation identity is immutable.']);
            }
        });
        self::deleting(fn () => throw ValidationException::withMessages([
            'operation_uuid' => 'Receiving operation audit evidence cannot be deleted.',
        ]));
    }
}
