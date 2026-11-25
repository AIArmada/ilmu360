<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use App\Features\Events\Actions\Projection\GuardFeaturedOccurrenceUpdateAction;

/** Framework observer adapter; the feature Action owns the behavior. */
final readonly class EventOccurrenceFeaturedObserver
{
    public function __construct(private GuardFeaturedOccurrenceUpdateAction $effects) {}

    public function saving(EventOccurrence $occurrence): void
    {
        $this->effects->saving($occurrence);
    }
}
