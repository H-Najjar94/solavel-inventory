<?php
namespace App\Services\Catalog;
use Illuminate\Support\Facades\{DB,Schema};
/** Read the same organization-enabled currency catalog used by SolaCount. No provisioning or FX writes. */
final class PartnerCurrencyOptions
{
 public function forOrganization(int $centralOrganizationId): array
 {
  $schema=Schema::connection('tenant');
  foreach(['organizations','currencies','organization_currencies'] as $table)if(!$schema->hasTable($table))return [];
  if(!$schema->hasColumn('organizations','central_org_id'))return [];
  $organization=DB::connection('tenant')->table('organizations')->where('central_org_id',$centralOrganizationId)->first(['id','base_currency_id']);
  if(!$organization)return [];
  return DB::connection('tenant')->table('currencies as c')->join('organization_currencies as oc','oc.currency_id','=','c.id')
   ->where('oc.organization_id',$organization->id)->where('oc.is_enabled',true)->where('c.is_active',true)->orderBy('c.code')
   ->get(['c.code','c.name','c.symbol','c.id'])->filter(fn($row)=>preg_match('/^[A-Z]{3}$/D',$row->code))
   ->map(fn($row)=>['code'=>$row->code,'name'=>$row->name,'symbol'=>$row->symbol,'is_base'=>(int)$row->id===(int)$organization->base_currency_id])->values()->all();
 }
}
