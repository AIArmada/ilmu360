<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;

final class OrganizerResolver
{
    public static function kindForId(?string $id): ?string
    {
        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        if (app(InstitutionLookup::class)->exists($id)) {
            return 'institution';
        }

        return app(PersonLookup::class)->exists($id) ? 'person' : null;
    }

    public static function involvementMatchesInstitution(
        ?string $involveableType,
        ?string $involveableId,
        string $institutionId,
    ): bool {
        return $involveableId === $institutionId
            && $involveableType === app(InstitutionLookup::class)->institutionMorphType();
    }
}
