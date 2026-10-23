<?php

namespace App\Services\Sales\CustomerItems;

use App\Services\Purchasing\SupplierTerms\FinanceLink;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only view of SolaCount customer-item identity (customer SKU / name / status).
 * SolaCount owns finance_customer_items; SolaStock never stores or prices from it.
 * Only MAPPED customers and items resolve, through clean master-data mappings of
 * the active, entitled organization mapping. Anything else returns [].
 */
final class CustomerItemIdentityReader
{
    private const TABLE = 'finance_customer_items';

    // Only verified mappings count, matching FinanceLink and PartyIdentityReview.
    private const MAPPED = ['verified'];

    public function __construct(private readonly FinanceLink $link) {}

    /**
     * @param  array<int, int|string>  $stockItemIds
     * @return array<int, array{customer_sku: ?string, customer_item_name: ?string, status: string}>
     */
    public function forCustomerItems(int $stockOrganizationId, int $stockCustomerId, array $stockItemIds): array
    {
        $stockItemIds = array_values(array_unique(array_filter(array_map('intval', $stockItemIds), fn (int $id) => $id > 0)));
        if ($stockItemIds === [] || $stockCustomerId <= 0) {
            return [];
        }
        try {
            if (app(OrganizationContext::class)->idOrFail() !== $stockOrganizationId) {
                return [];
            }
            $schema = Schema::connection('tenant');
            if (! $schema->hasTable(self::TABLE) || ! $schema->hasTable('integration_master_data_mappings')) {
                return [];
            }
            if (! $this->link->readable()) {
                return [];
            }
            $state = $this->link->state();
            $financeOrg = (int) ($state['finance_organization_id'] ?? 0);
            $uuid = (string) ($state['mapping_uuid'] ?? '');
            if ($financeOrg <= 0 || $uuid === '') {
                return [];
            }

            $customer = $this->financeIds($uuid, $stockOrganizationId, $financeOrg, 'customer', [$stockCustomerId]);
            if (! isset($customer[$stockCustomerId])) {
                return [];
            }
            $items = $this->financeIds($uuid, $stockOrganizationId, $financeOrg, 'item', $stockItemIds);
            if ($items === []) {
                return [];
            }

            $rows = DB::connection('tenant')->table(self::TABLE)
                ->where('organization_id', $financeOrg)
                ->where('customer_id', $customer[$stockCustomerId])
                ->whereIn('inventory_item_id', array_values($items))
                ->get(['inventory_item_id', 'customer_sku', 'customer_item_name', 'status'])
                ->keyBy(fn ($row) => (int) $row->inventory_item_id);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($items as $stockId => $financeId) {
            $row = $rows->get($financeId);
            if (! $row) {
                continue;
            }
            $out[$stockId] = [
                'customer_sku' => $row->customer_sku !== null ? (string) $row->customer_sku : null,
                'customer_item_name' => $row->customer_item_name !== null ? (string) $row->customer_item_name : null,
                'status' => (string) $row->status,
            ];
        }

        return $out;
    }

    /**
     * Stock id => finance id, only for one-to-one, conflict-free, unarchived mappings.
     *
     * @return array<int, int>
     */
    private function financeIds(string $uuid, int $stockOrg, int $financeOrg, string $type, array $stockIds): array
    {
        $rows = DB::connection('tenant')->table('integration_master_data_mappings')
            ->where('organization_mapping_uuid', $uuid)
            ->where('solastock_organization_id', $stockOrg)
            ->where('finance_organization_id', $financeOrg)
            ->where('entity_type', $type)
            ->whereIn('status', self::MAPPED)
            ->whereNull('conflict_code')
            ->whereNull('error_state')
            ->where('solastock_archived', false)
            ->where('solabooks_archived', false)
            ->whereIn('solastock_record_id', array_map('strval', $stockIds))
            ->whereNotNull('solabooks_record_id')
            ->get(['solastock_record_id', 'solabooks_record_id']);

        // An ambiguous (one-to-many) mapping is treated as unmapped.
        $out = [];
        foreach ($rows->groupBy('solastock_record_id') as $stockId => $group) {
            $targets = $group->pluck('solabooks_record_id')->unique();
            if ($targets->count() === 1 && (int) $targets->first() > 0) {
                $out[(int) $stockId] = (int) $targets->first();
            }
        }

        return $out;
    }
}
