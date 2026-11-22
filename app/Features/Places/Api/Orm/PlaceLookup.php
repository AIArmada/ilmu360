<?php

declare(strict_types=1);

namespace App\Features\Places\Api\Orm;

/**
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Places/FeatureServiceProvider.php, Discovery/PublicScheduleDiscoveryService, Events/EntryPoints/Livewire/Pages/Show
 */
interface PlaceLookup
{
    /**
     * Attach fully loaded application venue models to already-loaded event
     * location objects using the card relation set.
     *
     * @param  iterable<object>  $locations
     */
    public function hydrateCardVenues(iterable $locations): void;

    /**
     * Attach fully loaded application venue models to already-loaded event
     * location objects using the detail-page relation set.
     *
     * @param  iterable<object>  $locations
     */
    public function hydrateDetailVenues(iterable $locations): void;
}
