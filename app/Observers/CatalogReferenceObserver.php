<?php
namespace App\Observers;
use App\Models\Tenant\{Unit,ItemCategory,UnitConversion};
use App\Services\Catalog\DurableCatalogSync;
use Illuminate\Database\Eloquent\Model;
final class CatalogReferenceObserver {
 public function saved(Model $model):void{$this->record($model);}
 public function deleted(Model $model):void{$this->record($model);}
 public function restored(Model $model):void{$this->record($model);}
 private function record(Model $model):void {
  if(app(\App\Services\InventoryWorkspace\MigrationCatalogScope::class)->active())return;
  $sync=app(DurableCatalogSync::class);$org=(int)$model->organization_id;
  if($model instanceof UnitConversion){$sync->record('item',(int)$model->item_id,$org);return;}
  $sync->record($model instanceof Unit?'unit':'category',(int)$model->id,$org);
 }
}
