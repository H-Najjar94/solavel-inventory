<?php

namespace App\Services\Traceability;

use App\Models\Tenant\Item;
use App\Models\Tenant\SerialNumber;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Serial capture + validation. Writes ONLY the serial_numbers table; the stock
 * engine owns the in_stock/sold transitions during posting. This service mints
 * serial rows during capture and enforces count/duplicate rules BEFORE posting,
 * so the document layer carries valid serial_ids into movements.
 */
class SerialService
{
    public function __construct(private OrganizationContext $context) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /**
     * Validate a list of serial strings for an inbound capture of `expectedQty`
     * units. Returns normalized, de-duplicated serials. Pure validation — no DB
     * writes — so the same rules back both the API validator and capture.
     *
     * @return array{serials: string[], errors: string[]}
     */
    public function validateList(array $serials, int $expectedQty): array
    {
        $clean = [];
        $seen = [];
        $errors = [];
        foreach ($serials as $raw) {
            $s = trim((string) $raw);
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                $errors[] = "Duplicate serial in list: {$s}";

                continue;
            }
            $seen[$key] = true;
            $clean[] = $s;
        }
        if ($expectedQty >= 0 && count($clean) !== $expectedQty) {
            $errors[] = "Serial count (".count($clean).") must equal quantity ({$expectedQty}).";
        }

        return ['serials' => $clean, 'errors' => $errors];
    }

    /**
     * Resolve/create serial rows for an inbound capture. Blocks serials already
     * live in stock (status not retired/returned) for this item. Returns the
     * serial ids in input order.
     *
     * @param  string[]  $serials
     * @return int[]
     */
    public function resolveOrCreateInbound(int $itemId, array $serials, array $attributes = []): array
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($orgId, $itemId, $serials, $attributes) {
            $item = Item::query()->where('organization_id', $orgId)->findOrFail($itemId);
            if (! $item->tracksSerials()) {
                throw new RuntimeException("Item {$item->sku} is not serial-tracked.");
            }

            $ids = [];
            foreach ($serials as $raw) {
                $serial = trim((string) $raw);
                $existing = SerialNumber::query()->where('organization_id', $orgId)
                    ->where('item_id', $itemId)->where('serial', $serial)->first();

                if ($existing) {
                    // A serial already in stock/in flight cannot be received again.
                    if (in_array($existing->status, ['available', 'in_stock', 'reserved', 'picked', 'packed', 'shipped'], true)) {
                        throw new RuntimeException("Serial {$serial} is already live (status: {$existing->status}).");
                    }
                    $existing->status = 'available';
                    $existing->lot_id = $attributes['lot_id'] ?? $existing->lot_id;
                    $existing->save();
                    $ids[] = (int) $existing->id;

                    continue;
                }

                $row = SerialNumber::create([
                    'organization_id' => $orgId,
                    'item_id' => $itemId,
                    'variant_id' => $attributes['variant_id'] ?? null,
                    'serial' => $serial,
                    'lot_id' => $attributes['lot_id'] ?? null,
                    'status' => 'available',
                    'source_type' => $attributes['source_type'] ?? null,
                    'source_id' => $attributes['source_id'] ?? null,
                    'source_line_id' => $attributes['source_line_id'] ?? null,
                    'warranty_until' => $attributes['warranty_until'] ?? null,
                    'owner_ref' => $attributes['owner_ref'] ?? null,
                ]);
                $ids[] = (int) $row->id;
            }

            return $ids;
        });
    }

    /** Set a serial lifecycle status (used by returns: returned/damaged/quarantined/retired). */
    public function setStatus(SerialNumber $serial, string $status, array $attributes = []): SerialNumber
    {
        if (! in_array($status, SerialNumber::STATUSES, true)) {
            throw new RuntimeException("Invalid serial status '{$status}'.");
        }

        return DB::connection($this->conn())->transaction(function () use ($serial, $status, $attributes) {
            $serial = SerialNumber::query()->lockForUpdate()->findOrFail($serial->id);
            $serial->status = $status;
            foreach (['warehouse_id', 'bin_id', 'sales_return_id', 'shipment_id', 'owner_ref', 'notes'] as $k) {
                if (array_key_exists($k, $attributes)) {
                    $serial->{$k} = $attributes[$k];
                }
            }
            $serial->save();

            return $serial;
        });
    }
}
