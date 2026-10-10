<?php
namespace App\Services\Consumption;

use App\Models\Tenant\{InternalConsumption,InternalConsumptionLine,InventorySetting,Item,Warehouse,IntegrationOutboxEvent,StockLedger};
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Integration\{IntegrationOutboxService,OrganizationAccountRequirements};
use App\Services\Stock\{StockLedgerService,StockMovement};
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InternalConsumptionService {
    public function __construct(private OrganizationContext $context,private StockLedgerService $ledger,private UnitConversionResolver $units,
        private ConsumptionAccounts $accounts,private IntegrationOutboxService $outbox,private WarehouseAccessService $warehouses) {}
    private function permission(string $action): void {
        abort_unless(app(InventoryPermissionService::class)->can(auth()->user(),'inventory.consumption.'.$action),403);
    }
    private function fail(string $key): never { throw ValidationException::withMessages(['consumption'=>__('inventory.consumption.'.$key)]); }
    public function create(array $data): InternalConsumption {
        $this->permission(isset($data['original_issue_id']) ? 'return' : 'create');
        $org = $this->context->idOrFail();
        $hash = hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return DB::connection('tenant')->transaction(function () use ($data,$org,$hash) {
            // Organization row locking serializes first-document numbering and submission keys too.
            InventorySetting::query()->firstOrCreate(['organization_id'=>$org]);
            DB::connection('tenant')->table('inventory_settings')->where('organization_id',$org)->lockForUpdate()->first();
            $existing = InternalConsumption::query()->where('submission_key',$data['submission_key'])->first();
            if ($existing) {
                if (!hash_equals($existing->submission_hash,$hash)) $this->fail('submission_conflict');
                return $existing->load('lines');
            }
            $source = isset($data['original_issue_id']) ? InternalConsumption::query()->lockForUpdate()->findOrFail($data['original_issue_id']) : null;
            if ($source && $data['document_date'] < $source->document_date->toDateString()) $this->fail('return_date');
            if ($source && ($source->kind !== 'issue' || $source->status !== 'posted')) $this->fail('invalid_source');
            $warehouse = $source?->warehouse_id ?? $data['warehouse_id'];
            $this->warehouses->assertAllowed((int)$warehouse);
            $wh = Warehouse::query()->findOrFail($warehouse);
            if (!$wh->is_active) $this->fail('warehouse_inactive');
            foreach (['department_id'=>'departments','project_id'=>'projects'] as $field=>$table) {
                if (!empty($data[$field]) && (!\Schema::connection('tenant')->hasTable($table) || !DB::connection('tenant')->table($table)->where('id',$data[$field])->where('organization_id',$org)->exists())) $this->fail('dimension_invalid');
            }
            $doc = InternalConsumption::query()->create([
                'organization_id'=>$org,'document_number'=>DocumentNumber::next($source?'ICR':'IC',InternalConsumption::class,'document_number',$org,'tenant'),
                'kind'=>$source?'return':'issue','original_issue_id'=>$source?->id,'document_date'=>$data['document_date'],'warehouse_id'=>$warehouse,
                'status'=>'draft','reason'=>$data['reason'],'notes'=>$data['notes']??null,'recipient'=>$data['recipient']??null,
                'department_id'=>$source?->department_id ?? ($data['department_id']??null),'project_id'=>$source?->project_id ?? ($data['project_id']??null),
                'submission_key'=>$data['submission_key'],'submission_hash'=>$hash,'created_by'=>auth()->id(),
                'original_event_uuid'=>$source ? IntegrationOutboxEvent::query()->where('event_type','internal_consumption.posted')->where('aggregate_id',$source->id)->value('event_uuid'):null,
            ]);
            $seen=[];
            foreach ($data['lines'] as $input) {
                $original = $source ? $source->lines()->findOrFail($input['original_line_id']??0) : null;
                if ($original && isset($seen[$original->id])) $this->fail('duplicate_line');
                if ($original) $seen[$original->id]=true;
                $item = ($original ? Item::withTrashed() : Item::query())->findOrFail($original?->item_id ?? $input['item_id']);
                if (!$original && (!$item->is_active || $item->item_type !== 'inventory' || $item->track_inventory === false)) $this->fail('capitalized_inventory_required');
                if ($original) {
                    // Returns use the frozen issue UOM, even when the catalog changes.
                    $normalized = $original->conversion;
                    if (isset($input['entered_unit_id']) && (int)$input['entered_unit_id'] !== (int)($normalized['entered_unit_id'] ?? 0)) $this->fail('dimension_invalid');
                    $normalized['entered_qty'] = \App\Services\Stock\Support\Decimal::qty((string)$input['quantity']);
                    $normalized['quantity'] = \App\Services\Stock\Support\Decimal::qty(\App\Services\Stock\Support\Decimal::mul($normalized['entered_qty'], (string)($normalized['unit_conversion_factor'] ?? '1')));
                } else {
                    $normalized = $this->units->normalizeLine(['item_id'=>$item->id,'quantity'=>$input['quantity'],'entered_unit_id'=>$input['entered_unit_id']??$item->base_unit_id],'quantity');
                }
                $selected = $original ? ['account_id'=>$original->expense_account_id,'inventory_account_id'=>$original->inventory_account_id,'source'=>'original']
                    : $this->draftAccounts($item,isset($input['account_override_id'])?(int)$input['account_override_id']:null);
                $line=['organization_id'=>$org,'item_id'=>$item->id,'original_line_id'=>$original?->id,'quantity'=>$normalized['quantity'],
                    'conversion'=>$normalized,'expense_account_id'=>$selected['account_id'],'inventory_account_id'=>$selected['inventory_account_id'],
                    'account_source'=>$selected['source'],'account_override_id'=>$input['account_override_id']??null,'notes'=>$input['notes']??null];
                foreach (['variant_id','lot_id','serial_id','bin_id'] as $field) $line[$field]=$original?->{$field} ?? ($input[$field]??null);
                $doc->lines()->create($line);
            }
            return $doc->load('lines.item');
        },3);
    }
    public function approve(InternalConsumption $document): InternalConsumption {
        $this->permission('approve');
        return DB::connection('tenant')->transaction(function () use ($document) {
            $doc=InternalConsumption::query()->lockForUpdate()->findOrFail($document->id);
            $this->warehouses->assertAllowed((int)$doc->warehouse_id);
            if ($doc->status==='approved') return $doc;
            if ($doc->status!=='draft') $this->fail('locked');
            $doc->status='approved';$doc->approved_at=now();$doc->approved_by=auth()->id();$doc->save();return $doc;
        });
    }
    public function post(InternalConsumption $document): InternalConsumption {
        $this->permission($document->kind==='return'?'return':'post');
        return DB::connection('tenant')->transaction(function () use ($document) {
            // Source-first locking gives competing returns a single ordering.
            $source=$document->original_issue_id ? InternalConsumption::query()->lockForUpdate()->findOrFail($document->original_issue_id):null;
            $doc=InternalConsumption::query()->lockForUpdate()->with('lines')->findOrFail($document->id);
            $this->warehouses->assertAllowed((int)$doc->warehouse_id);
            if ($doc->status==='posted') return $doc;
            if (!in_array($doc->status,['draft','approved'],true)) $this->fail('locked');
            if (data_get(InventorySetting::query()->first()?->approvals,'internal_consumption',false) && $doc->status!=='approved') $this->fail('approval_required');
            $event=$source?'internal_consumption.returned':'internal_consumption.posted';
            $connected=$source ? $source->accounting_connected : false;
            if (!$source) foreach ($doc->lines as $line) {
                $item=Item::query()->lockForUpdate()->findOrFail($line->item_id);
                if (!$item->is_active || $item->item_type!=='inventory' || $item->track_inventory===false) $this->fail('capitalized_inventory_required');
                $resolved=$this->accounts->resolve($item,$line->account_override_id);
                $connected=$resolved['connected'];
                // Local guarded line update occurs only while the header transaction holds its lock.
                $line->expense_account_id=$resolved['account_id'];$line->inventory_account_id=$resolved['inventory_account_id'];$line->account_source=$resolved['source'];
            }
            if ($connected) { app(OrganizationAccountRequirements::class)->assertOperationReady((int)$doc->organization_id,$event); $this->accounts->assertDate((int)$doc->organization_id,$doc->document_date->toDateString()); }
            $movements=[];
            foreach ($doc->lines as $line) {
                if ($source) {
                    $original=$source->lines()->lockForUpdate()->findOrFail($line->original_line_id);
                    $row=$this->ledger->returnIssuedQuantity((int)$original->ledger_id,(string)$line->quantity,'internal_consumption:'.$doc->id.':line:'.$line->id,$doc->id,$line->id,$doc->document_date->toDateTimeString());
                    $line->expense_account_id=$original->expense_account_id;$line->inventory_account_id=$original->inventory_account_id;
                    $this->capture($line,$row);
                } else {
                    $movements[]=new StockMovement(direction:'out',itemId:(int)$line->item_id,warehouseId:(int)$doc->warehouse_id,quantity:(string)$line->quantity,
                        sourceType:InternalConsumption::class,sourceId:(int)$doc->id,sourceLineId:(int)$line->id,
                        variantId:$line->variant_id,binId:$line->bin_id,lotId:$line->lot_id,serialId:$line->serial_id,movedAt:$doc->document_date->toDateTimeString());
                }
            }
            if (!$source) {
                $rows=$this->ledger->post($movements,'internal_consumption:'.$doc->id.':post',['action'=>'internal_consumption.post','entity_type'=>'internal_consumption','entity_id'=>$doc->id]);
                foreach ($rows as $row) $this->capture($doc->lines->firstWhere('id',$row->source_line_id),$row);
            }
            $doc->status='posted';$doc->posted_at=now();$doc->posted_by=auth()->id();$doc->accounting_connected=$connected;$doc->markSystemTransition()->save();
            // A return of an issue posted standalone stays standalone even after later connection.
            if ($connected) $this->outbox->record($event,$doc,'internal_consumption',$doc->document_number,$doc->document_date->toDateString());
            return $doc->fresh('lines.item');
        },3);
    }
    private function draftAccounts(Item $item,?int $override): array {
        try { return $this->accounts->resolve($item,$override); }
        catch (ValidationException $e) {
            if ($override !== null) throw $e;
            return ['account_id'=>null,'inventory_account_id'=>null,'source'=>'unresolved'];
        }
    }
    private function capture(InternalConsumptionLine $line,StockLedger $row): void {
        // Posting owns these derived fields. No user-entered issue cost is accepted.
        DB::connection('tenant')->table('internal_consumption_lines')->where('organization_id',$line->organization_id)->where('id',$line->id)->update([
            'ledger_id'=>$row->id,'unit_cost'=>$row->unit_cost,'total_cost'=>$row->total_cost,
            'expense_account_id'=>$line->expense_account_id,'inventory_account_id'=>$line->inventory_account_id,'account_source'=>$line->account_source]);
    }
}
