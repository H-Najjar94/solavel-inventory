<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryCurrencyRate;
use App\Services\Stock\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resolves workflow currency from the operational document chain and the
 * Finance-authoritative currency snapshot installed with the immutable mapping.
 * It never substitutes a global or organization default for a missing
 * transaction currency.
 */
final class WorkflowCurrencyResolver
{
    public function resolve(object $document, string $documentType, ?string $date): array
    {
        $orgId = (int) $document->organization_id;
        $mapping = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $orgId)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->where('status', 'verified_hold')
            ->where('activation_state', 'maintenance_hold')
            ->first();
        if (! $mapping) {
            return ['code' => $this->legacyDocumentCurrency($document, $documentType)];
        }

        $setting = IntegrationSetting::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->first();
        $authority = (array) data_get($setting?->meta, 'finance_currency_contract', []);
        $base = (string) ($authority['base_currency_code'] ?? '');
        $enabled = (array) ($authority['enabled_currency_codes'] ?? []);
        $code = $this->documentCurrency($document, $documentType, true);
        $transactionDate = $this->normalizeDate($date);

        if (! preg_match('/^[A-Z]{3}$/', $base)
            || ! preg_match('/^[A-Z]{3}$/', $code)
            || ! in_array($code, $enabled, true)
            || ! $transactionDate) {
            $this->fail('workflow_currency_invalid', [
                'transaction_currency' => $code ?: null,
                'base_currency' => $base ?: null,
                'transaction_date' => $transactionDate,
            ]);
        }
        if ($base !== (string) $mapping->base_currency_code) {
            $this->fail('workflow_currency_authority_mismatch');
        }

        if ($code === $base) {
            return [
                'code' => $code,
                'exchange_rate' => '1',
                'rate_date' => $transactionDate,
                'rate_source' => 'identity',
            ];
        }

        $rate = InventoryCurrencyRate::query()
            ->where('organization_id', $orgId)
            ->where('currency_code', $code)
            ->whereDate('effective_date', $transactionDate)
            ->first();
        if (! $rate || Decimal::cmp((string) $rate->rate_to_base, '0') <= 0) {
            $this->fail('workflow_exchange_rate_missing_or_invalid', [
                'transaction_currency' => $code,
                'transaction_date' => $transactionDate,
            ]);
        }

        return [
            'code' => $code,
            'exchange_rate' => (string) $rate->rate_to_base,
            'rate_date' => $transactionDate,
            'rate_source' => 'solabooks_authoritative_snapshot',
        ];
    }

    private function documentCurrency(object $document, string $documentType, bool $strictContract = false): string
    {
        $direct = $document->integration_currency_code
            ?? ($strictContract ? null : ($document->currency_code ?? null));
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $table = match ($documentType) {
            'goods_receipt' => ['goods_receipts', 'purchase_order_id', 'inventory_purchase_orders'],
            'shipment', 'pick_list', 'pack' => [match ($documentType) {
                'shipment' => 'shipments',
                'pick_list' => 'pick_lists',
                default => 'packs',
            }, 'sales_order_id', 'inventory_sales_orders'],
            'sales_return' => ['sales_returns', 'shipment_id', 'shipments'],
            default => null,
        };
        if ($table) {
            [$sourceTable, $foreignKey, $parentTable] = $table;
            $parentId = $document->{$foreignKey} ?? null;
            if ($parentId) {
                if ($documentType === 'sales_return') {
                    $salesOrderId = DB::connection('tenant')->table($parentTable)
                        ->where('id', $parentId)->value('sales_order_id');
                    return $this->storedCurrency(
                        'inventory_sales_orders',
                        (int) $salesOrderId,
                        $strictContract,
                    );
                }

                return $this->storedCurrency($parentTable, (int) $parentId, $strictContract);
            }
        }

        if ($documentType === 'inventory_reversal') {
            $sourceType = Str::snake(class_basename((string) ($document->source_type ?? '')));
            if ($sourceType === 'goods_receipt') {
                $purchaseOrderId = DB::connection('tenant')->table('goods_receipts')
                    ->where('id', $document->source_id)->value('purchase_order_id');
                return $this->storedCurrency(
                    'inventory_purchase_orders',
                    (int) $purchaseOrderId,
                    $strictContract,
                );
            }
        }

        return '';
    }

    private function storedCurrency(string $table, int $id, bool $strictContract): string
    {
        if (Schema::connection('tenant')->hasColumn($table, 'integration_currency_code')) {
            $value = (string) DB::connection('tenant')->table($table)
                ->where('id', $id)->value('integration_currency_code');
            if ($value !== '' || $strictContract) {
                return $value;
            }
        }
        if (! $strictContract && Schema::connection('tenant')->hasColumn($table, 'currency_code')) {
            return (string) DB::connection('tenant')->table($table)
                ->where('id', $id)->value('currency_code');
        }

        return '';
    }

    private function legacyDocumentCurrency(object $document, string $documentType): ?string
    {
        $currency = $this->documentCurrency($document, $documentType);

        return $currency !== '' ? $currency : null;
    }

    private function normalizeDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    private function fail(string $code, array $context = []): never
    {
        throw ValidationException::withMessages([
            'currency' => [json_encode(['code' => $code] + $context, JSON_UNESCAPED_SLASHES)],
        ]);
    }
}
