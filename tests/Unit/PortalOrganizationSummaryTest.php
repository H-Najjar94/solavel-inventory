<?php

namespace Tests\Unit;

use App\Http\Middleware\VerifyPortalSummarySignature;
use App\Services\Portal\OrganizationSummary;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Hermetic SQLite-only tests: no production environment, migrations, billing, or HTTP. */
final class PortalOrganizationSummaryTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application(dirname(__DIR__, 2));
        $this->application->instance('config', new Repository([
            'app'=>['env'=>'testing'],
            'database'=>['default'=>'tenant'],
            'cache'=>['default'=>'array', 'stores'=>['array'=>['driver'=>'array', 'serialize'=>false]]],
            'solavel_sync'=>['secret'=>str_repeat('s', 64), 'allowed_client_ids'=>[]],
            'tenancy'=>['central_connection'=>'central'],
        ]));
        $this->application->instance('events', new \Illuminate\Events\Dispatcher($this->application));
        $capsule = new Manager($this->application);
        $capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>''], 'tenant');
        // Separate landlord DB catches accidentally reading tenant IDs for Stock.
        $capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>''], 'central');
        $this->application->instance('db', $capsule->getDatabaseManager());
        $this->application->instance('cache', new CacheManager($this->application));
        $this->application->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
            new class extends \Illuminate\Routing\ResponseFactory { public function __construct() {} });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->application);
        $this->assertSame('sqlite', DB::connection('tenant')->getDriverName());
        $this->assertSame(':memory:', DB::connection('tenant')->getDatabaseName());
        $this->identities();
    }

    protected function tearDown(): void
    {
        DB::disconnect('tenant');
        DB::disconnect('central');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function identities(): void
    {
        foreach (['tenant', 'central'] as $connection) {
            $db = DB::connection($connection);
            $db->statement('CREATE TABLE organizations (id INTEGER PRIMARY KEY, central_org_id INTEGER, client_id INTEGER, is_active INTEGER, deleted_at TEXT)');
            $db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, central_user_id INTEGER, status TEXT, deleted_at TEXT)');
            $db->statement('CREATE TABLE '.($connection === 'tenant' ? 'organization_user' : 'user_organizations').' (organization_id INTEGER, user_id INTEGER, role TEXT, status TEXT)');
        }
        DB::connection('tenant')->table('organizations')->insert([
            ['id'=>6, 'central_org_id'=>188], ['id'=>7, 'central_org_id'=>189],
        ]);
        DB::connection('tenant')->table('users')->insert(['id'=>9, 'central_user_id'=>323, 'status'=>'active']);
        DB::connection('tenant')->table('organization_user')->insert(['organization_id'=>6, 'user_id'=>9, 'role'=>'client_owner', 'status'=>'active']);
        $db = DB::connection('central');
        $db->statement('CREATE TABLE clients (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $db->table('clients')->insert(['id'=>87, 'is_active'=>1]);
        $db->table('organizations')->insert(['id'=>188, 'client_id'=>87, 'is_active'=>1]);
        $db->table('users')->insert(['id'=>323, 'status'=>'active']);
        $db->table('user_organizations')->insert(['organization_id'=>188, 'user_id'=>323, 'role'=>'client_owner', 'status'=>'active']);
    }

    private function signed(string $body = '{"client_id":87}', ?int $timestamp = null, ?string $appKey = null, ?string $nonce = null): Request
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));
        $canonical = implode("\n", ['POST', VerifyPortalSummarySignature::PATH, $appKey ?? VerifyPortalSummarySignature::APP_KEY, $timestamp, $nonce, hash('sha256', $body)]);
        return Request::create(VerifyPortalSummarySignature::PATH, 'POST', [], [], [], [
            'CONTENT_TYPE'=>'application/json', 'HTTP_X_PORTAL_TIMESTAMP'=>$timestamp, 'HTTP_X_PORTAL_NONCE'=>$nonce,
            'HTTP_X_PORTAL_SIGNATURE'=>hash_hmac('sha256', $canonical, str_repeat('s', 64)),
        ], $body);
    }

    private function authenticate(Request $request): int
    {
        return (new VerifyPortalSummarySignature)->handle($request, fn () => response()->json(['ok'=>true]))->getStatusCode();
    }

    public function test_authentication_requires_signature_and_app_binding(): void
    {
        $this->assertSame(401, $this->authenticate(Request::create(VerifyPortalSummarySignature::PATH, 'POST')));
        $this->assertSame(401, $this->authenticate($this->signed(appKey: 'other-app')));
        $this->assertSame(200, $this->authenticate($this->signed()));
    }

    public function test_signature_cannot_be_replayed_stale_or_mutated(): void
    {
        $request = $this->signed();
        $this->assertSame(200, $this->authenticate($request));
        $this->assertSame(409, $this->authenticate($request));
        $this->assertSame(401, $this->authenticate($this->signed(timestamp: time()-61)));
        $request = $this->signed();
        $request->initialize([], [], [], [], [], $request->server->all(), '{"client_id":88}');
        $this->assertSame(401, $this->authenticate($request));
    }

    public function test_missing_secret_and_client_allowlist_fail_closed(): void
    {
        config(['solavel_sync.secret'=>'']);
        $this->assertSame(503, $this->authenticate($this->signed()));
        config(['solavel_sync.secret'=>str_repeat('s',64), 'solavel_sync.allowed_client_ids'=>[88]]);
        $this->assertSame(403, $this->authenticate($this->signed()));
    }

    public function test_exact_mapping_does_not_fall_back_to_local_numeric_id(): void
    {
        $service = new OrganizationSummary;
        $this->assertSame(188, $service->mappedOrganization(87, 188, 323));
        $this->expectException(HttpException::class);
        $service->mappedOrganization(87, 6, 323);
    }

    public function test_sibling_organization_and_inactive_owner_are_denied(): void
    {
        $db = DB::connection('central');
        $db->table('user_organizations')->update(['status'=>'inactive']);
        $this->expectException(HttpException::class);
        (new OrganizationSummary)->mappedOrganization(87, 188, 323);
    }

    public function test_duplicate_mapping_or_wrong_client_is_not_accepted(): void
    {
        // The exact Central org belongs to client 87, never 88.
        $this->expectException(HttpException::class);
        (new OrganizationSummary)->mappedOrganization(88,188,323);
    }

    public function test_organization_counts_exclude_siblings_deleted_and_nonoperational_records(): void
    {
        $db = DB::connection('tenant');
        $db->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, organization_id INTEGER, is_active INTEGER, reorder_point DECIMAL, deleted_at TEXT)');
        $db->table('items')->insert([
            ['id'=>1,'organization_id'=>188,'is_active'=>1,'reorder_point'=>5,'deleted_at'=>null],
            ['id'=>2,'organization_id'=>188,'is_active'=>1,'reorder_point'=>5,'deleted_at'=>null],
            ['id'=>3,'organization_id'=>188,'is_active'=>1,'reorder_point'=>null,'deleted_at'=>null],
            ['id'=>4,'organization_id'=>189,'is_active'=>1,'reorder_point'=>5,'deleted_at'=>null],
            ['id'=>5,'organization_id'=>188,'is_active'=>1,'reorder_point'=>5,'deleted_at'=>'2026-01-01'],
        ]);
        $db->statement('CREATE TABLE warehouses (id INTEGER PRIMARY KEY, organization_id INTEGER, is_active INTEGER, deleted_at TEXT)');
        $db->table('warehouses')->insert([
            ['id'=>1,'organization_id'=>188,'is_active'=>1,'deleted_at'=>null],
            ['id'=>2,'organization_id'=>189,'is_active'=>1,'deleted_at'=>null],
            ['id'=>3,'organization_id'=>188,'is_active'=>1,'deleted_at'=>'2026-01-01'],
        ]);
        $db->statement('CREATE TABLE stock_balances (id INTEGER PRIMARY KEY, organization_id INTEGER, item_id INTEGER, warehouse_id INTEGER, on_hand_qty DECIMAL, reserved_qty DECIMAL)');
        // Item 1 is not low: its two bins total 8, despite each bin being below 5.
        $db->table('stock_balances')->insert([
            ['id'=>1,'organization_id'=>188,'item_id'=>1,'warehouse_id'=>1,'on_hand_qty'=>4,'reserved_qty'=>0],
            ['id'=>2,'organization_id'=>188,'item_id'=>1,'warehouse_id'=>1,'on_hand_qty'=>4,'reserved_qty'=>0],
            ['id'=>3,'organization_id'=>189,'item_id'=>2,'warehouse_id'=>1,'on_hand_qty'=>100,'reserved_qty'=>0],
        ]);
        $db->statement('CREATE TABLE warehouse_reorder_rules (organization_id INTEGER, item_id INTEGER, warehouse_id INTEGER, reorder_point DECIMAL, is_active INTEGER)');
        // Item 3 inherits this warehouse override and has zero stock.
        $db->table('warehouse_reorder_rules')->insert(['organization_id'=>188,'item_id'=>3,'warehouse_id'=>1,'reorder_point'=>3,'is_active'=>1]);
        $queries = [];
        DB::connection('tenant')->listen(function ($query) use (&$queries) { $queries[] = strtolower(ltrim($query->sql)); });
        $this->assertSame(['items'=>3,'warehouses'=>1,'low_stock_items'=>2], (new OrganizationSummary)->counts(188, ['grants'=>[]]));
        foreach ($queries as $sql) $this->assertFalse((bool) preg_match('/\\A(insert|update|delete|alter|create|drop)\\b/', $sql), $sql);
        $this->assertFalse((bool) array_filter($queries, fn ($sql) => str_contains($sql, 'billing') || str_contains($sql, 'quantity_counter')));
    }

    public function test_explicit_scoped_denial_hides_aggregate_counts(): void
    {
        $access = ['grants'=>[['permission_key'=>'*', 'effect'=>'deny', 'scope_type'=>'record', 'scope_id'=>123]]];
        $this->assertSame(['items'=>null,'warehouses'=>null,'low_stock_items'=>null], (new OrganizationSummary)->counts(188, $access));
    }
}
