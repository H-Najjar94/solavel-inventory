<?php
namespace Tests\Feature\Integration;
use App\Services\Integration\ApprovedTransportTargetRegistry;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class TransportAuthoritativeEligibilityTest extends TestCase
{
    public function test_stale_snapshots_cannot_authorize_inactive_clients_organizations_or_revoked_apps(): void
    {
        try {
            $d=DB::connection('mysql');
            foreach([
                'CREATE TEMPORARY TABLE clients (id INTEGER PRIMARY KEY, is_active INTEGER)',
                'CREATE TEMPORARY TABLE organizations (id INTEGER PRIMARY KEY, client_id INTEGER, is_active INTEGER)',
                'CREATE TEMPORARY TABLE projects (id INTEGER PRIMARY KEY, slug TEXT)',
                'CREATE TEMPORARY TABLE organization_projects (organization_id INTEGER, project_id INTEGER, is_active INTEGER)',
                'CREATE TEMPORARY TABLE entitlement_state_snapshots (organization_id INTEGER, state_payload TEXT)',
            ] as $sql) $d->statement($sql);
            $d->table('clients')->insert(['id'=>42,'is_active'=>1]);
            $d->table('organizations')->insert(['id'=>77,'client_id'=>42,'is_active'=>1]);
            $d->table('projects')->insert([['id'=>1,'slug'=>'finance'],['id'=>2,'slug'=>'inventory']]);
            $d->table('organization_projects')->insert([['organization_id'=>77,'project_id'=>1,'is_active'=>1],['organization_id'=>77,'project_id'=>2,'is_active'=>1]]);
            $state=['client_id'=>42,'organization_id'=>77,'plan_code'=>'advanced','accessible_apps'=>['finance','inventory'],'applications'=>['finance'=>['accessible'=>true,'commercially_entitled'=>true],'inventory'=>['accessible'=>true,'commercially_entitled'=>true]],'integration_capabilities'=>['connection_activation_delivery_entitled'=>true]];
            $d->table('entitlement_state_snapshots')->insert(['organization_id'=>77,'state_payload'=>json_encode($state)]);
            $tenants=$this->createStub(TenantManager::class);
            $tenants->method('resolveDatabaseName')->willReturn('tenant_000042');
            $registry=new ApprovedTransportTargetRegistry($tenants);
            $this->assertCount(1,$registry->targets());
            foreach(['clients','organizations'] as $table){$d->table($table)->update(['is_active'=>0]);$this->assertSame([],$registry->targets());$d->table($table)->update(['is_active'=>1]);}
            foreach([1,2] as $app){$d->table('organization_projects')->where('project_id',$app)->update(['is_active'=>0]);$this->assertSame([],$registry->targets());$d->table('organization_projects')->where('project_id',$app)->update(['is_active'=>1]);}
            $d->table('organizations')->update(['client_id'=>43]);$d->table('clients')->insert(['id'=>43,'is_active'=>1]);$this->assertSame([],$registry->targets());
            $d->table('organizations')->update(['client_id'=>42]);$this->assertCount(1,$registry->targets());
        } finally {
            foreach (['entitlement_state_snapshots','organization_projects','projects','organizations','clients'] as $table) {
                DB::connection('mysql')->statement('DROP TEMPORARY TABLE IF EXISTS '.$table);
            }
        }
    }
}
