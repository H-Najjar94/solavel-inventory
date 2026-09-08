<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\{GoodsReceipt, Shipment, SalesOrder, SalesOrderLine, Supplier, Customer, Item, IntegrationDocumentLifecycleMapping};
use App\Services\Access\{WarehouseAccessService, InventoryPermissionService};
use App\Models\Tenant\IntegrationOrganizationMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/** Read-only Stock-owned source snapshots for explicit Finance document review. */
final class FinanceDocumentSourceController extends ApiController
{
    public function suppliers(Request $request) { return $this->parties($request, Supplier::class); }
    public function customers(Request $request) { return $this->parties($request, Customer::class); }

    private function parties(Request $request, string $class)
    {
        $query = $class::query()->where('is_active', true)->orderBy('name');
        $search = mb_substr(trim((string) $request->input('search', '')), 0, 100);
        if ($search !== '') $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        return $this->success(['parties' => $query->limit(100)->get(['id', 'name', 'code'])->toArray(),
            'can_review_party' => app(InventoryPermissionService::class)->can($request->user(), 'inventory.integration.setup')]);
    }

    public function receipts(Request $request) { return $this->listing(GoodsReceipt::class, 'grn_number'); }
    public function shipments(Request $request) { return $this->listing(Shipment::class, 'shipment_number'); }
    public function receipt(Request $request, GoodsReceipt $goods_receipt) { return $this->snapshot($request, $goods_receipt, true); }
    public function shipment(Request $request, Shipment $shipment) { return $this->snapshot($request, $shipment, false); }

    private function listing(string $class, string $number)
    {
        $query = $class::query()->whereNotNull('posted_at')->whereNull('reversed_at')->orderByDesc('id');
        app(WarehouseAccessService::class)->scope($query);
        return $this->success($query->limit(100)->get()->map(fn ($document) => [
            'id' => $document->id, 'number' => $document->{$number} ?: (string) $document->id,
            'status' => $document->status, 'warehouse_id' => $document->warehouse_id,
        ])->all());
    }

    private function snapshot(Request $request, GoodsReceipt|Shipment $document, bool $receipt)
    {
        app(WarehouseAccessService::class)->assertAllowed((int) $document->warehouse_id);
        abort_unless($document->posted_at && ! $document->reversed_at, 409, 'finance_source_not_posted_or_reversed');
        $type = $receipt ? 'goods_receipt' : 'shipment';
        $connection = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $document->organization_id)
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
            ->where('status', 'verified')->where('activation_state', 'active')->firstOrFail();
        $mapping = IntegrationDocumentLifecycleMapping::query()
            ->where('organization_mapping_uuid', $connection->mapping_uuid)
            ->where('solastock_organization_id', $document->organization_id)
            ->where('source_application', 'solastock')->where('source_document_type', $type)
            ->where('source_document_id', (string) $document->id)->whereNull('conflict_code')->whereNull('error_state')->firstOrFail();
        $order = $receipt ? $document->purchaseOrder : SalesOrder::query()->find($document->sales_order_id);
        $partyId = $receipt ? $document->supplier_id : $order?->customer_id;
        $party = $partyId ? ($receipt ? Supplier::query() : Customer::query())->find($partyId) : null;
        $lines = $document->lines->map(function ($line) use ($receipt, $order) {
            $item = Item::query()->findOrFail($line->item_id);
            $salesLine = ! $receipt && $line->sales_order_line_id
                ? SalesOrderLine::query()->where('sales_order_id', $order?->id)->find($line->sales_order_line_id) : null;
            return ['item_id' => $item->id, 'sku' => $item->sku, 'name' => $item->name,
                'quantity' => (string) ($receipt ? $line->accepted_qty : $line->quantity),
                'unit_price' => $receipt ? (string) $line->unit_cost : ($salesLine ? (string) $salesLine->unit_price : null),
                'conversion_factor' => (string) ($line->unit_conversion_factor ?? 1),
                'source_line_id' => $line->id];
        })->all();
        return $this->success([
            'mapping_uuid' => $mapping->mapping_uuid, 'source_key' => $mapping->accounting_source_key,
            'document_type' => $type, 'document_id' => $document->id,
            'number' => $receipt ? $document->grn_number : $document->shipment_number,
            'order_id' => $order?->id, 'order_number' => $receipt ? $order?->po_number : $order?->order_number,
            'organization_id' => $document->organization_id, 'warehouse_id' => $document->warehouse_id,
            'currency' => $mapping->transaction_currency_code, 'base_currency' => $mapping->base_currency_code,
            'exchange_rate' => (string) $mapping->exchange_rate,
            'party' => $party ? ['id' => $party->id, 'name' => $party->name, 'code' => $party->code, 'type' => $receipt ? 'supplier' : 'customer'] : null,
            'lines' => $lines,
            'can_review_party' => app(InventoryPermissionService::class)->can($request->user(), 'inventory.integration.setup'),
        ]);
    }
}
