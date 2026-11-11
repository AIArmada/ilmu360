<?php

declare(strict_types=1);

namespace App\Features\Registration\Queries;

use App\Features\Registration\Api\AdmissionFacts;
use App\Platform\Models\MorphModelResolver;

/**
 * Interim admission facts while the models remain at their root namespace.
 *
 * Resolution goes through the stable morph alias so the feature never imports
 * the root model class. The query keeps the model's owner scope, matching the
 * previous `$event->registrations()->exists()` behavior exactly.
 */
final readonly class DatabaseAdmissionFacts implements AdmissionFacts
{
    public function hasRegistrations(string $eventId): bool
    {
        $registration = MorphModelResolver::resolve('registration');

        return $registration::query()
            ->where('event_id', $eventId)
            ->exists();
    }
}
