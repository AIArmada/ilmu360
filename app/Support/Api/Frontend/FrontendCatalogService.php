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
use App\Enums\MemberSubjectType;
use App\Enums\TagType;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\SpeakerSearchService;
use App\Support\Submission\EntitySubmissionAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nnjeim\World\Models\Language;

class FrontendCatalogService
{
    public function __construct(
        private readonly InstitutionSearchService $institutionSearchService,
        private readonly SpeakerSearchService $speakerSearchService,
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
     * First configured administrative-area options for admin_area_1_id.
     *
     * @return list<array{id: string, label: string, type: string, level: int|null}>
     */
    public function adminAreaLevel1(?string $countryId, ?string $stateId = null): array
    {
        if (is_string($stateId) && $stateId !== '') {
            return $this->storageAreaOptions(
                $countryId ?? $this->countryIdForState($stateId),
                'admin_area_1_id',
                $stateId,
            );
        }

        return $this->storageAreaOptions($countryId, 'admin_area_1_id');
    }

    /**
     * Next configured administrative-area options under admin_area_1_id.
     *
     * @return list<array{id: string, label: string, type: string, level: int|null}>
     */
    public function adminAreaLevel2(?string $adminArea1Id, ?string $countryId = null, ?string $stateId = null): array
    {
        if (is_string($adminArea1Id) && $adminArea1Id !== '') {
            return $this->addressAreas(countryId: $countryId, parentId: $adminArea1Id, level: null);
        }

        if (is_string($stateId) && $stateId !== '') {
            return $this->storageAreaOptions(
                $countryId ?? $this->countryIdForState($stateId),
                'admin_area_2_id',
                $stateId,
            );
        }

        if (is_string($countryId) && $countryId !== '') {
            return $this->storageAreaOptions($countryId, 'admin_area_2_id');
        }

        return [];
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
    private function storageAreaOptions(?string $countryId, string $storageColumn, ?string $parentId = null): array
    {
        $options = SharedFormSchema::areaOptionsForStorage($countryId, $storageColumn, $parentId);

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

    /**
     * @return list<array{id: int, label: string}>
     */
    public function languages(): array
    {
        /** @var Collection<int, Language> $languages */
        $languages = Language::query()->orderBy('name')->get(['id', 'name']);

        return $languages
            ->map(fn (Language $language): array => [
                'id' => (int) $language->id,
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
            $operator = config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where('name', $operator, '%'.$normalizedSearch.'%');
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
     * Transitional Spatie tag catalog. Prefer taxonomyTerms() for event classification (ADR-011).
     *
     * @return list<array{id: string, label: string}>
     */
    public function tags(TagType $type, ?string $search = null, int $limit = 50): array
    {
        return $this->taxonomyTerms($type->value, $search, $limit);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function references(?string $search = null, int $limit = 50): array
    {
        $query = Reference::query()->orderBy('title');
        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $operator = config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where(function (Builder $referenceQuery) use ($normalizedSearch, $operator): void {
                $referenceQuery
                    ->where('title', $operator, '%'.$normalizedSearch.'%')
                    ->orWhere('part_label', $operator, '%'.$normalizedSearch.'%')
                    ->orWhere('part_number', $operator, '%'.$normalizedSearch.'%');
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'title', 'parent_id', 'metadata'])
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
            ->get(['institutions.id', 'institutions.name', 'institutions.nickname'])
            ->map(fn (Institution $institution): array => [
                'id' => (string) $institution->id,
                'label' => $institution->display_name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function submitSpeakers(?User $user, ?string $search = null, int $limit = 50): array
    {
        $query = app(EntitySubmissionAccess::class)
            ->speakerQueryForSubmitter($user)
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $this->applySpeakerSearch($query, $normalizedSearch);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (Speaker $speaker): array => [
                'id' => (string) $speaker->id,
                'label' => $speaker->formatted_name,
            ])
            ->all();
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
            $operator = config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where('name', $operator, '%'.$normalizedSearch.'%');
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
        $query = Space::query()
            ->where('status', 'active')
            ->orderBy('name');

        if (is_string($institutionId) && $institutionId !== '') {
            $query->where(function (Builder $spaceQuery) use ($institutionId): void {
                $spaceQuery
                    ->whereDoesntHave('institutions')
                    ->orWhereHas('institutions', fn (Builder $institutionQuery) => $institutionQuery->whereKey($institutionId));
            });
        } else {
            $query->whereDoesntHave('institutions');
        }

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
                ->limit(50)
                ->get(['id', 'slug', 'name', 'nickname'])
                ->map(fn (Institution $institution): array => [
                    'id' => (string) $institution->id,
                    'slug' => (string) $institution->slug,
                    'label' => $institution->display_name,
                ])
                ->all(),
            MemberSubjectType::Speaker => Speaker::query()
                ->where('status', 'verified')
                ->whereIn('status', ['verified', 'pending'])
                ->tap(fn (Builder $query): Builder => $this->applySpeakerSearch($query, $search))
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->map(fn (Speaker $speaker): array => [
                    'id' => (string) $speaker->id,
                    'slug' => (string) $speaker->slug,
                    'label' => $speaker->formatted_name,
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
            ->limit(50)
            ->get(['id', 'name', 'nickname'])
            ->map(fn (Institution $institution): array => [
                'id' => (string) $institution->id,
                'label' => $institution->display_name,
            ])
            ->all();
    }

    /**
     * @return array{institution_options: array<string, string>, speaker_options: array<string, string>, default_form: array<string, mixed>}
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
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    private function applySpeakerSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        return $this->speakerSearchService->applyIndexedSearch($query, $normalizedSearch);
    }
}
