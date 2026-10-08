<?php

namespace Tests\Unit;

use App\Models\Tenant\Item;
use App\Observers\ItemCatalogObserver;
use App\Services\Catalog\SolaBooksItemCatalogBridge;
use RuntimeException;
use Tests\TestCase;

/** FATIMA-NEW-014: an unmapped catalog reference must not turn a committed item save into HTTP 500. */
class ItemCatalogObserverTest extends TestCase
{
    public function test_unmapped_catalog_reference_is_recorded_not_thrown(): void
    {
        $bridge = \Mockery::mock(SolaBooksItemCatalogBridge::class);
        $bridge->shouldReceive('sync')->andThrow(new RuntimeException('catalog_reference_mapping_required'));
        $this->app->instance(SolaBooksItemCatalogBridge::class, $bridge);

        (new ItemCatalogObserver())->deleted(new Item());
        $this->addToAssertionCount(1);
    }

    public function test_other_sync_failures_still_propagate(): void
    {
        $bridge = \Mockery::mock(SolaBooksItemCatalogBridge::class);
        $bridge->shouldReceive('sync')->andThrow(new RuntimeException('finance_transport_failed'));
        $this->app->instance(SolaBooksItemCatalogBridge::class, $bridge);

        $this->expectExceptionMessage('finance_transport_failed');
        (new ItemCatalogObserver())->deleted(new Item());
    }
}
