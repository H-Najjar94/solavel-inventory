<?php

namespace App\Services\Documents;

use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Whether landed costs are AVAILABLE to an organization (pages, menu, drafts),
 * kept apart from whether a connected organization is READY TO POST them
 * (LandedCostWorkflow: a reviewed clearing account).
 *
 * Available = the plan includes stock.landed_costs (the same commercial gate as
 * every other paid stock.* feature, honouring the same enforcement switch) AND the
 * organization has not explicitly opted out. No per-organization switch-on step
 * exists: availability follows the plan. Computing it writes nothing, and turning
 * the opt-out on or off never allocates, revalues or records an accounting event.
 */
final class LandedCostAvailability
{
    public const FEATURE = 'stock.landed_costs';

    public const COLUMN = 'landed_costs_opted_out_at';

    public function __construct(private InventoryCommercialEntitlementService $entitlements) {}

    /**
     * @return array{available:bool,reason:string,entitled:bool,entitlement_reason:string,opted_out:bool,opted_out_at:?string,preference_supported:bool}
     */
    public function status(int $organizationId): array
    {
        $entitlement = $this->entitlement();
        $optedOutAt = $this->optedOutAt($organizationId);
        $reason = match (true) {
            ! $entitlement['allowed'] => $entitlement['reason_code'] === 'feature_not_in_plan' ? 'not_in_plan' : $entitlement['reason_code'],
            $optedOutAt !== null => 'opted_out',
            default => 'available',
        };

        return [
            'available' => $entitlement['allowed'] && $optedOutAt === null,
            'reason' => $reason,
            'entitled' => $entitlement['allowed'],
            'entitlement_reason' => $entitlement['reason_code'],
            'opted_out' => $optedOutAt !== null,
            'opted_out_at' => $optedOutAt,
            'preference_supported' => $this->preferenceSupported(),
        ];
    }

    /** Document writes (draft, post, reverse, receipt picker) respect an explicit opt-out. */
    public function assertWritable(int $organizationId): void
    {
        $status = $this->status($organizationId);
        if (! $status['entitled']) {
            // The route gate already refuses with 402; this covers non-HTTP callers.
            throw ValidationException::withMessages(['landed_cost' => __('inventory.landed_cost.not_in_plan')]);
        }
        if ($status['opted_out']) {
            throw ValidationException::withMessages(['landed_cost' => __('inventory.landed_cost.opted_out')]);
        }
    }

    /**
     * Records or withdraws the organization's explicit opt-out. Only this method
     * writes the column, so a plan change, a deploy or a connection review never
     * resets it. It touches inventory_settings and the audit log only.
     */
    public function setOptOut(int $organizationId, bool $optOut, int $actorUserId): array
    {
        if (! $this->preferenceSupported()) {
            throw ValidationException::withMessages(['landed_cost' => __('inventory.landed_cost.preference_migration_pending')]);
        }
        DB::connection($this->connection())->transaction(function () use ($organizationId, $optOut, $actorUserId): void {
            InventorySetting::withoutGlobalScopes()->firstOrCreate(['organization_id' => $organizationId]);
            $settings = InventorySetting::withoutGlobalScopes()->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
            $before = $settings->getAttribute(self::COLUMN);
            if (($before !== null) === $optOut) {
                return; // idempotent: an existing opt-out keeps its original time
            }
            $settings->setAttribute(self::COLUMN, $optOut ? now() : null);
            $settings->save();
            InventoryAuditLog::create([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'action' => $optOut ? 'inventory.landed_costs.opted_out' : 'inventory.landed_costs.opt_out_withdrawn',
                'entity_type' => 'inventory_settings',
                'entity_id' => $settings->id,
                'before' => [self::COLUMN => $before ? (string) $before : null],
                'after' => [self::COLUMN => $optOut ? now()->toIso8601String() : null],
                'created_at' => now(),
            ]);
        });

        return $this->status($organizationId);
    }

    /** @return array{allowed:bool,reason_code:string} */
    private function entitlement(): array
    {
        // Same dark-launch semantics as EnsureInventoryFeature: with enforcement
        // off no stock.* feature is plan-gated, so neither is this one.
        if (! (bool) config('inventory_entitlements.feature_enforcement', false)) {
            return ['allowed' => true, 'reason_code' => 'feature_enforcement_off'];
        }
        $decision = $this->entitlements->checkFeature(self::FEATURE);

        return ['allowed' => (bool) $decision['allowed'], 'reason_code' => (string) $decision['reason_code']];
    }

    private function optedOutAt(int $organizationId): ?string
    {
        if (! $this->preferenceSupported()) {
            return null; // migration pending: no opt-out can exist yet
        }
        $value = InventorySetting::withoutGlobalScopes()->where('organization_id', $organizationId)->value(self::COLUMN);

        return $value !== null ? (string) $value : null;
    }

    private function preferenceSupported(): bool
    {
        return Schema::connection($this->connection())->hasColumn('inventory_settings', self::COLUMN);
    }

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }
}
