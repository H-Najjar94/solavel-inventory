<?php

namespace Tests\Feature\Access;

use App\Services\Access\InventoryPermissionService;
use App\Tenancy\OrganizationContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * C1 — proves the inventory permission layer is fail-closed and has NO default
 * admin: a user only gets the permissions their CENTRAL org-membership role
 * maps to, and an unknown/absent membership (or no tenant context) gets nothing.
 *
 * The central role lookup is overridden (no live central DB needed) so we test
 * the security mapping + gating logic deterministically.
 */
class InventoryPermissionTest extends TestCase
{
    private const ORG = 660066; // a non-demo, non-reserved org id

    /** Permission service with an injectable central role (bypasses the DB). */
    private function perms(?string $centralRole): InventoryPermissionService
    {
        return new class(app(OrganizationContext::class), $centralRole) extends InventoryPermissionService {
            public function __construct(OrganizationContext $ctx, private ?string $forcedRole)
            {
                parent::__construct($ctx);
            }

            protected function fetchCentralRole(int $userId, int $orgId): ?string
            {
                return $this->forcedRole;
            }
        };
    }

    private function user(): object
    {
        return (object) ['id' => 4242];
    }

    protected function tearDown(): void
    {
        app(OrganizationContext::class)->forget();
        parent::tearDown();
    }

    // Write/admin actions a low-privilege user must NOT be able to do.
    private const WRITE_ACTIONS = [
        'inventory.manage_items',        // create / edit items
        'inventory.manage_adjustments',  // post / reverse adjustments
        'inventory.manage_opening_stock',
        'inventory.manage_shipments',    // stock OUT
        'inventory.manage_settings',     // provisioning gate
    ];

    #[Test]
    public function no_tenant_context_denies_everything(): void
    {
        app(OrganizationContext::class)->forget();
        $p = $this->perms('client_owner'); // even an owner is denied without context
        foreach (['inventory.view_items', 'inventory.manage_items', 'inventory.manage_settings'] as $perm) {
            $this->assertFalse($p->can($this->user(), $perm), "no-context must deny {$perm}");
        }
    }

    #[Test]
    public function no_membership_denies_everything_no_admin_fallback(): void
    {
        app(OrganizationContext::class)->set(self::ORG);
        $p = $this->perms(null); // user is NOT a member of this org
        // The old bug granted full admin here. Now it must grant nothing.
        $this->assertSame([], $p->permissionsFor($this->user()));
        foreach (['inventory.view_dashboard', 'inventory.view_items', 'inventory.manage_items', 'inventory.manage_settings'] as $perm) {
            $this->assertFalse($p->can($this->user(), $perm), "no-membership must deny {$perm}");
        }
    }

    #[Test]
    public function viewer_cannot_create_post_reverse_or_provision(): void
    {
        app(OrganizationContext::class)->set(self::ORG);
        // Any unknown central role maps to the least-privilege read-only viewer.
        $p = $this->perms('some_unknown_role');
        $this->assertTrue($p->can($this->user(), 'inventory.view_items'), 'viewer can view');
        $this->assertTrue($p->can($this->user(), 'inventory.view_stock'), 'viewer can view stock');
        foreach (self::WRITE_ACTIONS as $perm) {
            $this->assertFalse($p->can($this->user(), $perm), "viewer must NOT {$perm}");
        }
    }

    #[Test]
    public function member_can_operate_but_cannot_manage_settings_or_provision(): void
    {
        app(OrganizationContext::class)->set(self::ORG);
        $p = $this->perms('client_member'); // → inventory_manager
        // Can operate inventory…
        $this->assertTrue($p->can($this->user(), 'inventory.manage_items'));
        $this->assertTrue($p->can($this->user(), 'inventory.manage_adjustments'));
        $this->assertTrue($p->can($this->user(), 'inventory.manage_shipments'));
        // …but NOT the admin/provisioning gate.
        $this->assertFalse($p->can($this->user(), 'inventory.manage_settings'), 'member must NOT provision/manage settings');
    }

    #[Test]
    public function owner_is_full_admin(): void
    {
        app(OrganizationContext::class)->set(self::ORG);
        $p = $this->perms('client_owner'); // → inventory_admin = '*'
        foreach (array_merge(self::WRITE_ACTIONS, ['inventory.view_items', 'inventory.manage_settings']) as $perm) {
            $this->assertTrue($p->can($this->user(), $perm), "owner must be able to {$perm}");
        }
    }
}
