<?php

namespace App\Support\Api\Frontend;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Events\ResolveAdvancedBuilderContextAction;
use App\Contracts\SpaceEligibilityResolver;
use App\Enums\MemberSubjectType;
use App\Forms\ReferenceAuthorFormSchema;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Support\Location\LocationSlugResolver;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Submission\EntitySubmissionAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FrontendCatalogService
{
    public function __construct(
        private readonly InstitutionSearchService $institutionSearchService,
        private readonly PersonSearchService $personSearchService,
        private readonly SpaceEligibilityResolver $spaceEligibilityResolver,
    ) {}

    /**
     * @return list<array{id: string, label: string, iso2: string, key: ?string}>
     */
    public function countries(): array
    {
        return AddressCountry::query()
            ->orderBy('name')
            ->get(['id', 'name', 'iso2'])
            ->map(fn (AddressCountry $country): array => [
                'id' => (string) $country->id,
                'label' => (string) $country->name,
                'iso2' => strtoupper((string) $country->iso2),
                'key' => Str::slug((string) $country->name),
            ])
            ->all();
    }

    /**
     * Package addressing `states` table (addresses.state_id FK). Distinct from AddressArea hierarchy.
     *
     * @return list<array{id: string, label: string, code: string|null}>
     */
    public function states(?string $countryId): array
    {
        if (! is_string($countryId) || $countryId === '') {
            return [];
        }

        if (app(LocationSlugResolver::class)->stateMaps($countryId)['options'] === []) {
            return [];
        }

        return State::query()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (State $state): array => [
                'id' => (string) $state->id,
                'label' => (string) $state->name,
                'code' => $state->code !== null ? (string) $state->code : null,
            ])
            ->all();
    }

    /**
     * Package addressing `cities` table (addresses.city_id FK).
     *
     * @return list<array{id: string, label: string}>
     */
    public function cities(?string $stateId, ?string $countryId = null): array
    {
        $query = City::query()->orderBy('name');

        if (is_string($stateId) && $stateId !== '') {
            $query->where('state_id', $stateId);
        } elseif (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', $countryId);
        } else {
            return [];
        }

        return $query
            ->get(['id', 'name'])
            ->map(fn (City $city): array => [
                'id' => (string) $city->id,
                'label' => (string) $city->name,
            ])
            ->all();
    }

    /**
     * First cascade-slot area options for the country (administrative level-1).
     *
     * The underlying role follows the country's provider profile; the
     * endpoint name stays a stable slot identifier for API clients.
     *
     * @return list<array{id: string, label: string, type: string, level: int|null}>
     */
    public function administrativeDistricts(?string $countryId, ?string $stateId = null): array
    {
        if (($countryId === null || $countryId === '') && is_string($stateId) && $stateId !== '') {
            $countryId = $this->countryIdForState($stateId);
        }

        if (! is_string($countryId) || $countryId === '') {
            return [];
        }

        $role = app(LocationSlugResolver::class)->districtRoleForCountry($countryId);

        if ($role === null) {
            return [];
        }

        return $this->roleAreaOptions($countryId, $role, $stateId);
    }

    /**
     * Second cascade-slot area options under the selected parent (administrative level-2).
     *
     * The underlying role follows the country's provider profile; the
     * endpoint name stays a stable slot identifier for API clients.
     *
     * @return list<array{id: string, label: string, type: string, level: int|null}>
     */
    public function administrativeSubdivisions(?string $districtId, ?string $countryId = null, ?string $stateId = null): array
    {
        $hasDistrict = is_string($districtId) && $districtId !== '';
        $hasState = is_string($stateId) && $stateId !== '';

        if ($countryId === null || $countryId === '') {
            $countryId = $hasDistrict
                ? $this->countryIdForArea($districtId)
                : ($hasState ? $this->countryIdForState($stateId) : null);
        }

        if (! is_string($countryId) || $countryId === '') {
            return [];
        }

        $role = app(LocationSlugResolver::class)->subdivisionRoleForCountry($countryId);

        if ($role === null) {
            return [];
        }

        if ($hasDistrict) {
            return $this->roleAreaOptions($countryId, $role, $districtId);
        }

        if ($hasState) {
            return $this->roleAreaOptions($countryId, $role, $stateId);
        }

        return $this->roleAreaOptions($countryId, $role);
    }

    /**
     * @return list<array{id: string, label: string, type: string, level: int|null}>
     */
    public function addressAreas(?string $countryId = null, ?string $parentId = null, ?int $level = null): array
    {
        $query = AddressArea::query();

        if (is_string($parentId) && $parentId !== '') {
            $query->where('parent_id', $parentId);
        }

        if (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', $countryId);
        } elseif (! is_string($parentId) || $parentId === '') {
            return [];
        }

        if ($level !== null) {
            $query->where('level', $level);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'level'])
            ->map(fn (AddressArea $area): array => [
                'id' => (string) $area->id,
                'label' => (string) $area->name,
                'type' => (string) $area->type,
                'level' => $area->level,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, label: string, type: string, level: int|null}>
     */
    private function roleAreaOptions(?string $countryId, string $role, ?string $parentId = null): array
    {
        $options = SharedFormSchema::areaOptionsForRole($countryId, $role, $parentId);

        if ($options === []) {
            return [];
        }

        return AddressArea::query()
            ->whereIn('id', array_keys($options))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'level'])
            ->map(fn (AddressArea $area): array => [
                'id' => (string) $area->id,
                'label' => (string) $area->name,
                'type' => (string) $area->type,
                'level' => $area->level !== null ? (int) $area->level : null,
            ])
            ->all();
    }

    private function countryIdForState(string $stateId): ?string
    {
        $countryId = State::query()->whereKey($stateId)->value('country_id');

        return is_string($countryId) ? $countryId : null;
    }

    private function countryIdForArea(string $areaId): ?string
    {
        $countryId = AddressArea::query()->whereKey($areaId)->value('country_id');

        if (is_string($countryId) && $countryId !== '') {
            return $countryId;
        }

        return $this->countryIdForState($areaId);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function languages(): array
    {
        /** @var Collection<int, Language> $languages */
        $languages = Language::query()->orderBy('name')->get(['id', 'name']);

        return $languages
            ->map(fn (Language $language): array => [
                'id' => (string) $language->id,
                'label' => (string) $language->name,
            ])
            ->all();
    }

    /**
     * Package EventTerm options for a taxonomy code (domain|discipline|source|issue).
     *
     * @return list<array{id: string, label: string, code: string}>
     */
    public function taxonomyTerms(string $taxonomyCode, ?string $search = null, int $limit = 50): array
    {
        $taxonomy = EventTaxonomy::query()
            ->where('code', $taxonomyCode)
            ->where('is_active', true)
            ->first();

        if ($taxonomy === null) {
            return [];
        }

        $query = EventTerm::query()
            ->where('event_taxonomy_id', $taxonomy->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->whereLike('name', '%'.$normalizedSearch.'%');
        }

        return $query
            ->limit($limit)
            ->get(['id', 'name', 'code'])
            ->map(fn (EventTerm $term): array => [
                'id' => (string) $term->id,
                'label' => (string) $term->name,
                'code' => (string) $term->code,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function references(?string $search = null, int $limit = 50): array
    {
        $query = Reference::query()->active()->with('parentReference.parentReference')->orderBy('title');
        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->where(function (Builder $referenceQuery) use ($normalizedSearch): void {
                $referenceQuery
                    ->whereLike('title', '%'.$normalizedSearch.'%')
                    ->orWherePartTextLike('%'.$normalizedSearch.'%')
                    ->orWhereLike('edition_label', '%'.$normalizedSearch.'%')
                    ->orWhereLike('publisher', '%'.$normalizedSearch.'%')
                    ->orWhereLike('isbn', '%'.$normalizedSearch.'%');
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'title', 'parent_id', 'record_kind', 'reference_parts', 'metadata', 'edition_number', 'edition_label', 'publisher', 'year'])
            ->map(fn (Reference $reference): array => [
                'id' => (string) $reference->id,
                'label' => $reference->displayTitle(),
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function submitInstitutions(?User $user, ?string $search = null, int $limit = 50): array
    {
        $query = app(EntitySubmissionAccess::class)
            ->institutionQueryForSubmitter($user)
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $this->applyInstitutionSearch($query, $normalizedSearch);
        }

        return $query
            ->limit($limit)
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->map(fn (Institution $institution): array => [
                'id' => (string) $institution->id,
                'label' => $institution->display_name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function submitPersons(?User $user, ?string $search = null, int $limit = 50): array
    {
        $query = app(EntitySubmissionAccess::class)
            ->personQueryForSubmitter($user)
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $this->applyPersonSearch($query, $normalizedSearch);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (Person $person): array => [
                'id' => (string) $person->id,
                'label' => $person->formatted_name,
            ])
            ->all();
    }

    /**
     * Pending/verified persons selectable as reference authors (no speaker-only scope).
     *
     * @return list<array{id: string, label: string}>
     */
    public function referenceAuthors(?string $search = null, int $limit = 50): array
    {
        $options = ReferenceAuthorFormSchema::searchOptions(trim((string) $search), $limit);
        $items = [];

        foreach ($options as $id => $label) {
            $items[] = ['id' => $id, 'label' => $label];
        }

        return $items;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function venues(?string $search = null, int $limit = 50): array
    {
        $query = Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->whereLike('name', '%'.$normalizedSearch.'%');
        }

        return $query
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Venue $venue): array => [
                'id' => (string) $venue->id,
                'label' => (string) $venue->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function spaces(?string $institutionId = null): array
    {
        $query = is_string($institutionId) && $institutionId !== ''
            ? $this->spaceEligibilityResolver->institutionQuery($institutionId)
            : $this->spaceEligibilityResolver->catalogQuery();

        $query
            ->where('status', 'active')
            ->orderBy('name');

        return $query
            ->get(['id', 'name'])
            ->map(fn (Space $space): array => [
                'id' => (string) $space->id,
                'label' => (string) $space->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function membershipClaimSubjects(MemberSubjectType $subjectType, string $search): array
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => Institution::query()
                ->where('status', 'verified')
                ->whereIn('status', ['verified', 'pending'])
                ->tap(fn (Builder $query): Builder => $this->applyInstitutionSearch($query, $search))
                ->orderBy('name')
                ->with('names')
                ->limit(50)
                ->get(['id', 'slug', 'name'])
                ->map(fn (Institution $institution): array => [
                    'id' => (string) $institution->id,
                    'slug' => (string) $institution->slug,
                    'label' => $institution->display_name,
                ])
                ->all(),
            MemberSubjectType::Person => Person::query()
                ->where('status', 'verified')
                ->whereIn('status', ['verified', 'pending'])
                ->tap(fn (Builder $query): Builder => $this->applyPersonSearch($query, $search))
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->map(fn (Person $person): array => [
                    'id' => (string) $person->id,
                    'slug' => (string) $person->slug,
                    'label' => $person->formatted_name,
                ])
                ->all(),
            default => [],
        };
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function prayerInstitutions(string $search): array
    {
        $query = Institution::query()
            ->active()
            ->where('status', 'verified')
            ->orderBy('name');

        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $this->applyInstitutionSearch($query, $normalizedSearch);
        }

        return $query
            ->with('names')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (Institution $institution): array => [
                'id' => (string) $institution->id,
                'label' => $institution->display_name,
            ])
            ->all();
    }

    /**
     * @return array{institution_options: array<string, string>, person_options: array<string, string>, default_form: array<string, mixed>}
     */
    public function advancedBuilderContext(User $user, ?string $requestedInstitutionId = null): array
    {
        return app(ResolveAdvancedBuilderContextAction::class)->handle($user, $requestedInstitutionId);
    }

    /**
     * @return array<string, string>
     */
    public function institutionRoleOptions(): array
    {
        return collect(MemberRole::cases())
            ->mapWithKeys(fn (MemberRole $r): array => [$r->value => $r->label()])
            ->all();
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    private function applyInstitutionSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        return $this->institutionSearchService->applySearch($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    private function applyPersonSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        return $this->personSearchService->applyIndexedSearch($query, $normalizedSearch);
    }
}
