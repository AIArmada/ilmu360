<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support;

use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;

/**
 * Maps a contribution subject type to its Eloquent model class.
 *
 * Kept out of the public enum so the Api value stays free of feature models.
 */
final class ContributionSubjectModels
{
    /**
     * @return class-string<Event|Institution|Person|Reference>
     */
    public static function modelClass(ContributionSubjectType $subjectType): string
    {
        return match ($subjectType) {
            ContributionSubjectType::Event => Event::class,
            ContributionSubjectType::Institution => Institution::class,
            ContributionSubjectType::Person => Person::class,
            ContributionSubjectType::Reference => Reference::class,
        };
    }
}
