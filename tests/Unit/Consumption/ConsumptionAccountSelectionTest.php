<?php

namespace Tests\Unit\Consumption;

use App\Services\Consumption\ConsumptionAccountSelection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../app/Services/Consumption/ConsumptionAccountSelection.php';

final class ConsumptionAccountSelectionTest extends TestCase
{
    public function testAuthorizedOverrideWins(): void
    {
        self::assertSame(['account_id' => 11, 'source' => 'override'], ConsumptionAccountSelection::resolve(11, 22, 33, 44, true));
    }

    public function testItemThenCategoryThenConnectionDefaults(): void
    {
        self::assertSame(['account_id' => 22, 'source' => 'item'], ConsumptionAccountSelection::resolve(null, 22, 33, 44, false));
        self::assertSame(['account_id' => 33, 'source' => 'category'], ConsumptionAccountSelection::resolve(null, null, 33, 44, false));
        self::assertSame(['account_id' => 44, 'source' => 'connection'], ConsumptionAccountSelection::resolve(null, null, null, 44, false));
    }

    public function testMissingMappingDoesNotGuess(): void
    {
        self::assertNull(ConsumptionAccountSelection::resolve(null, null, null, null, false));
    }

    public function testUnauthorizedOverrideCannotFallBackToValidDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consumption_account_override_forbidden');
        ConsumptionAccountSelection::resolve(11, 22, 33, 44, false);
    }

    public function testInvalidSelectionCannotFallBack(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConsumptionAccountSelection::resolve(null, 0, 33, 44, true);
    }

    public function testOnlyLivePostableExpenseAccountsInMappedOrganizationQualify(): void
    {
        $valid = ['organization_id' => 9, 'is_active' => true, 'is_postable' => true, 'type' => 'expense'];
        self::assertTrue(ConsumptionAccountSelection::validAccount($valid, 9));
        foreach ([['organization_id' => 8], ['is_active' => false], ['is_postable' => false],
            ['type' => 'asset'], ['type' => 'cogs'], ['type' => 'liability'], ['deleted_at' => '2026-10-01']] as $change) {
            self::assertFalse(ConsumptionAccountSelection::validAccount(array_replace($valid, $change), 9));
        }
        self::assertFalse(ConsumptionAccountSelection::validAccount(null, 9));
        self::assertFalse(ConsumptionAccountSelection::validAccount($valid, 0));
    }
}
