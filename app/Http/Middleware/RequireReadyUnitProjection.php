<?php

namespace App\Http\Middleware;

use App\Services\Catalog\ItemBaseUnitConversion;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RequireReadyUnitProjection
{
    public function handle(Request $request, Closure $next): mixed
    {
        $name = (string) $request->route()?->getName();
        // Recovery/detail remains reachable. Aggregate quantity views must not mix epochs while Finance catches up.
        if (in_array($name, ['api.v1.items.show', 'api.v1.meta', 'api.v1.settings.index'], true) || str_starts_with($name, 'api.v1.items.base-unit-conversion.')) {
            return $next($request);
        }
        $db = DB::connection('tenant');
        if ($db->getSchemaBuilder()->hasTable(ItemBaseUnitConversion::TABLE) && $db->table(ItemBaseUnitConversion::TABLE)->where('organization_id', app(OrganizationContext::class)->idOrFail())->where('state', '<>', 'ready')->exists()) {
            return response()->json(['message' => __('inventory.base_unit.projection_pending'), 'code' => 'unit_projection_pending'], 409);
        }

        return $next($request);
    }
}
