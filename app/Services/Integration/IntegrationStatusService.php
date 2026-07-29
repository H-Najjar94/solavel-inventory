<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\IntegrationTaxMapping;
use App\Models\Tenant\InventorySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only integration status + health for the settings page, sync dashboard,
 * and event viewer summary.
 */
class IntegrationStatusService
{
    public const MODES = ['disconnected', 'connected_readonly', 'connected_pending_mapping', 'active', 'paused', 'error'];

    public const REQUIRED_ACCOUNT_MAPPINGS = [
        'inventory_asset', 'cogs', 'adjustment_gain', 'adjustment_loss', 'grni',
        'landed_cost_clearing', 'transfer_clearing', 'opening_offset',
        'sales_returns', 'purchase_returns',
        'accounts_receivable', 'sales_revenue',
    ];

    public function status(int $orgId): array
    {
        $safety = app(IntegrationSafetyHold::class);
        $settings = IntegrationSetting::query()->firstOrNew([
            'organization_id' => $orgId, 'integration' => IntegrationEvents::INTEGRATION,
        ]);
        $sharedFinanceOrgId = $this->sharedFinanceOrganizationId($orgId);
        $workspaceConnected = $sharedFinanceOrgId !== null;
        $configuredMode = $settings->mode ?? 'disconnected';
        // SolaStock and SolaBooks already share the client's tenant database and
        // organization registry. Without an outbox API credential that is a real
        // read-only workspace connection, not a disconnected product.
        $mode = $configuredMode === 'disconnected' && $workspaceConnected
            ? 'connected_readonly'
            : $configuredMode;

        $events = IntegrationOutboxEvent::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION);
        $pending = (clone $events)->where('status', 'pending')->count();
        $failed = (clone $events)->where('status', 'failed')->count();
        $sent = (clone $events)->where('status', 'sent')->count();
        $lastSuccessfulDeliveryAt = $settings->meta['last_signed_delivery_at']
            ?? (clone $events)->where('status', 'sent')->max('sent_at');
        $ignored = (clone $events)->where('status', 'ignored')->get(['event_type', 'payload'])
            ->filter(fn (IntegrationOutboxEvent $event) => IntegrationEvents::postsJournalForPayload(
                (string) $event->event_type,
                (array) $event->payload
            ))->count();
        $incompleteMapping = (clone $events)->where('mapping_status', 'incomplete')->whereIn('status', ['pending', 'failed'])->count();

        $mapped = IntegrationAccountMapping::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('status', ['mapped', 'verified'])->pluck('mapping_type')->all();
        $mappingCompleteness = round(count(array_intersect(self::REQUIRED_ACCOUNT_MAPPINGS, $mapped)) / count(self::REQUIRED_ACCOUNT_MAPPINGS) * 100);
        $taxCodes = collect((array) (InventorySetting::query()->first()?->taxes ?? []))
            ->where('active', true)->pluck('code')->filter()->unique()->values();
        $mappedTaxCodes = IntegrationTaxMapping::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->where('status', 'mapped')
            ->pluck('tax_code')->unique();
        $taxMappingCompleteness = $taxCodes->isEmpty()
            ? 100
            : round($taxCodes->intersect($mappedTaxCodes)->count() / $taxCodes->count() * 100);

        $health = match (true) {
            ! $safety->deliveryEnabled() => 'maintenance_hold',
            $mode === 'disconnected' => 'disconnected',
            $failed > 0 => 'error',
            $incompleteMapping > 0 => 'needs_mapping',
            default => 'healthy',
        };

        return [
            'integration' => IntegrationEvents::INTEGRATION,
            'mode' => $mode,
            'workspace_connected' => $workspaceConnected,
            'solabooks_organization_id' => $settings->solabooks_organization_id ?: $sharedFinanceOrgId,
            'last_sync_at' => $settings->last_sync_at,
            'last_error' => $settings->last_error,
            'require_mapping_before_post' => (bool) ($settings->require_mapping_before_post ?? false),
            'health' => $health,
            'events' => compact('pending', 'failed', 'sent', 'ignored'),
            'documents_awaiting_sync' => $pending,
            'mapping_incomplete_events' => $incompleteMapping,
            'mapping_completeness_pct' => $mappingCompleteness,
            'tax_mapping_completeness_pct' => $taxMappingCompleteness,
            'last_event_generated_at' => IntegrationOutboxEvent::query()->where('organization_id', $orgId)->max('occurred_at'),
            'connection_implemented' => $workspaceConnected || $settings->exists,
            'delivery_configured' => (bool) (($settings->apiKey() || config('services.solabooks.api_key'))
                && ($settings->meta['client_id'] ?? config('services.solabooks.client_id'))
                && ($settings->solabooks_organization_id || config('services.solabooks.organization_id'))),
            'delivery_enabled' => $safety->deliveryEnabled(),
            'delivery_disabled_reason' => $safety->deliveryEnabled() ? null : $safety->reason(),
            'delivery_disabled_message' => $safety->deliveryEnabled() ? null : $safety->message(),
            'last_successful_delivery_at' => $lastSuccessfulDeliveryAt,
            'legacy_finance_inventory_writes_blocked' => (bool) config('integration_safety.legacy_finance_inventory_writes_blocked', false)
                && ($workspaceConnected || in_array($mode, ['connected_readonly', 'connected_pending_mapping', 'active', 'paused'], true)),
            'signing' => [
                'configured' => (bool) ($settings->signingSecret() && ($settings->meta['signing_key_id'] ?? null)),
                'key_id' => $settings->meta['signing_key_id'] ?? null,
                'protocol_version' => $settings->meta['signing_protocol_version'] ?? null,
                'rotated_at' => $settings->meta['signing_key_rotated_at'] ?? null,
                'last_successful_delivery_at' => $lastSuccessfulDeliveryAt,
            ],
        ];
    }

    protected function sharedFinanceOrganizationId(int $orgId): ?int
    {
        try {
            if (! Schema::connection('tenant')->hasTable('organizations')
                || ! Schema::connection('tenant')->hasColumn('organizations', 'central_org_id')) {
                return null;
            }

            $id = DB::connection('tenant')->table('organizations')
                ->where('central_org_id', $orgId)
                ->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
