<?php

declare(strict_types=1);

namespace App\Features\Engagement\Services;

use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use Illuminate\Support\Str;

/**
 * Resolves directory subjects with the existing card/index eligibility rules.
 *
 * Directory cards keep their speakers-only person narrowing; the follow API
 * deliberately does not use this resolver because the accepted ruling keeps
 * pending/verified persons followable there without a speaker-status filter.
 */
final class DirectoryFollowSubjectResolver
{
    public function resolve(string $type, string $id): Institution|Person|Reference|null
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return match ($type) {
            'institution' => Institution::query()
                ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
                ->whereKey($id)
                ->first(),
            'person' => Person::query()
                ->speakers()
                ->whereIn('persons.status', ['verified', 'pending'])
                ->whereKey($id)
                ->first(),
            'reference' => Reference::query()
                ->active()
                ->whereKey($id)
                ->first(),
            default => null,
        };
    }
}
