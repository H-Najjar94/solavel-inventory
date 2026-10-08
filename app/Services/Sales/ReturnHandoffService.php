<?php
namespace App\Services\Sales;

use App\Models\Tenant\{IntegrationDocumentLifecycleMapping,SalesDocumentOutbox,SalesReturn};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Str;

/** Commercial return handoff uses the frozen original shipment identity, never a guessed invoice. */
final class ReturnHandoffService
{
    public static function ordinaryShipmentSource(array $payload, int $organizationId, int $shipmentId): bool
    {
        return ($payload['source_app'] ?? null) === 'solastock'
            && ($payload['schema_version'] ?? null) === 'sales.v1'
            && ($payload['event_type'] ?? null) === 'sales.shipment.confirmed'
            && (int) ($payload['identity']['inventory_organization_id'] ?? 0) === $organizationId
            && $shipmentId > 0 && (int) ($payload['shipment']['id'] ?? 0) === $shipmentId
            && \Illuminate\Support\Str::isUuid($payload['shipment']['mapping_uuid'] ?? '')
            && \Illuminate\Support\Str::isUuid($payload['identity']['organization_mapping_uuid'] ?? '');
    }

    public function record(SalesReturn $return, bool $reverse = false): ?SalesDocumentOutbox
    {
        // Typed origins (including cash) have their own financial closure. Only
        // native invoice shipment handoffs establish this commercial ownership.
        $original = SalesDocumentOutbox::query()->where('organization_id', $return->organization_id)
            ->where('event_type', 'sales.shipment.confirmed')
            ->where('payload->shipment->id', $return->shipment_id)->lockForUpdate()->first();
        if (! $original || ! self::ordinaryShipmentSource((array) $original->payload, (int) $return->organization_id, (int) $return->shipment_id)) return null;
        $shipment = (array) data_get($original->payload, 'shipment', []);
        $life = IntegrationDocumentLifecycleMapping::query()
            ->where('organization_mapping_uuid', $original->organization_mapping_uuid)
            ->where('source_application', 'solastock')->where('source_document_type', 'sales_return')
            ->where('source_document_id', (string) $return->id)->lockForUpdate()->firstOrFail();
        $key = 'sales:return:'.$life->mapping_uuid.':'.($reverse ? 'reversed' : 'confirmed');
        if ($existing = SalesDocumentOutbox::query()->where('organization_id', $return->organization_id)->where('source_key', $key)->first()) return $existing;
        $refs = collect($shipment['lines'] ?? [])->keyBy(fn ($line) => (string) $line['source_line_id']);
        $lines = [];
        foreach ($return->loadMissing('lines')->lines as $line) {
            if (! Decimal::gt((string) $line->returned_qty, '0')) continue;
            $source = $refs->get((string) $line->source_shipment_line_id);
            if (! $source || (int) $source['item_id'] !== (int) $line->item_id) throw new \RuntimeException('Original shipment line identity is required for return delivery.');
            $factor = (string) ($source['unit_conversion_factor'] ?? '1');
            $lines[] = ['source_line_id' => (string) $line->id,
                'source_shipment_line_id' => (string) $line->source_shipment_line_id,
                'source_invoice_line_id' => $source['source_invoice_line_id'] ?? null,
                'item_id' => $line->item_id, 'item_external_id' => $source['item_external_id'] ?? null,
                'unit_id' => $source['unit_id'], 'unit_external_id' => $source['unit_external_id'] ?? null,
                'quantity' => Decimal::qty(Decimal::div((string) $line->returned_qty, $factor)),
                'base_quantity' => (string) $line->returned_qty, 'unit_conversion_factor' => $factor,
                'condition' => $line->condition];
        }
        $payload = $original->payload;
        unset($payload['shipment']);
        $payload['event_uuid'] = (string) Str::uuid();
        $payload['event_type'] = $reverse ? 'sales.return.reversed' : 'sales.return.confirmed';
        $payload['external_source_key'] = $key;
        $payload['return'] = ['mapping_uuid' => $life->mapping_uuid, 'id' => $return->id,
            'number' => $return->return_number, 'date' => $return->return_date?->format('Y-m-d'),
            'shipment_id' => $return->shipment_id, 'shipment_mapping_uuid' => $shipment['mapping_uuid'],
            'source_invoice_id' => $shipment['source_invoice_id'] ?? null,
            'customer_external_id' => $shipment['customer_external_id'] ?? null,
            'currency_code' => $shipment['currency_code'] ?? null, 'lines' => $lines];
        return SalesDocumentOutbox::create(['organization_id' => $return->organization_id,
            'organization_mapping_uuid' => $original->organization_mapping_uuid,
            'source_key' => $key, 'event_uuid' => $payload['event_uuid'],
            'event_type' => $payload['event_type'], 'payload' => $payload,
            'payload_hash' => hash('sha256', SolaStockJournalContract::canonicalJson($payload))]);
    }
}
