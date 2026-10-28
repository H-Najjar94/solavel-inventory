<?php

namespace App\Console\Commands;

use App\Models\Tenant\ShipmentLine;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Stock\Support\Decimal;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY. Lists posted shipment lines whose frozen unit-conversion snapshot is not a
 * valid current (v2) snapshot, and whether a customer return can still be posted:
 *   v1_verified  - historical v1 evidence that verifies under v1 rules; returnable via the
 *                  narrow compatibility path (v2-equivalent values derived at return time)
 *   v1_verified_cash_path_unsupported - verified v1, but the shipment belongs to a cash sale
 *                  (sales_receipt origin): the cash-admission return path still uses
 *                  normalizeLineFromSnapshot and rejects v1, so NOT returnable
 *   v1_invalid   - v1 label but hash/arithmetic does not verify: still blocked
 *   v2_invalid   - v2 label but hash/arithmetic invalid: blocked
 *   missing      - no/partial snapshot: blocked
 * Out of scope (still unsupported, tracked separately): supplier returns against older goods
 * receipts and cash-sale returns. No bulk mode, no writes.
 */
final class ReportLegacyUnitConversion extends Command
{
    protected $signature = 'returns:report-legacy-unit-conversion {--organization= : Exact SolaStock organization id}';

    protected $description = 'Read-only: shipment lines with a v1/invalid unit-conversion snapshot and whether a CUSTOMER return can post. Supplier returns against older receipts and cash-sale returns remain unsupported.';

    public function handle(TenantManager $tenants, UnitConversionResolver $resolver): int
    {
        $organization = (int) $this->option('organization');
        if ($organization <= 0) {
            $this->error('An exact --organization is required.');

            return self::FAILURE;
        }
        $rows = $tenants->runForTenant($organization, function () use ($organization, $resolver): array {
            $out = [];
            $returned = DB::connection('tenant')->table('sales_return_lines as l')
                ->join('sales_returns as r', 'r.id', '=', 'l.sales_return_id')
                ->where('l.organization_id', $organization)->whereNull('r.deleted_at')->where('r.status', '!=', 'cancelled')
                ->whereNotNull('l.source_shipment_line_id')
                ->selectRaw('l.source_shipment_line_id as id, sum(l.returned_qty) as qty')->groupBy('l.source_shipment_line_id')
                ->pluck('qty', 'id');
            $numbers = DB::connection('tenant')->table('shipments')->where('organization_id', $organization)
                ->where('status', 'posted')->pluck('shipment_number', 'id');
            $cashOrders = DB::connection('tenant')->table('stock_financial_origin_requests')
                ->where('organization_id', $organization)->where('source_document_type', 'sales_receipt')
                ->whereNotNull('sales_order_id')->pluck('sales_order_id')->flip();
            $orderOf = DB::connection('tenant')->table('shipments')->where('organization_id', $organization)->pluck('sales_order_id', 'id');
            ShipmentLine::query()->where('organization_id', $organization)
                ->whereIn('shipment_id', $numbers->keys()->all())
                ->orderBy('id')->each(function (ShipmentLine $l) use (&$out, $resolver, $returned, $numbers, $cashOrders, $orderOf): void {
                    $status = $this->finalStatus($l, $resolver, $cashOrders->all(), $orderOf->all());
                    if ($status === 'v2_ok') {
                        return;
                    }
                    $remaining = Decimal::sub((string) $l->quantity, (string) ($returned[$l->id] ?? '0'));
                    $out[] = [$numbers[$l->shipment_id] ?? null, $l->shipment_id, $l->id, $l->item_id, (string) $l->quantity,
                        (string) $l->unit_conversion_version, $status, in_array($status, ['v1_verified'], true) ? 'yes' : 'no', $remaining];
                });

            return $out;
        });
        $this->table(['shipment', 'shipment_id', 'line_id', 'item', 'qty', 'snapshot version', 'status', 'returnable', 'remaining'], $rows);
        $this->info(count($rows).' shipment line(s) with a non-current snapshot. Nothing was changed.');

        return self::SUCCESS;
    }

    /** @param array<int|string,mixed> $cashOrders sales_order_id => index; $orderOf shipment_id => sales_order_id */
    public function finalStatus(ShipmentLine $l, UnitConversionResolver $resolver, array $cashOrders, array $orderOf): string
    {
        $status = $this->classify($l, $resolver);
        $order = $orderOf[$l->shipment_id] ?? null;
        if ($status === 'v1_verified' && $order !== null && array_key_exists($order, $cashOrders)) {
            return 'v1_verified_cash_path_unsupported';
        }

        return $status;
    }

    private function classify(ShipmentLine $l, UnitConversionResolver $resolver): string
    {
        foreach (['entered_qty', 'entered_unit_id', 'base_unit_id', 'unit_conversion_factor', 'unit_conversion_version',
            'unit_conversion_hash', 'unit_conversion_precision', 'unit_conversion_rounding_mode'] as $f) {
            if ($l->{$f} === null || $l->{$f} === '') {
                return 'missing';
            }
        }
        if ((string) $l->unit_conversion_version === UnitConversionResolver::LEGACY_V1_VERSION) {
            return $resolver->legacyV1Verifies($l, (string) $l->quantity) ? 'v1_verified' : 'v1_invalid';
        }
        if ((string) $l->unit_conversion_version !== UnitConversionResolver::CONTRACT_VERSION) {
            return 'v2_invalid';
        }
        $factor = (string) $l->unit_conversion_factor;
        $snapshot = [
            'organization_id' => (int) $l->organization_id, 'item_id' => (int) $l->item_id,
            'source_unit_id' => (int) $l->entered_unit_id, 'base_unit_id' => (int) $l->base_unit_id,
            'conversion_id' => $l->unit_conversion_id === null ? null : (int) $l->unit_conversion_id,
            'factor' => Decimal::round($factor, 8), 'version' => UnitConversionResolver::CONTRACT_VERSION,
            'precision' => UnitConversionResolver::PRECISION, 'rounding_mode' => UnitConversionResolver::ROUNDING_MODE,
        ];
        $ok = (int) $l->unit_conversion_precision === UnitConversionResolver::PRECISION
            && Decimal::cmp(Decimal::qty(Decimal::mul((string) $l->entered_qty, $factor)), (string) $l->quantity, UnitConversionResolver::PRECISION) === 0
            && hash_equals((string) $l->unit_conversion_hash, hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)));

        return $ok ? 'v2_ok' : 'v2_invalid';
    }
}
