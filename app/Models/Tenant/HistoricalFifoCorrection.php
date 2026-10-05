<?php
namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class HistoricalFifoCorrection extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];
    protected $casts = ['causal_payload' => 'array', 'conversion_snapshot' => 'array', 'ledger_ids' => 'array'];
}
