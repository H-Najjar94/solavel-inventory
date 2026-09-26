<?php

namespace App\Services\Access;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Services\Stock\Support\Decimal;

final class OperationalReceiving
{
    public function restricted(): bool
    {
        return ! app(InventoryPermissionService::class)->can(request()->user(), 'inventory.manage_adjustments');
    }

    public function prepare(array $data): array
    {
        if (! $this->restricted()) {
            return $data;
        }
        abort_unless((int) ($data['purchase_order_id'] ?? 0) > 0, 403, __('inventory.common.approved_po_required'));
        $po = PurchaseOrder::findOrFail((int) ($data['purchase_order_id'] ?? 0));
        app(WarehouseAccessService::class)->assertAllowed((int) $po->warehouse_id);
        abort_unless(in_array($po->status, ['approved', 'partially_received'], true) && (int) $data['warehouse_id'] === (int) $po->warehouse_id, 403);
        $data['supplier_id'] = $po->supplier_id;
        foreach ($data['lines'] as &$line) {
            $source = PurchaseOrderLine::where('purchase_order_id', $po->id)->findOrFail((int) ($line['purchase_order_line_id'] ?? 0));
            abort_unless((int) $source->item_id === (int) $line['item_id'], 422);
            $enteredCost = Decimal::cost(Decimal::mul((string) $source->unit_price, (string) ($source->unit_conversion_factor ?: '1')));
            abort_if(isset($line['unit_cost']) && Decimal::cmp((string) $line['unit_cost'], $enteredCost) !== 0, 403);
            $line['unit_cost'] = $enteredCost;
            $line['entered_unit_id'] = $source->entered_unit_id;
        }unset($line);

        return $data;
    }

    public function posting(GoodsReceipt $receipt): void
    {
        if (! $this->restricted()) {
            return;
        }
        abort_unless((int) $receipt->purchase_order_id > 0, 403, __('inventory.common.approved_po_required'));
        $po = PurchaseOrder::findOrFail((int) $receipt->purchase_order_id);
        app(WarehouseAccessService::class)->assertAllowed((int) $po->warehouse_id);
        abort_unless(in_array($po->status, ['approved', 'partially_received'], true) && (int) $receipt->warehouse_id === (int) $po->warehouse_id, 403);
        foreach ($receipt->lines as $line) {
            $source = PurchaseOrderLine::where('purchase_order_id', $po->id)->findOrFail((int) $line->purchase_order_line_id);
            $expected = Decimal::cost((string) $source->unit_price);
            abort_unless((int) $source->item_id === (int) $line->item_id && Decimal::cmp((string) $line->unit_cost, $expected) === 0, 403);
        }
    }
}
