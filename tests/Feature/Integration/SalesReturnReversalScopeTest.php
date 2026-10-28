<?php

namespace Tests\Feature\Integration;

use App\Services\Integration\AccountRolePolicy;
use App\Services\Integration\OrganizationAccountRequirements;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * A connected organization on the default reviewed scope must be able to reverse a
 * posted customer return: sales_return.reversed uses a subset of the roles that
 * sales_return.posted already required (inventory_asset, cogs).
 */
class SalesReturnReversalScopeTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function default_reviewed_scope_includes_every_reversal_a_posted_return_can_emit(): void
    {
        $scope = config('integration_connection_wizard.allowed_workflows');
        foreach (['sales_return.posted', 'sales_return.reversed', 'adjustment.posted', 'adjustment.reversed'] as $op) {
            $this->assertContains($op, $scope, "{$op} missing from the default reviewed scope");
        }
        $this->assertSame([], array_diff(
            AccountRolePolicy::forOperations(['sales_return.reversed']),
            AccountRolePolicy::forOperations(['sales_return.posted']),
        ), 'reversal must not introduce roles beyond the posted return');
    }

    #[Test]
    public function connected_org_on_default_scope_is_not_refused_as_out_of_scope(): void
    {
        $this->useTenantA();
        DB::connection('tenant')->table('integration_settings')->insert([
            'organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks', 'mode' => 'active',
            'meta' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
        try {
            app(OrganizationAccountRequirements::class)->assertOperationReady(TenantTestManager::ORG_A, 'sales_return.reversed');
            $this->addToAssertionCount(1);
        } catch (ValidationException $e) {
            $this->assertArrayNotHasKey('workflow', $e->errors(), 'sales_return.reversed refused as operation_not_in_reviewed_scope');
        }
    }
}
