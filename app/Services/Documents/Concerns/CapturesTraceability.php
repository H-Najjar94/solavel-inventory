<?php

namespace App\Services\Documents\Concerns;

use App\Models\Tenant\Item;
use App\Services\Traceability\LotService;
use App\Services\Traceability\SerialService;

/**
 * Shared inbound traceability capture for document services. Resolves a raw
 * captured line (lot_code / expiry_date / serials[]) into concrete lot_id /
 * serial_id values by minting lot + serial rows through the approved
 * traceability services. NEVER writes stock — the document service still hands
 * resolved ids to StockLedgerService for the actual movement.
 *
 * Using services must expose protected lotService()/serialService() accessors.
 */
trait CapturesTraceability
{
    abstract protected function lotService(): LotService;

    abstract protected function serialService(): SerialService;

    /**
     * Resolve capture for ONE inbound line. Returns:
     *   ['lot_id' => ?int, 'expiry_date' => ?string, 'serial_ids' => int[]]
     * serial_ids is empty for non-serial items; one id per captured serial
     * otherwise (callers expand to qty-1 lines).
     *
     * @return array{lot_id: ?int, expiry_date: ?string, serial_ids: int[]}
     */
    protected function resolveCapture(array $line, int $orgId, string $sourceType, int $sourceId): array
    {
        $item = Item::query()->where('organization_id', $orgId)->find($line['item_id']);
        $lotId = $line['lot_id'] ?? null;
        $expiry = $line['expiry_date'] ?? null;

        if ($item && $item->tracksLots() && empty($lotId) && ! empty($line['lot_code'])) {
            $lot = $this->lotService()->resolveOrCreate((int) $line['item_id'], (string) $line['lot_code'], [
                'expiry_date' => $expiry,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
            $lotId = $lot->id;
            $expiry = $expiry ?? $lot->expiry_date?->toDateString();
        }

        $serialIds = [];
        if ($item && $item->tracksSerials() && ! empty($line['serials']) && is_array($line['serials'])) {
            $serialIds = $this->serialService()->resolveOrCreateInbound((int) $line['item_id'], $line['serials'], [
                'lot_id' => $lotId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
        }

        return ['lot_id' => $lotId, 'expiry_date' => $expiry, 'serial_ids' => $serialIds];
    }

    /** True if a captured line carries serial numbers to expand into qty-1 rows. */
    protected function lineHasSerialCapture(array $line, int $orgId): bool
    {
        if (empty($line['serials']) || ! is_array($line['serials'])) {
            return false;
        }
        $item = Item::query()->where('organization_id', $orgId)->find($line['item_id']);

        return $item && $item->tracksSerials();
    }
}
