<?php
namespace App\Services\Stock\Historical;

use App\Models\Tenant\HistoricalFifoPlan;
use App\Models\Tenant\StockLedger;
use App\Services\Integration\SolaStockJournalContract;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class HistoricalFifoReviewService
{
    public function __construct(private OrganizationContext $context, private HistoricalFifoPlanner $planner) {}

    /** Caller must authenticate the review permission and bind this actor to its signed request. */
    public function review(string $uuid, string $batchId, array $events, array $openings, int $reviewer): HistoricalFifoPlan
    {
        if (! Str::isUuid($uuid) || ! Str::isUuid($batchId) || $reviewer <= 0) throw new RuntimeException('Historical FIFO review identity invalid');
        $org = $this->context->idOrFail();
        $plan = $this->planner->plan($events, $openings);
        return DB::connection(config('tenancy.tenant_connection', 'tenant'))->transaction(function () use ($uuid, $batchId, $reviewer, $org, $plan) {
            $existing = HistoricalFifoPlan::query()->where('organization_id', $org)->where('correction_uuid', $uuid)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->plan_sha256 !== $plan['plan_sha256'] || $existing->batch_id !== $batchId || (int) $existing->reviewed_by_central_id !== $reviewer) {
                    throw new RuntimeException('Historical FIFO review identity already bound to different immutable input');
                }
                return $existing;
            }
            $ledger = $this->ledgerForPlan($org, $plan, true);
            return HistoricalFifoPlan::query()->create(['organization_id' => $org, 'correction_uuid' => $uuid, 'batch_id' => $batchId,
                'plan_sha256' => $plan['plan_sha256'], 'ledger_sha256' => SolaStockJournalContract::payloadHash($this->projectionSnapshot($org, $plan, true)),
                'reviewed_by_central_id' => $reviewer, 'plan' => $plan, 'status' => $plan['blocked_items'] === [] ? 'reviewed' : 'blocked']);
        });
    }

    public function projectionSnapshot(int $org, array $plan, bool $lock = false): array
    {
        $snapshot = [];
        foreach (['stock_ledger', 'stock_balances', 'cost_layers'] as $table) {
            $q = DB::connection(config('tenancy.tenant_connection', 'tenant'))->table($table)->where('organization_id', $org)->where(function ($q) use ($plan) {
                foreach (array_keys($plan['layers']) as $key) {
                    [$item, $warehouse] = explode(':', $key);
                    $q->orWhere(fn ($coordinate) => $coordinate->where('item_id', $item)->where('warehouse_id', $warehouse));
                }
            })->orderBy('id');
            if ($lock) $q->lockForUpdate();
            $snapshot[$table] = $q->get()->map(fn ($row) => (array) $row)->all();
        }
        $q = DB::connection(config('tenancy.tenant_connection', 'tenant'))->table('cost_layer_consumptions')->where('organization_id', $org)
            ->whereIn('ledger_id', array_column($snapshot['stock_ledger'], 'id'))->orderBy('id');
        if ($lock) $q->lockForUpdate();
        $snapshot['cost_layer_consumptions'] = $q->get()->map(fn ($row) => (array) $row)->all();
        return $snapshot;
    }

    public function ledgerForPlan(int $org, array $plan, bool $lock = false)
    {
        $query = StockLedger::query()->where('organization_id', $org)->where(function ($q) use ($plan) {
            foreach (array_keys($plan['layers']) as $key) {
                [$item, $warehouse] = explode(':', $key);
                $q->orWhere(fn ($coordinate) => $coordinate->where('item_id', $item)->where('warehouse_id', $warehouse));
            }
        })->orderBy('id');
        if ($plan['layers'] === []) throw new RuntimeException('Historical FIFO plan is empty');
        if ($lock) $query->lockForUpdate();
        return $query->get();
    }
}
