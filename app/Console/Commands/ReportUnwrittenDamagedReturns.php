<?php

namespace App\Console\Commands;

use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockAdjustment;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Lists posted customer returns that have damaged/retired lines but no
 * DMG- write-off adjustment (returns posted before the receive-then-write-off fix:
 * those units moved no stock and their COGS was never reversed). No repair mode.
 */
final class ReportUnwrittenDamagedReturns extends Command
{
    protected $signature = 'returns:report-unwritten-damaged {--organization= : Exact SolaStock organization id}';

    protected $description = 'Read-only: posted returns with damaged/retired lines and no DMG- write-off';

    public function handle(TenantManager $tenants): int
    {
        $organization = (int) $this->option('organization');
        if ($organization <= 0) {
            $this->error('An exact --organization is required.');

            return self::FAILURE;
        }
        $rows = $tenants->runForTenant($organization, function () use ($organization): array {
            $out = [];
            SalesReturn::query()->where('organization_id', $organization)->whereIn('status', ['posted'])
                ->whereHas('lines', fn ($q) => $q->whereIn('condition', ['damaged', 'retired'])->where('returned_qty', '>', 0))
                ->with(['lines' => fn ($q) => $q->whereIn('condition', ['damaged', 'retired'])->where('returned_qty', '>', 0)])
                ->orderBy('id')->each(function (SalesReturn $r) use (&$out, $organization): void {
                    $base = 'DMG-'.$r->return_number;
                    $written = StockAdjustment::query()->where('organization_id', $organization)
                        ->where(fn ($q) => $q->where('adjustment_number', $base)->orWhere('adjustment_number', 'like', $base.'-W%'))->exists();
                    if ($written) {
                        return;
                    }
                    $qty = '0';
                    $value = '0';
                    foreach ($r->lines as $l) {
                        $qty = bcadd($qty, (string) $l->returned_qty, 6);
                        $value = bcadd($value, bcmul((string) $l->returned_qty, (string) $l->unit_cost, 6), 2);
                    }
                    $out[] = [$r->id, $r->return_number, (string) $r->return_date?->toDateString(), $r->lines->count(), $qty, $value];
                });

            return $out;
        });
        $this->table(['id', 'return', 'date', 'lines', 'qty', 'cost value'], $rows);
        $this->info(count($rows).' return(s) without a write-off. Nothing was changed.');

        return self::SUCCESS;
    }
}
