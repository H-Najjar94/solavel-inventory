<?php

namespace App\Observers;

use App\Models\Tenant\Item;
use App\Services\Catalog\SolaBooksItemCatalogBridge;

class ItemCatalogObserver
{
    public function saved(Item $item): void
    {
        app(SolaBooksItemCatalogBridge::class)->sync(
            $item,
            $item->wasRecentlyCreated ? null : (string) $item->getOriginal('sku')
        );
    }

    public function deleted(Item $item): void
    {
        app(SolaBooksItemCatalogBridge::class)->sync($item, (string) $item->getOriginal('sku'));
    }

    public function restored(Item $item): void
    {
        app(SolaBooksItemCatalogBridge::class)->sync($item, (string) $item->getOriginal('sku'));
    }
}
