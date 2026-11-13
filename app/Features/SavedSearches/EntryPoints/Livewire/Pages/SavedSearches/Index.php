<?php

namespace App\Features\SavedSearches\EntryPoints\Livewire\Pages\SavedSearches;

use App\Features\SavedSearches\Api\CreateSavedSearchWithAttribution;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\SavedSearches\Enums\NotificationFrequency;
use App\Features\SavedSearches\Exceptions\SavedSearchLimitReachedException;
use App\Platform\Location\LocationLevelLabels;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Auth\ResourceOwner;
use App\Shared\Discovery\SearchFilterCatalog;
use App\Shared\Discovery\SearchFilterLabels;
use App\Shared\Discovery\SearchFilterRules;
use App\Shared\Location\VisitorCountryResolving;
use App\Shared\Time\UserDateTimeFormatting;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use InteractsWithToasts;

    public string $name = '';

    public ?string $query = null;

    public string $notify = 'daily';

    public ?int $radius_km = null;

    public ?string $lat = null;

    public ?string $lng = null;

    /**
     * @var array<string, mixed>
     */
    public array $filters = [];

    public ?string $editingId = null;

    public string $editName = '';

    public string $editNotify = 'daily';

    public bool $hasFilters = false;

    public function mount(): void
    {
        $this->prefillFromRequest();
        $this->hasFilters = $this->filters !== [] || ($this->lat !== null && $this->lng !== null);
    }

    /**
     * @return Collection<int, SavedSearchSnapshot>
     */
    #[Computed]
    public function savedSearches(): Collection
    {
        $user = auth()->user();

        return $user instanceof ResourceOwner
            ? collect(app(SavedSearchReader::class)->forOwner($user->resourceOwnerType(), $user->resourceOwnerId()))
            : collect();
    }

    public function save(): void
    {
        $user = auth()->user();

        if (! $user instanceof ResourceOwner) {
            abort(403);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'query' => ['nullable', 'string', 'max:255'],
            ...app(SearchFilterRules::class)->rules($this->filters),
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'lat' => ['nullable', 'required_with:radius_km', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:radius_km', 'numeric', 'between:-180,180'],
            'notify' => ['required', Rule::in(array_keys($this->notifyOptions()))],
        ]);

        /** @var array<string, mixed> $filters */
        $filters = $validated['filters'] ?? [];

        try {
            app(CreateSavedSearchWithAttribution::class)->handle($user, [
                'name' => $validated['name'],
                'query' => $validated['query'] ?? null,
                'filters' => $filters,
                'radius_km' => $validated['radius_km'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lng' => $validated['lng'] ?? null,
                'notify' => $validated['notify'],
            ]);
        } catch (SavedSearchLimitReachedException) {
            $this->addError('name', __('You can only keep up to 10 saved searches.'));

            return;
        }

        $this->reset(['name', 'query', 'radius_km', 'lat', 'lng']);
        $this->notify = 'daily';
        $this->filters = [];

        $this->successToast(__('Saved search saved.'));
    }

    public function delete(string $savedSearchId): void
    {
        $user = auth()->user();

        if (! $user instanceof ResourceOwner) {
            abort(403);
        }

        app(SavedSearchCommands::class)->delete($user->resourceOwnerType(), $user->resourceOwnerId(), $savedSearchId);

        $this->successToast(__('Saved search deleted.'));
    }

    public function startEdit(string $savedSearchId): void
    {
        $user = auth()->user();

        if (! $user instanceof ResourceOwner) {
            abort(403);
        }

        $savedSearch = app(SavedSearchReader::class)->findForOwner($user->resourceOwnerType(), $user->resourceOwnerId(), $savedSearchId);
        abort_if($savedSearch === null, 404);

        $this->editingId = $savedSearch->id;
        $this->editName = $savedSearch->name;
        $this->editNotify = (string) $savedSearch->notify;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editName = '';
        $this->editNotify = 'daily';
    }

    public function update(string $savedSearchId): void
    {
        $user = auth()->user();

        if (! $user instanceof ResourceOwner) {
            abort(403);
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:100'],
            'editNotify' => ['required', Rule::in(array_keys($this->notifyOptions()))],
        ]);

        app(SavedSearchCommands::class)->update($user->resourceOwnerType(), $user->resourceOwnerId(), $savedSearchId, [
            'name' => $validated['editName'],
            'notify' => $validated['editNotify'],
        ]);

        $this->cancelEdit();

        $this->successToast(__('Saved search updated.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toEventQueryParams(SavedSearchSnapshot $savedSearch): array
    {
        $params = array_merge(
            ['search' => $savedSearch->query],
            is_array($savedSearch->filters) ? $savedSearch->filters : []
        );

        // Saved searches already carry a complete result set; land on the
        // listings with the advanced filter panel collapsed.
        $params['filters_panel'] = 'closed';

        if ($savedSearch->lat !== null && $savedSearch->lng !== null) {
            $params = array_merge($params, [
                'lat' => $savedSearch->lat,
                'lng' => $savedSearch->lng,
                'radius_km' => $savedSearch->radiusKm,
                'sort' => 'distance',
            ]);
        }

        return array_filter($params, filled(...));
    }

    public function render(): View
    {
        return view('livewire.pages.saved-searches.index');
    }

    /**
     * @return array<string, string>
     */
    public function notifyOptions(): array
    {
        return [
            NotificationFrequency::Off->value => __('Paused'),
            NotificationFrequency::Instant->value => __('Instant'),
            NotificationFrequency::Daily->value => __('Daily'),
            NotificationFrequency::Weekly->value => __('Weekly'),
        ];
    }

    public function notifyLabel(string $notify): string
    {
        return $this->notifyOptions()[$notify] ?? str($notify)->headline()->toString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{label: string, value: string}>
     */
    public function formatCapturedFilters(
        array $filters,
        ?int $radiusKm = null,
        float|int|string|null $lat = null,
        float|int|string|null $lng = null
    ): array {
        $formatted = [];

        foreach ($filters as $filterKey => $filterValue) {
            if (! is_string($filterKey)) {
                continue;
            }

            $valueLabel = $this->capturedFilterValue($filterKey, $filterValue);

            if (! filled($valueLabel)) {
                continue;
            }

            $formatted[] = [
                'label' => $this->capturedFilterLabel($filterKey),
                'value' => $valueLabel,
            ];
        }

        if ($radiusKm !== null && $radiusKm > 0 && filled($lat) && filled($lng)) {
            $formatted[] = [
                'label' => __('Radius'),
                'value' => __(':distance km', ['distance' => $radiusKm]),
            ];
        }

        return $formatted;
    }

    protected function prefillFromRequest(): void
    {
        $this->query = request()->filled('search') ? (string) request('search') : null;
        $this->lat = request()->filled('lat') ? (string) request('lat') : null;
        $this->lng = request()->filled('lng') ? (string) request('lng') : null;
        $this->radius_km = ($this->lat !== null && $this->lng !== null && request()->filled('radius_km'))
            ? (int) request('radius_km')
            : null;

        $this->filters = $this->extractRequestFilters();

        if ($this->query || $this->filters !== [] || ($this->lat && $this->lng)) {
            $this->name = $this->suggestedName();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractRequestFilters(): array
    {
        $filterKeys = [
            'country_id',
            'state_id',
            'city_id',
            'area_assignments',
            'event_category_ids',
            'event_format',
            'gender',
            'institution_id',
            'venue_id',
            'key_person_roles',
            'person_in_charge_search',
            'person_name_search',
            'moderator_ids',
            'imam_ids',
            'khatib_ids',
            'bilal_ids',
            'starts_after',
            'starts_before',
            'time_scope',
            'prayer_time',
            'timing_mode',
            'starts_time_from',
            'starts_time_until',
            'children_allowed',
            'is_muslim_only',
            'has_event_url',
            'has_live_url',
            'has_end_time',
        ];

        $filters = [];

        foreach ($filterKeys as $filterKey) {
            if (request()->filled($filterKey)) {
                $filters[$filterKey] = request()->input($filterKey);
            }
        }

        $ageGroups = array_values(array_filter((array) request()->input('age_group', [])));

        if ($ageGroups !== []) {
            $filters['age_group'] = $ageGroups;
        }

        $disciplineTagIds = array_values(array_filter((array) request()->input('discipline_tag_ids', [])));

        if ($disciplineTagIds !== []) {
            $filters['discipline_tag_ids'] = $disciplineTagIds;
        }

        $domainTagIds = array_values(array_filter((array) request()->input('domain_tag_ids', [])));

        if ($domainTagIds !== []) {
            $filters['domain_tag_ids'] = $domainTagIds;
        }

        $sourceTagIds = array_values(array_filter((array) request()->input('source_tag_ids', [])));

        if ($sourceTagIds !== []) {
            $filters['source_tag_ids'] = $sourceTagIds;
        }

        $issueTagIds = array_values(array_filter((array) request()->input('issue_tag_ids', [])));

        if ($issueTagIds !== []) {
            $filters['issue_tag_ids'] = $issueTagIds;
        }

        $referenceIds = array_values(array_filter((array) request()->input('reference_ids', [])));

        if ($referenceIds !== []) {
            $filters['reference_ids'] = $referenceIds;
        }

        $personIds = array_values(array_filter((array) request()->input('person_ids', [])));

        if ($personIds !== []) {
            $filters['person_ids'] = $personIds;
        }

        $keyPersonRoles = array_values(array_filter((array) request()->input('key_person_roles', [])));

        if ($keyPersonRoles !== []) {
            $filters['key_person_roles'] = $keyPersonRoles;
        }

        foreach (['person_in_charge_ids', 'moderator_ids', 'imam_ids', 'khatib_ids', 'bilal_ids'] as $filterKey) {
            $roleSpecificIds = array_values(array_filter((array) request()->input($filterKey, [])));

            if ($roleSpecificIds !== []) {
                $filters[$filterKey] = $roleSpecificIds;
            }
        }

        $eventType = array_values(array_filter((array) request()->input('event_category_ids', [])));

        if ($eventType !== []) {
            $filters['event_category_ids'] = $eventType;
        }

        $eventFormat = array_values(array_filter((array) request()->input('event_format', [])));

        if ($eventFormat !== []) {
            $filters['event_format'] = $eventFormat;
        }

        $languageCodes = array_values(array_filter((array) request()->input('language_codes', [])));

        if ($languageCodes !== []) {
            $filters['language_codes'] = $languageCodes;
        }

        return $filters;
    }

    protected function suggestedName(): string
    {
        if (filled($this->query)) {
            return $this->limitSuggestedName(__('Search: :query', ['query' => Str::limit($this->query, 40)]));
        }

        $subject = $this->suggestedSubject();
        $location = ($subject['facility'] ?? false) ? null : $this->suggestedLocation();
        $hasTimeScope = in_array($this->filters['time_scope'] ?? null, ['upcoming', 'past', 'all'], true);
        $hasDateRange = filled($this->filters['starts_after'] ?? null) || filled($this->filters['starts_before'] ?? null);

        if ($subject === null && $location === null && ! $hasTimeScope) {
            return $hasDateRange ? __('Upcoming event dates') : __('My event search');
        }

        $name = $this->suggestedTimeScopeLabel();

        if ($subject !== null) {
            $name .= str_starts_with($subject['phrase'], ':')
                ? $subject['phrase']
                : ' '.$subject['phrase'];
        }

        if ($location !== null) {
            $name .= ' '.$location;
        }

        return $this->limitSuggestedName($name);
    }

    private function suggestedTimeScopeLabel(): string
    {
        return match ($this->filters['time_scope'] ?? null) {
            'past' => __('Majlis Lepas'),
            'all' => __('Semua Majlis'),
            'upcoming' => __('Majlis Akan Datang'),
            default => __('Majlis'),
        };
    }

    /**
     * @return array{phrase: string, facility: bool}|null
     */
    private function suggestedSubject(): ?array
    {
        $personNames = $this->resolvedSuggestedLabels(
            $this->filters['person_ids'] ?? [],
            fn (string $id): ?string => app(SearchFilterLabels::class)->label('person_ids', $id),
        );

        if ($personNames !== []) {
            return [
                'phrase' => __('bersama :names', ['names' => $this->joinSuggestedLabels($personNames)]),
                'facility' => false,
            ];
        }

        $referenceTitles = $this->resolvedSuggestedLabels(
            $this->filters['reference_ids'] ?? [],
            fn (string $id): ?string => app(SearchFilterLabels::class)->label('reference_ids', $id),
        );

        if ($referenceTitles !== []) {
            return [
                'phrase' => ': '.$this->joinSuggestedLabels($referenceTitles),
                'facility' => false,
            ];
        }

        $institutionId = $this->filters['institution_id'] ?? null;
        $institutionName = filled($institutionId) ? app(SearchFilterLabels::class)->label('institution_id', (string) $institutionId) : null;

        if (filled($institutionName)) {
            return [
                'phrase' => __('di :name', ['name' => $institutionName]),
                'facility' => true,
            ];
        }

        $venueId = $this->filters['venue_id'] ?? null;
        $venueName = filled($venueId) ? app(SearchFilterLabels::class)->label('venue_id', (string) $venueId) : null;

        if (filled($venueName)) {
            return [
                'phrase' => __('di :name', ['name' => $venueName]),
                'facility' => true,
            ];
        }

        $categoryIds = array_values(array_filter((array) ($this->filters['event_category_ids'] ?? [])));

        if ($categoryIds !== []) {
            $categoryId = (string) $categoryIds[0];
            $categoryLabel = app(SearchFilterCatalog::class)->label('event_category_ids', $categoryId) ?? $categoryId;

            return [
                'phrase' => ': '.$categoryLabel,
                'facility' => false,
            ];
        }

        return null;
    }

    private function suggestedLocation(): ?string
    {
        $areaAssignments = is_array($this->filters['area_assignments'] ?? null) ? $this->filters['area_assignments'] : [];

        foreach (['administrative_subdivision', 'administrative_district', 'postal_locality', 'administrative_division'] as $role) {
            $areaId = $areaAssignments[$role] ?? null;
            $areaName = filled($areaId) ? app(SearchFilterLabels::class)->label('area_assignments', (string) $areaId) : null;

            if (filled($areaName)) {
                return __('di :place', ['place' => $areaName]);
            }
        }

        $cityId = $this->filters['city_id'] ?? null;
        $cityName = filled($cityId) ? app(SearchFilterLabels::class)->label('city_id', (string) $cityId) : null;

        if (filled($cityName)) {
            return __('di :place', ['place' => $cityName]);
        }

        $stateId = $this->filters['state_id'] ?? null;
        $stateName = filled($stateId) ? app(SearchFilterLabels::class)->label('state_id', (string) $stateId) : null;

        if (filled($stateName)) {
            return __('di :place', ['place' => $stateName]);
        }

        $countryId = $this->filters['country_id'] ?? null;
        $countryName = filled($countryId) && (string) $countryId !== (string) app(VisitorCountryResolving::class)->resolve()
            ? app(SearchFilterLabels::class)->label('country_id', (string) $countryId)
            : null;

        return filled($countryName) ? __('di :place', ['place' => $countryName]) : null;
    }

    /**
     * @param  callable(string): ?string  $resolver
     * @return list<string>
     */
    private function resolvedSuggestedLabels(mixed $ids, callable $resolver): array
    {
        return collect((array) $ids)
            ->filter(fn (mixed $id): bool => is_scalar($id) && filled($id))
            ->map(fn (mixed $id): ?string => $resolver((string) $id))
            ->filter(filled(...))
            ->take(2)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $labels
     */
    private function joinSuggestedLabels(array $labels): string
    {
        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        return $labels[0].' & '.$labels[1];
    }

    private function limitSuggestedName(string $name): string
    {
        return Str::limit(trim($name), 100, '');
    }

    private function capturedFilterLabel(string $filterKey): string
    {
        if ($filterKey === 'state_id') {
            return LocationLevelLabels::label($this->filters['country_id'] ?? null, 'state_id', __('State / Province'));
        }

        return match ($filterKey) {
            'country_id' => __('Country'),
            'city_id' => __('City'),
            'area_assignments' => __('Administrative Areas'),
            'institution_id' => __('Institution'),
            'venue_id' => __('Venue'),
            'person_ids' => __('Speaker'),
            'key_person_roles' => __('Key Person Roles'),
            'person_in_charge_ids' => __('PIC / Penyelaras'),
            'person_in_charge_search' => __('Nama PIC / Penyelaras'),
            'person_name_search' => __('Nama penceramah'),
            'moderator_ids' => __('Moderator'),
            'imam_ids' => __('Imam'),
            'khatib_ids' => __('Khatib'),
            'bilal_ids' => __('Bilal'),
            'domain_tag_ids' => __('Kategori'),
            'discipline_tag_ids' => __('Discipline'),
            'source_tag_ids' => __('Primary Sources'),
            'issue_tag_ids' => __('Themes / Issues'),
            'reference_ids' => __('References'),
            'language_codes' => __('Languages'),
            'event_category_ids' => __('Event Category'),
            'event_format' => __('Event Format'),
            'gender' => __('Gender'),
            'starts_after' => __('Starts After'),
            'starts_before' => __('Starts Before'),
            'time_scope' => __('Time Scope'),
            'prayer_time' => __('Prayer Time'),
            'timing_mode' => __('Timing'),
            'starts_time_from' => __('Starts From'),
            'starts_time_until' => __('Starts Until'),
            'children_allowed' => __('Children Allowed'),
            'is_muslim_only' => __('Muslim Only'),
            'has_event_url' => __('Event URL'),
            'has_live_url' => __('Live URL'),
            'has_end_time' => __('End Time'),
            'age_group' => __('Age Group'),
            default => $this->translatedFilterFallbackLabel($filterKey),
        };
    }

    private function capturedFilterValue(string $filterKey, mixed $filterValue): ?string
    {
        if ($filterKey === 'area_assignments' && is_array($filterValue)) {
            $values = [];

            foreach ($filterValue as $role => $areaId) {
                if (! is_string($role) || ! is_scalar($areaId)) {
                    continue;
                }

                $label = LocationLevelLabels::label(
                    $this->filters['country_id'] ?? null,
                    $role,
                    Str::headline($role),
                    $this->filters['state_id'] ?? null,
                    $filterValue,
                );
                $values[] = $label.': '.(app(SearchFilterLabels::class)->label('area_assignments', (string) $areaId) ?? (string) $areaId);
            }

            return $values === [] ? null : implode(', ', $values);
        }

        if (is_array($filterValue)) {
            $values = array_values(array_filter(
                array_map(fn (mixed $value): ?string => $this->capturedFilterValue($filterKey, $value), $filterValue),
                filled(...)
            ));

            return $values === [] ? null : implode(', ', $values);
        }

        $value = trim((string) $filterValue);

        if ($value === '') {
            return null;
        }

        return match ($filterKey) {
            'country_id' => app(SearchFilterLabels::class)->label('country_id', $value) ?? $value,
            'state_id' => app(SearchFilterLabels::class)->label('state_id', $value) ?? $value,
            'city_id' => app(SearchFilterLabels::class)->label('city_id', $value) ?? $value,
            'institution_id' => app(SearchFilterLabels::class)->label('institution_id', $value) ?? $value,
            'venue_id' => app(SearchFilterLabels::class)->label('venue_id', $value) ?? $value,
            'person_ids' => app(SearchFilterLabels::class)->label('person_ids', $value) ?? $value,
            'key_person_roles' => app(SearchFilterCatalog::class)->label('key_person_roles', $value) ?? $value,
            'person_in_charge_ids', 'moderator_ids', 'imam_ids', 'khatib_ids', 'bilal_ids' => app(SearchFilterLabels::class)->label('person_ids', $value) ?? $value,
            'domain_tag_ids', 'discipline_tag_ids', 'source_tag_ids', 'issue_tag_ids' => app(SearchFilterLabels::class)->label($filterKey, $value) ?? $value,
            'reference_ids' => app(SearchFilterLabels::class)->label('reference_ids', $value) ?? $value,
            'language_codes' => $this->languageLabel($value) ?? $value,
            'event_category_ids' => app(SearchFilterCatalog::class)->label('event_category_ids', $value) ?? $value,
            'event_format' => app(SearchFilterCatalog::class)->label('event_format', $value) ?? $value,
            'gender' => app(SearchFilterCatalog::class)->label('gender', $value) ?? $value,
            'age_group' => app(SearchFilterCatalog::class)->label('age_group', $value) ?? $value,
            'starts_after', 'starts_before' => $this->dateLabel($value) ?? $value,
            'time_scope' => app(SearchFilterCatalog::class)->label('time_scope', $value) ?? $value,
            'prayer_time' => app(SearchFilterCatalog::class)->label('prayer_time', $value) ?? $value,
            'timing_mode' => app(SearchFilterCatalog::class)->label('timing_mode', $value) ?? $value,
            'starts_time_from', 'starts_time_until' => $this->timeLabel($value) ?? $value,
            'children_allowed', 'is_muslim_only', 'has_event_url', 'has_live_url', 'has_end_time' => $this->booleanLabel($value) ?? $value,
            default => $value,
        };
    }

    private function languageLabel(string $code): ?string
    {
        $normalized = mb_strtolower(trim($code));
        $locale = app()->getLocale();

        $label = match ($normalized) {
            'ms' => __('Malay'),
            'ar' => __('Arabic'),
            'en' => __('English'),
            'id' => __('Indonesian'),
            'zh' => __('Mandarin Chinese'),
            'ta' => __('Tamil'),
            'jv' => __('Javanese'),
            default => null,
        };

        if (! is_string($label) || $label === '') {
            return null;
        }

        if (in_array($locale, ['ms', 'ms_MY'], true)) {
            return __('Bahasa').' '.$label;
        }

        return $label;
    }

    private function booleanLabel(string $value): ?string
    {
        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => __('Yes'),
            '0', 'false', 'no', 'off' => __('No'),
            default => null,
        };
    }

    private function dateLabel(string $value): ?string
    {
        try {
            return Carbon::parse($value, app(UserDateTimeFormatting::class)->resolveTimezone())->translatedFormat('j M Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private function timeLabel(string $value): ?string
    {
        foreach (['H:i:s', 'H:i'] as $format) {
            try {
                return Carbon::createFromFormat(
                    $format,
                    $value,
                    app(UserDateTimeFormatting::class)->resolveTimezone()
                )->format('g:i A');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function translatedFilterFallbackLabel(string $filterKey): string
    {
        $fallback = str($filterKey)->replace('_', ' ')->headline()->toString();

        return __($fallback);
    }
}
