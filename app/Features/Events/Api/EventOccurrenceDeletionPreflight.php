<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use AIArmada\Events\Models\EventOccurrence;

/**
 * Public contract for deletion preflight on the final vendor occurrence model.
 * The application registers the listener before the vendor model boots.
 */
interface EventOccurrenceDeletionPreflight
{
    public function handle(EventOccurrence $occurrence): void;
}
