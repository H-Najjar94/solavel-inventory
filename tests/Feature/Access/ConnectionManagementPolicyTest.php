<?php

namespace Tests\Feature\Access;

use App\Services\Integration\ConnectionManagementPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectionManagementPolicyTest extends TestCase
{
    private const CLIENT = 880021;

    private const ORGANIZATION = 880022;

    private const OWNER = 880023;

    private const MEMBER = 880024;

    /** @var string[] */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCentralTables();
        DB::connection('mysql')->beginTransaction();
        $this->seedIdentity();
    }

    protected function tearDown(): void
    {
        $central = DB::connection('mysql');
        while ($central->transactionLevel() > 0) {
            $central->rollBack();
        }
        foreach (array_reverse($this->createdTables) as $table) {
            Schema::connection('mysql')->drop($table);
        }
        parent::tearDown();
    }

    #[Test]
    public function eligible_owner_with_both_products_manages_and_reviews_without_role_grant(): void
    {
        $before = DB::connection('mysql')->table('app_permission_grants')->count();
        $status = app(ConnectionManagementPolicy::class)->status(self::ORGANIZATION, $this->user(self::OWNER));

        $this->assertTrue($status['current_user']['is_owner']);
        $this->assertTrue($status['current_user']['has_inventory_access']);
        $this->assertTrue($status['current_user']['has_finance_access']);
        $this->assertTrue($status['can_manage_connection']);
        $this->assertTrue($status['can_review_accounting']);
        $this->assertSame('allowed', $status['reason']);
        $this->assertSame($before, DB::connection('mysql')->table('app_permission_grants')->count());
    }

    #[Test]
    public function owner_without_finance_access_is_denied_with_an_explicit_reason(): void
    {
        $financeId = DB::connection('mysql')->table('projects')->where('slug', 'finance')->value('id');
        DB::connection('mysql')->table('user_projects')->where('user_id', self::OWNER)->where('project_id', $financeId)->delete();

        $status = app(ConnectionManagementPolicy::class)->status(self::ORGANIZATION, $this->user(self::OWNER));

        $this->assertFalse($status['can_manage_connection']);
        $this->assertFalse($status['can_review_accounting']);
        $this->assertSame('finance_access_required', $status['reason']);
    }

    #[Test]
    public function ordinary_dual_product_member_needs_explicit_connection_permission(): void
    {
        $status = app(ConnectionManagementPolicy::class)->status(self::ORGANIZATION, $this->user(self::MEMBER));

        $this->assertTrue($status['current_user']['has_both_product_access']);
        $this->assertFalse($status['can_manage_connection']);
        $this->assertSame('connection_management_permission_required', $status['reason']);
    }

    #[Test]
    public function authorized_admin_can_manage_according_to_the_canonical_grant(): void
    {
        $this->grant(self::MEMBER, ConnectionManagementPolicy::MANAGEMENT_PERMISSION);
        $status = app(ConnectionManagementPolicy::class)->status(self::ORGANIZATION, $this->user(self::MEMBER));

        $this->assertTrue($status['current_user']['is_admin']);
        $this->assertTrue($status['can_manage_connection']);
        $this->assertTrue($status['can_review_accounting']);
    }

    #[Test]
    public function explicit_segregation_policy_requires_a_different_reviewer(): void
    {
        $this->grant(null, ConnectionManagementPolicy::SEGREGATION_POLICY);
        $owner = app(ConnectionManagementPolicy::class)->status(self::ORGANIZATION, $this->user(self::OWNER));

        $this->assertTrue($owner['can_manage_connection']);
        $this->assertFalse($owner['can_review_accounting']);
        $this->assertSame('separate_reviewer_required', $owner['reason']);

        $this->grant(self::MEMBER, ConnectionManagementPolicy::MANAGEMENT_PERMISSION);
        $this->grant(self::MEMBER, ConnectionManagementPolicy::ACCOUNTING_REVIEW_PERMISSION);
        $reviewer = app(ConnectionManagementPolicy::class)->status(self::ORGANIZATION, $this->user(self::MEMBER));
        $this->assertTrue($reviewer['can_review_accounting']);
    }

    #[Test]
    public function cross_client_or_cross_organization_identity_fails_closed(): void
    {
        $this->assertSame('organization_unavailable',
            app(ConnectionManagementPolicy::class)->status(self::ORGANIZATION + 1, $this->user(self::OWNER))['reason']);
    }

    private function seedIdentity(): void
    {
        $central = DB::connection('mysql');
        $central->table('clients')->insert(['id' => self::CLIENT, 'is_active' => true]);
        $central->table('organizations')->insert([
            'id' => self::ORGANIZATION, 'central_organization_id' => self::ORGANIZATION,
            'client_id' => self::CLIENT, 'name' => 'Policy fixture', 'display_name' => 'Policy fixture',
            'database_name' => 'solastock_test_policy', 'is_active' => true,
        ]);
        foreach ([[self::OWNER, 'client_owner'], [self::MEMBER, 'client_manager']] as [$userId, $role]) {
            $central->table('users')->insert([
                'id' => $userId, 'client_id' => self::CLIENT, 'name' => "User {$userId}",
                'email' => "policy-{$userId}@invalid.test", 'password' => 'not-used', 'status' => 'active',
            ]);
            $central->table('user_organizations')->insert([
                'user_id' => $userId, 'organization_id' => self::ORGANIZATION, 'role' => $role, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['inventory', 'finance'] as $index => $slug) {
            $projectId = 880030 + $index;
            $central->table('projects')->insert(['id' => $projectId, 'slug' => $slug, 'is_active' => true]);
            $central->table('organization_projects')->insert([
                'organization_id' => self::ORGANIZATION, 'project_id' => $projectId, 'is_active' => true,
            ]);
            foreach ([self::OWNER, self::MEMBER] as $userId) {
                $central->table('user_projects')->insert([
                    'organization_id' => self::ORGANIZATION, 'user_id' => $userId,
                    'project_id' => $projectId, 'is_active' => true,
                ]);
            }
        }
    }

    private function grant(?int $userId, string $permission): void
    {
        DB::connection('mysql')->table('app_permission_grants')->insert([
            'organization_id' => self::ORGANIZATION, 'app_key' => 'inventory', 'user_id' => $userId,
            'permission_key' => $permission, 'effect' => 'allow',
        ]);
    }

    private function user(int $id): object
    {
        return (object) ['id' => $id, 'central_user_id' => $id];
    }

    private function ensureCentralTables(): void
    {
        $schema = Schema::connection('mysql');
        if (! $schema->hasTable('clients')) {
            $schema->create('clients', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_active');
                $table->softDeletes();
            });
            $this->createdTables[] = 'clients';
        }
        if (! $schema->hasTable('projects')) {
            $schema->create('projects', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('slug')->unique();
                $table->boolean('is_active');
                $table->softDeletes();
            });
            $this->createdTables[] = 'projects';
        }
        if (! $schema->hasTable('organization_projects')) {
            $schema->create('organization_projects', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->boolean('is_active');
            });
            $this->createdTables[] = 'organization_projects';
        }
        if (! $schema->hasTable('user_projects')) {
            $schema->create('user_projects', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('project_id');
                $table->boolean('is_active');
            });
            $this->createdTables[] = 'user_projects';
        }
        if (! $schema->hasTable('app_permission_grants')) {
            $schema->create('app_permission_grants', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->string('app_key');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('permission_key');
                $table->string('effect');
                $table->timestamp('expires_at')->nullable();
            });
            $this->createdTables[] = 'app_permission_grants';
        }
    }
}
