<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\IntegrationEvents;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only view of this organization's SolaCount connection for supplier terms.
 * Integrated features (Finance bill history, Finance-normalized FX) require a
 * verified mapping AND Central's connection entitlement; nothing here writes.
 */
final class FinanceLink
{
    private ?array $state = null;

    /** @return array{state: string, finance_organization_id: ?int, mapping_uuid: ?string, base_currency: ?string} */
    public function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $none = ['state' => 'standalone', 'finance_organization_id' => null, 'mapping_uuid' => null, 'base_currency' => null];
        if (! Schema::connection('tenant')->hasTable('integration_organization_mappings')) {
            return $this->state = $none;
        }
        $org = app(OrganizationContext::class)->idOrFail();
        $mappings = IntegrationOrganizationMapping::query()->where('solastock_organization_id', $org)
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
            ->whereIn('status', ['verified', 'verified_hold'])->get();
        if ($mappings->isEmpty()) {
            return $this->state = $none;
        }
        if ($mappings->count() !== 1) {
            return $this->state = ['state' => 'needs_review'] + $none;
        }
        $mapping = $mappings->sole();
        $base = [
            'finance_organization_id' => (int) $mapping->finance_organization_id,
            'mapping_uuid' => (string) $mapping->mapping_uuid,
            'base_currency' => $mapping->base_currency_code ? (string) $mapping->base_currency_code : null,
        ];
        try {
            app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
        } catch (Throwable) {
            return $this->state = ['state' => 'not_entitled'] + $base;
        }
        $mode = IntegrationSetting::query()->where('organization_id', $org)
            ->where('integration', IntegrationEvents::INTEGRATION)->value('mode');
        $connected = $mapping->status === 'verified' && $mapping->activation_state === 'active' && $mode === 'active';

        return $this->state = ['state' => $connected ? 'connected' : 'paused'] + $base;
    }

    /** Integrated reads are allowed while connected or paused (data stays visible, writes stop). */
    public function readable(): bool
    {
        return in_array($this->state()['state'], ['connected', 'paused'], true);
    }

    /** @return array<int, int> stock id => finance id for verified mappings */
    public function financeIds(string $entityType, array $stockIds): array
    {
        $state = $this->state();
        if (! $state['mapping_uuid'] || $stockIds === []) {
            return [];
        }

        return DB::connection('tenant')->table('integration_master_data_mappings')
            ->where('organization_mapping_uuid', $state['mapping_uuid'])
            ->where('entity_type', $entityType)->where('status', 'verified')
            ->whereIn('solastock_record_id', array_map('strval', $stockIds))
            ->pluck('solabooks_record_id', 'solastock_record_id')
            ->mapWithKeys(fn ($finance, $stock) => [(int) $stock => (int) $finance])->all();
    }

    /** Latest Finance rate on or before the date (1 base = rate quote). Null when unknown. */
    public function rate(string $quote, string $date): ?string
    {
        $state = $this->state();
        $base = $state['base_currency'];
        if (! $base || ! $state['finance_organization_id']) {
            return null;
        }
        if ($quote === $base) {
            return '1';
        }
        if (! Schema::connection('tenant')->hasTable('exchange_rates')) {
            return null;
        }
        $rate = DB::connection('tenant')->table('exchange_rates')
            ->where('organization_id', $state['finance_organization_id'])
            ->where('base_currency_code', $base)->where('quote_currency_code', $quote)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')->orderByRaw("CASE source WHEN 'manual' THEN 0 WHEN 'api' THEN 1 ELSE 2 END")
            ->value('rate');

        return $rate !== null && (float) $rate > 0 ? (string) $rate : null;
    }
}
