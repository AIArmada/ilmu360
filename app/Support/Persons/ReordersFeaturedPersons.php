<?php

namespace App\Support\Persons;

use App\Features\Directory\Api\PersonWorkflow;

trait ReordersFeaturedPersons
{
    /** @param list<string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        app(PersonWorkflow::class)->reorderFeatured($order);
        $this->flushCachedTableRecords();
    }
}
