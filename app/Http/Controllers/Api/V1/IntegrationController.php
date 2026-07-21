<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\IntegrationTaxMapping;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemIntegrationMapping;
use App\Services\Documents\SourceDocumentPresenter;
use App\Services\Integration\IntegrationEvents;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Integration\IntegrationStatusService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * SolaBooks integration API. Posting stays outbox-only inside inventory
 * transactions; retries deliver over SolaBooks' external API and never write
 * directly into Finance tables.
 */
class IntegrationController extends ApiController
{
    public function __construct(
        private OrganizationContext $context,
        private IntegrationStatusService $statusService,
        private SolaBooksOutboxDeliveryService $delivery,
        private IntegrationOutboxService $outbox,
    ) {}

    public function status(): JsonResponse
    {
        return $this->success($this->statusService->status($this->context->idOrFail()));
    }

    public function configure(Request $request): JsonResponse
    {
        $orgId = $this->context->idOrFail();
        $data = $request->validate([
            'mode' => ['required', 'in:disconnected,connected_readonly,connected_pending_mapping,active,paused'],
            'solabooks_organization_id' => ['required_unless:mode,disconnected', 'nullable', 'integer', 'min:1'],
            'client_id' => ['required_unless:mode,disconnected', 'nullable', 'integer', 'min:1'],
            'api_key' => ['nullable', 'string', 'min:32', 'max:255'],
            'require_mapping_before_post' => ['boolean'],
        ]);
        $setting = IntegrationSetting::query()->firstOrNew([
            'organization_id' => $orgId,
            'integration' => IntegrationEvents::INTEGRATION,
        ]);
        $meta = $setting->meta ?? [];
        if (! empty($data['api_key'])) {
            $meta['api_key_encrypted'] = Crypt::encryptString($data['api_key']);
        }
        if (! empty($data['client_id'])) {
            $meta['client_id'] = (int) $data['client_id'];
        }
        if ($data['mode'] !== 'disconnected' && empty($meta['api_key_encrypted'])) {
            return $this->error('api_key_required', 'A SolaBooks API key is required to connect.', 422);
        }
        $setting->fill([
            'mode' => $data['mode'],
            'solabooks_organization_id' => $data['solabooks_organization_id'] ?? null,
            'require_mapping_before_post' => $data['require_mapping_before_post'] ?? true,
            'meta' => $meta,
            'last_error' => null,
        ])->save();
        InventoryAuditLog::create([
            'organization_id' => $orgId,
            'actor_user_id' => auth()->id(),
            'action' => 'inventory.solabooks_connection.updated',
            'entity_type' => 'integration_settings',
            'entity_id' => $setting->id,
            'after' => ['mode' => $setting->mode, 'solabooks_organization_id' => $setting->solabooks_organization_id, 'credential_configured' => ! empty($meta['api_key_encrypted'])],
            'created_at' => now(),
        ]);

        return $this->status();
    }

    public function rotateSigningKey(): JsonResponse
    {
        $orgId = $this->context->idOrFail();
        try {
            $setting = $this->delivery->rotateSigningKey();
        } catch (\RuntimeException $e) {
            return $this->error('signing_key_rotation_failed', $e->getMessage(), 422);
        }
        InventoryAuditLog::create([
            'organization_id' => $orgId,
            'actor_user_id' => auth()->id(),
            'action' => 'inventory.solabooks_signing_key.rotated',
            'entity_type' => 'integration_settings',
            'entity_id' => $setting->id,
            'after' => [
                'key_id' => $setting->meta['signing_key_id'] ?? null,
                'protocol_version' => $setting->meta['signing_protocol_version'] ?? null,
            ],
            'created_at' => now(),
        ]);

        return $this->status();
    }

    public function revokeSigningKey(Request $request): JsonResponse
    {
        $data = $request->validate(['key_id' => ['required', 'string', 'max:64']]);
        try {
            $this->delivery->revokeSigningKey($data['key_id']);
        } catch (\RuntimeException $e) {
            return $this->error('signing_key_revoke_failed', $e->getMessage(), 422);
        }
        InventoryAuditLog::create([
            'organization_id' => $this->context->idOrFail(),
            'actor_user_id' => auth()->id(),
            'action' => 'inventory.solabooks_signing_key.revoked',
            'entity_type' => 'integration_settings',
            'entity_id' => null,
            'after' => ['key_id' => $data['key_id']],
            'created_at' => now(),
        ]);

        return $this->status();
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
        $this->outbox->refreshMappingStatus($orgId);

        return $this->accountMappings();
    }

