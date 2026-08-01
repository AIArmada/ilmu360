<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Addressing\Models\AddressAreaRelationship;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class AddressAreaRelationshipObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SelectionCatalogCache $selectionCatalogCache) {}

    public function saved(AddressAreaRelationship $relationship): void
    {
        if ($relationship->wasRecentlyCreated || $relationship->wasChanged()) {
            $this->selectionCatalogCache->bustAddress();
        }
    }

    public function deleted(AddressAreaRelationship $relationship): void
    {
        $this->selectionCatalogCache->bustAddress();
    }
}
