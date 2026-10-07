<?php

namespace App\Services\Sales;

use App\Models\Tenant\{IntegrationDocumentLifecycleMapping, IntegrationOrganizationMapping, IntegrationOutboxEvent, IntegrationSetting, SalesDocumentOutbox, SalesReturn};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A physical customer return proposes one credit draft; it never posts credit or undoes an invoice. */
final class SalesReturnDocumentBuilder
{
    public function record(SalesReturn $return, bool $reversed = false): ?SalesDocumentOutbox
    {
        return DB::connection('tenant')->transaction(function () use ($return, $reversed) {
            $mapping = IntegrationOrganizationMapping::query()
                ->where('solastock_organization_id', $return->organization_id)
                ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
                ->whereIn('status', ['verified', 'verified_hold'])
                ->whereIn('activation_state', ['active', 'maintenance_hold'])->lockForUpdate()->first();
            $setting = IntegrationSetting::query()->where('organization_id', $return->organization_id)->where('integration', 'solabooks')->first();
            if (! $mapping || ! in_array($setting?->mode, ['active', 'paused', 'connected_readonly', 'connected_pending_mapping'], true)) {
                return null;
            }
            $return = SalesReturn::query()->where('organization_id', $return->organization_id)->whereKey($return->id)->lockForUpdate()->with('lines')->firstOrFail();
            $life = IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
                ->where('source_application', 'solastock')->where('source_document_type', 'sales_return')
                ->where('source_document_id', (string) $return->id)->firstOrFail();
            $key = 'sales:return:'.$life->mapping_uuid.':'.($reversed ? 'reversed' : 'confirmed');
            if ($existing = SalesDocumentOutbox::query()->where('organization_id', $return->organization_id)->where('source_key', $key)->first()) {
                return $existing;
            }
            $shipmentId = $return->shipment_id ?: $return->source_reversal_shipment_id;
            $shipment = IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
                ->where('source_application', 'solastock')->where('source_document_type', 'shipment')->where('source_document_id', (string) $shipmentId)->first();
            $original = $shipment ? SalesDocumentOutbox::query()->where('organization_id', $return->organization_id)
                ->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('source_key', 'sales:shipment:'.$shipment->mapping_uuid.':confirmed')->first() : null;
            $source = (array) data_get($original?->payload, 'shipment', []);
            $sourceLines = collect($source['lines'] ?? [])->keyBy(fn ($line) => (string) ($line['source_line_id'] ?? ''));
            $missing = [];
            if (! $original) $missing[] = 'original_shipment_document';
            $lines = [];
            foreach ($return->lines as $line) {
                $ref = $sourceLines->get((string) $line->source_shipment_line_id);
                if (! $ref || (int) ($ref['item_id'] ?? 0) !== (int) $line->item_id
                    || (int) ($ref['unit_id'] ?? 0) !== (int) $line->entered_unit_id) {
                    $missing[] = 'original_shipment_line:'.$line->id;
                    $ref = null;
                }
                $factor = (string) ($line->unit_conversion_factor ?: '1');
                $lines[] = [
                    'source_line_id' => (string) $line->id,
                    'source_shipment_line_id' => $line->source_shipment_line_id ? (string) $line->source_shipment_line_id : null,
                    'source_invoice_line_id' => $ref['source_invoice_line_id'] ?? null,
                    'item_id' => $line->item_id, 'item_external_id' => $ref['item_external_id'] ?? null,
                    'unit_id' => $line->entered_unit_id, 'unit_external_id' => $ref['unit_external_id'] ?? null,
                    'quantity' => Decimal::qty(Decimal::div((string) $line->returned_qty, $factor)),
                    'base_quantity' => (string) $line->returned_qty, 'unit_conversion_factor' => $factor,
                    'condition' => $line->condition, 'receipt_unit_cost_base' => (string) $line->unit_cost,
                ];
            }
            $journal = IntegrationOutboxEvent::query()->where('organization_id', $return->organization_id)
                ->where('event_type', 'sales_return.posted')->where('aggregate_id', $return->id)->firstOrFail();
            $uuid = (string) Str::uuid();
            $payload = [
                'source_app' => 'solastock', 'schema_version' => 'sales.v1', 'contract_version' => SolaStockJournalContract::VERSION,
                'event_uuid' => $uuid, 'event_type' => $reversed ? 'sales.return.reversed' : 'sales.return.confirmed', 'external_source_key' => $key,
                'inventory_organization_id' => $return->organization_id, 'finance_organization_id' => $mapping->finance_organization_id,
                'identity' => ['central_client_id' => $mapping->central_client_id, 'central_organization_id' => $mapping->central_organization_id,
                    'finance_organization_id' => $mapping->finance_organization_id, 'inventory_organization_id' => $mapping->solastock_organization_id,
                    'integration_mapping_id' => $mapping->id, 'organization_mapping_uuid' => $mapping->mapping_uuid,
                    'signing_key_id' => (string) data_get($setting->meta, 'signing_key_id')],
                'return' => ['mapping_uuid' => $life->mapping_uuid, 'id' => $return->id, 'number' => $return->return_number,
                    'date' => $return->return_date?->format('Y-m-d'), 'is_source_reversal' => (bool) $return->is_source_reversal,
                    'shipment_id' => $shipmentId, 'shipment_mapping_uuid' => $shipment?->mapping_uuid,
                    'shipment_event_uuid' => $original?->event_uuid, 'source_invoice_id' => $source['source_invoice_id'] ?? null,
                    'customer_id' => $return->customer_id, 'customer_external_id' => $source['customer_external_id'] ?? null,
                    'currency_code' => $source['currency_code'] ?? null, 'base_currency_code' => $mapping->base_currency_code,
                    'accounting_source_key' => $journal->idempotency_key, 'lines' => $lines, 'missing_information' => array_values(array_unique($missing))],
            ];
            return SalesDocumentOutbox::create(['organization_id' => $return->organization_id, 'organization_mapping_uuid' => $mapping->mapping_uuid,
                'source_key' => $key, 'event_uuid' => $uuid, 'event_type' => $payload['event_type'], 'payload' => $payload,
                'payload_hash' => hash('sha256', SolaStockJournalContract::canonicalJson($payload))]);
        });
    }
}
