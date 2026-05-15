<?php

namespace App\Livewire\Pages\SavedSearches;

use App\Actions\SavedSearches\CreateSavedSearchAction;
use App\Actions\SavedSearches\UpdateSavedSearchAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\NotificationFrequency;
use App\Enums\TimingMode;
use App\Exceptions\SavedSearchLimitReachedException;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Country;
use App\Models\District;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\SavedSearch;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Support\Timezone\UserDateTimeFormatter;
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

    /**
     * @var array<int, string|null>
     */
    private array $countryNames = [];

    /**
     * @var array<int, string|null>
     */
    private array $stateNames = [];

    /**
     * @var array<int, string|null>
     */
    private array $districtNames = [];

    /**
     * @var array<int, string|null>
     */
    private array $subdistrictNames = [];

    /**
     * @var array<string, string|null>
     */
    private array $institutionNames = [];

    /**
     * @var array<string, string|null>
     */
    private array $venueNames = [];

    /**
     * @var array<string, string|null>
     */
    private array $tagNames = [];

    /**
     * @var array<string, string|null>
     */
    private array $referenceTitles = [];

    /**
     * @var array<string, string|null>
     */
    private array $speakerNames = [];

    public bool $hasFilters = false;

    public function mount(): void
    {
        $this->prefillFromRequest();
        $this->hasFilters = $this->filters !== [] || ($this->lat !== null && $this->lng !== null);
    }

    /**
     * @return Collection<int, SavedSearch>
     */
    #[Computed]
    public function savedSearches(): Collection
    {
        $user = auth()->user();

        return $user ? $user->savedSearches()->latest()->get() : collect();
    }

    public function save(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'query' => ['nullable', 'string', 'max:255'],
            'notify' => ['required', Rule::in(array_keys($this->notifyOptions()))],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'lat' => ['nullable', 'required_with:radius_km', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:radius_km', 'numeric', 'between:-180,180'],
        ]);

        try {
            app(CreateSavedSearchAction::class)->handle($user, [
                'name' => $validated['name'],
                'query' => $validated['query'] ?? null,
                'filters' => $this->filters,
                'radius_km' => $validated['radius_km'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lng' => $validated['lng'] ?? null,
                'notify' => $validated['notify'],
            ], request());
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

        if (! $user instanceof User) {
            abort(403);
        }

        $savedSearch = $user->savedSearches()->where('id', $savedSearchId)->firstOrFail();
        $savedSearch->delete();

        $this->successToast(__('Saved search deleted.'));
    }

    public function startEdit(string $savedSearchId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $savedSearch = $user->savedSearches()->where('id', $savedSearchId)->firstOrFail();

        $this->editingId = $savedSearch->id;
        $this->editName = $savedSearch->name;
        $this->editNotify = $savedSearch->notify;
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

        if (! $user instanceof User) {
            abort(403);
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:100'],
            'editNotify' => ['required', Rule::in(array_keys($this->notifyOptions()))],
        ]);

        $savedSearch = $user->savedSearches()->where('id', $savedSearchId)->firstOrFail();
        app(UpdateSavedSearchAction::class)->handle($savedSearch, [
            'name' => $validated['editName'],
            'notify' => $validated['editNotify'],
        ]);

        $this->cancelEdit();

        $this->successToast(__('Saved search updated.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toEventQueryParams(SavedSearch $savedSearch): array
    {
        $params = array_merge(
            ['search' => $savedSearch->query],
            is_array($savedSearch->filters) ? $savedSearch->filters : []
        );

        if ($savedSearch->lat !== null && $savedSearch->lng !== null) {
            $params = array_merge($params, [
                'lat' => $savedSearch->lat,
                'lng' => $savedSearch->lng,
                'radius_km' => $savedSearch->radius_km,
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
            'district_id',
            'subdistrict_id',
            'event_type',
            'event_format',
            'gender',
            'institution_id',
            'venue_id',
            'key_person_roles',
            'person_in_charge_search',
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

        $topicIds = array_values(array_filter((array) request()->input('topic_ids', [])));

        if ($topicIds !== []) {
            $filters['topic_ids'] = $topicIds;
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

        $speakerIds = array_values(array_filter((array) request()->input('speaker_ids', [])));

        if ($speakerIds !== []) {
            $filters['speaker_ids'] = $speakerIds;
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

        $eventType = array_values(array_filter((array) request()->input('event_type', [])));

        if ($eventType !== []) {
            $filters['event_type'] = $eventType;
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
            return __('Search: :query', ['query' => Str::limit($this->query, 40)]);
        }

        if (! empty($this->filters['event_type'])) {
            $eventType = is_array($this->filters['event_type'])
                ? $this->filters['event_type'][0]
                : $this->filters['event_type'];

            $eventTypeLabel = EventType::tryFrom((string) $eventType)?->getLabel()
                ?? Str::of((string) $eventType)->replace('_', ' ')->headline()->toString();

            return __('Event type: :type', ['type' => $eventTypeLabel]);
        }

        if (! empty($this->filters['starts_after']) || ! empty($this->filters['starts_before'])) {
            return __('Upcoming event dates');
        }

        return __('My event search');
    }

    private function capturedFilterLabel(string $filterKey): string
    {
        return match ($filterKey) {
            'country_id' => __('Country'),
            'state_id' => __('State'),
            'district_id' => __('District'),
            'subdistrict_id' => __('Subdistrict / Mukim / Zone'),
            'institution_id' => __('Institution'),
            'venue_id' => __('Venue'),
            'speaker_ids' => __('Speaker'),
            'key_person_roles' => __('Key Person Roles'),
            'person_in_charge_ids' => __('PIC / Penyelaras'),
            'person_in_charge_search' => __('Nama PIC / Penyelaras'),
            'moderator_ids' => __('Moderator'),
            'imam_ids' => __('Imam'),
            'khatib_ids' => __('Khatib'),
            'bilal_ids' => __('Bilal'),
            'domain_tag_ids' => __('Kategori'),
            'topic_ids' => __('Discipline'),
            'source_tag_ids' => __('Primary Sources'),
            'issue_tag_ids' => __('Themes / Issues'),
            'reference_ids' => __('References'),
            'language_codes' => __('Languages'),
            'event_type' => __('Event Type'),
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
            'country_id' => $this->countryName($value) ?? $value,
            'state_id' => $this->stateName($value) ?? $value,
            'district_id' => $this->districtName($value) ?? $value,
            'subdistrict_id' => $this->subdistrictName($value) ?? $value,
            'institution_id' => $this->institutionName($value) ?? $value,
            'venue_id' => $this->venueName($value) ?? $value,
            'speaker_ids' => $this->speakerName($value) ?? $value,
            'key_person_roles' => EventKeyPersonRole::tryFrom($value)?->getLabel() ?? $value,
            'person_in_charge_ids', 'moderator_ids', 'imam_ids', 'khatib_ids', 'bilal_ids' => $this->speakerName($value) ?? $value,
            'domain_tag_ids', 'topic_ids', 'source_tag_ids', 'issue_tag_ids' => $this->tagName($value) ?? $value,
            'reference_ids' => $this->referenceTitle($value) ?? $value,
            'language_codes' => $this->languageLabel($value) ?? $value,
            'event_type' => EventType::tryFrom($value)?->getLabel() ?? $value,
            'event_format' => EventFormat::tryFrom($value)?->getLabel() ?? $value,
            'gender' => EventGenderRestriction::tryFrom($value)?->getLabel() ?? $value,
            'age_group' => EventAgeGroup::tryFrom($value)?->getLabel() ?? $value,
            'starts_after', 'starts_before' => $this->dateLabel($value) ?? $value,
            'time_scope' => match ($value) {
                'upcoming' => __('Upcoming'),
                'past' => __('Past'),
                'all' => __('All Time'),
                default => $value,
            },
            'prayer_time' => EventPrayerTime::tryFrom($value)?->getLabel() ?? $value,
            'timing_mode' => TimingMode::tryFrom($value)?->label() ?? $value,
            'starts_time_from', 'starts_time_until' => $this->timeLabel($value) ?? $value,
            'children_allowed', 'is_muslim_only', 'has_event_url', 'has_live_url', 'has_end_time' => $this->booleanLabel($value) ?? $value,
            default => $value,
        };
    }

    private function stateName(string $id): ?string
    {
        $stateId = (int) $id;

        if ($stateId <= 0) {
            return null;
        }

        if (! array_key_exists($stateId, $this->stateNames)) {
            $this->stateNames[$stateId] = State::query()->whereKey($stateId)->value('name');
        }

        return $this->stateNames[$stateId];
    }

    private function countryName(string $id): ?string
    {
        $countryId = (int) $id;

        if ($countryId <= 0) {
            return null;
        }

        if (! array_key_exists($countryId, $this->countryNames)) {
            $this->countryNames[$countryId] = Country::query()->whereKey($countryId)->value('name');
        }

        return $this->countryNames[$countryId];
    }

    private function districtName(string $id): ?string
    {
        $districtId = (int) $id;

        if ($districtId <= 0) {
            return null;
        }

        if (! array_key_exists($districtId, $this->districtNames)) {
            $this->districtNames[$districtId] = District::query()->whereKey($districtId)->value('name');
        }

        return $this->districtNames[$districtId];
    }

    private function subdistrictName(string $id): ?string
    {
        $subdistrictId = (int) $id;

        if ($subdistrictId <= 0) {
            return null;
        }

        if (! array_key_exists($subdistrictId, $this->subdistrictNames)) {
            $this->subdistrictNames[$subdistrictId] = Subdistrict::query()->whereKey($subdistrictId)->value('name');
        }

        return $this->subdistrictNames[$subdistrictId];
    }

    private function institutionName(string $id): ?string
    {
        if (! array_key_exists($id, $this->institutionNames)) {
            $this->institutionNames[$id] = Institution::query()
                ->whereKey($id)
                ->first(['id', 'name', 'nickname'])
                ?->display_name;
        }

        return $this->institutionNames[$id];
    }

    private function venueName(string $id): ?string
    {
        if (! array_key_exists($id, $this->venueNames)) {
            $this->venueNames[$id] = Venue::query()->whereKey($id)->value('name');
        }

        return $this->venueNames[$id];
    }

    private function speakerName(string $id): ?string
    {
        if (! array_key_exists($id, $this->speakerNames)) {
            $this->speakerNames[$id] = Speaker::query()->whereKey($id)->value('name');
        }

        return $this->speakerNames[$id];
    }

    private function tagName(string $id): ?string
    {
        if (! array_key_exists($id, $this->tagNames)) {
            $tag = Tag::query()->whereKey($id)->first(['id', 'name']);

            if (! $tag instanceof Tag) {
                $this->tagNames[$id] = null;
            } else {
                $name = $tag->name;

                if (is_array($name)) {
                    $locale = app()->getLocale();
                    $fallback = array_find($name, static fn (): bool => true);

                    $this->tagNames[$id] = (is_string($name[$locale] ?? null) && ($name[$locale] ?? '') !== '')
                        ? $name[$locale]
                        : ((is_string($name['ms'] ?? null) && ($name['ms'] ?? '') !== '')
                            ? $name['ms']
                            : ((is_string($name['en'] ?? null) && ($name['en'] ?? '') !== '')
                                ? $name['en']
                                : $fallback));
                } else {
                    $this->tagNames[$id] = is_string($name) ? $name : null;
                }
            }
        }

        return $this->tagNames[$id];
    }

    private function referenceTitle(string $id): ?string
    {
        if (! array_key_exists($id, $this->referenceTitles)) {
            $this->referenceTitles[$id] = Reference::query()->whereKey($id)->value('title');
        }

        return $this->referenceTitles[$id];
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
            return Carbon::parse($value, UserDateTimeFormatter::resolveTimezone())->translatedFormat('j M Y');
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
                    UserDateTimeFormatter::resolveTimezone()
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
