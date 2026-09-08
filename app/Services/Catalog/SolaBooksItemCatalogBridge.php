<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\FinanceConnectionClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Compatibility boundary: automatic cross-application writes are permanently contained. */
class SolaBooksItemCatalogBridge
{
    public function __construct(private readonly FinanceConnectionClient $finance) {}

    public function sync(Item $item, ?string $previousSku = null): bool
    {
        $mapping = IntegrationOrganizationMapping::query()->where('solastock_organization_id', $item->organization_id)
            ->where('status', 'verified')->where('activation_state', 'active')->first();
        $setting = IntegrationSetting::query()->where('organization_id', $item->organization_id)
            ->where('integration', 'solabooks')->where('mode', 'active')->first();
        $actorId = (int) Auth::id();
        if (! $mapping || ! $setting || $actorId <= 0) return false;

        $catalog = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('entity_type', 'item')->where('solastock_record_id', (string) $item->id)->where('status', 'verified')->first();
        $category = $this->financeId($mapping, 'category', $item->category_id);
        $unit = $this->financeId($mapping, 'unit', $item->base_unit_id);
        if (! $category || ! $unit) throw new RuntimeException('catalog_reference_mapping_required');
        $data = ['entity_type' => 'item', 'finance_id' => $catalog?->solabooks_record_id,
            'name' => (string) $item->name, 'sku' => (string) $item->sku, 'category_id' => $category,
            'unit_id' => $unit, 'tracking_type' => (string) $item->tracking_type,
            'valuation_method' => (string) $item->costing_method, 'is_active' => ! $item->trashed() && (bool) $item->is_active];
        foreach (['inventory_asset' => 'inventory_asset_account_id', 'cogs' => 'cogs_account_id'] as $role => $field) {
            $data[$field] = (int) DB::connection('tenant')->table('integration_account_mappings')
                ->where('organization_id', $mapping->solastock_organization_id)->where('integration', 'solabooks')
                ->where('mapping_type', $role)->where('status', 'verified')->value('solabooks_account_id');
        }
        $result = $this->finance->command(['client_id' => $mapping->central_client_id,
            'organization_id' => $mapping->central_organization_id, 'finance_organization_id' => $mapping->finance_organization_id,
            'actor_id' => $actorId, 'action' => 'catalog.upsert',
            'idempotency_key' => 'catalog-item-'.$item->id.'-'.hash('sha256', json_encode($data)), 'data' => $data]);
        if (! $catalog) {
            IntegrationMasterDataMapping::query()->create(['mapping_uuid' => (string) Str::uuid(),
                'organization_mapping_uuid' => $mapping->mapping_uuid, 'central_client_id' => $mapping->central_client_id,
                'central_organization_id' => $mapping->central_organization_id, 'finance_organization_id' => $mapping->finance_organization_id,
                'solastock_organization_id' => $mapping->solastock_organization_id, 'entity_type' => 'item',
                'solastock_record_id' => (string) $item->id, 'solabooks_record_id' => (string) $result['id'],
                'status' => 'verified', 'contract_source_version' => 'catalog-owner-command.v1',
                'discovery_method' => 'signed_stock_owner_command', 'last_verified_at' => now(),
                'created_by_user_id' => $actorId, 'updated_by_user_id' => $actorId]);
        }
        return true;
    }

    private function financeId(IntegrationOrganizationMapping $mapping, string $type, mixed $stockId): ?int
    {
        if (! $stockId) return null;
        $id = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('entity_type', $type)->where('solastock_record_id', (string) $stockId)
            ->where('status', 'verified')->value('solabooks_record_id');
        return $id ? (int) $id : null;
    }

    public function importFromSolaBooks(object $source): bool
    {
        throw new RuntimeException('catalog_owner_command_required: automatic catalog import is disabled. Review explicit identity mappings.');
    }

    public function reconcileOrganization(int $stockOrganizationId): array
    {
        throw new RuntimeException('catalog_owner_command_required: bidirectional catalog reconciliation is disabled. Existing records and mappings are retained.');
    }
}
