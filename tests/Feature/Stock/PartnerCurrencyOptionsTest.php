<?php
namespace Tests\Feature\Stock;
use App\Services\Catalog\PartnerCurrencyOptions;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
use Tests\Traits\TenantAware;
class PartnerCurrencyOptionsTest extends TestCase {
 use TenantAware;
 protected function setUp():void {
  parent::setUp();$schema=Schema::connection('tenant');
  if(!$schema->hasColumn('currencies','symbol'))$schema->table('currencies',fn($t)=>$t->string('symbol')->nullable());
  if(!$schema->hasTable('organization_currencies'))$schema->create('organization_currencies',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('currency_id');$t->boolean('is_enabled')->default(false);});
  $this->useTenantA();
 }
 private function fixture():void {
  DB::connection('tenant')->table('organizations')->insert([['id'=>81001,'central_org_id'=>91001,'base_currency_id'=>81001],['id'=>81002,'central_org_id'=>91002,'base_currency_id'=>81002]]);
  DB::connection('tenant')->table('currencies')->insert([['id'=>81001,'code'=>'JOD','name'=>'Jordanian dinar','is_active'=>1],['id'=>81002,'code'=>'USD','name'=>'US dollar','is_active'=>1],['id'=>81003,'code'=>'EUR','name'=>'Euro','is_active'=>0]]);
  DB::connection('tenant')->table('organization_currencies')->insert([['organization_id'=>81001,'currency_id'=>81001,'is_enabled'=>1],['organization_id'=>81001,'currency_id'=>81002,'is_enabled'=>0],['organization_id'=>81001,'currency_id'=>81003,'is_enabled'=>1],['organization_id'=>81002,'currency_id'=>81002,'is_enabled'=>1]]);
 }
 public function test_only_active_enabled_currencies_of_the_matched_organization_are_returned():void {$this->fixture();$rows=app(PartnerCurrencyOptions::class)->forOrganization(91001);$this->assertSame(['JOD'],array_column($rows,'code'));$this->assertTrue($rows[0]['is_base']);$this->assertSame(['USD'],array_column(app(PartnerCurrencyOptions::class)->forOrganization(91002),'code'));}
 public function test_unmapped_organization_never_receives_another_organizations_currencies():void {$this->fixture();$this->assertSame([],app(PartnerCurrencyOptions::class)->forOrganization(99999));}
 public function test_finance_currency_changes_are_reflected_without_copying_or_seeding_records():void {$this->fixture();$service=app(PartnerCurrencyOptions::class);$this->assertCount(1,$service->forOrganization(91001));DB::connection('tenant')->table('organization_currencies')->where('organization_id',81001)->where('currency_id',81002)->update(['is_enabled'=>true]);$before=DB::connection('tenant')->table('organization_currencies')->orderBy('id')->get()->toJson();$this->assertSame(['JOD','USD'],array_column($service->forOrganization(91001),'code'));$this->assertSame($before,DB::connection('tenant')->table('organization_currencies')->orderBy('id')->get()->toJson());}
}
