<?php

namespace App\Services\Documents;

use App\Models\Tenant\Customer;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockLedger;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Services\Traceability\SerialService;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sales return — the inbound counterpart of a shipment. Posting puts physically
 * resellable / quarantine stock BACK via StockLedgerService (IN); damaged units
 * are recorded but NOT returned to on-hand. The IN cost is the cost the units
 * left at (carried on the line as unit_cost), so value reconciles with the
 * shipment's COGS. Records a sales_return.posted outbox event (reverse COGS hint).
 * Never writes stock tables directly; never creates credit notes/accounting.
 */
class SalesReturnService
{
    /** Conditions whose units physically re-enter stock. */
    private const STOCKABLE = ['resellable', 'quarantine'];

    /** Map a return line condition to the resulting serial lifecycle status. */
    private const SERIAL_DISPOSITION = [
        'resellable' => 'available',
        'quarantine' => 'quarantined',
        'damaged' => 'damaged',
        'retired' => 'retired',
    ];

    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private IntegrationOutboxService $outbox,
        private SerialService $serials,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    private function postNamespace(SalesReturn $r): string
    {
        return 'sales_return:'.$r->id.':post';
    }

    public function createDraft(array $attributes, array $lines): SalesReturn
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($attributes, $lines, $orgId) {
            $sourceShipment = ! empty($attributes['shipment_id'])
                ? Shipment::query()->with('lines')->lockForUpdate()->findOrFail((int) $attributes['shipment_id'])
                : null;
            if ($sourceShipment) {
                if ($sourceShipment->status !== 'posted' || $sourceShipment->reversed_at) {
                    throw new RuntimeException(__('inventory.documents.return_source_invalid'));
                }
                if (mb_strlen(trim((string) ($attributes['reason'] ?? ''))) < 3) {
                    throw new RuntimeException('A source reversal reason of at least 3 characters is required.');
                }
                $existing = SalesReturn::query()
                    ->where('source_reversal_shipment_id', $sourceShipment->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing->fresh('lines');
                }
                $attributes['warehouse_id'] = $sourceShipment->warehouse_id;
                $attributes['is_source_reversal'] = true;
                $attributes['source_reversal_shipment_id'] = $sourceShipment->id;
                $attributes['original_event_uuid'] = IntegrationOutboxEvent::query()
                    ->where('event_type', 'shipment.posted')
                    ->where('aggregate_id', $sourceShipment->id)
                    ->value('event_uuid');
            }
            $r = new SalesReturn(array_merge([
                'status' => 'draft', 'return_date' => $attributes['return_date'] ?? now()->toDateString(),
            ], $this->applyCustomerName($attributes)));
            $r->organization_id = $orgId;
            $r->save();
            $sourceShipment
                ? $this->syncSourceLines($r, $sourceShipment, $orgId)
                : $this->syncLines($r, $lines, $orgId);

            return $r->fresh('lines');
        });
    }

    public function updateDraft(SalesReturn $r, array $attributes, array $lines): SalesReturn
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($r, $attributes, $lines, $orgId) {
            $r = SalesReturn::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->status !== 'draft') {
                throw new RuntimeException("Only a draft sales return can be edited (status '{$r->status}').");
            }
            if ($r->is_source_reversal) {
                throw new RuntimeException(__('inventory.documents.return_source_immutable'));
            }
            $attributes = $this->applyCustomerName($attributes);
            $r->fill(collect($attributes)->only(['return_number', 'shipment_id', 'customer_id', 'customer_name', 'return_date', 'warehouse_id', 'reason', 'notes'])->toArray());
            $r->save();
            $r->lines()->delete();
            $this->syncLines($r, $lines, $orgId);

            return $r->fresh('lines');
        });
    }

    public function post(SalesReturn $r): SalesReturn
    {
        return DB::connection($this->conn())->transaction(function () use ($r) {
            $r = SalesReturn::query()->lockForUpdate()->with('lines')->findOrFail($r->id);
            if ($r->status === 'posted') {
                return $r; // idempotent
            }
            if (! in_array($r->status, ['draft', 'authorized', 'inspected'], true)) {
                throw new RuntimeException("Sales return {$r->id} cannot be posted from status '{$r->status}'.");
            }

            $movements = [];
            foreach ($r->lines as $line) {
                if (! in_array($line->condition, self::STOCKABLE, true)) {
                    continue; // damaged: recorded, not returned to stock
                }
                if (! Decimal::gt((string) $line->returned_qty, '0')) {
                    continue;
                }
                $movements[] = new StockMovement(
                    direction: 'in',
                    itemId: (int) $line->item_id,
                    warehouseId: (int) ($line->warehouse_id ?? $r->warehouse_id),
                    quantity: (string) $line->returned_qty,
                    sourceType: SalesReturn::class,
                    sourceId: (int) $r->id,
                    sourceLineId: (int) $line->id,
                    variantId: $line->variant_id ? (int) $line->variant_id : null,
                    binId: $line->bin_id ? (int) $line->bin_id : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    serialId: $line->serial_id ? (int) $line->serial_id : null,
                    unitCost: Decimal::cost((string) ($line->unit_cost ?? '0')),
                    movedAt: $r->return_date?->toDateTimeString() ?? now()->toDateTimeString(),
                );
            }

            if ($r->is_source_reversal) {
                $shipment = Shipment::query()->lockForUpdate()->findOrFail($r->source_reversal_shipment_id);
                if ($shipment->reversal_sales_return_id && (int) $shipment->reversal_sales_return_id !== (int) $r->id) {
                    throw new RuntimeException(__('inventory.documents.shipment_already_reversed'));
                }
                $this->ledger->reverse(
                    'shipment:'.$shipment->id.':post',
                    $this->postNamespace($r),
                    [
                        'action' => 'shipment.reverse',
                        'entity_type' => 'sales_return',
                        'entity_id' => $r->id,
                        'document_ref' => $r->return_number,
                    ],
                    SalesReturn::class,
                    $r->id,
                );
                $shipment->reversal_sales_return_id = $r->id;
                $shipment->reversed_at = now();
                $shipment->reversed_by = auth()->id();
                $shipment->markSystemTransition()->save();
            } elseif ($movements !== []) {
                $this->ledger->post($movements, $this->postNamespace($r), [
                    'action' => 'sales_return.post', 'entity_type' => 'sales_return',
                    'entity_id' => $r->id, 'document_ref' => $r->return_number,
                ]);
            }

            // Serial disposition: stockable serials are already set to in_stock by
            // the engine's IN; mark non-stockable (damaged/retired) and quarantine
            // serials to their disposition status so they do not read as available.
            foreach ($r->lines as $line) {
                if (! $line->serial_id) {
                    continue;
                }
                $status = self::SERIAL_DISPOSITION[$line->condition] ?? 'returned';
                $serial = SerialNumber::query()->find($line->serial_id);
                if ($serial) {
                    $this->serials->setStatus($serial, $status, ['sales_return_id' => $r->id, 'warehouse_id' => $line->warehouse_id ?? $r->warehouse_id]);
                }
            }

            $r->status = 'posted';
            $r->posted_at = now();
            $r->posted_by = auth()->id();
            $r->posted_guard_key = $this->postNamespace($r);
            $r->markSystemTransition()->save();

            $event = $this->outbox->record('sales_return.posted', $r, 'sales_return', $r->return_number, (string) $r->return_date);
            if ($event) {
                $r->reversal_event_uuid = $event->event_uuid;
                $r->markSystemTransition()->save();
            }

            return $r->fresh('lines');
        });
    }

    public function authorizeReturn(SalesReturn $r): SalesReturn
    {
        return DB::connection($this->conn())->transaction(function () use ($r) {
            $r = SalesReturn::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->status === 'authorized') {
                return $r;
            }
            if ($r->status !== 'draft') {
                throw new RuntimeException("Only a draft return can be authorized (status '{$r->status}').");
            }
            $r->status = 'authorized';
            $r->authorized_at = now();
            $r->authorized_by = auth()->id();
            $r->save();

            return $r->fresh('lines');
        });
    }

    public function inspect(SalesReturn $r, ?string $notes = null): SalesReturn
    {
        return DB::connection($this->conn())->transaction(function () use ($r, $notes) {
            $r = SalesReturn::query()->lockForUpdate()->with('lines')->findOrFail($r->id);
            if (! in_array($r->status, ['authorized', 'inspected'], true)) {
                throw new RuntimeException("Return must be authorized before inspection (status '{$r->status}').");
            }
            foreach ($r->lines as $line) {
                $line->inspection_status = match ($line->condition) {
                    'resellable' => 'accepted',
                    'quarantine' => 'quarantine',
                    'damaged' => 'damaged',
                    'retired' => 'retired',
                    default => 'accepted',
                };
                $line->disposition = match ($line->condition) {
                    'resellable' => 'restock',
                    'quarantine' => 'quarantine',
                    'damaged' => 'damage',
                    'retired' => 'retire',
                    default => 'restock',
                };
                $line->save();
            }
            $r->status = 'inspected';
            $r->inspected_at = now();
            $r->inspected_by = auth()->id();
            $r->inspection_notes = $notes;
            $r->save();

            return $r->fresh('lines');
        });
    }

    private function syncLines(SalesReturn $r, array $lines, int $orgId): void
    {
        foreach ($lines as $line) {
            $r->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? $r->warehouse_id,
                'bin_id' => $line['bin_id'] ?? null,
                'returned_qty' => Decimal::qty((string) $line['returned_qty']),
                'unit_cost' => Decimal::cost((string) ($line['unit_cost'] ?? '0')),
                'condition' => $line['condition'] ?? 'resellable',
                'inspection_status' => $line['inspection_status'] ?? 'pending',
                'disposition' => $line['disposition'] ?? match ($line['condition'] ?? 'resellable') {
                    'resellable' => 'restock',
                    'quarantine' => 'quarantine',
                    'damaged' => 'damage',
                    'retired' => 'retire',
                    default => 'restock',
                },
                'lot_id' => $line['lot_id'] ?? null,
                'serial_id' => $line['serial_id'] ?? null,
            ]);
        }
    }

    private function syncSourceLines(SalesReturn $return, Shipment $shipment, int $orgId): void
    {
        $ledger = StockLedger::query()
            ->where('source_type', Shipment::class)
            ->where('source_id', $shipment->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->get();
        if ($ledger->isEmpty()) {
            throw new RuntimeException(__('inventory.documents.shipment_no_ledger'));
        }

        foreach ($ledger as $row) {
            $return->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $row->item_id,
                'variant_id' => $row->variant_id,
                'warehouse_id' => $row->warehouse_id,
                'bin_id' => $row->bin_id,
                'returned_qty' => $row->quantity,
                'unit_cost' => $row->unit_cost,
                'condition' => 'resellable',
                'inspection_status' => 'pending',
                'disposition' => 'restock',
                'lot_id' => $row->lot_id,
                'serial_id' => $row->serial_id,
            ]);
        }
    }

    private function applyCustomerName(array $attributes): array
    {
        if (! empty($attributes['customer_id'])) {
            $customer = Customer::query()->find((int) $attributes['customer_id']);
            if ($customer) {
                $attributes['customer_name'] = $attributes['customer_name'] ?? $customer->name;
            }
        }

        return $attributes;
    }
}
