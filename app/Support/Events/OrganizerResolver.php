<?php

declare(strict_types=1);

namespace App\Support\Events;

use AIArmada\Events\Models\EventInvolvement;
use App\Models\Institution;
use App\Models\Person;
use App\Shared\Support\MorphAlias;

final class OrganizerResolver
{
    public static function find(?string $id): Institution|Person|null
    {
        if ($id === null) {
            return null;
        }

        return Institution::query()->find($id)
            ?? Person::query()->find($id);
    }

    /**
     * Organizer rows store the configured morph alias. Legacy rows may still
     * hold the fully qualified class name, so normalize before comparing.
     */
    public static function involvementMatchesInstitution(?EventInvolvement $involvement, Institution|string $institution): bool
    {
        if (! $involvement instanceof EventInvolvement) {
            return false;
        }

        $institutionId = $institution instanceof Institution ? (string) $institution->getKey() : $institution;

        return (string) $involvement->involveable_id === $institutionId
            && MorphAlias::of($involvement->involveable_type) === (new Institution)->getMorphClass();
    }
}
