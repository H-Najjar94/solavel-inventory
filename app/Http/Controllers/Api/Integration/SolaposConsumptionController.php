<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Services\Integration\SolaposConsumptionService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/integration/solapos/consumptions — SolaStock receiver for
 * SolaPOS retail consumption (pos_sale.consumed) and restoration
 * (pos_return.restored). Contract v1.
 *
 * SolaStock is the writer of record: it validates item/warehouse ownership,
 * posts the stock ledger through StockLedgerService (idempotent namespace
 * "solapos:{idempotency_key}"), applies canonical costing (item's method:
 * average | fifo), materialises a PosSaleConsumption document and records the
 * outbox event that carries COGS / inventory-asset to SolaCount. Duplicate
 * delivery (same source key) returns the original result — no second ledger
 * row, no second cost consumption, no second journal.
 */
class SolaposConsumptionController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly OrganizationContext $context,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contract_version' => 'required|integer|in:1',
            'source_app' => 'required|in:solapos',
            'idempotency_key' => 'required|string|max:160',
            'event_type' => 'required|in:pos_sale.consumed,pos_return.restored',
            'solastock_organization_id' => 'required|integer|min:1',
            'order_id' => 'nullable|integer', 'order_number' => 'nullable|string|max:60', 'return_id' => 'nullable|integer', 'return_number' => 'nullable|string|max:60',
            'original_order_id' => 'nullable|integer', 'original_consumption_key' => 'nullable|string|max:160',
            'completed_at' => 'required|date',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.item_id' => 'required|integer|min:1', 'lines.*.item_variant_id' => 'nullable|integer',
            'lines.*.warehouse_id' => 'required|integer|min:1',
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'],
            'lines.*.pos_allocation_id' => 'nullable|integer', 'lines.*.pos_order_item_id' => 'nullable|integer', 'lines.*.pos_order_return_item_id' => 'nullable|integer',
        ]);
        $clientId = (int) $request->header('X-Solavel-Central-Client-Id');
        abort_unless($clientId > 0, 422, 'Central client id header is required.');
        $this->tenants->switchToDatabase($this->tenants->resolveDatabaseName($clientId));
        $orgId = (int) $data['solastock_organization_id'];
        $this->context->set($orgId);

        try {
            $result = app(SolaposConsumptionService::class)->apply($orgId, $data);
        } catch (\RuntimeException $e) {
            // Stock-engine business rules (insufficient cost layers / negative stock disabled) are a
            // PERMANENT condition for this payload, not a transient outage: answer 422 so the sender
            // parks the event for review instead of retrying it into a dead letter. Phase 8 UAT found
            // this surfacing as HTTP 500 and being retried.
            if (str_contains($e->getMessage(), 'insufficient cost layers') || str_contains($e->getMessage(), 'Negative stock is disabled')) {
                Log::warning('SolaPOS consumption refused by the stock engine.', ['organization_id' => $orgId, 'idempotency_key' => $data['idempotency_key']]);

                return response()->json(['error' => 'insufficient_stock', 'message' => $e->getMessage(), 'code' => 'solapos_insufficient_stock'], 422);
            }
            throw $e;
        }

        return response()->json(['data' => $result['data']], $result['replayed'] ? 200 : 201);
    }
}
