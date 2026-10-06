<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Item;
use App\Models\Tenant\PurchasingReceivingCommand;
use App\Models\Tenant\ReceivingRequest;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseBin;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\OperationalReceiving;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Native receiving under the signed acting user's ordinary Stock permissions. */
final class FinanceReceivingService
{
    private function source(array $data): ReceivingRequest
    {
        abort_unless(request()->attributes->get('purchasing_authority'), 403);
        abort_unless(app(InventoryPermissionService::class)->can(request()->user(), 'inventory.receive_goods'), 403);
        $source = ReceivingRequest::query()->where('request_uuid', $data['request_uuid'])
            ->where('source_bill_id', $data['source_bill_id'])->firstOrFail();
        $authority = request()->attributes->get('purchasing_authority');
        abort_unless(hash_equals((string) ($authority['source_revision'] ?? ''), (string) $data['bill_revision'])
            && hash_equals($source->source_revision, (string) ($authority['request_revision'] ?? '')), 409, __('inventory.purchasing.refresh_required'));
        if (isset($data['request_revision'])) {
            abort_unless(hash_equals($source->source_revision, $data['request_revision']), 409, __('inventory.purchasing.refresh_required'));
        }

        return $source;
    }

    public function options(array $data): array
    {
        $source = $this->source($data);
        $permissions = app(InventoryPermissionService::class);

        $warehouses = Warehouse::query()->where('is_active', true)->get(['id', 'name']);
        $approved = $source->approved_at !== null && $source->approved_revision === $source->source_revision;
        $reason = $source->status === 'cancelled' ? 'cancelled'
            : ($source->status === 'complete' || ! $source->lines->contains(fn ($line) => Decimal::gt(Decimal::sub($line->requested_qty, $line->received_qty), '0')) ? 'complete'
                : (! $approved ? 'approval_required'
                    : (! $warehouses->contains(fn ($warehouse) => (int) $warehouse->id === (int) $source->warehouse_id) ? 'warehouse_unavailable' : 'ready')));

        return [
            'request' => app(ReceivingRequestService::class)->status($source),
            'warehouses' => $warehouses->map(fn ($warehouse) => [
                'id' => $warehouse->id, 'name' => $warehouse->name, 'requires_bin' => false,
                'bins' => WarehouseBin::query()->where('warehouse_id', $warehouse->id)
                    ->where('is_active', true)->get()->map(fn ($bin) => ['id' => $bin->id, 'label' => $bin->name ?: $bin->code])->all(),
            ])->all(),
            'can_receive' => $reason === 'ready',
            'receiving_ready' => $reason === 'ready',
            'receiving_block_reason' => $reason === 'ready' ? null : $reason,
            'receiving_block_message' => $reason === 'ready' ? null : __('receiving.readiness_'.$reason),
            'can_approve' => $permissions->can(request()->user(), 'inventory.approve_purchase_orders'),
            'can_edit_receipt_cost' => $permissions->can(request()->user(), 'inventory.manage_adjustments'),
            'lines' => $source->lines->map(function ($line) {
                $item = Item::query()->findOrFail($line->item_id);

                return ['source_bill_line_id' => $line->source_line_id, 'request_line_id' => $line->id,
                    'item_id' => $line->item_id, 'item_name' => $item->name, 'sku' => $item->sku,
                    'unit_id' => $line->entered_unit_id, 'unit_label' => Unit::query()->find($line->entered_unit_id)?->code, 'requested_quantity' => $line->requested_qty,
                    'received_quantity' => $line->received_qty,
                    'remaining_quantity' => Decimal::sub($line->requested_qty, $line->received_qty),
                    'unit_cost' => $line->unit_cost, 'tracking_type' => $item->tracking_type,
                    'tracks_expiry' => $item->tracksExpiry(), 'requires_lot' => $item->tracksLots(),
                    'requires_serials' => $item->tracksSerials(), 'requires_variant' => (bool) $item->is_variant_parent,
                    'variants' => $item->variants()->where('is_active', true)->get()->map(fn ($variant) => [
                        'id' => $variant->id, 'label' => $variant->sku,
                    ])->all()];
            })->all(),
        ];
    }

