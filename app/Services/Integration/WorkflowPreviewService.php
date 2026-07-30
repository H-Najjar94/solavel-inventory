<?php

namespace App\Services\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\Pack;
use App\Models\Tenant\PickList;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shipment;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class WorkflowPreviewService
{
    private const TYPES = [
        'purchase_order' => PurchaseOrder::class,
        'goods_receipt' => GoodsReceipt::class,
        'inventory_reversal' => InventoryReversal::class,
        'sales_order' => SalesOrder::class,
        'pick_list' => PickList::class,
        'pack' => Pack::class,
        'shipment' => Shipment::class,
        'sales_return' => SalesReturn::class,
    ];

    public function __construct(
        private readonly WorkflowValidationService $validation,
        private readonly AccountingJournalBuilder $journals,
        private readonly WorkflowMatchingService $matching,
    ) {
    }

    public function preview(string $type, int $id): array
    {
        $model = self::TYPES[$type] ?? null;
        if (! $model || $id <= 0) {
            throw new RuntimeException(__('inventory.integration.workflow_type_invalid'));
        }
        $document = $model::query()->with($this->relations($type))->findOrFail($id);
        $orgId = (int) $document->organization_id;
        $event = IntegrationOutboxEvent::query()
            ->where('organization_id', $orgId)
            ->where('aggregate_type', class_basename($model))
            ->where('aggregate_id', $id)
            ->orderByDesc('id')->first();
        $eventType = $event?->event_type ?? $this->eventType($type);
        $errors = [];
        try {
            $this->validation->assertOperationalDocumentReady($document, $eventType);
        } catch (ValidationException $exception) {
            $errors = $exception->errors()['workflow'] ?? [$exception->getMessage()];
        }

        $journal = null;
        if ($event && IntegrationEvents::postsJournalForPayload((string) $event->event_type, (array) $event->payload)) {
            try {
                $journal = $this->journals->build($event, $orgId);
            } catch (\Throwable $exception) {
                $errors[] = json_encode([
                    'code' => 'journal_preview_failed',
                    'message' => $exception->getMessage(),
                ], JSON_UNESCAPED_SLASHES);
            }
        }

        $mapping = IntegrationDocumentLifecycleMapping::query()
            ->where('source_application', 'solastock')
            ->where('source_document_type', $type === 'inventory_reversal' ? 'supplier_return' : $type)
            ->where('source_document_id', (string) $id)
            ->first();
        $children = $mapping
            ? IntegrationDocumentLifecycleMapping::query()
                ->where('parent_mapping_uuid', $mapping->mapping_uuid)
                ->orderBy('source_document_type')->orderBy('id')->get()
            : collect();

        return [
            'schema_version' => 'phase3.workflow-preview.v1',
            'read_only' => true,
            'valid' => $errors === [],
            'operational_source' => [
                'application' => 'solastock',
                'document_type' => $type,
                'document_id' => (string) $id,
                'status' => (string) ($document->status ?? 'recorded'),
                'mapping_uuid' => $mapping?->mapping_uuid,
            ],
            'finance_document' => $mapping?->destination_document_id ? [
                'application' => $mapping->destination_application,
                'document_type' => $mapping->destination_document_type,
                'document_id' => $mapping->destination_document_id,
            ] : null,
            'quantity_effect' => $this->quantityEffect($type, $document),
            'valuation_effect' => $event?->payload['total_inventory_value_change'] ?? 0,
            'proposed_journal' => $journal,
            'currency' => $event?->payload['currency'] ?? null,
            'tax_effect' => in_array($eventType, ['grn.posted', 'shipment.posted', 'grn.reversed', 'sales_return.posted'], true)
                ? ['owned_by' => 'solabooks_financial_document', 'amount' => 0] : null,
            'subledger_effect' => [
                'customer' => in_array($type, ['sales_order', 'shipment', 'sales_return'], true)
                    ? ['owned_by' => 'solabooks_invoice_or_credit_note', 'stock_journal_effect' => 0] : null,
                'supplier' => in_array($type, ['purchase_order', 'goods_receipt', 'inventory_reversal'], true)
                    ? ['owned_by' => 'solabooks_bill_or_debit_note', 'stock_journal_effect' => 0] : null,
            ],
            'matching' => [
                'state' => $mapping?->matching_state ?? 'mapping_required',
                'ordered_qty' => $mapping?->ordered_qty,
                'reserved_qty' => $mapping?->reserved_qty,
                'received_qty' => $mapping?->received_qty,
                'billed_qty' => $mapping?->billed_qty,
                'shipped_qty' => $mapping?->shipped_qty,
                'invoiced_qty' => $mapping?->invoiced_qty,
                'returned_qty' => $mapping?->returned_qty,
                'related_documents' => $children->map(fn ($child) => [
                    'mapping_uuid' => $child->mapping_uuid,
                    'document_type' => $child->source_document_type,
                    'document_id' => $child->source_document_id,
                    'status' => $child->lifecycle_status,
                    'matching_state' => $child->matching_state,
                ])->all(),
                'evaluation' => $this->matching->evaluate([
                    'lifecycle' => in_array(
                        $mapping?->source_document_type,
                        ['purchase_order', 'goods_receipt', 'supplier_return', 'landed_cost'],
                        true,
                    ) ? 'purchasing' : (in_array(
                        $mapping?->source_document_type,
                        ['sales_order', 'reservation', 'pick_list', 'pack', 'shipment', 'sales_return'],
                        true,
                    ) ? 'sales' : null),
                    'ordered' => $mapping?->ordered_qty,
                    'reserved' => $mapping?->reserved_qty,
                    'received' => $mapping?->received_qty,
                    'billed' => $mapping?->billed_qty,
                    'shipped' => $mapping?->shipped_qty,
                    'invoiced' => $mapping?->invoiced_qty,
                    'returned' => $mapping?->returned_qty,
                ], [
                    'inventory_valuation' => $mapping?->inventory_valuation_effect,
                    'financial_subtotal' => $mapping?->base_subtotal,
                ]),
            ],
            'validation_errors' => $errors,
            'proposed_reversal' => $this->reversal($type, $mapping),
            'mutation' => [
                'performed' => false,
                'events' => 0,
                'attempts' => 0,
                'nonces' => 0,
                'api_usage' => 0,
                'journals' => 0,
                'documents' => 0,
                'quantities' => 0,
                'mappings' => 0,
            ],
        ];
    }

    private function relations(string $type): array
    {
        return match ($type) {
            'purchase_order', 'goods_receipt', 'sales_order', 'shipment', 'sales_return' => ['lines'],
            default => [],
        };
    }

    private function eventType(string $type): string
    {
        return match ($type) {
            'goods_receipt' => 'grn.posted',
            'inventory_reversal' => 'grn.reversed',
            'shipment' => 'shipment.posted',
            'sales_return' => 'sales_return.posted',
            default => $type.'.operational',
        };
    }

    private function quantityEffect(string $type, object $document): array
    {
        $lines = collect($document->lines ?? []);
        $sum = fn (string $field): string => Decimal::qty($lines->reduce(
            fn (string $carry, $line): string => Decimal::add(
                $carry,
                (string) ($line->{$field} ?? 0),
            ),
            '0',
        ));
        return match ($type) {
            'purchase_order' => ['owned_qty' => '0.0000', 'ordered_qty' => $sum('ordered_qty')],
            'goods_receipt' => ['owned_qty' => $sum('accepted_qty'), 'direction' => 'in'],
            'sales_order' => ['owned_qty' => '0.0000', 'ordered_qty' => $sum('ordered_qty')],
            'shipment' => ['owned_qty' => Decimal::sub('0', $sum('quantity')), 'direction' => 'out'],
            'sales_return' => ['owned_qty' => $sum('returned_qty'), 'direction' => 'in'],
            'inventory_reversal' => [
                'owned_qty' => Decimal::sub('0', Decimal::qty(DB::connection('tenant')->table('stock_ledger')
                    ->where('source_type', get_class($document))
                    ->where('source_id', $document->id)
                    ->where('direction', 'out')
                    ->orderBy('id')->pluck('quantity')
                    ->reduce(fn (string $carry, $value): string => Decimal::add(
                        $carry,
                        (string) $value,
                    ), '0'))),
                'direction' => 'out',
            ],
            default => ['owned_qty' => '0.0000'],
        };
    }

    private function reversal(string $type, ?IntegrationDocumentLifecycleMapping $mapping): array
    {
        return match ($type) {
            'goods_receipt' => [
                'document_type' => 'inventory_reversal',
                'effect' => 'reverse_inventory_and_grni_only',
                'requires_original_mapping' => $mapping?->mapping_uuid,
            ],
            'shipment' => [
                'document_type' => 'sales_return',
                'effect' => 'restore_original_stock_layers_and_reverse_cogs_only',
                'requires_original_mapping' => $mapping?->mapping_uuid,
            ],
            default => ['effect' => 'document_owned_effect_only'],
        };
    }
}
