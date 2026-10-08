<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class PurchasingDocumentOutbox extends Model
{
    use BelongsToOrganization;

    protected $table = 'purchasing_document_outbox';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'receiver_response' => 'array', 'lease_expires_at' => 'datetime', 'next_attempt_at' => 'datetime'];
}