    public function prepare(array $data): array
    {
        $source = $this->source($data);
        $hash = SolaStockJournalContract::payloadHash($data);

        return DB::connection('tenant')->transaction(function () use ($source, $data, $hash) {
            // Serialize commands on the source before their unique operation insert.
            ReceivingRequest::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $command = PurchasingReceivingCommand::query()->where('operation_uuid', $data['operation_uuid'])->first();
            if ($command) {
                abort_unless($command->status !== 'abandoned', 409, __('receiving.operation_abandoned'));
                abort_unless((int) $command->actor_id === (int) request()->user()->id && hash_equals($command->payload_hash, $hash), 409, __('inventory.purchasing.source_mismatch'));
            } else {
                $command = PurchasingReceivingCommand::query()->create([
                    'organization_id' => app(OrganizationContext::class)->idOrFail(),
                    'operation_uuid' => $data['operation_uuid'], 'receiving_request_id' => $source->id,
                    'source_bill_id' => $source->source_bill_id, 'actor_id' => request()->user()->id,
                    'payload_hash' => $hash, 'payload' => $data, 'status' => 'prepared',
                ]);
            }

            return $this->result($command);
        });
    }

    public function execute(array $data): array
    {
        $this->prepare($data); // Durable identity survives a later failed receiving transaction.

        return DB::connection('tenant')->transaction(function () use ($data) {
            $source = ReceivingRequest::query()->where('request_uuid', $data['request_uuid'])->lockForUpdate()->firstOrFail();
            $command = PurchasingReceivingCommand::query()->where('operation_uuid', $data['operation_uuid'])->lockForUpdate()->firstOrFail();
            if ($command->status === 'posted') {
                return $this->result($command);
            }
            abort_unless($source->approved_at && $source->approved_revision === $source->source_revision && $source->status !== 'cancelled', 403, __('inventory.purchasing.approval_needed'));
            abort_unless((int) $source->warehouse_id === (int) $data['warehouse_id'], 403);
            $warehouse = Warehouse::query()->where('is_active', true)->findOrFail($data['warehouse_id']);
            app(WarehouseAccessService::class)->assertAllowed($warehouse->id);
            $lines = [];
            foreach ($data['lines'] as $index => $line) {
                $original = $source->lines()->where('source_line_id', $line['source_bill_line_id'])->findOrFail($line['request_line_id']);
                abort_unless((int) $original->entered_unit_id === (int) $line['unit_id'], 422);
                if (! (Decimal::cmp((string) $line['quantity'], '0') > 0 && Decimal::cmp((string) $line['quantity'], Decimal::sub($original->requested_qty, $original->received_qty)) <= 0)) {
                    throw ValidationException::withMessages(["lines.$index.quantity" => __('receiving.quantity_remaining', ['quantity' => Decimal::sub($original->requested_qty, $original->received_qty)])]);
                }
                $item = Item::query()->findOrFail($original->item_id);
                foreach (['lot_code' => $item->tracksLots(), 'serials' => $item->tracksSerials(),
                    'expiry_date' => $item->tracksExpiry(), 'variant_id' => (bool) $item->is_variant_parent] as $field => $required) {
                    if ($required && empty($line[$field])) {
                        $key = ['lot_code' => 'lot_required', 'serials' => 'serials_required', 'expiry_date' => 'expiry_required', 'variant_id' => 'variant_required'][$field];
                        throw ValidationException::withMessages(["lines.$index.$field" => __('receiving.'.$key)]);
                    }
                }
                if (app(OperationalReceiving::class)->restricted() && isset($line['unit_cost']) && Decimal::cmp((string) $line['unit_cost'], (string) $original->unit_cost) !== 0) {
                    throw ValidationException::withMessages(["lines.$index.unit_cost" => __('receiving.fixed_cost')]);
                }

                abort_unless(! $item->is_variant_parent || ! empty($line['variant_id']), 422);
                if (! empty($line['variant_id'])) {
                    $item->variants()->where('is_active', true)->findOrFail($line['variant_id']);
                }
                $tracking = array_intersect_key($line, array_flip(['lot_code', 'expiry_date', 'serials', 'bin_id', 'variant_id']));
                $lines[] = $tracking + ['receiving_request_line_id' => $original->id, 'item_id' => $original->item_id,
                    'entered_unit_id' => $original->entered_unit_id, 'received_qty' => $line['quantity'],
                    'accepted_qty' => $line['quantity'], 'unit_cost' => $line['unit_cost'] ?? $original->unit_cost];
            }
            $attributes = ['receiving_request_id' => $source->id, 'supplier_id' => $source->supplier_id,
                'warehouse_id' => $warehouse->id, 'receipt_date' => $data['receipt_date']];
            $prepared = app(OperationalReceiving::class)->prepare($attributes + ['lines' => $lines]);
            $receipt = app(GoodsReceiptService::class)->createDraft($attributes, $prepared['lines']);
            app(OperationalReceiving::class)->posting($receipt);
            $receipt = app(GoodsReceiptService::class)->post($receipt);
            $command->update(['status' => 'posted', 'goods_receipt_id' => $receipt->id]);

            return $this->result($command);
        });
    }

