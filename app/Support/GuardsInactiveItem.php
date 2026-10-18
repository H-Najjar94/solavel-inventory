<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Applied to every stock document line model and to Reservation. Whatever the entry point (UI/API
 * controller, Finance workspace command, CSV import, handoff or background processing), a new row, or an
 * existing row re-pointed to another item, is written in one transaction together with the item row lock
 * and the inactive-item check (InactiveItemPolicy::guardDocumentLine).
 */
trait GuardsInactiveItem
{
    protected function performInsert(Builder $query)
    {
        return $this->getConnection()->transaction(function () use ($query) {
            InactiveItemPolicy::guardDocumentLine($this);

            return parent::performInsert($query);
        });
    }

    protected function performUpdate(Builder $query)
    {
        if (! $this->isDirty('item_id')) {
            return parent::performUpdate($query);
        }

        return $this->getConnection()->transaction(function () use ($query) {
            InactiveItemPolicy::guardDocumentLine($this);

            return parent::performUpdate($query);
        });
    }
}
