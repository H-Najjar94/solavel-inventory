<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemIntegrationMapping;
use App\Services\Integration\IntegrationEvents;
use App\Services\Integration\IntegrationStatusService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SolaBooks integration API — FOUNDATION ONLY. No real connection, no posting, no
 * journal entries. Manages local mappings + reads the outbox. Retry/ignore are
 * honest placeholders (no worker yet).
 */
class IntegrationController extends ApiController
{
    public function __construct(
        private OrganizationContext $context,
        private IntegrationStatusService $statusService,
    ) {}

    public function status(): JsonResponse
    {
        return $this->success($this->statusService->status($this->context->idOrFail()));
    }

    // ── Account mappings ──
    public function accountMappings(): JsonResponse
    {
        $existing = IntegrationAccountMapping::query()
            ->where('integration', IntegrationEvents::INTEGRATION)->get()->keyBy('mapping_type');

        $rows = collect(IntegrationStatusService::REQUIRED_ACCOUNT_MAPPINGS)->map(fn ($type) => [
            'mapping_type' => $type,
            'solabooks_account_id' => $existing[$type]->solabooks_account_id ?? null,
            'account_code' => $existing[$type]->account_code ?? null,
            'account_name' => $existing[$type]->account_name ?? null,
            'status' => $existing[$type]->status ?? 'unmapped',
            'notes' => $existing[$type]->notes ?? null,
        ]);

        return $this->success(['mappings' => $rows]);
    }

    public function updateAccountMappings(Request $request): JsonResponse
    {
        $orgId = $this->context->idOrFail();
        $data = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*.mapping_type' => ['required', 'string'],
            'mappings.*.solabooks_account_id' => ['nullable', 'string', 'max:191'],
            'mappings.*.account_code' => ['nullable', 'string', 'max:191'],
            'mappings.*.account_name' => ['nullable', 'string', 'max:191'],
            'mappings.*.notes' => ['nullable', 'string'],
        ]);

        foreach ($data['mappings'] as $m) {
            if (! in_array($m['mapping_type'], IntegrationStatusService::REQUIRED_ACCOUNT_MAPPINGS, true)) {
                continue;
            }
            IntegrationAccountMapping::query()->updateOrCreate(
                ['organization_id' => $orgId, 'integration' => IntegrationEvents::INTEGRATION, 'mapping_type' => $m['mapping_type']],
                [
                    'solabooks_account_id' => $m['solabooks_account_id'] ?? null,
                    'account_code' => $m['account_code'] ?? null,
                    'account_name' => $m['account_name'] ?? null,
                    'status' => ! empty($m['solabooks_account_id']) ? 'mapped' : 'unmapped',
                    'notes' => $m['notes'] ?? null,
                ]
            );
        }

        return $this->accountMappings();
    }

    // ── Item mappings ──
    public function itemMappings(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);
        $rows = Item::query()
            ->leftJoin('item_integration_mappings as m', function ($j) {
                $j->on('m.item_id', '=', 'items.id')->where('m.integration', '=', IntegrationEvents::INTEGRATION);
            })
            ->when($request->filled('search'), fn ($q) => $q->where('items.name', 'like', '%'.$request->query('search').'%'))
            ->select('items.id', 'items.sku', 'items.name', 'm.solabooks_item_id', 'm.sync_status', 'm.last_synced_at')
            ->orderBy('items.name')->paginate($perPage)->withQueryString();

        return $this->paginated($rows);
    }

    public function updateItemMapping(Request $request, Item $item): JsonResponse
    {
        $orgId = $this->context->idOrFail();
        $data = $request->validate([
            'solabooks_item_id' => ['nullable', 'string', 'max:191'],
            'income_account_ref' => ['nullable', 'string', 'max:191'],
            'cogs_account_ref' => ['nullable', 'string', 'max:191'],
            'inventory_asset_account_ref' => ['nullable', 'string', 'max:191'],
            'tax_category' => ['nullable', 'string', 'max:100'],
            'external_reference' => ['nullable', 'string', 'max:191'],
        ]);

        $mapping = ItemIntegrationMapping::query()->updateOrCreate(
            ['organization_id' => $orgId, 'integration' => IntegrationEvents::INTEGRATION, 'item_id' => $item->id],
            $data + ['sync_status' => 'not_synced']
        );

        return $this->success($mapping);
    }

    // ── Events (outbox viewer) ──
    public function events(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);
        $query = IntegrationOutboxEvent::query()
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->query('event_type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->query('to')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function event(int $event): JsonResponse
    {
        return $this->success(IntegrationOutboxEvent::query()->findOrFail($event));
    }

    /** Placeholder — honest "not implemented". No worker drains the outbox yet. */
    public function retryPlaceholder(int $event): JsonResponse
    {
        $event = IntegrationOutboxEvent::query()->findOrFail($event);

        return $this->error('not_implemented', 'Event delivery is not implemented yet. Events are recorded locally; a future worker will send them to SolaBooks.', 501, [
            'event_uuid' => $event->event_uuid,
        ]);
    }

    /** Mark an event ignored (local-only state change; no external call). */
    public function ignorePlaceholder(int $event): JsonResponse
    {
        $event = IntegrationOutboxEvent::query()->findOrFail($event);
        if (in_array($event->status, ['pending', 'failed'], true)) {
            $event->update(['status' => 'ignored']);

            return $this->success(['status' => 'ignored', 'event_uuid' => $event->event_uuid]);
        }

        return $this->error('cannot_ignore', "An event with status '{$event->status}' cannot be ignored.", 422);
    }
}
