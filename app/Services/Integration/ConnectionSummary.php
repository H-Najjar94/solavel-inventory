<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One plain-language answer to "where is the SolaCount ↔ SolaStock connection?",
 * shared by SolaStock's connection page and SolaCount's Connection Status page
 * (which receives it through the signed finance-workspace channel).
 *
 * Every state is derived from stored evidence: Central's entitlement snapshot,
 * Finance setup markers, the automatic-preparation record, the guided setup run,
 * the organization mapping and delivery health. Nothing is inferred from time
 * alone except a preparation that stopped reporting (stalled).
 *
 * States and their single primary action:
 *   plan_required            → manage_plans      (the approved Premium + Premium rule)
 *   finance_setup_required   → finish_finance_setup
 *   finance_provisioning     → finish_finance_setup — SolaCount tenant/org does not exist yet
 *   preparing                → none (auto refresh) — connection activation is running now
 *   failed                   → retry             — preparation stopped with an error
 *   ready_to_connect         → connect           — fresh organization, automatic path
 *   needs_input              → continue          — existing records need business decisions
 *   ready_to_activate        → continue          — review the summary, then connect
 *   connected                → open
 *   needs_attention          → review            — connected, but delivery needs a look
 *   on_hold                  → none              — a safety hold paused delivery
 *   unavailable              → refresh
 */
final class ConnectionSummary
{
    /** A preparation that has not finished after this long has stopped, not "still running". */
    public const STALL_AFTER_SECONDS = 180;

    /** Automatic-preparation outcomes that mean "a person must decide", not "try again". */
    private const REVIEW_REASONS = ['existing_activity_requires_review', 'unscoped_history_requires_review',
        'existing_connection_preserved', 'custom_mapping_preserved', 'immutable_role_requires_review', 'separate_review_required'];

    public function forOrganization(int $orgId, ?array $status = null): array
    {
        $status ??= app(IntegrationStatusService::class)->status($orgId);
        $readiness = (array) ($status['readiness'] ?? []);
        $canManage = (bool) ($readiness['can_manage'] ?? false);
        $setting = IntegrationSetting::query()->where('organization_id', $orgId)->where('integration', 'solabooks')->first();
        $prep = (array) data_get($setting?->meta, 'default_connection', []);
        $wizard = $status['connection_wizard'] ?? null;

        $summary = [
            'organization_id' => $orgId,
            'organization' => $this->organizationName($orgId),
            'can_manage' => $canManage,
            'plan_requirement' => $status['plan_requirement'] ?? null,
            'reason' => null,
            'progress' => null,
            'task' => null,
            'health' => $status['health'] ?? null,
            'last_sync_at' => $status['last_sync_at'] ?? null,
            'connected_at' => data_get($prep, 'configured_at') ?? data_get($wizard, 'activated_at'),
            'checked_at' => now()->toIso8601String(),
            'links' => $this->links($orgId, $readiness),
        ];

        $state = match (true) {
            $summary['plan_requirement'] !== null => 'plan_required',
            ($readiness['state'] ?? null) === 'ACCESS_REQUIRED' => 'plan_required',
            ($readiness['state'] ?? null) === 'READINESS_UNAVAILABLE' => 'unavailable',
            ($readiness['state'] ?? null) === 'PROVISIONING_PENDING' => 'finance_provisioning',
            ($readiness['state'] ?? null) === 'FINANCE_PROVISIONED_SETUP_INCOMPLETE' => 'finance_setup_required',
            ($readiness['state'] ?? null) === 'MAINTENANCE_HOLD' => 'on_hold',
            ($readiness['state'] ?? null) === 'CONNECTED_READY' => 'connected',
            ($readiness['state'] ?? null) === 'CONNECTION_BLOCKED' => 'needs_attention',
            default => null,
        };
        if (($readiness['state'] ?? null) === 'PROVISIONING_PENDING') {
            $summary['reason'] = 'finance_provisioning';
        }
        if ($state === 'needs_attention') {
            $summary['reason'] = collect((array) ($readiness['blockers'] ?? []))
                ->first(fn ($b) => ! in_array($b, ['connection_setup_incomplete'], true)) ?? 'delivery_paused';
        }

        // Finance is ready and the connection is not active yet: find where setup really is.
        if ($state === null) {
            [$state, $summary] = $this->setupState($orgId, $prep, $setting, $wizard, $summary);
        }

        $summary['state'] = $state;
        $summary['action'] = $this->action($state, $canManage);

        return $summary;
    }

