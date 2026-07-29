<?php

namespace App\Services\Integration;

use App\Models\Landlord\Organization as CentralOrganization;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SolaStockJournalContractBuilder
{
    public function __construct(private readonly AccountingJournalBuilder $journals) {}

    /**
     * Build only. No event, attempt, nonce, usage counter, mapping, or remote
     * state is changed by this method.
     */
    public function build(IntegrationOutboxEvent $event): array
    {
        $orgId = (int) $event->organization_id;
        $setting = IntegrationSetting::query()->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)->first();
        if (! $setting) {
            throw new RuntimeException('The immutable SolaBooks integration mapping is missing.');
        }
        $meta = (array) $setting->meta;
        $finance = (array) ($meta['finance_currency_contract'] ?? []);
        foreach (['client_id', 'central_organization_id', 'signing_key_id'] as $field) {
            if (empty($meta[$field])) {
                throw new RuntimeException("Integration identity field '{$field}' is missing.");
            }
        }
        foreach (['base_currency_code', 'enabled_currency_codes', 'money_scale', 'rate_scale'] as $field) {
            if (! array_key_exists($field, $finance)) {
                throw new RuntimeException("Authoritative Finance currency field '{$field}' is missing.");
            }
        }
        $tenantConnection = config('tenancy.tenant_connection', 'tenant');
        if (Schema::connection($tenantConnection)->hasTable('organizations')) {
            $orgQuery = DB::connection($tenantConnection)->table('organizations');
            if (Schema::connection($tenantConnection)->hasColumn('organizations', 'central_org_id')) {
                $orgQuery->where('central_org_id', (int) $meta['central_organization_id']);
            } else {
                $orgQuery->where('id', (int) $meta['central_organization_id']);
            }
            $central = $orgQuery->first();
        } else {
            $central = CentralOrganization::query()
                ->where('central_organization_id', (int) $meta['central_organization_id'])->first();
        }
        if (! $central
            || (property_exists($central, 'is_active') && ! (bool) $central->is_active)
            || (property_exists($central, 'deleted_at') && $central->deleted_at !== null)
            || (property_exists($central, 'setup_status') && $central->setup_status !== 'complete')) {
            throw new RuntimeException('The central organization is inactive, archived, deleted, or mismatched.');
        }

        $eventPayload = (array) $event->payload;
        $transaction = $eventPayload['currency'] ?? null;
        if (! is_array($transaction) || empty($transaction['code'])) {
            throw new RuntimeException('Transaction currency is required; no currency default is permitted.');
        }
        $txCode = (string) $transaction['code'];
        $baseCode = (string) $finance['base_currency_code'];
        if (! preg_match('/^[A-Z]{3}$/', $txCode) || ! preg_match('/^[A-Z]{3}$/', $baseCode)) {
            throw new RuntimeException('Currency codes must be uppercase three-letter ISO codes.');
        }
        if (! in_array($txCode, (array) $finance['enabled_currency_codes'], true)) {
            throw new RuntimeException("Currency '{$txCode}' is not enabled by SolaBooks for this organization.");
        }
        $date = (string) ($eventPayload['document_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('The source transaction date is required.');
        }
        if ($txCode === $baseCode) {
            $rate = '1';
            $rateDate = $date;
            $rateSource = 'identity';
        } else {
            foreach (['exchange_rate', 'rate_date', 'rate_source'] as $field) {
                if (! isset($transaction[$field]) || $transaction[$field] === '') {
                    throw new RuntimeException("Foreign-currency field '{$field}' is required.");
                }
            }
            $rate = (string) $transaction['exchange_rate'];
            $rateDate = (string) $transaction['rate_date'];
            $rateSource = (string) $transaction['rate_source'];
            if (Decimal::cmp($rate, '0') <= 0 || $rateDate !== $date) {
                throw new RuntimeException('Foreign-currency rate must be positive and dated on the transaction date.');
            }
        }

        $moneyScale = (int) $finance['money_scale'];
        $txScale = (int) ($finance['currency_precisions'][$txCode] ?? $moneyScale);
        $lines = array_map(function (array $line) use ($rate, $moneyScale, $txScale, $meta): array {
            $debit = Decimal::round((string) ($line['debit'] ?? '0'), $txScale);
            $credit = Decimal::round((string) ($line['credit'] ?? '0'), $txScale);
            $result = [
                'account_id' => (int) $line['account_id'],
                'account_role' => (string) $line['account_role'],
                'debit' => $debit,
                'credit' => $credit,
                'base_debit' => Decimal::round(Decimal::div($debit, $rate), $moneyScale),
                'base_credit' => Decimal::round(Decimal::div($credit, $rate), $moneyScale),
                'description' => $line['description'] ?? null,
            ];
            if (! empty($line['is_tax_line'])) {
                $taxId = (int) $line['tax_rate_id'];
                $taxAuthority = (array) data_get($meta, "finance_tax_contract.{$taxId}", []);
                if (! isset($taxAuthority['rate'])) {
                    throw new RuntimeException("Authoritative Finance tax metadata is missing for tax {$taxId}.");
                }
                $taxAmount = Decimal::round(Decimal::gt($debit, $credit) ? $debit : $credit, $txScale);
                $result['tax'] = [
                    'tax_id' => $taxId,
                    'code' => (string) $line['tax_rate_code'],
                    'rate' => (string) $taxAuthority['rate'],
                    'treatment' => (string) ($taxAuthority['treatment'] ?? 'standard'),
                    'taxable_amount' => Decimal::round((string) ($line['taxable_base_amount'] ?? '0'), $txScale),
                    'base_taxable_amount' => Decimal::round(Decimal::div((string) ($line['taxable_base_amount'] ?? '0'), $rate), $moneyScale),
                    'tax_amount' => $taxAmount,
                    'base_tax_amount' => Decimal::round(Decimal::div($taxAmount, $rate), $moneyScale),
                ];
            }

            return $result;
        }, $this->journals->build($event, $orgId));
        $lines = $this->balanceBaseLines($lines, $moneyScale, (int) ($meta['finance_rounding_account_id'] ?? 0));

        $original = $eventPayload['original_source'] ?? null;
        $reversal = null;
        if (is_array($original) && ! empty($original['event_uuid'])) {
            $originalKey = IntegrationOutboxEvent::query()->where('organization_id', $orgId)
                ->where('event_uuid', $original['event_uuid'])->value('idempotency_key');
            if (! $originalKey) {
                throw new RuntimeException('The original reversal source identity is missing.');
            }
            $reversal = ['original_source_key' => $originalKey, 'reason_code' => $original['reason_code'] ?? null];
        }

        return [
            // Header aliases are also bound by the request signature middleware.
            'inventory_organization_id' => $orgId,
            'finance_organization_id' => (int) $setting->solabooks_organization_id,
            'external_source_key' => (string) $event->idempotency_key,
            'event_type' => (string) $event->event_type,
            'contract_version' => SolaStockJournalContract::VERSION,
            'identity' => [
                'central_client_id' => (int) $meta['client_id'],
                'central_organization_id' => (int) $meta['central_organization_id'],
                'inventory_organization_id' => $orgId,
                'finance_organization_id' => (int) $setting->solabooks_organization_id,
                'integration_mapping_id' => (int) $setting->id,
                'signing_key_id' => (string) $meta['signing_key_id'],
            ],
            'source' => [
                'key' => (string) $event->idempotency_key,
                'idempotency_key' => (string) $event->idempotency_key,
                'event_type' => (string) $event->event_type,
                'document_type' => (string) $event->aggregate_type,
                'document_id' => (int) $event->aggregate_id,
                'document_number' => $event->aggregate_number,
                'transaction_date' => $date,
                'reversal' => $reversal,
            ],
            'currency' => [
                'transaction_code' => $txCode,
                'base_code' => $baseCode,
                'exchange_rate' => $rate,
                'rate_date' => $rateDate,
                'rate_source' => $rateSource,
                'money_scale' => $moneyScale,
                'rate_scale' => (int) $finance['rate_scale'],
                'rounding_mode' => 'HALF_UP',
            ],
            'lines' => $lines,
        ];
    }

    private function balanceBaseLines(array $lines, int $scale, int $roundingAccountId): array
    {
        $debit = '0';
        $credit = '0';
        foreach ($lines as $line) {
            $debit = Decimal::add($debit, (string) $line['base_debit']);
            $credit = Decimal::add($credit, (string) $line['base_credit']);
        }
        $diff = Decimal::round(Decimal::sub($debit, $credit), $scale);
        if (Decimal::isZero($diff, $scale)) {
            return $lines;
        }
        $absolute = Decimal::lt($diff, '0') ? ltrim($diff, '-') : $diff;
        $unit = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
        if (Decimal::gt($absolute, $unit, $scale)) {
            throw new RuntimeException('Foreign-currency conversion produced more than one minor unit of base imbalance.');
        }
        $target = null;
        foreach ($lines as $index => $line) {
            if ($roundingAccountId > 0 && (int) $line['account_id'] === $roundingAccountId) {
                $target = $index;
                break;
            }
        }
        $target ??= 0;
        foreach ($lines as $index => $line) {
            if ($roundingAccountId > 0 && (int) $lines[$target]['account_id'] === $roundingAccountId) {
                break;
            }
            $magnitude = Decimal::gt((string) $line['base_debit'], (string) $line['base_credit'])
                ? (string) $line['base_debit'] : (string) $line['base_credit'];
            $best = Decimal::gt((string) $lines[$target]['base_debit'], (string) $lines[$target]['base_credit'])
                ? (string) $lines[$target]['base_debit'] : (string) $lines[$target]['base_credit'];
            if (Decimal::cmp($magnitude, $best, $scale) > 0
                || (Decimal::cmp($magnitude, $best, $scale) === 0 && (int) $line['account_id'] < (int) $lines[$target]['account_id'])) {
                $target = $index;
            }
        }
        if (Decimal::gt((string) $lines[$target]['base_debit'], '0')) {
            $lines[$target]['base_debit'] = Decimal::round(Decimal::sub((string) $lines[$target]['base_debit'], $diff), $scale);
        } else {
            $lines[$target]['base_credit'] = Decimal::round(Decimal::add((string) $lines[$target]['base_credit'], $diff), $scale);
        }

        return $lines;
    }
}
