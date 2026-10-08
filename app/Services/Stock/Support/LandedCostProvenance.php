<?php

namespace App\Services\Stock\Support;

use App\Models\Tenant\StockLedger;
use Ramsey\Uuid\Uuid;

/**
 * Identity of one landed-cost share on one posted receipt ledger row. It has the
 * shape the cost-adjustment planner reads from a supplier-bill allocation, so a
 * landed cost is split through the receipt's actual disposition by the same code.
 */
final readonly class LandedCostProvenance
{
    private function __construct(
        public string $allocation_uuid,
        public int $solastock_organization_id,
        public string $source_document_type,
        public int $source_document_id,
        public int $source_line_id,
        public string $base_quantity,
        public int $receipt_ledger_id,
    ) {}

    public static function forReceiptLedger(int $landedCostId, int $landedCostLineId, StockLedger $receipt): self
    {
        return new self(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'stock-landed-cost|'.$landedCostId.'|'.$landedCostLineId.'|'.$receipt->id)->toString(),
            (int) $receipt->organization_id, 'goods_receipt', (int) $receipt->source_id,
            (int) $receipt->source_line_id, (string) $receipt->quantity, (int) $receipt->id,
        );
    }
}
