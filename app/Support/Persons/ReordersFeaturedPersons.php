<?php

namespace App\Support\Persons;

use App\Actions\ReorderFeaturedPersonsAction;

trait ReordersFeaturedPersons
{
    /** @param list<string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        app(ReorderFeaturedPersonsAction::class)->handle($order);
        $this->flushCachedTableRecords();
    }
}
