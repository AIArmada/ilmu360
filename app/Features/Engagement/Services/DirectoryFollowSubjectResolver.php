<?php

declare(strict_types=1);

namespace App\Features\Engagement\Services;

use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\References\Api\ReferenceLookup;
use App\Shared\Directory\FollowableSubject;
use Illuminate\Support\Str;

/**
 * Resolves directory subjects with the existing card/index eligibility rules.
 *
 * Directory cards keep their speakers-only person narrowing; the follow API
 * deliberately does not use this resolver because the accepted ruling keeps
 * pending/verified persons followable there without a speaker-status filter.
 */
final readonly class DirectoryFollowSubjectResolver
{
    public function __construct(
        private InstitutionLookup $institutions,
        private PersonLookup $persons,
        private ReferenceLookup $references,
    ) {}

    public function resolve(string $type, string $id): ?FollowableSubject
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return match ($type) {
            'institution' => $this->institutions->findDirectoryFollowableSubject($id),
            'person' => $this->persons->findDirectoryFollowableSubject($id),
            'reference' => $this->references->findDirectoryFollowableSubject($id),
            default => null,
        };
    }
}
