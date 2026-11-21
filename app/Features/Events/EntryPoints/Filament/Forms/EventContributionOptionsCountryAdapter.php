<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Forms;

use App\Features\Events\Api\Orm\Filament\EventContributionOptionsCountry;
use App\Features\Events\Models\Event;
use App\Features\Events\Services\EventContributionUpdateStateMapper;

final readonly class EventContributionOptionsCountryAdapter implements EventContributionOptionsCountry
{
    public function forFormState(array $formState, ?Event $event = null): string
    {
        return EventContributionUpdateStateMapper::effectiveOptionsCountry(
            $formState,
            $event instanceof Event ? $event : null,
        );
    }
}
