<?php
namespace App\Services\Stock\Support;

use App\Services\PurchasingCredits\SupplierCreditCostAuthority;
use Ramsey\Uuid\Uuid;

/** Private credit allocation identity. Neither a supplier Bill nor an Expense settlement. */
final readonly class SupplierCreditCostProvenance
{
    private function __construct(
        public string $allocation_uuid,
        public int $solastock_organization_id,
        public string $source_document_type,
        public int $source_document_id,
        public int $source_line_id,
        public string $base_quantity,
        public ?int $variant_id,
        public ?int $lot_id,
        public ?int $bin_id,
    ) {}

    public static function fromAuthority(SupplierCreditCostAuthority $authority, array $source): self
    {
        abort_unless(in_array($source, $authority->sourceAllocations(), true), 403);
        return new self(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'supplier-credit-value|'.$authority->mappingUuid().'|'.$authority->operationUuid().'|'.$authority->allocationUuid().'|'.$source['receipt_line_id'])->toString(),
            $authority->organizationId(), 'goods_receipt', (int) $source['receipt_id'],
            (int) $source['receipt_line_id'], (string) $source['quantity_base'],
            $source['variant_id'], $source['lot_id'], $source['bin_id'],
        );
    }
}
