<?php

namespace App\Services\Reports;

use Illuminate\Http\Request;

/**
 * Normalized report filter bag. Built from the request query so both the screen
 * and the export endpoint apply identical filters.
 */
final class ReportFilters
{
    public function __construct(
        public readonly ?int $itemId = null,
        public readonly ?int $warehouseId = null,
        public readonly ?int $binId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $supplierId = null,
        public readonly ?string $status = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly int $days = 90,
        public readonly int $limit = 1000,
    ) {}

    public static function fromRequest(Request $r): self
    {
        return new self(
            itemId: $r->filled('item_id') ? (int) $r->query('item_id') : null,
            warehouseId: $r->filled('warehouse_id') ? (int) $r->query('warehouse_id') : null,
            binId: $r->filled('bin_id') ? (int) $r->query('bin_id') : null,
            categoryId: $r->filled('category_id') ? (int) $r->query('category_id') : null,
            supplierId: $r->filled('supplier_id') ? (int) $r->query('supplier_id') : null,
            status: $r->query('status') ?: null,
            from: $r->query('from') ?: null,
            to: $r->query('to') ?: null,
            days: (int) $r->query('days', 90),
            limit: min((int) $r->query('limit', 1000), 5000),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'item_id' => $this->itemId, 'warehouse_id' => $this->warehouseId, 'bin_id' => $this->binId,
            'category_id' => $this->categoryId, 'supplier_id' => $this->supplierId, 'status' => $this->status,
            'from' => $this->from, 'to' => $this->to, 'days' => $this->days,
        ], fn ($v) => $v !== null);
    }
}
