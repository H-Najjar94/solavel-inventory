<?php
// Standalone pure regression lane: no Laravel bootstrap, configuration, or database access.
require_once __DIR__.'/../../../app/Services/Stock/Support/Decimal.php';
require_once __DIR__.'/../../../app/Services/Integration/FinanceMoneyPrecision.php';
use App\Services\Integration\FinanceMoneyPrecision as Precision;
use App\Services\Stock\Support\Decimal;

$checks = 0;
$check = function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
foreach ([2, 3, '2', '3'] as $scale) $check(Precision::supported($scale), 'Qualified precision rejected');
foreach ([null, 0, 1, 4, 6, 2.5, true, '03', '3x'] as $scale) $check(!Precision::supported($scale), 'Unqualified precision accepted');
// Independently chosen carrying value: debit inventory 10; credit accrual 10.
// Extra Finance decimal changes representation only, not inventory carrying value.
$lines = [['base_debit'=>'10.000','base_credit'=>'0.000'], ['base_debit'=>'0.000','base_credit'=>'10.000']];
$before = $lines;
Precision::assertBalancedInventory($lines, 3);
$check($lines === $before, 'Inventory values mutated');
Precision::assertBalancedInventory([['base_debit'=>'12.34','base_credit'=>'0'],['base_debit'=>'0','base_credit'=>'12.34']], 2);
$check(Decimal::MONEY_SCALE === 2 && Decimal::QTY_SCALE === 4 && Decimal::COST_SCALE === 4, 'Native precision changed');
// A one-unit Finance discrepancy must stop, not change either carrying value.
foreach (['0.001', '0.010', '-0.001'] as $difference) {
    $rejected = false;
    try { Precision::assertBalancedInventory([['base_debit'=>'10.000','base_credit'=>Decimal::add('10', $difference)]], 3); }
    catch (RuntimeException $e) { $rejected = true; }
    $check($rejected, 'Inventory discrepancy automatically tolerated');
}
// 10 AED at 3.67 transaction units/AED is exactly 36.70; divide back gives 10.000.
$check(Decimal::round(Decimal::mul('10.00', '3.67'), 2) === '36.70', 'Foreign nominal amount changed');
$check(Decimal::round(Decimal::div('36.70','3.67'), 3) === '10.000', 'Foreign carrying value changed');
// 1.01 base at rate1.5 rounds nominal1.515 to1.52: back-conversion1.013 cannot represent1.010.
$check(Decimal::cmp(Decimal::round(Decimal::div('1.52','1.5'),3),'1.010') !== 0, 'Unrepresentable carrying value concealed');
$current = ['money_scale'=>3, 'rate_scale'=>8, 'base_currency_code'=>'AED'];
$old = ['version'=>'trusted-v2', 'currency'=>['money_scale'=>2,'rate_scale'=>6,'rounding_mode'=>'HALF_UP','base_code'=>'AED']];
$reversal = Precision::reversalContract($current, $old, 'trusted-v2');
$check($reversal['money_scale'] === 2 && $reversal['rate_scale'] === 6, 'Historical reversal precision changed');
$check($current['money_scale'] === 3 && $old['currency']['money_scale'] === 2, 'Source or current settings mutated');
foreach ([null, [], array_replace($old,['version'=>'legacy']), ['version'=>'trusted-v2','currency'=>array_replace($old['currency'],['base_code'=>'USD'])]] as $missing) {
    $rejected = false;
    try { Precision::reversalContract($current,$missing,'trusted-v2'); } catch (RuntimeException $e) { $rejected = true; }
    $check($rejected, 'Missing or untrusted original reversal proof accepted');
}
echo "PASS {$checks} pure precision assertions; no database access\n";