    /** Close only an operation proven not to have committed any receiving. */
    public function abandon(array $data): array
    {
        $source = $this->source($data);

        return DB::connection('tenant')->transaction(function () use ($source, $data) {
            // Same lock ordering as execute: this waits for uncertain execution.
            ReceivingRequest::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $command = PurchasingReceivingCommand::query()->where('operation_uuid', $data['operation_uuid'])
                ->lockForUpdate()->first();
            if (! $command) {
                // A validation failure may precede prepare. A durable tombstone also
                // prevents a delayed original prepare/execute from receiving later.
                $payload = ['abandoned_before_prepare' => true, 'command_identity' => $data];
                $command = PurchasingReceivingCommand::query()->create([
                    'organization_id' => app(OrganizationContext::class)->idOrFail(),
                    'operation_uuid' => $data['operation_uuid'], 'receiving_request_id' => $source->id,
                    'source_bill_id' => $source->source_bill_id, 'actor_id' => request()->user()->id,
                    'payload_hash' => SolaStockJournalContract::payloadHash($payload),
                    'payload' => $payload, 'status' => 'abandoned',
                ]);
            }
            abort_unless((int) $command->receiving_request_id === (int) $source->id
                && (int) $command->source_bill_id === (int) $source->source_bill_id
                && (int) $command->actor_id === (int) request()->user()->id, 409);
            abort_unless(in_array($command->status, ['prepared', 'abandoned'], true)
                && $command->goods_receipt_id === null, 409, __('receiving.operation_already_received'));
            if ($command->status !== 'abandoned') {
                $command->update(['status' => 'abandoned']);
            }

            return $this->result($command);
        });
    }

    public function status(array $data): array
    {
        $this->source($data);
        $command = PurchasingReceivingCommand::query()->where('operation_uuid', $data['operation_uuid'])
            ->where('source_bill_id', $data['source_bill_id'])->where('actor_id', request()->user()->id)->firstOrFail();

        return $this->result($command);
    }

    private function result(PurchasingReceivingCommand $command): array
    {
        $receipt = $command->goods_receipt_id ? GoodsReceipt::query()->findOrFail($command->goods_receipt_id) : null;

        return ['operation_uuid' => $command->operation_uuid, 'status' => $command->status,
            'goods_receipt_id' => $receipt?->id, 'goods_receipt_number' => $receipt?->grn_number,
            'request' => app(ReceivingRequestService::class)->status(ReceivingRequest::query()->findOrFail($command->receiving_request_id))];
    }
}
