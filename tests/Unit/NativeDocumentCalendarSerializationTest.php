<?php
namespace Tests\Unit;

use App\Models\Tenant\{GoodsReceipt, Shipment, PurchasingDocumentOutbox, SalesDocumentOutbox};
use Tests\TestCase;

/** Model serialization only: no database, posting or customer history changes. */
final class NativeDocumentCalendarSerializationTest extends TestCase
{
    public function test_receipt_and_shipment_date_fields_keep_the_calendar_day(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Amman');
        try {
            foreach ([[GoodsReceipt::class, 'receipt_date'], [Shipment::class, 'ship_date']] as [$class, $field]) {
                $document = (new $class)->setDateFormat('Y-m-d H:i:s');
                $document->setRawAttributes([$field => '2026-10-08', 'posted_at' => '2026-10-08 00:00:00'], true);
                $this->assertSame('2026-10-08', $document->toArray()[$field]);
                $this->assertSame('2026-10-08', $document->$field->format('Y-m-d'));
                $this->assertSame('2026-10-08 00:00:00', (string) $document->$field);
                $this->assertSame('2026-10-07T21:00:00.000000Z', $document->toArray()['posted_at']);
                $this->assertSame('2026-10-08', $document->getRawOriginal($field));
            }
        } finally { date_default_timezone_set($previous); }
    }

    public function test_serializing_document_does_not_rewrite_saved_signed_outbox_bytes(): void
    {
        foreach ([[GoodsReceipt::class, 'receipt_date', PurchasingDocumentOutbox::class, 'receipt'], [Shipment::class, 'ship_date', SalesDocumentOutbox::class, 'shipment']] as [$class, $field, $outboxClass, $type]) {
            $oldBytes = json_encode([$type => ['date' => '2026-10-07', 'original_timestamp' => '2026-10-07T21:00:00.000000Z']], JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $oldBytes);
            $outbox = new $outboxClass;
            $outbox->setRawAttributes(['payload' => $oldBytes, 'payload_hash' => $hash], true);
            $document = (new $class)->setDateFormat('Y-m-d H:i:s');
            $document->setRawAttributes([$field => '2026-10-08'], true);
            $document->toArray();
            $this->assertSame($oldBytes, $outbox->getRawOriginal('payload'));
            $this->assertSame($hash, $outbox->getRawOriginal('payload_hash'));
            $this->assertSame('2026-10-07', $outbox->payload[$type]['date']);
            $this->assertSame($hash, hash('sha256', $outbox->getRawOriginal('payload')));
        }
    }
}
