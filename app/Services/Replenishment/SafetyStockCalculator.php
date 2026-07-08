<?php

namespace App\Services\Replenishment;

use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Carbon;

class SafetyStockCalculator
{
    public function __construct(private OrganizationContext $context) {}

    public function calculate(int $itemId, int $warehouseId, int $lookbackDays = 30, int $leadTimeDays = 14, float $serviceFactor = 1.65): array
    {
        $orgId = $this->context->idOrFail();
        $lookbackDays = max(7, min($lookbackDays, 365));
        $leadTimeDays = max(1, min($leadTimeDays, 365));
        $serviceFactor = max(0.0, min($serviceFactor, 3.0));
        $start = Carbon::today()->subDays($lookbackDays - 1)->startOfDay();

        $daily = StockLedger::query()
            ->selectRaw('DATE(moved_at) as demand_date, SUM(quantity) as qty')
            ->where('organization_id', $orgId)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('direction', 'out')
            ->where('moved_at', '>=', $start)
            ->groupBy('demand_date')
            ->pluck('qty', 'demand_date')
            ->all();

        $values = [];
        for ($i = 0; $i < $lookbackDays; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $values[] = (float) ($daily[$date] ?? 0);
        }

        $totalDemand = array_sum($values);
        $averageDaily = $lookbackDays > 0 ? $totalDemand / $lookbackDays : 0.0;
        $variance = 0.0;
        foreach ($values as $qty) {
            $variance += ($qty - $averageDaily) ** 2;
        }
        $stddevDaily = $lookbackDays > 0 ? sqrt($variance / $lookbackDays) : 0.0;
        $safetyStock = $serviceFactor * $stddevDaily * sqrt($leadTimeDays);
        $leadTimeDemand = $averageDaily * $leadTimeDays;
        $reorderPoint = $leadTimeDemand + $safetyStock;

        $available = StockBalance::query()
            ->where('organization_id', $orgId)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('COALESCE(SUM(on_hand_qty - reserved_qty), 0) as available_qty')
            ->value('available_qty') ?? '0';
        $suggestedQty = max(0.0, $reorderPoint - (float) $available);

        return [
            'lookback_days' => $lookbackDays,
            'lead_time_days' => $leadTimeDays,
            'service_factor' => $serviceFactor,
            'total_demand' => Decimal::qty((string) $totalDemand),
            'average_daily_demand' => Decimal::qty((string) $averageDaily),
            'demand_stddev' => Decimal::qty((string) $stddevDaily),
            'lead_time_demand' => Decimal::qty((string) $leadTimeDemand),
            'calculated_safety_stock' => Decimal::qty((string) $safetyStock),
            'calculated_reorder_point' => Decimal::qty((string) $reorderPoint),
            'suggested_reorder_qty' => Decimal::qty((string) $suggestedQty),
            'available_qty' => Decimal::qty((string) $available),
        ];
    }
}
