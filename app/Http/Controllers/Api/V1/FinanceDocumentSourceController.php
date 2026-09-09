<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\{GoodsReceipt, Shipment, SalesReturn, SalesOrder, SalesOrderLine, Supplier, Customer, Item, Unit, IntegrationDocumentLifecycleMapping};
use App\Services\Access\{WarehouseAccessService, InventoryPermissionService};
use App\Models\Tenant\IntegrationOrganizationMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    public function returns(Request $request)
    {
        $query = SalesReturn::query()->where('status', 'posted')->whereNotNull('posted_at')->orderByDesc('id');
        app(WarehouseAccessService::class)->scope($query);
        return $this->success($query->limit(100)->get()->map(fn ($document) => [
            'id' => $document->id, 'number' => $document->return_number ?: (string) $document->id,
            'status' => $document->status, 'warehouse_id' => $document->warehouse_id,
        ])->all());
    }
    public function salesReturn(Request $request, SalesReturn $sales_return) { return $this->returnSnapshot($request, $sales_return); }

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
        $lines = $document->lines->map(function ($line) use ($request, $receipt, $order, $mapping) {
            $item = Item::query()->findOrFail($line->item_id);
            $salesLine = ! $receipt && $line->sales_order_line_id
                ? SalesOrderLine::query()->where('sales_order_id', $order?->id)->find($line->sales_order_line_id) : null;
            $quantity = (string) ($receipt ? $line->accepted_qty : $line->quantity);
            $consumed = $this->consumedQuantity($request, $mapping, (int) $line->id);
            $factor = (string) ($line->unit_conversion_factor ?? 1);
            $entered = (string) ($line->entered_qty ?? $quantity);
            $enteredUnit = $line->entered_unit_id ? Unit::query()->find($line->entered_unit_id) : null;
            $baseUnit = $line->base_unit_id ? Unit::query()->find($line->base_unit_id) : null;
            return ['item_id' => $item->id, 'sku' => $item->sku, 'name' => $item->name,
                'quantity' => $quantity, 'entered_quantity' => $entered,
                'available_base_quantity' => \App\Services\Stock\Support\Decimal::qty(\App\Services\Stock\Support\Decimal::sub($quantity, $consumed)),
                'unit_price' => $receipt ? (string) $line->unit_cost : ($salesLine ? (string) $salesLine->unit_price : null),
                'entered_unit_price' => ($receipt ? $line->unit_cost : ($salesLine ? $salesLine->unit_price : null)) === null
                    ? null : \App\Services\Stock\Support\Decimal::cost(\App\Services\Stock\Support\Decimal::mul(
                        (string) ($receipt ? $line->unit_cost : $salesLine->unit_price), $factor)),
                'entered_unit_id' => $line->entered_unit_id, 'entered_unit' => $enteredUnit ? ['id' => $enteredUnit->id, 'code' => $enteredUnit->code, 'name' => $enteredUnit->name] : null,
                'base_unit_id' => $line->base_unit_id, 'base_unit' => $baseUnit ? ['id' => $baseUnit->id, 'code' => $baseUnit->code, 'name' => $baseUnit->name] : null,
                'unit_conversion_id' => $line->unit_conversion_id, 'conversion_factor' => $factor,
                'unit_conversion_version' => $line->unit_conversion_version, 'unit_conversion_hash' => $line->unit_conversion_hash,
                'unit_conversion_precision' => $line->unit_conversion_precision, 'unit_conversion_rounding_mode' => $line->unit_conversion_rounding_mode,
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

    private function returnSnapshot(Request $request, SalesReturn $return)
    {
        abort_unless($return->posted_at && $return->status === 'posted', 409, 'finance_source_not_posted');
        app(WarehouseAccessService::class)->assertAllowed((int) $return->warehouse_id);
        $connection = IntegrationOrganizationMapping::query()->where('solastock_organization_id', $return->organization_id)
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->where('status', 'verified')->where('activation_state', 'active')->firstOrFail();
        $mapping = IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid', $connection->mapping_uuid)
            ->where('source_application', 'solastock')->where('source_document_type', 'sales_return')
            ->where('source_document_id', (string) $return->id)->whereNull('conflict_code')->whereNull('error_state')->firstOrFail();
        $shipment = Shipment::query()->find($return->shipment_id ?: $return->source_reversal_shipment_id);
        $order = $shipment ? SalesOrder::query()->find($shipment->sales_order_id) : null;
        $party = $return->customer_id ? Customer::query()->find($return->customer_id) : ($order?->customer_id ? Customer::query()->find($order->customer_id) : null);
        $return->load('lines');
        $lines = $return->lines->map(function ($line) use ($request, $mapping, $order) {
            $item = Item::query()->findOrFail($line->item_id);
            $shipmentLine = $line->source_shipment_line_id ? \App\Models\Tenant\ShipmentLine::query()->find($line->source_shipment_line_id) : null;
            $salesLine = $shipmentLine?->sales_order_line_id ? SalesOrderLine::query()->where('sales_order_id', $order?->id)->find($shipmentLine->sales_order_line_id) : null;
            $quantity = (string) $line->returned_qty; $factor = (string) ($line->unit_conversion_factor ?? 1);
            $consumed = $this->consumedQuantity($request, $mapping, (int) $line->id);
            $enteredUnit = $line->entered_unit_id ? Unit::query()->find($line->entered_unit_id) : null;
            $baseUnit = $line->base_unit_id ? Unit::query()->find($line->base_unit_id) : null;
            return ['item_id' => $item->id, 'sku' => $item->sku, 'name' => $item->name, 'quantity' => $quantity,
                'entered_quantity' => (string) ($line->entered_qty ?? $quantity),
                'available_base_quantity' => \App\Services\Stock\Support\Decimal::qty(\App\Services\Stock\Support\Decimal::sub($quantity, $consumed)),
                'unit_price' => $salesLine ? (string) $salesLine->unit_price : null,
                'entered_unit_price' => $salesLine ? \App\Services\Stock\Support\Decimal::cost(\App\Services\Stock\Support\Decimal::mul((string) $salesLine->unit_price, $factor)) : null,
                'entered_unit_id' => $line->entered_unit_id, 'entered_unit' => $enteredUnit ? ['id' => $enteredUnit->id, 'code' => $enteredUnit->code, 'name' => $enteredUnit->name] : null,
                'base_unit_id' => $line->base_unit_id, 'base_unit' => $baseUnit ? ['id' => $baseUnit->id, 'code' => $baseUnit->code, 'name' => $baseUnit->name] : null,
                'unit_conversion_id' => $line->unit_conversion_id, 'conversion_factor' => $factor,
                'unit_conversion_version' => $line->unit_conversion_version, 'unit_conversion_hash' => $line->unit_conversion_hash,
                'unit_conversion_precision' => $line->unit_conversion_precision, 'unit_conversion_rounding_mode' => $line->unit_conversion_rounding_mode,
                'condition' => $line->condition, 'disposition' => $line->disposition,
                'source_shipment_line_id' => $line->source_shipment_line_id, 'source_stock_ledger_id' => $line->source_stock_ledger_id,
                'source_line_id' => $line->id];
        })->all();
        return $this->success(['mapping_uuid' => $mapping->mapping_uuid, 'source_key' => $mapping->accounting_source_key,
            'document_type' => 'sales_return', 'document_id' => $return->id, 'number' => $return->return_number,
            'organization_id' => $return->organization_id, 'warehouse_id' => $return->warehouse_id,
            'currency' => $mapping->transaction_currency_code, 'base_currency' => $mapping->base_currency_code, 'exchange_rate' => (string) $mapping->exchange_rate,
            'party' => $party ? ['id' => $party->id, 'name' => $party->name, 'code' => $party->code, 'type' => 'customer'] : null,
            'lines' => $lines, 'requires_finance_receipt' => $return->lines->contains(fn ($line) => in_array($line->condition, ['resellable', 'quarantine'], true)),
            'can_review_party' => app(InventoryPermissionService::class)->can($request->user(), 'inventory.integration.setup')]);
    }

    /**
     * A draft that lost its Finance acknowledgement must be able to reload and
     * renew its own reservation. Other drafts and every posted allocation stay
     * consumed; Stock remains the concurrency authority on the reserve call.
     */
    private function consumedQuantity(Request $request, IntegrationDocumentLifecycleMapping $mapping, int $lineId): string
    {
        if (! Schema::connection('tenant')->hasTable('integration_financial_line_allocations')) return '0';
        $destinationType = (string) $request->input('destination_document_type', '');
        $destinationId = (int) $request->input('destination_document_id', 0);
        $validDestination = in_array($destinationType, ['supplier_bill', 'customer_invoice', 'customer_credit_note'], true)
            && $destinationId > 0;

        $query = DB::connection('tenant')->table('integration_financial_line_allocations')
            ->where('organization_mapping_uuid', $mapping->organization_mapping_uuid)
            ->where('source_document_mapping_uuid', $mapping->mapping_uuid)
            ->where('source_line_id', $lineId)
            ->where(function ($query): void {
                $query->where('state', 'posted')->orWhere(function ($query): void {
                    $query->where('state', 'draft_reserved')->where('reserved_until', '>', now());
                });
            });
        if ($validDestination) {
            $query->where(function ($query) use ($destinationType, $destinationId): void {
                $query->where('state', 'posted')->orWhere(function ($query) use ($destinationType, $destinationId): void {
                    $query->where('state', 'draft_reserved')->where(function ($query) use ($destinationType, $destinationId): void {
                        $query->where('destination_document_type', '!=', $destinationType)
                            ->orWhere('destination_document_id', '!=', $destinationId);
                    });
                });
            });
        }

        return (string) $query->sum('base_quantity');
    }
}
