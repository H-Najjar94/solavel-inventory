<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Catalog\{CatalogSourceAuthority,DurableCatalogSync};
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema};
final class CatalogSyncController extends ApiController {
 private function mapping():object {
  $org=app(OrganizationContext::class)->idOrFail();
  abort_unless(Schema::connection('tenant')->hasTable(DurableCatalogSync::TABLE),409,'catalog_schema_not_ready');
  $maps=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())->where('status','verified')->get();
  abort_unless($maps->count()===1,409,'catalog_mapping_invalid');return$maps->first();
 }
 public function index(Request $request){return$this->success(app(CatalogSourceAuthority::class)->dispatch('catalog.status',[],$this->mapping(),$request->user()));}
 public function retry(Request $request,string $source){
  $map=$this->mapping();abort_unless($map->activation_state==='active',409,'catalog_connection_paused');
  $row=DB::connection('tenant')->table(DurableCatalogSync::TABLE)->where('organization_mapping_uuid',$map->mapping_uuid)->where('organization_id',$map->solastock_organization_id)->where('source_uuid',$source)->first();abort_unless($row,404);
  return$this->success(app(CatalogSourceAuthority::class)->dispatch('catalog.reconcile',['entity_type'=>$row->entity_type,'source_id'=>$row->source_id],$map,$request->user()));
 }
}
