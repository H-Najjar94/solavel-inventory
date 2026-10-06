<?php

namespace App\Services\Purchasing;

final class PurchasingBillAuthority
{
    public static function receipt(int $id): bool
    {
        $a = request()->attributes->get('purchasing_authority');
        $action = request()->attributes->get('verified_workspace_action');

        return is_array($a) && str_starts_with((string) $action, 'purchasing.bill.') && in_array($id, array_map('intval', (array) ($a['receipt_ids'] ?? [])), true);
    }
}
