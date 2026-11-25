<?php

declare(strict_types=1);

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Models\AddressAreaRelationship;
use App\Shared\Cache\SelectionCatalog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class AddressAreaRelationshipObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SelectionCatalog $selectionCatalog) {}

    public function saved(AddressAreaRelationship $relationship): void
    {
        if ($relationship->wasRecentlyCreated || $relationship->wasChanged()) {
            $this->selectionCatalog->bustAddress();
        }
    }

    public function deleted(AddressAreaRelationship $relationship): void
    {
        $this->selectionCatalog->bustAddress();
    }
}