    /** @return array{0:string,1:array} */
    private function setupState(int $orgId, array $prep, ?IntegrationSetting $setting, ?array $wizard, array $summary): array
    {
        // 1. A guided setup session is open: resume exactly where it stopped.
        if ($wizard && ! in_array($wizard['state'] ?? '', ['connected', 'discarded', 'active'], true)) {
            $summary['task'] = $wizard['current_step'] ?? 'required_decisions';
            $summary['run_uuid'] = $wizard['run_uuid'] ?? null;
            if (in_array($wizard['state'], ['activation_ready', 'ready_for_approval', 'approved_maintenance_hold'], true)) {
                $summary['task'] = 'result_preview';

                return ['ready_to_activate', $summary];
            }
            $remaining = $wizard['decisions_remaining'] ?? null;
            $summary['reason'] = $remaining === null ? 'setup_in_progress' : ($remaining > 0 ? 'decisions_remaining' : 'review_result');
            $summary['progress'] = $remaining === null ? null : ['decisions_remaining' => (int) $remaining];

            return ['needs_input', $summary];
        }

        // 2. Automatic preparation has a record: running, stopped with an error, or stalled.
        if (($prep['version'] ?? null) === DefaultStockConnection::VERSION && ($prep['state'] ?? null) !== 'ready') {
            $reason = (string) ($prep['reason'] ?? '');
            if (($prep['state'] ?? null) === 'failed') {
                $summary['reason'] = $reason ?: 'preparation_failed';
                $summary['failed_at'] = $prep['failed_at'] ?? null;

                return [$this->isReview($reason) ? 'needs_input' : 'failed', $summary];
            }
            $since = $setting?->updated_at ? Carbon::parse($setting->updated_at) : null;
            if ($since && $since->diffInSeconds(now()) < self::STALL_AFTER_SECONDS) {
                $summary['reason'] = 'connecting';
                $summary['started_at'] = $since->toIso8601String();

                return ['preparing', $summary];
            }
            $summary['reason'] = 'preparation_stalled';

            return ['failed', $summary];
        }

        // 3. Nothing started yet: can the automatic path connect this organization as it is?
        $existing = $this->automaticBlocker($orgId);
        if ($existing !== null) {
            $summary['reason'] = $existing;
            $summary['task'] = 'required_decisions';

            return ['needs_input', $summary];
        }

        return ['ready_to_connect', $summary];
    }

    /** Why the one-click path would hand over to a person, from the same checks it runs. */
    private function automaticBlocker(int $orgId): ?string
    {
        $db = DB::connection('tenant');
        $financeId = (int) $db->table('organizations')->where('central_org_id', $orgId)->value('id');
        if ($financeId < 1) {
            return null;
        }
        $setting = IntegrationSetting::query()->where('organization_id', $orgId)->where('integration', 'solabooks')->first();
        $owned = data_get($setting?->meta, 'default_connection.version') === DefaultStockConnection::VERSION;
        if (! $owned && ($setting || $db->table('integration_account_mappings')->where('organization_id', $orgId)->exists())) {
            return 'existing_connection_preserved';
        }
        try {
            PristineStockHistory::assertEmpty($financeId, $orgId);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function isReview(string $reason): bool
    {
        foreach (self::REVIEW_REASONS as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function action(string $state, bool $canManage): array
    {
        $kind = match ($state) {
            'plan_required' => 'manage_plans',
            'finance_provisioning', 'finance_setup_required' => 'finish_finance_setup',
            'ready_to_connect' => 'connect',
            'failed' => 'retry',
            'needs_input', 'ready_to_activate' => 'continue',
            'connected' => 'open',
            'needs_attention' => 'review',
            'unavailable' => 'refresh',
            default => 'none',
        };
        // Starting, retrying, resuming or finishing setup belongs to people allowed to manage it.
        $managed = in_array($kind, ['connect', 'retry', 'continue', 'finish_finance_setup', 'manage_plans'], true);

        return ['kind' => $managed && ! $canManage ? 'ask_admin' : $kind, 'auto_refresh' => $state === 'preparing'];
    }

    private function links(int $orgId, array $readiness): array
    {
        $central = rtrim((string) config('tenancy.parent_base_url'), '/');

        return [
            'manage_plans' => $readiness['manage_access_url'] ?? null,
            'finish_finance_setup' => $readiness['setup_url'] ?? null,
            // Opens SolaCount's Connection Status for this organization through Central sign-in.
            'solacount' => $central ? $central.'/sso/finance/redirect?'.http_build_query([
                'organization_id' => $orgId, 'intended_url' => '/inventory/workspace/connection']) : null,
        ];
    }

    private function organizationName(int $orgId): ?string
    {
        try {
            $central = DB::connection((string) config('tenancy.central_connection', 'mysql'));
            static $columns = null;
            $columns ??= array_values(array_intersect(['display_name', 'name'], $central->getSchemaBuilder()->getColumnListing('organizations')));
            $row = $columns ? $central->table('organizations')->where('id', $orgId)->first($columns) : null;

            return $row ? (($row->display_name ?? null) ?: ($row->name ?? null)) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
