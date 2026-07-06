<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

class IntegrityRepairService
{
    public function applySafeBalanceRepairs(string $connection, int $organizationId, array $plan): array
    {
        $applied = ['created_balance_rows' => 0, 'updated_balance_rows' => 0, 'skipped_blocked_rows' => 0];

        DB::connection($connection)->transaction(function () use ($connection, $organizationId, $plan, &$applied): void {
            foreach ($plan['missing_balance_rows'] as $row) {
                if (! ($row['applyable'] ?? false)) {
                    $applied['skipped_blocked_rows']++;
                    continue;
                }

                DB::connection($connection)->table('stock_balances')->insert([
                    'organization_id' => $organizationId,
                    'item_id' => $row['item_id'],
                    'variant_id' => $row['variant_id'],
                    'warehouse_id' => $row['warehouse_id'],
                    'lot_id' => $row['lot_id'],
                    'bin_id' => $row['bin_id'],
                    'on_hand_qty' => $row['ledger_qty'],
                    'reserved_qty' => '0.0000',
                    'average_cost' => abs((float) $row['ledger_qty']) > 0.0001
                        ? round(((float) $row['ledger_value']) / ((float) $row['ledger_qty']), 4)
                        : 0,
                    'total_value' => $row['ledger_value'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $applied['created_balance_rows']++;
            }

            foreach ($plan['balance_recalculations'] as $row) {
                if (! ($row['applyable'] ?? false)) {
                    $applied['skipped_blocked_rows']++;
                    continue;
                }

                DB::connection($connection)->table('stock_balances')
                    ->where('organization_id', $organizationId)
                    ->where('id', $row['balance_id'])
                    ->update([
                        'on_hand_qty' => $row['ledger_qty'],
                        'total_value' => $row['ledger_value'],
                        'average_cost' => abs((float) $row['ledger_qty']) > 0.0001
                            ? round(((float) $row['ledger_value']) / ((float) $row['ledger_qty']), 4)
                            : 0,
                        'updated_at' => now(),
                    ]);
                $applied['updated_balance_rows']++;
            }
        });

        return $applied;
    }
}
