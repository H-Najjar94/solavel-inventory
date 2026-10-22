<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\Item;
use App\Models\Tenant\ItemSupplier;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPriceList;
use App\Models\Tenant\Unit;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vendor pricelist CSV (one row per price tier; relationship columns repeat per row).
 * Import is non-destructive: tiers matched on (minimum qty, valid from, valid to) are
 * updated, new ones added, tiers missing from the file are kept. Preview runs the real
 * save rules and rolls back; apply is all-or-nothing.
 */
final class SupplierTermsCsv
{
    public const COLUMNS = [
        'item_sku', 'supplier_code', 'supplier_name', 'status', 'preferred', 'supplier_sku', 'supplier_item_name',
        'currency_code', 'purchase_unit', 'minimum_order_qty', 'order_multiple', 'lead_time_days',
        'discount_percent', 'price_scale', 'min_qty', 'unit_price', 'valid_from', 'valid_to', 'notes',
    ];

    public const MAX_ROWS = 5000;

    public function __construct(private readonly ItemSupplierService $service) {}

    public function export(?int $itemId, ?int $supplierId): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::COLUMNS);
        ItemSupplier::query()
            ->when($itemId, fn ($q) => $q->where('item_id', $itemId))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->with(['item:id,sku,preferred_supplier_id', 'supplier:id,code,name', 'purchaseUnit:id,code', 'prices'])
            ->orderBy('item_id')->orderBy('supplier_id')
            ->chunk(500, function ($relationships) use ($handle) {
                foreach ($relationships as $r) {
                    $tiers = $r->prices->isEmpty() ? [null] : $r->prices->all();
                    foreach ($tiers as $tier) {
                        fputcsv($handle, $this->safe([
                            $r->item?->sku, $r->supplier?->code, $r->supplier?->name, $r->status,
                            (int) $r->item?->preferred_supplier_id === (int) $r->supplier_id ? 'yes' : 'no',
                            $r->supplier_sku, $r->supplier_item_name, $r->currency_code, $r->purchaseUnit?->code,
                            $r->minimum_order_qty, $r->order_multiple, $r->lead_time_days, $r->discount_percent,
                            $r->price_scale, $tier?->minimum_qty, $tier?->unit_cost,
                            $tier?->effective_from?->toDateString(), $tier?->effective_to?->toDateString(), $r->notes,
                        ]));
                    }
                }
            });
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }

    /** @return array{applied: bool, rows: array, summary: array} */
    public function import(string $content, bool $apply, ?int $actorId): array
    {
        [$groups, $rows] = $this->parse($content);
        $connection = DB::connection('tenant');
        $connection->beginTransaction();
        try {
            foreach ($groups as $key => $group) {
                $this->importGroup($group, $rows, $actorId);
            }
            $errors = array_filter($rows, fn ($row) => $row['status'] === 'error');
            if (! $apply || $errors !== []) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $counts = array_count_values(array_column($rows, 'status'));

        return [
            'applied' => $apply && ($counts['error'] ?? 0) === 0,
            'rows' => array_values($rows),
            'summary' => [
                'rows' => count($rows),
                'created' => $counts['created'] ?? 0,
                'updated' => $counts['updated'] ?? 0,
                'unchanged' => $counts['unchanged'] ?? 0,
                'errors' => $counts['error'] ?? 0,
            ],
        ];
    }

    private function importGroup(array $group, array &$rows, ?int $actorId): void
    {
        $first = $rows[$group[0]]['values'];
        $item = Item::query()->where('sku', $first['item_sku'])->first();
        $supplier = Supplier::query()->where('code', $first['supplier_code'])->first();
        if (! $item || ! $supplier) {
            foreach ($group as $line) {
                $this->fail($rows, $line, ! $item ? __('inventory.supplier_terms.import_item_missing') : __('inventory.supplier_terms.import_supplier_missing'));
            }

            return;
        }
        $existing = ItemSupplier::query()->where('item_id', $item->id)->where('supplier_id', $supplier->id)->with('prices')->first();
        $tiers = $existing ? $existing->prices->map(fn (SupplierPriceList $t) => [
            'id' => $t->id, 'minimum_qty' => (string) $t->minimum_qty, 'unit_cost' => (string) $t->unit_cost,
            'effective_from' => $t->effective_from?->toDateString(), 'effective_to' => $t->effective_to?->toDateString(),
            'is_active' => (bool) $t->is_active,
        ])->all() : [];
        $seen = [];
        $changed = [];
        foreach ($group as $line) {
            $v = $rows[$line]['values'];
            if ($v['min_qty'] === '' && $v['unit_price'] === '') {
                $rows[$line]['status'] = $existing ? 'unchanged' : 'created';
                continue;
            }
            $tierKey = implode('|', [$v['min_qty'] === '' ? '1' : Decimal::qty($this->number($v['min_qty'])), $v['valid_from'], $v['valid_to']]);
            if (isset($seen[$tierKey])) {
                $this->fail($rows, $line, __('inventory.supplier_terms.import_duplicate_row', ['row' => $rows[$seen[$tierKey]]['row']]));
                continue;
            }
            $seen[$tierKey] = $line;
            $match = null;
            foreach ($tiers as $index => $tier) {
                if (implode('|', [Decimal::qty($tier['minimum_qty']), (string) $tier['effective_from'], (string) $tier['effective_to']]) === $tierKey) {
                    $match = $index;
                }
            }
            $price = $this->number($v['unit_price']);
            if ($match !== null) {
                $same = Decimal::cmp((string) $tiers[$match]['unit_cost'], $price === '' ? '-1' : $price) === 0;
                $tiers[$match]['unit_cost'] = $price;
                $tiers[$match]['is_active'] = true;
                $rows[$line]['status'] = $same ? 'unchanged' : 'updated';
            } else {
                $tiers[] = ['minimum_qty' => $v['min_qty'] === '' ? '1' : $this->number($v['min_qty']), 'unit_cost' => $price,
                    'effective_from' => $v['valid_from'] ?: null, 'effective_to' => $v['valid_to'] ?: null, 'is_active' => true];
                $rows[$line]['status'] = $existing ? 'updated' : 'created';
            }
            $changed[] = $line;
        }
        $data = ['supplier_id' => $supplier->id, 'tiers' => array_values($tiers)];
        foreach (['status', 'supplier_sku', 'supplier_item_name', 'currency_code', 'minimum_order_qty', 'order_multiple',
            'lead_time_days', 'discount_percent', 'price_scale', 'notes'] as $field) {
            if ($first[$field] !== '') {
                $data[$field] = in_array($field, ['minimum_order_qty', 'order_multiple', 'discount_percent'], true) ? $this->number($first[$field]) : $first[$field];
            }
        }
        if ($first['preferred'] !== '') {
            $data['is_preferred'] = in_array(strtolower($first['preferred']), ['1', 'yes', 'true', 'y', 'نعم'], true);
        }
        if ($first['purchase_unit'] !== '') {
            $unit = Unit::query()->where('code', $first['purchase_unit'])->first();
            if (! $unit) {
                foreach ($group as $line) {
                    $this->fail($rows, $line, __('inventory.supplier_terms.import_unit_missing'));
                }

                return;
            }
            $data['purchase_unit_id'] = $unit->id;
        }
        try {
            validator($data, app(ItemSupplierService::class)->rules())->validate();
            $connection = DB::connection('tenant');
            $connection->transaction(fn () => $this->service->save($item, $data, $existing, $actorId));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: $e->getMessage();
            foreach ($group as $line) {
                if ($rows[$line]['status'] !== 'error') {
                    $this->fail($rows, $line, $message);
                }
            }
        }
    }

    /** @return array{0: array<string, int[]>, 1: array<int, array>} */
    private function parse(string $content): array
    {
        $content = preg_replace('/^\x{FEFF}/u', '', $content);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, (string) $content);
        rewind($handle);
        $records = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $records[] = $cells;
        }
        fclose($handle);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), (array) array_shift($records));
        $missing = array_diff(['item_sku', 'supplier_code'], $header);
        if ($missing !== []) {
            throw ValidationException::withMessages(['content' => __('inventory.supplier_terms.import_header_invalid', ['columns' => implode(', ', $missing)])]);
        }
        if (count($records) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['content' => __('inventory.supplier_terms.import_too_many_rows', ['max' => self::MAX_ROWS])]);
        }
        $rows = [];
        $groups = [];
        foreach ($records as $index => $cells) {
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            $values = [];
            foreach (self::COLUMNS as $column) {
                $position = array_search($column, $header, true);
                $value = $position === false ? '' : trim((string) ($cells[$position] ?? ''));
                // Undo the export's formula guard so a round trip keeps the original text.
                $values[$column] = preg_match("/^'[=+\\-@]/", $value) ? substr($value, 1) : $value;
            }
            foreach (['valid_from', 'valid_to'] as $date) {
                if ($values[$date] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $values[$date])) {
                    $values[$date] = '!'.$values[$date];
                }
            }
            $rows[$index] = ['row' => $index + 2, 'values' => $values, 'status' => 'pending', 'message' => null];
            if ($values['item_sku'] === '' || $values['supplier_code'] === '') {
                $this->fail($rows, $index, __('inventory.supplier_terms.import_keys_required'));
                continue;
            }
            if (str_starts_with($values['valid_from'], '!') || str_starts_with($values['valid_to'], '!')) {
                $this->fail($rows, $index, __('inventory.supplier_terms.import_date_invalid'));
                continue;
            }
            $groups[mb_strtolower($values['item_sku']).'|'.mb_strtolower($values['supplier_code'])][] = $index;
        }

        return [$groups, $rows];
    }

    private function fail(array &$rows, int $line, string $message): void
    {
        $rows[$line]['status'] = 'error';
        $rows[$line]['message'] = $message;
    }

    /** Accepts "1,234.50" style input but never guesses decimal commas. */
    private function number(string $value): string
    {
        return str_replace([',', ' '], '', trim($value));
    }

    /** Neutralize spreadsheet formula injection in exported text cells. */
    private function safe(array $cells): array
    {
        return array_map(function ($cell) {
            $cell = $cell === null ? '' : (string) $cell;

            return preg_match('/^[=+\-@\t\r]/', $cell) && ! is_numeric($cell) ? "'".$cell : $cell;
        }, $cells);
    }
}
