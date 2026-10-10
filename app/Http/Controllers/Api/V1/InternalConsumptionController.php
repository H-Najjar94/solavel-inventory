<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\{InternalConsumption,IntegrationOutboxEvent,Item,StockBalance};
use App\Services\Access\WarehouseAccessService;
use App\Services\Consumption\{InternalConsumptionService,ConsumptionAccounts};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InternalConsumptionController extends ApiController {
    public function __construct(private InternalConsumptionService $service,private WarehouseAccessService $warehouses) {}
    public function options() {
        $org=app(\App\Tenancy\OrganizationContext::class)->idOrFail();$result=[];
        foreach (['departments','projects']as$table) {
            $result[$table]=\Schema::connection('tenant')->hasTable($table) ? DB::connection('tenant')->table($table)->where('organization_id',$org)->orderBy('name')->get(['id','name']) : [];
        }
        $mapping=app(ConsumptionAccounts::class)->connection($org);
        $result['accounts']=$mapping ? DB::connection('tenant')->table('accounts')->where('organization_id',$mapping->finance_organization_id)->where('type','expense')->where('is_active',true)->where('is_postable',true)->whereNull('deleted_at')->orderBy('code')->get(['id','code','name']) : [];
        return $this->success($result);
    }
    public function index(Request $request) {
        $q=InternalConsumption::query()->with('warehouse:id,name')->orderByDesc('id');
        $this->warehouses->scope($q);
        foreach (['warehouse_id','kind','status'] as $field) if ($request->filled($field)) $q->where($field,$request->input($field));
        return $this->paginated($q->paginate(25));
    }
    public function show(InternalConsumption $consumption) {
        $this->warehouses->assertAllowed((int)$consumption->warehouse_id);
        $consumption->load(['lines.item','warehouse','originalIssue']);
        $consumption->lines->each(function ($line) {
            $line->setAttribute('returned_quantity',DB::connection('tenant')->table('stock_ledger')->where('organization_id',$line->organization_id)->where('original_ledger_id',$line->ledger_id)->sum('quantity'));
        });
        $event=IntegrationOutboxEvent::query()->where('aggregate_type','InternalConsumption')->where('aggregate_id',$consumption->id)->first();
        $accounting=$event ? $event->only(['event_uuid','status','last_error','safe_error','external_document_id']) : ['status'=>$consumption->status!=='posted'?'draft':($consumption->accounting_connected?'pending':'standalone')];
        if ($event?->external_document_id) {
            $base=preg_replace('~/api/v1(?:/.*)?$~','',rtrim((string)config('services.solabooks.api_base_url'),'/'));
            if (filter_var($base,FILTER_VALIDATE_URL)) $accounting['journal_url']=$base.'/entries/'.(int)$event->external_document_id;
        }
        return $this->success(['document'=>$consumption,'accounting'=>$accounting]);
    }
    public function store(Request $request) {
        $data=$request->validate([
            'submission_key'=>'required|uuid','document_date'=>'required|date_format:Y-m-d','warehouse_id'=>'required|integer|min:1',
            'original_issue_id'=>'nullable|integer|min:1','reason'=>'required|string|min:3|max:500','notes'=>'nullable|string|max:10000',
            'department_id'=>'nullable|integer|min:1','project_id'=>'nullable|integer|min:1','recipient'=>'nullable|string|max:255',
            'lines'=>'required|array|min:1|max:200','lines.*.item_id'=>'required_without:original_issue_id|integer|min:1',
            'lines.*.original_line_id'=>'nullable|integer|min:1','lines.*.quantity'=>'required|numeric|gt:0',
            'lines.*.entered_unit_id'=>'nullable|integer|min:1','lines.*.variant_id'=>'nullable|integer|min:1','lines.*.bin_id'=>'nullable|integer|min:1',
            'lines.*.lot_id'=>'nullable|integer|min:1','lines.*.serial_id'=>'nullable|integer|min:1','lines.*.account_override_id'=>'nullable|integer|min:1','lines.*.notes'=>'nullable|string|max:2000',
        ]);
        return $this->success($this->service->create($data),201);
    }
    public function approve(InternalConsumption $consumption) { return $this->success($this->service->approve($consumption)); }
    public function post(InternalConsumption $consumption) { return $this->success($this->service->post($consumption)); }
    public function preview(Request $request) {
        $data=$request->validate(['item_id'=>'required|integer','warehouse_id'=>'required|integer','quantity'=>'required|numeric|gt:0','entered_unit_id'=>'nullable|integer|min:1','variant_id'=>'nullable|integer|min:1','bin_id'=>'nullable|integer|min:1','lot_id'=>'nullable|integer|min:1','account_override_id'=>'nullable|integer|min:1']);
        $this->warehouses->assertAllowed((int)$data['warehouse_id']);
        $item=Item::query()->findOrFail($data['item_id']);
        $normalized=app(\App\Services\Catalog\UnitConversionResolver::class)->normalizeLine($data,'quantity');
        $cost=app(\App\Services\Stock\StockLedgerService::class)->previewOutbound(new \App\Services\Stock\StockMovement(direction:'out',itemId:$item->id,warehouseId:(int)$data['warehouse_id'],quantity:(string)$normalized['quantity'],sourceType:InternalConsumption::class,sourceId:0,variantId:$data['variant_id']??null,binId:$data['bin_id']??null,lotId:$data['lot_id']??null));
        $account=app(ConsumptionAccounts::class)->resolve($item,$data['account_override_id']??null);
        return $this->success($cost+['account'=>$account]);
    }
    public function report(Request $request) {
        $request->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d','warehouse_id'=>'nullable|integer','item_id'=>'nullable|integer','department_id'=>'nullable|integer','project_id'=>'nullable|integer']);
        $q=InternalConsumption::query()->where('status','posted');$this->warehouses->scope($q);
        foreach (['warehouse_id','department_id','project_id'] as $field) if ($request->filled($field)) $q->where($field,$request->input($field));
        if ($request->filled('from')) $q->whereDate('document_date','>=',$request->input('from'));
        if ($request->filled('to')) $q->whereDate('document_date','<=',$request->input('to'));
        $rows=DB::connection('tenant')->table('internal_consumption_lines as l')->joinSub($q->select(['id','kind','document_date','warehouse_id']),'d','d.id','=','l.internal_consumption_id')
            ->join('items as i','i.id','=','l.item_id')->where('l.organization_id',app(\App\Tenancy\OrganizationContext::class)->idOrFail());
        if ($request->filled('item_id')) $rows->where('l.item_id',$request->integer('item_id'));
        return $this->success($rows->groupBy('l.item_id','i.name','i.sku')->selectRaw("l.item_id,i.name,i.sku,SUM(CASE WHEN d.kind='issue' THEN l.quantity ELSE 0 END) issued_quantity,SUM(CASE WHEN d.kind='return' THEN l.quantity ELSE 0 END) returned_quantity,SUM(CASE WHEN d.kind='issue' THEN l.quantity ELSE -l.quantity END) net_quantity,SUM(CASE WHEN d.kind='issue' THEN l.total_cost ELSE 0 END) issued_cost,SUM(CASE WHEN d.kind='return' THEN l.total_cost ELSE 0 END) returned_cost,SUM(CASE WHEN d.kind='issue' THEN l.total_cost ELSE -l.total_cost END) net_cost")->orderBy('i.name')->paginate(100));
    }
}
