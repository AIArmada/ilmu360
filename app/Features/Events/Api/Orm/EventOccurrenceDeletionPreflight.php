<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use AIArmada\Events\Models\EventOccurrence;

/**
 * Public contract for deletion preflight on the final vendor occurrence model.
 * The application registers the listener before the vendor model boots.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/FeatureServiceProvider.php, Events/Actions
 */
interface EventOccurrenceDeletionPreflight
{
    public function handle(EventOccurrence $occurrence): void;
}
