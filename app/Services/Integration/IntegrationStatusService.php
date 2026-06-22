<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;

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
    ];

    public function status(int $orgId): array
    {
        $settings = IntegrationSetting::query()->firstOrNew([
            'organization_id' => $orgId, 'integration' => IntegrationEvents::INTEGRATION,
        ]);
        $mode = $settings->mode ?? 'disconnected';

        $events = IntegrationOutboxEvent::query()->where('integration', IntegrationEvents::INTEGRATION);
        $pending = (clone $events)->where('status', 'pending')->count();
        $failed = (clone $events)->where('status', 'failed')->count();
        $sent = (clone $events)->where('status', 'sent')->count();
        $ignored = (clone $events)->where('status', 'ignored')->count();
        $incompleteMapping = (clone $events)->where('mapping_status', 'incomplete')->whereIn('status', ['pending', 'failed'])->count();

        $mapped = IntegrationAccountMapping::query()
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('status', ['mapped', 'verified'])->pluck('mapping_type')->all();
        $mappingCompleteness = round(count(array_intersect(self::REQUIRED_ACCOUNT_MAPPINGS, $mapped)) / count(self::REQUIRED_ACCOUNT_MAPPINGS) * 100);

        $health = match (true) {
            $mode === 'disconnected' => 'disconnected',
            $failed > 0 => 'error',
            $incompleteMapping > 0 => 'needs_mapping',
            default => 'healthy',
        };

        return [
            'integration' => IntegrationEvents::INTEGRATION,
            'mode' => $mode,
            'solabooks_organization_id' => $settings->solabooks_organization_id,
            'last_sync_at' => $settings->last_sync_at,
            'last_error' => $settings->last_error,
            'require_mapping_before_post' => (bool) ($settings->require_mapping_before_post ?? false),
            'health' => $health,
            'events' => compact('pending', 'failed', 'sent', 'ignored'),
            'documents_awaiting_sync' => $pending,
            'mapping_incomplete_events' => $incompleteMapping,
            'mapping_completeness_pct' => $mappingCompleteness,
            'last_event_generated_at' => IntegrationOutboxEvent::query()->max('occurred_at'),
            'connection_implemented' => false, // real SSO/app-linking not wired yet
        ];
    }
}
