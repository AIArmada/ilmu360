<?php

declare(strict_types=1);

namespace App\Shared\Events;

use App\Models\Institution;
use App\Models\Person;

final class OrganizerResolver
{
    public static function kindForId(?string $id): ?string
    {
        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        if (Institution::query()->whereKey($id)->exists()) {
            return 'institution';
        }

        return Person::query()->whereKey($id)->exists() ? 'person' : null;
    }

    public static function involvementMatchesInstitution(
        ?string $involveableType,
        ?string $involveableId,
        string $institutionId,
    ): bool {
        return $involveableId === $institutionId
            && $involveableType === (new Institution)->getMorphClass();
    }
}
