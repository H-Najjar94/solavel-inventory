<?php

namespace App\Console\Commands;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProvisionPhase2V2Scope extends Command
{
    protected $signature = 'integration:phase2-provision-v2-scope
        {--organization-mapping= : Validated immutable organization mapping UUID}
        {--tenant-database= : Explicit tenant_XXXXXX database for an operator run}
        {--solastock-organization= : SolaStock organization ID for the tenant context}
        {--confirm-maintenance-hold : Confirm every Phase 0 hold remains active}';

    protected $description = 'Provision an idempotent v2 signing scope without enabling delivery';

    public function handle(
        OrganizationContext $context,
        SolaBooksOutboxDeliveryService $delivery,
        TenantManager $tenants,
    ): int {
        if (! $this->option('confirm-maintenance-hold') || ! $this->holdsActive()) {
            $this->error('All Phase 0 holds and --confirm-maintenance-hold are required.');
            return self::FAILURE;
        }
        $operation = function () use ($context, $delivery): array {
            $mapping = IntegrationOrganizationMapping::query()
                ->where('mapping_uuid', (string) $this->option('organization-mapping'))
                ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
                ->where('status', 'verified_hold')
                ->where('activation_state', 'maintenance_hold')
                ->where('contract_version', 'solastock-journal.v2')
                ->firstOrFail();
            $setting = IntegrationSetting::query()
                ->where('organization_id', $mapping->solastock_organization_id)
                ->where('solabooks_organization_id', $mapping->finance_organization_id)
                ->where('integration', 'solabooks')
                ->firstOrFail();

            $settingMeta = (array) $setting->meta;
            if ($mapping->v2_key_scope_status === 'provisioned_held'
                && ($settingMeta['contract_version'] ?? null) === 'solastock-journal.v2'
                && ! empty($settingMeta['signing_key_id'])
                && ! empty($settingMeta['signing_secret_encrypted'])) {
                $this->line(json_encode([
                    'mapping_uuid' => $mapping->mapping_uuid,
                    'status' => 'already_provisioned_held',
                    'delivery_enabled' => false,
                ]));
                return ['exit' => self::SUCCESS];
            }

            $updated = $context->runFor(
                (int) $mapping->solastock_organization_id,
                fn () => $delivery->rotateSigningKey()
            );
            $this->line(json_encode([
                'mapping_uuid' => $mapping->mapping_uuid,
                'status' => 'provisioned_held',
                'key_id' => $updated->meta['signing_key_id'] ?? null,
                'contract_version' => $updated->meta['contract_version'] ?? null,
                'delivery_enabled' => false,
            ], JSON_UNESCAPED_SLASHES));

            return ['exit' => self::SUCCESS];
        };
        $tenantDatabase = trim((string) $this->option('tenant-database'));
        $solaStockOrganization = (int) $this->option('solastock-organization');
        if (($tenantDatabase === '') !== ($solaStockOrganization <= 0)) {
            $this->error('--tenant-database and --solastock-organization must be supplied together.');
            return self::FAILURE;
        }
        if ($tenantDatabase !== '' && ! preg_match('/^tenant_[0-9]{6}$/D', $tenantDatabase)) {
            $this->error('--tenant-database must use the tenant_XXXXXX format.');
            return self::FAILURE;
        }
        $result = $tenantDatabase === ''
            ? $operation()
            : $tenants->runForTenant($solaStockOrganization, $operation, $tenantDatabase);

        return $result['exit'];
    }

    private function holdsActive(): bool
    {
        return ! config('integration_safety.solabooks_delivery_enabled', true)
            && config('integration_safety.legacy_finance_inventory_writes_blocked', false)
            && ! config('integration_safety.legacy_journal_contract_enabled', true)
            && ! config('integration_safety.historical_repair_enabled', true)
            && ! config('integration_safety.pending_event_replay_enabled', true);
    }
}
