<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\Integration\ConnectionManagementPolicy;
use App\Services\Integration\DefaultStockConnection;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReconcileDefaultStockConnections extends Command
{
    protected $signature = 'inventory:reconcile-default-connections {--organization=* : Central organization IDs} {--apply : Apply only explicitly selected eligible organizations}';
    protected $description = 'Report or safely initialize pristine default-chart Finance/Stock connections; never posts financial activity.';

    public function handle(TenantManager $tenants, DefaultStockConnection $connections): int
    {
        $ids=array_map('intval',(array)$this->option('organization'));
        if ($this->option('apply') && ($ids === [] || min($ids)<=0)) {
            $this->error('Apply requires explicit organization IDs from the dry-run report.');return self::FAILURE;
        }
        $central=DB::connection('mysql');
        $query=$central->table('organizations as o')->join('clients as c','c.id','=','o.client_id')
            ->where('o.is_active',true)->whereNull('o.deleted_at')->where('c.is_active',true)->whereNull('c.deleted_at');
        if($ids) $query->whereIn('o.id',$ids);
        $report=[];
        foreach($query->orderBy('o.id')->get(['o.id','o.client_id']) as $org){
            $row=['organization_id'=>(int)$org->id,'client_id'=>(int)$org->client_id];
            try {
                $apps=$central->table('organization_projects as op')->join('projects as p','p.id','=','op.project_id')
                    ->where('op.organization_id',$org->id)->where('op.is_active',true)->where('p.is_active',true)
                    ->whereIn('p.slug',['finance','inventory'])->distinct()->pluck('p.slug');
                if($apps->count()!==2){$report[]=$row+['status'=>'not_entitled'];continue;}
                $tenants->useTenant((int)$org->id,$tenants->resolveDatabaseName((int)$org->client_id));
                if(!Schema::connection('tenant')->hasTable('integration_settings')){$report[]=$row+['status'=>'schema_not_ready'];continue;}
                $financeId=(int)DB::connection('tenant')->table('organizations')->where('central_org_id',$org->id)->value('id');
                $actorId=$central->table('user_organizations')->where('organization_id',$org->id)->whereIn('role',['client_owner','owner'])
                    ->where(fn($q)=>$q->whereNull('status')->orWhere('status','active'))->orderBy('user_id')->value('user_id');
                $actor=$actorId?User::find($actorId):null;
                $policy=app(ConnectionManagementPolicy::class)->status((int)$org->id,$actor);
                if(!($policy['can_manage_connection']??false)||($policy['separation_of_duties']??false)){
                    $report[]=$row+['status'=>'manual_review','reason'=>'owner_or_separate_review_required'];continue;
                }
                $report[]=$row+$connections->initialize((int)$org->client_id,(int)$org->id,$financeId,(int)$actorId,(bool)$this->option('apply'));
            }catch(\Throwable $e){
                // Only application reason codes are emitted; database exception messages can contain credentials/SQL.
                $reason=$e instanceof \RuntimeException && preg_match('/^[a-z_]+(?::[a-z_]+)?$/D',$e->getMessage())?$e->getMessage():get_class($e);
                $report[]=$row+['status'=>'manual_review','reason'=>$reason];
            }
        }
        $this->line(json_encode(['apply'=>(bool)$this->option('apply'),'generated_at'=>now()->toIso8601String(),'organizations'=>$report],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
