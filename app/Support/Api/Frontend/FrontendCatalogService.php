<?php

namespace App\Support\Api\Frontend;

use App\Actions\Events\ResolveAdvancedBuilderContextAction;
use App\Enums\MemberSubjectType;
use App\Enums\TagType;
use App\Models\Country;
use App\Models\District;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\ScopedMemberRoleSeeder;
use App\Support\Location\PublicCountryRegistry;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\SpeakerSearchService;
use App\Support\Submission\EntitySubmissionAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Nnjeim\World\Models\Language;

class FrontendCatalogService
{
    public function __construct(
        private readonly InstitutionSearchService $institutionSearchService,
        private readonly SpeakerSearchService $speakerSearchService,
        private readonly PublicCountryRegistry $publicCountryRegistry,
    ) {}

    /**
     * @return list<array{id: int, label: string, iso2: string, key: ?string}>
     */
    public function countries(): array
    {
        return Country::query()
            ->orderBy('name')
            ->get(['id', 'name', 'iso2'])
            ->map(fn (Country $country): array => [
                'id' => (int) $country->id,
                'label' => (string) $country->name,
                'iso2' => strtoupper((string) $country->iso2),
                'key' => $this->publicCountryRegistry->keyForCountryId((int) $country->id),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function states(?int $countryId): array
    {
        if (! is_int($countryId)) {
            return [];
        }

        return State::query()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (State $state): array => [
                'id' => (int) $state->id,
                'label' => (string) $state->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function districts(?int $stateId, ?int $countryId = null): array
    {
        $query = District::query();

        if (is_int($stateId)) {
            $query->where('state_id', $stateId);
        } elseif (is_int($countryId)) {
            $query->where('country_id', $countryId);
        } else {
            return [];
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (District $district): array => [
                'id' => (int) $district->id,
                'label' => (string) $district->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function subdistricts(?int $stateId, ?int $districtId): array
    {
        $query = Subdistrict::query();

        if ($districtId !== null) {
            $query->where('district_id', $districtId);
        } elseif ($stateId !== null) {
            $query->where('state_id', $stateId)->whereNull('district_id');
        } else {
            return [];
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Subdistrict $subdistrict): array => [
                'id' => (int) $subdistrict->id,
                'label' => (string) $subdistrict->name,
            ])
            ->all();
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
     * @return list<array{id: string, label: string}>
     */
    public function tags(TagType $type, ?string $search = null, int $limit = 50): array
    {
        $query = Tag::query()
            ->ofType($type)
            ->whereIn('status', ['verified', 'pending'])
            ->ordered();

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->whereRaw("LOWER(name->>'ms') LIKE ?", ['%'.mb_strtolower($normalizedSearch).'%']);
        }

        return $query
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Tag $tag): array => [
                'id' => (string) $tag->id,
                'label' => (string) (data_get($tag->name, 'ms') ?: data_get($tag->name, 'en') ?: ''),
            ])
            ->filter(fn (array $option): bool => $option['label'] !== '')
            ->values()
            ->all();
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
            ->get(['id', 'title', 'parent_reference_id', 'part_type', 'part_number', 'part_label'])
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
            ->where('is_active', true)
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
            ->where('is_active', true)
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
                ->where('is_active', true)
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
                ->where('is_active', true)
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
        app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

        return app(MemberRoleCatalog::class)->roleOptionsFor(MemberSubjectType::Institution);
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
