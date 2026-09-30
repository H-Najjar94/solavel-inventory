<?php

namespace App\Observers;

use App\Models\Tenant\Item;
use App\Services\Catalog\SolaBooksItemCatalogBridge;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ItemCatalogObserver
{
    public function saved(Item $item): void
    {
        if (app(\App\Services\InventoryWorkspace\MigrationCatalogScope::class)->active()) return;
        $this->sync($item, $item->wasRecentlyCreated ? null : (string) $item->getOriginal('sku'));
    }

    public function deleted(Item $item): void
    {
        $this->sync($item, (string) $item->getOriginal('sku'));
    }

    public function restored(Item $item): void
    {
        $this->sync($item, (string) $item->getOriginal('sku'));
    }

    /**
     * The item is already saved when this runs (no surrounding transaction). A
     * category/unit that has no SolaCount mapping yet is not a failure of the
     * save: throwing here returned HTTP 500 for a committed item, and a retry
     * then hit the duplicate-SKU rule (FATIMA-NEW-014). Record it and let the
     * catalog sync pick the item up once its references are mapped. Any other
     * sync failure still propagates.
     */
    private function sync(Item $item, ?string $previousSku): void
    {
        try {
            app(SolaBooksItemCatalogBridge::class)->sync($item, $previousSku);
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'catalog_reference_mapping_required') {
                throw $e;
            }
            Log::warning('stock_item_catalog_sync_pending_mapping', [
                'organization_id' => $item->organization_id,
                'item_id' => $item->id,
                'category_id' => $item->category_id,
                'base_unit_id' => $item->base_unit_id,
            ]);
        }
    }
}
