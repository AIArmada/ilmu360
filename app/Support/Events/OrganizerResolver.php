<?php

declare(strict_types=1);

namespace App\Support\Events;

use AIArmada\Events\Models\EventInvolvement;
use App\Models\Institution;
use App\Models\Person;

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
     * Organizer rows are written by Event::setPrimaryOrganizer with the
     * organizer's class name; that storage form is pinned by
     * EventOrganizerInvolvementSyncTest, so comparisons match it exactly.
     */
    public static function involvementMatchesInstitution(?EventInvolvement $involvement, Institution|string $institution): bool
    {
        if (! $involvement instanceof EventInvolvement) {
            return false;
        }

        $institutionId = $institution instanceof Institution ? (string) $institution->getKey() : $institution;

        return (string) $involvement->involveable_id === $institutionId
            && (string) $involvement->involveable_type === Institution::class;
    }
}