    public function taxMappings(): JsonResponse
    {
        $existing = IntegrationTaxMapping::query()
            ->where('integration', IntegrationEvents::INTEGRATION)->get()->keyBy('tax_code');
        $definitions = collect((array) (InventorySetting::query()->first()?->taxes ?? []));

        return $this->success(['mappings' => $definitions->map(fn (array $tax) => [
            'tax_code' => $tax['code'],
            'tax_name' => $tax['name'],
            'treatment' => $tax['treatment'] ?? 'standard',
            'active' => (bool) ($tax['active'] ?? false),
            'solabooks_tax_id' => $existing[$tax['code']]->solabooks_tax_id ?? null,
            'solabooks_tax_code' => $existing[$tax['code']]->solabooks_tax_code ?? null,
            'input_tax_account_id' => $existing[$tax['code']]->input_tax_account_id ?? null,
            'output_tax_account_id' => $existing[$tax['code']]->output_tax_account_id ?? null,
            'status' => $existing[$tax['code']]->status ?? 'unmapped',
        ])->values()]);
    }

    public function updateTaxMappings(Request $request): JsonResponse
    {
        $orgId = $this->context->idOrFail();
        $definitions = collect((array) (InventorySetting::query()->first()?->taxes ?? []))->keyBy('code');
        $data = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*.tax_code' => ['required', 'string', 'max:50'],
            'mappings.*.solabooks_tax_id' => ['nullable', 'integer', 'min:1'],
            'mappings.*.solabooks_tax_code' => ['nullable', 'string', 'max:50'],
            'mappings.*.input_tax_account_id' => ['nullable', 'integer', 'min:1'],
            'mappings.*.output_tax_account_id' => ['nullable', 'integer', 'min:1'],
            'mappings.*.status' => ['required', 'in:unmapped,mapped,inactive'],
        ]);

        foreach ($data['mappings'] as $mapping) {
            $definition = $definitions[$mapping['tax_code']] ?? null;
            abort_unless($definition, 422, 'Every tax mapping must reference an organization tax code.');
            if ($mapping['status'] === 'mapped') {
                abort_unless(! empty($mapping['solabooks_tax_id']) && ! empty($mapping['solabooks_tax_code']), 422, 'Mapped taxes require a stable SolaBooks tax ID and code.');
                if (($definition['treatment'] ?? 'standard') === 'standard') {
                    abort_unless(! empty($mapping['input_tax_account_id']) && ! empty($mapping['output_tax_account_id']), 422, 'Standard tax mappings require input and output tax accounts.');
                }
            }
            IntegrationTaxMapping::query()->updateOrCreate(
                ['organization_id' => $orgId, 'integration' => IntegrationEvents::INTEGRATION, 'tax_code' => $mapping['tax_code']],
                [
                    'treatment' => $definition['treatment'] ?? 'standard',
                    'solabooks_tax_id' => $mapping['solabooks_tax_id'] ?? null,
                    'solabooks_tax_code' => $mapping['solabooks_tax_code'] ?? null,
                    'input_tax_account_id' => $mapping['input_tax_account_id'] ?? null,
                    'output_tax_account_id' => $mapping['output_tax_account_id'] ?? null,
                    'status' => $mapping['status'],
                ]
            );
        }
        $this->outbox->refreshMappingStatus($orgId);
        InventoryAuditLog::create([
            'organization_id' => $orgId, 'actor_user_id' => auth()->id(),
            'action' => 'inventory.solabooks_tax_mappings.updated', 'entity_type' => 'integration_tax_mappings',
            'entity_id' => null, 'after' => ['tax_codes' => collect($data['mappings'])->pluck('tax_code')->all()], 'created_at' => now(),
        ]);

        return $this->taxMappings();
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

        $page = $query->paginate($perPage)->withQueryString();
        $page->setCollection(SourceDocumentPresenter::decorateRows($page->getCollection(), 'aggregate_type', 'aggregate_id'));

        return $this->paginated($page);
    }

    public function event(int $event): JsonResponse
    {
        return $this->success(IntegrationOutboxEvent::query()->findOrFail($event));
    }

    public function retry(int $event): JsonResponse
    {
        $event = IntegrationOutboxEvent::query()->findOrFail($event);

        try {
            return $this->success($this->delivery->deliver($event, manual: true)->fresh());
        } catch (\Throwable $e) {
            return $this->error('delivery_failed', $e->getMessage(), 422, [
                'event_uuid' => $event->event_uuid,
            ]);
        }
    }

    public function retryPlaceholder(int $event): JsonResponse
    {
        return $this->retry($event);
    }

    /** Mark an event ignored (local-only state change; no external call). */
    public function ignore(int $event): JsonResponse
    {
        $event = IntegrationOutboxEvent::query()->findOrFail($event);
        if (in_array($event->status, ['pending', 'failed'], true)) {
            $event->update(['status' => 'ignored']);

            return $this->success(['status' => 'ignored', 'event_uuid' => $event->event_uuid]);
        }

        return $this->error('cannot_ignore', "An event with status '{$event->status}' cannot be ignored.", 422);
    }

    public function ignorePlaceholder(int $event): JsonResponse
    {
        return $this->ignore($event);
    }
}
