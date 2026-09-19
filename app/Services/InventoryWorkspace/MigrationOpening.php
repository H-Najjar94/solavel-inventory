<?php

namespace App\Services\InventoryWorkspace;

use App\Services\Documents\OpeningStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** An atomic owner operation. Workspace receipt and native outbox share its transaction. */
final class MigrationOpening
{
    public function post(array $input): array
    {
        return DB::connection('tenant')->transaction(function () use ($input) {
            $ids=array_column($input['lines'],'finance_item_id');
            $requirements=app(OpeningRequirements::class);
            $before=$requirements->read($input['warehouse_id'],$ids);
            if (!hash_equals($before['version'],$input['requirements_version'])) {
                $this->fail('requirements_version','Stock mappings or opening positions changed. Review the new owner requirements before posting.');
            }
            $items=collect($before['items'])->keyBy('finance_item_id');
            $lines=[];
            foreach ($input['lines'] as $line) {
                $item=$items[$line['finance_item_id']];
                $quantity=bcadd($line['quantity'],'0',4);
                $cost=bcadd($line['unit_cost'],'0',4);
                $value=bcadd(bcmul($quantity,$cost,8),'0.005',2);
                if (bccomp($value,$line['total_value'],2)!==0) $this->fail('total_value','The owner quantity and unit cost do not equal the approved source value.');
                $lines[]=['item_id'=>$item['stock_item_id'],'quantity'=>$quantity,'unit_cost'=>$cost,
                    'entered_qty'=>$quantity,'entered_unit_id'=>$item['base_unit_id']];
            }
            $service=app(OpeningStockService::class);
            $entry=$service->createDraft(['warehouse_id'=>$input['warehouse_id'],'opening_date'=>$input['cutover_date'],
                'notes'=>'Migration '.$input['session_id']],$lines);
            $entry=$service->post($entry);
            $after=$requirements->read($input['warehouse_id'],$ids);
            $current=collect($after['items'])->keyBy('finance_item_id');
            $report=[];
            foreach ($input['lines'] as $line) {
                $id=$line['finance_item_id']; $prior=$items[$id]; $next=$current[$id];
                $quantity=bcsub($next['quantity'],$prior['quantity'],4);
                $value=bcsub($next['value'],$prior['value'],2);
                if (bccomp($quantity,$line['quantity'],4)!==0 || bccomp($value,$line['total_value'],2)!==0) {
                    $this->fail('reconciliation','Native opening valuation differs from the approved source. The owner operation was rolled back.');
                }
                $report[]=['finance_item_id'=>$id,'stock_item_id'=>$prior['stock_item_id'],'baseline'=>$prior,'after'=>$next,
                    'posted_quantity'=>$quantity,'posted_value'=>$value,'quantity_difference'=>'0.0000','value_difference'=>'0.00'];
            }
            return ['entry_id'=>$entry->id,'status'=>$entry->status,'positions'=>$report];
        });
    }

    private function fail(string $field,string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }
}
