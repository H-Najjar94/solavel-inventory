<?php
namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class HistoricalFifoPlan extends Model
{
    use BelongsToOrganization;
    protected $guarded = ['id'];
    protected $casts = ['plan' => 'array', 'result' => 'array'];
}
