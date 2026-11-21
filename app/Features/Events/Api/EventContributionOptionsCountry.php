<?php

declare(strict_types=1);

namespace App\Features\Events\Api;


/**
 * Effective prayer-options country for the contribution event form.
 *
 * The owning feature resolves the same organizer/location normalization used
 * by contribution persistence; consumers only supply raw form state.
 *
 */
interface EventContributionOptionsCountry
{
    /**
     * @param  array<string, mixed>  $formState
     */
    public function forFormState(array $formState, ?string $eventId = null): string;
}
