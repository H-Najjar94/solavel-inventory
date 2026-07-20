<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class IntegrationSetting extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_settings';

    protected $guarded = ['id'];

    protected $casts = ['meta' => 'array', 'last_sync_at' => 'datetime', 'require_mapping_before_post' => 'boolean'];

    public function apiKey(): ?string
    {
        $encrypted = $this->meta['api_key_encrypted'] ?? null;

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }
}
