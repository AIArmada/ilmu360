<?php

namespace App\Support\Api\Frontend;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use App\Actions\Events\ResolveAdvancedBuilderContextAction;
use App\Enums\MemberSubjectType;
use App\Enums\TagType;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\ScopedMemberRoleSeeder;
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
     * @return list<array{id: string, label: string, type: string, level: int|null}>
     */
    public function states(?string $countryId): array
    {
        if (! is_string($countryId) || $countryId === '') {
            return [];
        }

        return AddressArea::query()
            ->where('country_id', $countryId)
            ->where('level', 1)
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
    public function districts(?string $stateId, ?string $countryId = null): array
    {
        $query = AddressArea::query();

        if (is_string($stateId) && $stateId !== '') {
            $query->where('parent_id', $stateId);
        } elseif (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', $countryId);
            $query->where('level', 2);
        } else {
            return [];
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
    public function subdistricts(?string $stateId, ?string $districtId): array
    {
        $parentId = is_string($districtId) && $districtId !== ''
            ? $districtId
            : (is_string($stateId) && $stateId !== '' ? $stateId : null);

        if ($parentId === null) {
            return [];
        }

        return AddressArea::query()
            ->where('parent_id', $parentId)
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
    public function addressAreas(?string $countryId = null, ?string $parentId = null, ?int $level = null): array
    {
        $query = AddressArea::query();

        if (is_string($parentId) && $parentId !== '') {
            $query->where('parent_id', $parentId);
        } elseif (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', $countryId);
        } else {
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
