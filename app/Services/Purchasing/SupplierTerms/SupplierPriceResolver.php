<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\Item;
use App\Models\Tenant\ItemSupplier;
use App\Models\Tenant\SupplierPriceList;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Reports\InventoryReportService;
use App\Services\Stock\Support\Decimal;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Vendor price rule (shown in the UI as "how this price was chosen"):
 * approved relationships only; active tiers valid on the date (inclusive);
 * among tiers whose minimum quantity <= the ordered quantity (in purchase units)
 * the highest minimum wins; net = tier price x (1 - discount%). A document
 * suggestion needs the same currency. Cross-currency comparison converts to base
 * with the latest Finance rate on or before the date (base = quote / rate);
 * without a rate the candidate is listed but not ranked.
 */
final class SupplierPriceResolver
{
    public function __construct(private readonly FinanceLink $link) {}

    /**
     * @param string $quantity quantity in $unitId (or the base unit when null)
     */
    public function suggest(Item $item, ?int $supplierId, string $quantity, ?int $unitId, ?string $currency, ?string $date): array
    {
        $date = $date ? CarbonImmutable::parse($date)->toDateString() : now()->toDateString();
        $baseCurrency = app(InventoryReportService::class)->baseCurrency();
        $currency = $currency ? strtoupper($currency) : null;
        $enteredFactor = $this->enteredFactor($item, $unitId);
        $baseQty = Decimal::mul($this->positive($quantity), $enteredFactor);

        $relationships = ItemSupplier::query()->where('item_id', $item->id)
            ->with(['supplier:id,code,name,is_active', 'purchaseUnit:id,code,name,symbol', 'prices'])->get();
        $candidates = [];
        $blocked = null;
        foreach ($relationships as $relationship) {
            if ($supplierId && (int) $relationship->supplier_id === $supplierId && $relationship->status === 'blocked') {
                $blocked = $relationship;
            }
            if ($relationship->status !== 'approved') {
                continue;
            }
            $candidate = $this->candidate($item, $relationship, $baseQty, $enteredFactor, $date, $baseCurrency);
            if ($candidate) {
                $candidates[] = $candidate;
            }
        }
        $ranked = array_values(array_filter($candidates, fn ($c) => $c['net_unit_price_base_unit_in_base_currency'] !== null));
        usort($ranked, fn ($a, $b) => Decimal::cmp($a['net_unit_price_base_unit_in_base_currency'], $b['net_unit_price_base_unit_in_base_currency'])
            ?: ((int) $b['is_preferred'] <=> (int) $a['is_preferred']) ?: ($a['lead_time_days'] ?? PHP_INT_MAX) <=> ($b['lead_time_days'] ?? PHP_INT_MAX));
        $matchesDocument = fn ($c) => (! $supplierId || (int) $c['supplier_id'] === $supplierId) && (! $currency || $c['currency_code'] === $currency);
        $applicable = array_values(array_filter($candidates, $matchesDocument));
        usort($applicable, fn ($a, $b) => ((int) $b['is_preferred'] <=> (int) $a['is_preferred'])
            ?: Decimal::cmp($a['net_unit_price'], $b['net_unit_price']));

        return [
            'item_id' => $item->id,
            'quantity' => Decimal::qty($quantity),
            'unit_id' => $unitId,
            'base_quantity' => Decimal::qty($baseQty),
            'date' => $date,
            'currency_code' => $currency,
            'base_currency_code' => $baseCurrency,
            'preferred_supplier_id' => $item->preferred_supplier_id ? (int) $item->preferred_supplier_id : null,
            'suggestion' => $applicable[0] ?? null,
            'best' => $ranked[0] ?? null,
            'candidates' => $candidates,
            'blocked' => $blocked ? [
                'supplier_id' => (int) $blocked->supplier_id,
                'message' => __('inventory.supplier_terms.supplier_blocked_for_item'),
            ] : null,
            'rule' => __('inventory.supplier_terms.price_rule'),
        ];
    }

