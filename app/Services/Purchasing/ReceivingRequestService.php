<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\ReceivingRequest;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Services\Access\WarehouseAccessService;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Integration\WorkflowValidationService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReceivingRequestService
{
    public function __construct(private OrganizationContext $context) {}

    public function mapping(): IntegrationOrganizationMapping
    {
        return IntegrationOrganizationMapping::query()->where('solastock_organization_id', $this->context->idOrFail())->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->where('status', 'verified')->where('activation_state', 'active')->firstOrFail();
    }

    private function local(IntegrationOrganizationMapping $m, string $type, int $id, string $field): int
    {
        $mapping = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $m->mapping_uuid)->where('central_client_id', $m->central_client_id)->where('central_organization_id', $m->central_organization_id)->where('finance_organization_id', $m->finance_organization_id)->where('solastock_organization_id', $m->solastock_organization_id)->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived', false)->where('solabooks_archived', false)->where('entity_type', $type)->where('status', 'verified')->where('solabooks_record_id', (string) $id)->first();
        if (! $mapping) {
            throw ValidationException::withMessages([$field => __('inventory.purchasing.mapping_required')]);
        }
        $local = (int) $mapping->solastock_record_id;
        $class = match ($type) {
            'item' => Item::class,'supplier' => Supplier::class,'unit' => Unit::class
        };
        if (! $class::query()->whereKey($local)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([$field => __('inventory.purchasing.mapping_required')]);
        }

        return $local;
    }

    public function upsert(array $d): array
    {
        return DB::connection('tenant')->transaction(function () use ($d) {
            $m = $this->mapping();
            IntegrationOrganizationMapping::query()->whereKey($m->id)->lockForUpdate()->firstOrFail();
            $r = ReceivingRequest::query()->where('request_uuid', $d['request_uuid'])->lockForUpdate()->first();
            $sameBill = ReceivingRequest::query()->where('source_bill_id', $d['source_bill_id'])->first();
            if ($sameBill && (! $r || $sameBill->id !== $r->id)) {
                throw ValidationException::withMessages(['request_uuid' => __('inventory.purchasing.identity_conflict')]);
            }
            if ($r && ((int) $r->source_bill_id !== (int) $d['source_bill_id'] || $r->organization_mapping_uuid !== $m->mapping_uuid)) {
                abort(404);
            }
            if ($r && $r->source_revision === $d['source_revision']) {
                return $this->status($r);
            }
            if ($r) {
                if ($r->status === 'cancelled' || GoodsReceipt::query()->where('receiving_request_id', $r->id)->exists()) {
                    throw ValidationException::withMessages(['source_revision' => __('inventory.purchasing.edits_locked')]);
                }
                if (($d['expected_revision'] ?? null) !== $r->source_revision) {
                    throw ValidationException::withMessages(['source_revision' => __('inventory.purchasing.refresh_required')]);
                }
            }
            $supplier = $this->local($m, 'supplier', (int) $d['supplier_external_id'], 'supplier_external_id');
            $lines = [];
            foreach ($d['lines'] as $i => $line) {
                $lines[] = ['organization_id' => $this->context->idOrFail(), 'source_line_id' => (string) $line['source_line_id'], 'item_id' => $this->local($m, 'item', (int) $line['item_external_id'], "lines.$i.item_external_id"), 'entered_unit_id' => $this->local($m, 'unit', (int) $line['unit_external_id'], "lines.$i.unit_external_id"), 'requested_qty' => $line['quantity'], 'unit_cost' => $line['unit_cost']];
            }
            $attrs = ['organization_mapping_uuid' => $m->mapping_uuid, 'finance_organization_id' => $m->finance_organization_id, 'source_bill_id' => $d['source_bill_id'], 'source_bill_number' => $d['source_bill_number'], 'source_revision' => $d['source_revision'], 'supplier_id' => $supplier, 'currency_code' => $d['currency_code'], 'exchange_rate' => $d['exchange_rate'] ?? null, 'exchange_rate_date' => $d['exchange_rate_date'] ?? null, 'source_payload' => $d, 'warehouse_id' => null, 'approved_by' => null, 'approved_at' => null, 'approved_revision' => null];
            if (! $r) {
                $r = ReceivingRequest::create($attrs + ['organization_id' => $this->context->idOrFail(), 'request_uuid' => $d['request_uuid']]);
            } else {
                $r->update($attrs);
                $r->lines()->delete();
            }
            $r->lines()->createMany($lines);

            return $this->status($r->fresh());
        });
    }

    public function status(ReceivingRequest $r): array
    {
        $r->load('lines');
        $receipts = GoodsReceipt::query()->where('receiving_request_id', $r->id)->with('lines')->get();

        return ['request_uuid' => $r->request_uuid, 'id' => $r->id, 'number' => 'RR-'.$r->id, 'source_revision' => $r->source_revision, 'source_bill_id' => $r->source_bill_id, 'source_bill_number' => $r->source_bill_number, 'status' => $r->status, 'supplier_id' => $r->supplier_id, 'warehouse_id' => $r->warehouse_id, 'approved' => $r->approved_at !== null && $r->approved_revision === $r->source_revision, 'currency_code' => $r->currency_code, 'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'source_line_id' => $l->source_line_id, 'item_id' => $l->item_id, 'item_name' => Item::query()->find($l->item_id)?->name, 'entered_unit_id' => $l->entered_unit_id, 'requested_qty' => $l->requested_qty, 'received_qty' => $l->received_qty, 'remaining_qty' => Decimal::sub((string) $l->requested_qty, (string) $l->received_qty), 'unit_cost' => $l->unit_cost])->all(), 'receipts' => $receipts->map(fn ($g) => ['id' => $g->id, 'number' => $g->grn_number, 'mapping_uuid' => IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid', $r->organization_mapping_uuid)->where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $g->id)->value('mapping_uuid'), 'status' => $g->reversal_id ? 'reversed' : $g->status, 'lines' => $g->lines->map(fn ($l) => ['source_line_id' => $r->lines->firstWhere('id', $l->receiving_request_line_id)?->source_line_id, 'received_qty' => Decimal::qty(Decimal::div((string) $l->accepted_qty, (string) ($l->unit_conversion_factor ?: '1'))), 'received_base_qty' => $l->accepted_qty, 'entered_unit_id' => $l->entered_unit_id, 'base_unit_id' => $l->base_unit_id])->all()])->all()];
    }

    public function cancel(ReceivingRequest $r, string $revision): array
    {
        return DB::connection('tenant')->transaction(function () use ($r, $revision) {
            $r = ReceivingRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($r->source_revision !== $revision) {
                throw ValidationException::withMessages(['source_revision' => __('inventory.purchasing.refresh_required')]);
            }$r->update(['status' => 'cancelled']);

            return $this->status($r);
        });
    }

    public function approve(ReceivingRequest $r, int $warehouseId): array
    {
        app(WarehouseAccessService::class)->assertAllowed($warehouseId);

        return DB::connection('tenant')->transaction(function () use ($r, $warehouseId) {
            $r = ReceivingRequest::query()->with('lines')->whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending' || GoodsReceipt::query()->where('receiving_request_id', $r->id)->where('status', 'posted')->whereNull('reversal_id')->exists()) {
                throw ValidationException::withMessages(['request' => __('inventory.purchasing.edits_locked')]);
            }
            // Use exactly the native PO approval preflight without creating any PO.
            $check = new PurchaseOrder(['organization_id' => $r->organization_id, 'warehouse_id' => $warehouseId, 'supplier_id' => $r->supplier_id, 'order_date' => now()->toDateString(), 'integration_currency_code' => $r->currency_code]);
            $normalized = $r->lines->map(function ($l) {
                $data = app(UnitConversionResolver::class)->normalizeLine(['item_id' => $l->item_id, 'entered_unit_id' => $l->entered_unit_id, 'entered_qty' => $l->requested_qty, 'ordered_qty' => $l->requested_qty], 'ordered_qty');

                return new PurchaseOrderLine($data);
            });
            $check->setRelation('lines', $normalized);
            app(WorkflowValidationService::class)->assertOperationalDocumentReady($check, 'purchase_order.approved');
            foreach ($normalized as $index => $line) {
                $r->lines[$index]->update(['approval_conversion' => array_intersect_key($line->getAttributes(), array_flip(['entered_unit_id', 'base_unit_id', 'unit_conversion_id', 'unit_conversion_factor', 'unit_conversion_version', 'unit_conversion_hash', 'unit_conversion_precision', 'unit_conversion_rounding_mode']))]);
            }
            $r->update(['warehouse_id' => $warehouseId, 'approved_at' => now(), 'approved_by' => auth()->id(), 'approved_revision' => $r->source_revision]);
            InventoryAuditLog::create(['organization_id' => $r->organization_id, 'actor_user_id' => auth()->id(), 'action' => 'receiving_request.approved', 'entity_type' => ReceivingRequest::class, 'entity_id' => $r->id, 'document_ref' => $r->request_uuid, 'after' => ['source_revision' => $r->source_revision, 'warehouse_id' => $warehouseId]]);

            return $this->status($r);
        });
    }

    public function validateReceipt(?int $id, array $attributes, array $lines): void
    {
        if (! $id) {
            return;
        }$r = ReceivingRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($r->status === 'cancelled') {
            throw ValidationException::withMessages(['receiving_request_id' => __('inventory.purchasing.cancelled')]);
        }
        if (! empty($attributes['purchase_order_id'])) {
            throw ValidationException::withMessages(['purchase_order_id' => __('inventory.purchasing.source_mismatch')]);
        }
        if ((int) ($attributes['supplier_id'] ?? 0) !== (int) $r->supplier_id) {
            throw ValidationException::withMessages(['supplier_id' => __('inventory.purchasing.source_mismatch')]);
        }
        foreach ($lines as $i => $l) {
            $source = $r->lines()->whereKey($l['receiving_request_line_id'] ?? 0)->first();
            if (! $source || (int) $source->item_id !== (int) $l['item_id'] || (int) $source->entered_unit_id !== (int) ($l['entered_unit_id'] ?? 0)) {
                throw ValidationException::withMessages(["lines.$i.receiving_request_line_id" => __('inventory.purchasing.source_mismatch')]);
            }
            if ($r->approved_at && $source->approval_conversion) {
                $current = isset($l['base_unit_id']) ? $l : app(UnitConversionResolver::class)->normalizeLine($l, 'received_qty');
                foreach ($source->approval_conversion as $key => $value) {
                    if ((string) ($current[$key] ?? '') !== (string) ($value ?? '')) {
                        throw ValidationException::withMessages(["lines.$i.entered_unit_id" => __('inventory.purchasing.conversion_review_required')]);
                    }
                }
            }
        }
    }

    public function posted(GoodsReceipt $g, bool $reverse = false): void
    {
        if (! $g->receiving_request_id) {
            return;
        }$r = ReceivingRequest::query()->whereKey($g->receiving_request_id)->lockForUpdate()->firstOrFail();
        $totals = [];
        foreach ($g->lines as $l) {
            $key = (int) $l->receiving_request_line_id;
            $totals[$key] = Decimal::add($totals[$key] ?? '0', (string) $l->accepted_qty);
        }
        foreach ($totals as $id => $qty) {
            $l = $r->lines()->whereKey($id)->lockForUpdate()->firstOrFail();
            // Request quantities are entered-unit quantities; GRN captured costs/physical qty are base-unit.
            $matching = $g->lines->where('receiving_request_line_id', $id);
            $entered = '0';
            foreach ($matching as $gl) {
                $f = (string) ($gl->unit_conversion_factor ?: '1');
                $entered = Decimal::add($entered, Decimal::div((string) $gl->accepted_qty, $f));
            }
            $new = $reverse ? Decimal::sub((string) $l->received_qty, $entered) : Decimal::add((string) $l->received_qty, $entered);
            if (! $reverse && Decimal::gt($new, (string) $l->requested_qty)) {
                throw ValidationException::withMessages(['lines' => __('inventory.purchasing.over_receipt')]);
            }$l->update(['received_qty' => $new]);
        }
        $r->load('lines');
        $all = $r->lines->every(fn ($l) => ! Decimal::lt((string) $l->received_qty, (string) $l->requested_qty));
        $any = $r->lines->contains(fn ($l) => Decimal::gt((string) $l->received_qty, '0'));
        if ($r->status !== 'cancelled') {
            $r->update(['status' => $all ? 'complete' : ($any ? 'partial' : 'pending')]);
        }
    }
}
