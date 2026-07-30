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
use App\Services\Integration\DeadLetterReviewService;
use App\Services\Integration\DurableOutboxTransportService;
use App\Services\Integration\IntegrationEvents;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Integration\IntegrationSafetyHold;
use App\Services\Integration\IntegrationStatusService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

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
        private IntegrationSafetyHold $safety,
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
        $existing = IntegrationSetting::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->first();
        $activating = $data['mode'] !== 'disconnected'
            && (! $existing || ($existing->mode ?? 'disconnected') === 'disconnected');
        if ($activating && ! $this->safety->deliveryEnabled()) {
            return $this->error('integration_safety_hold', $this->safety->message(), 423, [
                'reason' => $this->safety->reason(),
            ]);
        }
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
            return $this->error('api_key_required', __('inventory.integration.api_key_required'), 422);
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
            abort_unless($definition, 422, __('inventory.integration.tax_mapping_code'));
            if ($mapping['status'] === 'mapped') {
                abort_unless(! empty($mapping['solabooks_tax_id']) && ! empty($mapping['solabooks_tax_code']), 422, __('inventory.integration.mapped_tax_id'));
                if (($definition['treatment'] ?? 'standard') === 'standard') {
                    abort_unless(! empty($mapping['input_tax_account_id']) && ! empty($mapping['output_tax_account_id']), 422, __('inventory.integration.standard_tax_accounts'));
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
            ->when($request->filled('failure_category'), fn ($q) => $q->where('failure_category', $request->query('failure_category')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = mb_substr((string) $request->query('search'), 0, 100);
                $query->where(fn ($query) => $query
                    ->where('event_uuid', 'like', "%{$search}%")
                    ->orWhere('aggregate_number', 'like', "%{$search}%")
                    ->orWhere('failure_code', 'like', "%{$search}%"));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->query('to')))
            ->orderByDesc('id');

        $page = $query->paginate($perPage)->withQueryString();
        $page->setCollection(SourceDocumentPresenter::decorateRows($page->getCollection(), 'aggregate_type', 'aggregate_id'));

        return $this->paginated($page);
    }

    public function event(int $event): JsonResponse
    {
        $row = IntegrationOutboxEvent::query()->findOrFail($event);
        $workflow = DB::connection('tenant')->table('integration_document_lifecycle_mappings')
            ->where('solastock_organization_id', $this->context->idOrFail())
            ->where('source_document_id', (string) $row->aggregate_id)
            ->orderByDesc('id')
            ->first([
                'mapping_uuid', 'source_document_type', 'destination_document_type',
                'destination_document_id', 'lifecycle_status', 'matching_state',
                'conflict_code', 'last_verified_at',
            ]);
        $transitions = DB::connection('tenant')->table('integration_outbox_transition_audits')
            ->where('organization_id', $this->context->idOrFail())
            ->where('event_id', $row->id)
            ->orderBy('state_version')
            ->get(['from_status', 'to_status', 'state_version', 'reason_code', 'actor_type', 'created_at']);
        $reviews = DB::connection('tenant')->table('integration_dead_letter_reviews')
            ->where('organization_id', $this->context->idOrFail())
            ->where('event_id', $row->id)->orderBy('reviewed_at')
            ->get(['action', 'required_recovery_action', 'reviewer_user_id', 'review_note', 'reviewed_at']);

        return $this->success([
            'event' => $row,
            'workflow' => $workflow,
            'transition_history' => $transitions,
            'review_history' => $reviews,
        ]);
    }

    public function deadLetters(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);
        $page = IntegrationOutboxEvent::query()
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->where('status', 'dead_letter')
            ->when($request->filled('failure_category'), fn ($q) => $q->where('failure_category', $request->query('failure_category')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = mb_substr((string) $request->query('search'), 0, 100);
                $query->where(fn ($query) => $query
                    ->where('event_uuid', 'like', "%{$search}%")
                    ->orWhere('aggregate_number', 'like', "%{$search}%")
                    ->orWhere('failure_code', 'like', "%{$search}%"));
            })
            ->orderByDesc('dead_lettered_at')->paginate($perPage)->withQueryString();

        return $this->paginated($page);
    }

    public function reviewDeadLetter(
        Request $request,
        int $event,
        DeadLetterReviewService $reviews,
    ): JsonResponse {
        if (! $this->safety->deliveryEnabled()) {
            return $this->error('integration_safety_hold', $this->safety->message(), 423, [
                'reason' => $this->safety->reason(),
            ]);
        }
        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
            'retry' => ['required', 'boolean'],
        ]);
        try {
            $result = $reviews->review(
                IntegrationOutboxEvent::query()->findOrFail($event),
                (int) auth()->id(),
                $data['note'],
                (bool) $data['retry'],
            );
        } catch (\Throwable $e) {
            return $this->error('dead_letter_review_rejected', $e->getMessage(), 422);
        }

        return $this->success($result);
    }

    public function retry(int $event, ?DurableOutboxTransportService $transport = null): JsonResponse
    {
        $event = IntegrationOutboxEvent::query()->findOrFail($event);

        if (! $this->safety->deliveryEnabled()) {
            return $this->error('integration_safety_hold', $this->safety->message(), 423, [
                'reason' => $this->safety->reason(),
                'event_uuid' => $event->event_uuid,
            ]);
        }

        try {
            $transport ??= app(DurableOutboxTransportService::class);

            return $this->success($transport->queueReviewedRetry($event, (int) auth()->id()));
        } catch (\Throwable $e) {
            return $this->error('delivery_failed', $e->getMessage(), 422, [
                'event_uuid' => $event->event_uuid,
            ]);
        }
    }

    public function retryPlaceholder(int $event): JsonResponse
    {
        return $this->retry($event, app(DurableOutboxTransportService::class));
    }

    /** Mark an event ignored (local-only state change; no external call). */
    public function ignore(int $event): JsonResponse
    {
        if (! $this->safety->deliveryEnabled()) {
            return $this->error('integration_safety_hold', $this->safety->message(), 423, [
                'reason' => $this->safety->reason(),
            ]);
        }

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
