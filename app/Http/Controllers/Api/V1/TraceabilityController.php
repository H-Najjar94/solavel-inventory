<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Lot;
use App\Models\Tenant\Recall;
use App\Models\Tenant\SerialNumber;
use App\Services\Traceability\LotService;
use App\Services\Traceability\SerialService;
use App\Services\Traceability\TraceabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Lot / serial / expiry traceability. Reads are built from the canonical ledger
 * via TraceabilityService (where-used). Lot/serial status edits go through the
 * approved traceability services; this controller never writes stock.
 */
class TraceabilityController extends ApiController
{
    public function __construct(
        private TraceabilityService $trace,
        private LotService $lots,
        private SerialService $serials,
    ) {}

    // ── Lots ──
    public function lots(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);
        $query = Lot::query()->with('item:id,sku,name')
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', (int) $request->query('item_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('lot_code', 'like', '%'.$request->query('q').'%'))
            ->when($request->boolean('expiring'), fn ($q) => $q->whereNotNull('expiry_date')->orderBy('expiry_date'))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function lot(Lot $lot): JsonResponse
    {
        return $this->success($this->trace->lotTrace($lot));
    }

    public function lotMovements(Lot $lot): JsonResponse
    {
        return $this->success(['movements' => $this->trace->lotTrace($lot)['movements']]);
    }

    public function lotAvailability(Request $request): JsonResponse
    {
        $itemId = (int) $request->query('item_id');
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null;

        return $this->success(['lots' => $this->trace->lotAvailability($itemId, $warehouseId)]);
    }

    public function updateLotStatus(Request $request, Lot $lot): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,expired,quarantined,consumed,recalled'], 'notes' => ['nullable', 'string']]);
        try {
            $updated = $this->lots->setStatus($lot, $data['status'], $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return $this->error('lot_status_failed', $e->getMessage(), 422);
        }

        return $this->success(['lot' => $updated]);
    }

    // ── Serials ──
    public function serials(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);
        $query = SerialNumber::query()->with('item:id,sku,name')
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', (int) $request->query('item_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('serial', 'like', '%'.$request->query('q').'%'))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function serial(SerialNumber $serial): JsonResponse
    {
        return $this->success($this->trace->serialTrace($serial));
    }

    public function serialLifecycle(SerialNumber $serial): JsonResponse
    {
        $t = $this->trace->serialTrace($serial);

        return $this->success(['timeline' => $t['timeline'], 'lifecycle_status' => $t['lifecycle_status']]);
    }

    public function serialAvailability(Request $request): JsonResponse
    {
        $itemId = (int) $request->query('item_id');
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null;

        return $this->success(['serials' => $this->trace->serialAvailability($itemId, $warehouseId)]);
    }

    public function updateSerialStatus(Request $request, SerialNumber $serial): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:available,reserved,picked,packed,shipped,returned,damaged,quarantined,retired'],
            'notes' => ['nullable', 'string'],
        ]);
        try {
            $updated = $this->serials->setStatus($serial, $data['status'], ['notes' => $data['notes'] ?? null]);
        } catch (RuntimeException $e) {
            return $this->error('serial_status_failed', $e->getMessage(), 422);
        }

        return $this->success(['serial' => $updated]);
    }

    // ── Helpers ──
    public function validateSerials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serials' => ['required', 'array'],
            'expected_qty' => ['required', 'integer', 'min:0'],
        ]);
        $result = $this->serials->validateList($data['serials'], (int) $data['expected_qty']);

        return $this->success([
            'serials' => $result['serials'],
            'errors' => $result['errors'],
            'valid' => $result['errors'] === [],
        ]);
    }

    public function validateLotAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'lot_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ]);
        $lots = collect($this->trace->lotAvailability((int) $data['item_id'], $data['warehouse_id'] ?? null));
        $row = $lots->firstWhere('lot_id', (int) $data['lot_id']);
        $available = $row ? (float) $row->on_hand_qty - (float) $row->reserved_qty : 0.0;
        $lot = Lot::query()->find($data['lot_id']);

        return $this->success([
            'available' => number_format($available, 4, '.', ''),
            'sufficient' => $available >= (float) $data['qty'],
            'lot_status' => $lot?->effectiveStatus(),
            'shippable' => $lot?->isShippable() ?? false,
        ]);
    }

    public function suggestOutboundLots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->success($this->trace->suggestOutboundLots(
            (int) $data['item_id'],
            $data['warehouse_id'] ?? null,
            (string) $data['quantity']
        ));
    }

    /**
     * Validate a capture intent before saving a document line. Supports lot
     * availability + shippability for OUT, and serial count/duplicate for either
     * direction. Pure validation; no writes.
     */
    public function validateCapture(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'movement_type' => ['nullable', 'in:in,out'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'lot_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'serials' => ['nullable', 'array'],
            'require_shippable' => ['nullable', 'boolean'],
        ]);
        $errors = [];

        // Serial count + duplicates.
        $serials = [];
        if (! empty($data['serials'])) {
            $res = $this->serials->validateList($data['serials'], (int) ($data['quantity'] ?? count($data['serials'])));
            $serials = $res['serials'];
            $errors = array_merge($errors, $res['errors']);
        }

        // Lot shippability + availability for OUT movements.
        if (($data['movement_type'] ?? null) === 'out' && ! empty($data['lot_id'])) {
            $lot = Lot::query()->find($data['lot_id']);
            if ($lot && ($data['require_shippable'] ?? true) && ! $lot->isShippable()) {
                $errors[] = "Lot {$lot->lot_code} is {$lot->effectiveStatus()} and is not shippable without an override.";
            }
            if (! empty($data['quantity'])) {
                $avail = collect($this->trace->lotAvailability((int) $data['item_id'], $data['warehouse_id'] ?? null))
                    ->firstWhere('lot_id', (int) $data['lot_id']);
                $a = $avail ? (float) $avail->on_hand_qty - (float) ($avail->reserved_qty ?? 0) : 0.0;
                if ($a < (float) $data['quantity']) {
                    $errors[] = "Insufficient lot availability: have {$a}, need {$data['quantity']}.";
                }
            }
        }

        return $this->success(['valid' => $errors === [], 'errors' => $errors, 'serials' => $serials]);
    }

    public function expiryRiskSummary(Request $request): JsonResponse
    {
        $days = (int) $request->query('within_days', 90);
        $cutoff = now()->addDays($days)->toDateString();
        $rows = Lot::query()->with('item:id,sku,name')
            ->whereNotNull('expiry_date')->where('status', '!=', 'consumed')
            ->whereDate('expiry_date', '<=', $cutoff)
            ->orderBy('expiry_date')
            ->limit(min((int) $request->query('limit', 200), 500))->get();

        $expired = $rows->filter(fn ($l) => $l->isExpired())->count();

        return $this->success([
            'within_days' => $days,
            'at_risk' => $rows->count(),
            'expired' => $expired,
            'lots' => $rows,
        ]);
    }

    public function expiryReport(Request $request): JsonResponse
    {
        $days = (int) $request->query('within_days', 90);
        $query = Lot::query()->with('item:id,sku,name')->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expiry_date');

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 100), 200))->withQueryString());
    }
}
