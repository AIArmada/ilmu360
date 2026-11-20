<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Api\Orm\AdvancedBuilderMembershipOptions;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MemberSubjectType;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Authoring options are constrained by the actor's actual memberships and
 * the current visibility of their institution/person records.
 */
final readonly class ResolveAdvancedBuilderMembershipOptionsAction implements AdvancedBuilderMembershipOptions
{
    public function __construct(private MembershipDirectory $memberships) {}

    public function forAccount(string $accountId): array
    {
        $institutionIds = $this->memberships->subjectIdsFor($accountId, MemberSubjectType::Institution);
        $personIds = $this->memberships->subjectIdsFor($accountId, MemberSubjectType::Person);

        return [
            'institution_options' => $institutionIds === []
                ? []
                : MorphModelResolver::resolve('institution')::query()
                    ->whereKey($institutionIds)
                    ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
                    ->orderBy('name')
                    ->with('names')
                    ->get(['institutions.id', 'institutions.name'])
                    ->mapWithKeys(static fn (Model $institution): array => [
                        (string) $institution->getKey() => (string) $institution->getAttribute('display_name'),
                    ])
                    ->all(),
            'person_options' => $personIds === []
                ? []
                : MorphModelResolver::resolve('person')::query()
                    ->whereKey($personIds)
                    ->whereIn('status', ['verified', 'pending'])
                    ->orderBy('name')
                    ->pluck('persons.name', 'persons.id')
                    ->all(),
        ];
    }
}