    private function candidate(Item $item, ItemSupplier $relationship, string $baseQty, string $enteredFactor, string $date, string $baseCurrency): ?array
    {
        $factor = (string) $relationship->purchase_unit_factor ?: '1';
        if (! Decimal::gt($factor, '0')) {
            return null;
        }
        $purchaseQty = Decimal::div($baseQty, $factor);
        $tier = $relationship->prices
            ->filter(fn (SupplierPriceList $t) => (bool) $t->is_active
                && (! $t->effective_from || $t->effective_from->toDateString() <= $date)
                && (! $t->effective_to || $t->effective_to->toDateString() >= $date)
                && Decimal::gte($purchaseQty, (string) $t->minimum_qty))
            ->sort(fn ($a, $b) => Decimal::cmp((string) $b->minimum_qty, (string) $a->minimum_qty))
            ->first();
        if (! $tier) {
            return null;
        }
        $discount = (string) ($relationship->discount_percent ?? '0');
        $net = Decimal::mul((string) $tier->unit_cost, Decimal::sub('1', Decimal::div($discount, '100')));
        $perBase = Decimal::div($net, $factor);
        $code = (string) ($relationship->currency_code ?: $baseCurrency);
        $rate = $code === $baseCurrency ? '1' : $this->link->rate($code, $date);
        $suggestedPurchaseQty = $this->roundToTerms($purchaseQty, $relationship);
        $scale = $relationship->price_scale ?: 6;

        return [
            'item_supplier_id' => $relationship->id,
            'supplier_id' => (int) $relationship->supplier_id,
            'supplier' => $relationship->supplier?->only(['id', 'code', 'name']),
            'is_preferred' => (int) $item->preferred_supplier_id === (int) $relationship->supplier_id,
            'supplier_sku' => $relationship->supplier_sku,
            'supplier_item_name' => $relationship->supplier_item_name,
            'currency_code' => $code,
            'tier_id' => $tier->id,
            'tier_minimum_qty' => (string) $tier->minimum_qty,
            'unit_price' => (string) $tier->unit_cost,
            'discount_percent' => $relationship->discount_percent ? (string) $relationship->discount_percent : null,
            'net_unit_price' => Decimal::round($net, $scale),
            'purchase_unit' => $relationship->purchaseUnit?->only(['id', 'code', 'name', 'symbol']),
            'purchase_unit_factor' => $factor,
            'net_unit_price_base_unit' => Decimal::round($perBase, 6),
            'net_unit_price_entered_unit' => Decimal::round(Decimal::mul($perBase, $enteredFactor), 6),
            'net_unit_price_base_unit_in_base_currency' => $rate === null ? null : Decimal::round(Decimal::div($perBase, $rate), 6),
            'exchange_rate' => $rate,
            'price_scale' => $relationship->price_scale,
            'lead_time_days' => $relationship->lead_time_days,
            'expected_date' => $relationship->lead_time_days !== null ? CarbonImmutable::parse($date)->addDays((int) $relationship->lead_time_days)->toDateString() : null,
            'minimum_order_qty' => $relationship->minimum_order_qty ? (string) $relationship->minimum_order_qty : null,
            'order_multiple' => $relationship->order_multiple ? (string) $relationship->order_multiple : null,
            'purchase_quantity' => Decimal::qty($purchaseQty),
            'suggested_purchase_quantity' => $suggestedPurchaseQty,
            'suggested_entered_quantity' => Decimal::qty(Decimal::div(Decimal::mul($suggestedPurchaseQty, $factor), $enteredFactor)),
            'quantity_adjusted' => Decimal::cmp($suggestedPurchaseQty, Decimal::qty($purchaseQty)) !== 0,
        ];
    }

    /** At least the minimum order quantity, rounded up to the order multiple. */
    public function roundToTerms(string $purchaseQty, ItemSupplier $relationship): string
    {
        $qty = $purchaseQty;
        if ($relationship->minimum_order_qty && Decimal::lt($qty, (string) $relationship->minimum_order_qty)) {
            $qty = (string) $relationship->minimum_order_qty;
        }
        $multiple = (string) ($relationship->order_multiple ?? '');
        if ($multiple !== '' && Decimal::gt($multiple, '0')) {
            $steps = Decimal::div($qty, $multiple);
            $whole = Decimal::round($steps, 0);
            if (Decimal::lt($whole, $steps)) {
                $whole = Decimal::add($whole, '1');
            }
            $qty = Decimal::mul($whole, $multiple);
        }

        return Decimal::qty($qty);
    }

    private function enteredFactor(Item $item, ?int $unitId): string
    {
        if (! $unitId || (int) $unitId === (int) $item->base_unit_id) {
            return '1';
        }
        try {
            return (string) app(UnitConversionResolver::class)->normalizeLine([
                'item_id' => $item->id, 'entered_unit_id' => $unitId, 'entered_qty' => '1',
            ], 'ordered_qty')['unit_conversion_factor'];
        } catch (RuntimeException) {
            return '1';
        }
    }

    private function positive(string $quantity): string
    {
        $quantity = trim($quantity);

        return preg_match('/^\d+(?:\.\d+)?$/D', $quantity) && Decimal::gt($quantity, '0') ? $quantity : '1';
    }
}
