<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm\Filament;

/**
 * Effective prayer-options country for the contribution event form.
 *
 * The owning feature resolves the same organizer/location normalization used
 * by contribution persistence; consumers only supply raw form state.
 *
 * @internal Explicit ORM integration port for Filament form/relation state.
 *
 * Consumers: owning Filament resources and relation managers.
 */
interface EventContributionOptionsCountry
{
    /**
     * @param  array<string, mixed>  $formState
     */
    public function forFormState(array $formState, ?object $event = null): string;
}
