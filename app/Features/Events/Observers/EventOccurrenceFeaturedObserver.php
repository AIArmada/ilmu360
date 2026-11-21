<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use App\Features\Events\Actions\Projection\GuardFeaturedOccurrenceUpdateAction;
use AIArmada\Events\Models\EventOccurrence;


/** Framework observer adapter; the feature Action owns the behavior. */
final readonly class EventOccurrenceFeaturedObserver
{
    public function __construct(private GuardFeaturedOccurrenceUpdateAction $effects) {}

    public function saving(EventOccurrence $occurrence): void
    {
        $this->effects->saving($occurrence);
    }
}
