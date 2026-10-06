<?php

namespace App\Services\Access;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\ReceivingRequest;
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
        if (! empty($data['receiving_request_id'])) {
            $r = ReceivingRequest::query()->findOrFail($data['receiving_request_id']);
            abort_unless($r->approved_at && $r->approved_revision === $r->source_revision && $r->status !== 'cancelled', 403, __('inventory.purchasing.approval_needed'));
            app(WarehouseAccessService::class)->assertAllowed((int) $r->warehouse_id);
            abort_unless((int) $data['warehouse_id'] === (int) $r->warehouse_id, 403);
            $data['supplier_id'] = $r->supplier_id;
            foreach ($data['lines'] as &$line) {
                $source = $r->lines()->whereKey($line['receiving_request_line_id'] ?? 0)->firstOrFail();
                abort_unless((int) $source->item_id === (int) $line['item_id'], 422);
                abort_if(isset($line['unit_cost']) && Decimal::cmp((string) $line['unit_cost'], (string) $source->unit_cost) !== 0, 403);
                $line['unit_cost'] = $source->unit_cost;
                $line['entered_unit_id'] = $source->entered_unit_id;
            }unset($line);

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
        if ($receipt->receiving_request_id) {
            $r = ReceivingRequest::query()->findOrFail($receipt->receiving_request_id);
            abort_unless($r->approved_at && $r->approved_revision === $r->source_revision && $r->status !== 'cancelled' && (int) $receipt->warehouse_id === (int) $r->warehouse_id, 403, __('inventory.purchasing.approval_needed'));
            app(WarehouseAccessService::class)->assertAllowed((int) $r->warehouse_id);
            foreach ($receipt->lines as $line) {
                $source = $r->lines()->whereKey($line->receiving_request_line_id)->firstOrFail();
                $cost = Decimal::cost(Decimal::mul((string) $line->unit_cost, (string) ($line->unit_conversion_factor ?: '1')));
                abort_unless((int) $source->item_id === (int) $line->item_id && (int) $source->entered_unit_id === (int) $line->entered_unit_id && Decimal::cmp((string) $source->unit_cost, $cost) === 0, 403);
            }

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
