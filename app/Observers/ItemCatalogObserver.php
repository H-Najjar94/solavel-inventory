<?php
namespace App\Observers;
use App\Models\Tenant\Item;
use App\Services\Catalog\DurableCatalogSync;
final class ItemCatalogObserver {
 public function saved(Item $item):void {$this->record($item);}
 public function deleted(Item $item):void {$this->record($item);}
 public function restored(Item $item):void {$this->record($item);}
 private function record(Item $item):void {
  if(app(\App\Services\InventoryWorkspace\MigrationCatalogScope::class)->active())return;
  app(DurableCatalogSync::class)->record('item',(int)$item->id,(int)$item->organization_id);
 }
}
