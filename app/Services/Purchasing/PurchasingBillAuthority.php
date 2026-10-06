<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\IntegrationDocumentLifecycleMapping;

final class PurchasingBillAuthority
{
    public static function receipt(int $id): bool
    {
        $a = request()->attributes->get('purchasing_authority');
        $action = request()->attributes->get('verified_workspace_action');

        if (! is_array($a) || ! str_starts_with((string) $action, 'purchasing.bill.')) {
            return false;
        }
        $ids = array_values(array_map('intval', (array) ($a['receipt_ids'] ?? [])));
        $uuids = array_values((array) ($a['receipt_mapping_uuids'] ?? []));
        $index = array_search($id, $ids, true);
        if ($index === false || count($ids) !== count($uuids) || ! is_string($uuids[$index] ?? null)) {
            return false;
        }

        // The independently authorized Finance receipt reference must match the
        // actual Stock lifecycle identity, never merely an overlapping numeric ID.
        return IntegrationDocumentLifecycleMapping::query()
            ->where('organization_mapping_uuid', $a['organization_mapping_uuid'] ?? '')
            ->where('finance_organization_id', (int) ($a['finance_organization_id'] ?? 0))
            ->where('mapping_uuid', $uuids[$index])
            ->where('source_application', 'solastock')
            ->where('source_document_type', 'goods_receipt')
            ->where('source_document_id', (string) $id)
            ->whereNotIn('lifecycle_status', ['cancelled', 'reversed', 'deleted', 'archived'])
            ->exists();
    }
}
