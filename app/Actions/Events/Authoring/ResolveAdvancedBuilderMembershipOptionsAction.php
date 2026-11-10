<?php

declare(strict_types=1);

namespace App\Actions\Events\Authoring;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveAdvancedBuilderMembershipOptionsAction
{
    use AsAction;

    public function __construct(
        private readonly MembershipDirectory $memberships,
    ) {}

    /**
     * @return array{
     *     institution_options: array<string, string>,
     *     person_options: array<string, string>
     * }
     */
    public function handle(User $user): array
    {
        $accountId = (string) $user->getKey();
        $institutionIds = $this->memberships->subjectIdsFor($accountId, MembershipSubjectType::Institution);
        $personIds = $this->memberships->subjectIdsFor($accountId, MembershipSubjectType::Person);

        return [
            'institution_options' => $institutionIds === []
                ? []
                : Institution::query()
                    ->whereKey($institutionIds)
                    ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
                    ->orderBy('name')
                    ->with('names')
                    ->get(['institutions.id', 'institutions.name'])
                    ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
                    ->all(),
            'person_options' => $personIds === []
                ? []
                : Person::query()
                    ->whereKey($personIds)
                    ->whereIn('status', ['verified', 'pending'])
                    ->orderBy('name')
                    ->pluck('persons.name', 'persons.id')
                    ->all(),
        ];
    }
}
