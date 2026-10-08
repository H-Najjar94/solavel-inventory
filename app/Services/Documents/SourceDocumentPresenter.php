<?php

namespace App\Services\Documents;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockTransfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SourceDocumentPresenter
{
    private const DOCUMENTS = [
        OpeningStockEntry::class => ['label' => 'Opening stock', 'number' => 'entry_number', 'route' => '/opening-stock'],
        StockAdjustment::class => ['label' => 'Adjustment', 'number' => 'adjustment_number', 'route' => '/adjustments'],
        GoodsReceipt::class => ['label' => 'Goods receipt', 'number' => 'grn_number', 'route' => '/goods-receipts'],
        StockTransfer::class => ['label' => 'Transfer', 'number' => 'transfer_number', 'route' => '/transfers'],
        SalesOrder::class => ['label' => 'Sales order', 'number' => 'order_number', 'route' => '/sales-orders'],
        Shipment::class => ['label' => 'Shipment', 'number' => 'shipment_number', 'route' => '/shipments'],
        SalesReturn::class => ['label' => 'Sales return', 'number' => 'return_number', 'route' => '/sales-returns'],
    ];

    public static function describe(?string $sourceType, mixed $sourceId): array
    {
        if (! $sourceType || ! $sourceId) {
            return [
                'source_label' => null,
                'source_number' => null,
                'source_display' => null,
                'source_route' => null,
                'source_missing' => false,
            ];
        }

        $class = class_exists($sourceType) ? $sourceType : 'App\\Models\\Tenant\\'.class_basename($sourceType);
        $meta = self::DOCUMENTS[$class] ?? null;
        if (! $meta || ! is_a($class, Model::class, true)) {
            $label = class_basename($sourceType);

            return [
                'source_label' => $label,
                'source_number' => null,
                'source_display' => trim($label) !== '' ? $label : "Document #{$sourceId}",
                'source_route' => null,
                'source_missing' => false,
            ];
        }

        $numberColumn = $meta['number'];
        $doc = $class::query()->find((int) $sourceId, ['id', $numberColumn]);
        $number = $doc?->{$numberColumn};

        return [
            'source_label' => $meta['label'],
            'source_number' => $number,
            'source_display' => $number ? "{$meta['label']} {$number}" : "{$meta['label']} #{$sourceId}",
            'source_route' => $meta['route'].'/'.$sourceId,
            'source_missing' => $doc === null,
        ];
    }

    public static function decorateRows(Collection $rows, string $typeKey = 'source_type', string $idKey = 'source_id'): Collection
    {
        $lookup = [];
        $rows->groupBy(fn ($row) => (string) data_get($row, $typeKey))
            ->each(function (Collection $group, string $sourceType) use (&$lookup, $idKey) {
                $class = class_exists($sourceType) ? $sourceType : 'App\\Models\\Tenant\\'.class_basename($sourceType);
                $meta = self::DOCUMENTS[$class] ?? null;
                if (! $meta || ! is_a($class, Model::class, true)) {
                    return;
                }

                $ids = $group->pluck($idKey)->filter()->unique()->values()->all();
                if ($ids === []) {
                    return;
                }

                $numberColumn = $meta['number'];
                $class::query()
                    ->whereIn('id', $ids)
                    ->get(['id', $numberColumn])
                    ->each(function (Model $doc) use (&$lookup, $sourceType, $meta, $numberColumn) {
                        $lookup[$sourceType][(int) $doc->id] = [
                            'source_label' => $meta['label'],
                            'source_number' => $doc->{$numberColumn},
                            'source_display' => $meta['label'].' '.$doc->{$numberColumn},
                            'source_route' => $meta['route'].'/'.$doc->id,
                            'source_missing' => false,
                        ];
                    });
            });

        return $rows->map(function ($row) use ($lookup, $typeKey, $idKey) {
            $sourceType = (string) data_get($row, $typeKey);
            $sourceId = data_get($row, $idKey);
            $attrs = $lookup[$sourceType][(int) $sourceId] ?? self::describe($sourceType, $sourceId);

            foreach ($attrs as $key => $value) {
                if ($row instanceof Model) {
                    $row->setAttribute($key, $value);
                } else {
                    $row->{$key} = $value;
                }
            }

            return $row;
        });
    }
}
