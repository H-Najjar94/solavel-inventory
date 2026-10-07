<?php
namespace App\Services\Stock\Support;

use App\Services\Purchasing\LandedCostAllocationAuthority;
use Ramsey\Uuid\Uuid;

/** Internal receipt identity, never a synthetic supplier-bill allocation. */
final readonly class LandedCostReceiptProvenance
{
    private function __construct(
        public string $allocation_uuid,
        public int $solastock_organization_id,
        public string $source_document_type,
        public int $source_document_id,
        public int $source_line_id,
        public string $base_quantity,
    ) {}

    public static function fromAuthority(LandedCostAllocationAuthority $authority, array $source): self
    {
        abort_unless(in_array($source, $authority->sourceAllocations(), true), 403);
        return new self(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'landed-cost-receipt|'.$authority->mappingUuid().'|'.$authority->operationUuid().'|'.$source['position_uuid'].'|'.$source['receipt_line_id'])->toString(),
            $authority->organizationId(), 'goods_receipt', (int) $source['receipt_id'],
            (int) $source['receipt_line_id'], (string) $source['quantity_base'],
        );
    }
}
