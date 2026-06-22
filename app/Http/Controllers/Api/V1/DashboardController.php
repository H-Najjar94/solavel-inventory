<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\DashboardLayout;
use App\Services\Reports\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard KPIs (via DashboardMetricsService) + per-user widget layout. All
 * figures read from canonical projections/documents; nothing mutates stock.
 */
class DashboardController extends ApiController
{
    public function index(Request $request, DashboardMetricsService $metrics): JsonResponse
    {
        return $this->success($metrics->metrics());
    }

    public function getLayout(Request $request): JsonResponse
    {
        $layout = DashboardLayout::query()->where('user_id', $request->user()?->id)->first();

        return $this->success(['layout' => $layout?->layout]);
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate(['layout' => ['required', 'array']]);

        $layout = DashboardLayout::query()->updateOrCreate(
            ['user_id' => $request->user()?->id],
            ['layout' => $data['layout']]
        );

        return $this->success(['layout' => $layout->layout]);
    }
}
