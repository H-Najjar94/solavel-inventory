<?php

namespace App\Services\Traceability;

use App\Models\Tenant\Item;
use App\Models\Tenant\Lot;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lot capture + lifecycle. Writes ONLY the lots table (a traceability master),
 * never stock — the stock engine remains the single writer of stock. Documents
 * carry a resolved lot_id into their movements; this service is how that id is
 * minted during capture.
 */
class LotService
{
    public function __construct(private OrganizationContext $context) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /**
     * Find an existing lot by (item, lot_code) or create it. Used by capture on
     * inbound documents (GRN / opening stock / adjustment IN). Idempotent per code.
     */
    public function resolveOrCreate(int $itemId, string $lotCode, array $attributes = []): Lot
    {
        $orgId = $this->context->idOrFail();
        $lotCode = trim($lotCode);
        if ($lotCode === '') {
            throw new RuntimeException('A lot code is required for lot-tracked items.');
        }

        return DB::connection($this->conn())->transaction(function () use ($orgId, $itemId, $lotCode, $attributes) {
            $item = Item::query()->where('organization_id', $orgId)->findOrFail($itemId);
            if (! $item->tracksLots()) {
                throw new RuntimeException("Item {$item->sku} is not lot-tracked.");
            }
            if ($item->tracksExpiry() && empty($attributes['expiry_date'])) {
                throw new RuntimeException("Item {$item->sku} requires an expiry date on lot capture.");
            }

            $lot = Lot::query()->where('organization_id', $orgId)
                ->where('item_id', $itemId)->where('lot_code', $lotCode)->first();
            if ($lot) {
                // Backfill expiry/source if the existing lot lacks them.
                $dirty = false;
                foreach (['expiry_date', 'mfg_date', 'received_date', 'supplier_id', 'source_type', 'source_id', 'source_line_id'] as $k) {
                    if (! empty($attributes[$k]) && empty($lot->{$k})) {
                        $lot->{$k} = $attributes[$k];
                        $dirty = true;
                    }
                }
                if ($dirty) {
                    $lot->save();
                }

                return $lot;
            }

            return Lot::create([
                'organization_id' => $orgId,
                'item_id' => $itemId,
                'variant_id' => $attributes['variant_id'] ?? null,
                'lot_code' => $lotCode,
                'mfg_date' => $attributes['mfg_date'] ?? null,
                'expiry_date' => $attributes['expiry_date'] ?? null,
                'received_date' => $attributes['received_date'] ?? now()->toDateString(),
                'status' => 'active',
                'supplier_id' => $attributes['supplier_id'] ?? null,
                'source_type' => $attributes['source_type'] ?? null,
                'source_id' => $attributes['source_id'] ?? null,
                'source_line_id' => $attributes['source_line_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);
        });
    }

    /** Set a lot's lifecycle status (active|expired|quarantined|consumed|recalled). */
    public function setStatus(Lot $lot, string $status, ?string $notes = null): Lot
    {
        if (! in_array($status, Lot::STATUSES, true)) {
            throw new RuntimeException("Invalid lot status '{$status}'.");
        }

        return DB::connection($this->conn())->transaction(function () use ($lot, $status, $notes) {
            $lot = Lot::query()->lockForUpdate()->findOrFail($lot->id);
            $lot->status = $status;
            if ($notes !== null) {
                $lot->notes = trim(($lot->notes ? $lot->notes."\n" : '').$notes);
            }
            $lot->save();

            return $lot;
        });
    }
}
