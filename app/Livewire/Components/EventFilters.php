<?php

namespace App\Livewire\Components;

use App\Enums\EventAgeGroup;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\TimingMode;
use App\Livewire\Pages\Events\Index as EventsIndex;
use App\Support\Location\VisitorCountryResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Features\SupportAttributes\AttributeCollection;
use Livewire\Features\SupportPageComponents\BaseLayout;
use Livewire\Features\SupportPageComponents\BaseTitle;

class EventFilters extends EventsIndex
{
    /**
     * @var array<string, array{starts_after: string, starts_before: string, time_scope: string}>
     */
    public array $quickFilterRanges = [];

    public bool $showNearbyButton = true;

    public ?string $selectedHomeQuickFilter = null;

    private bool $applyingHomeQuickFilter = false;

    /**
     * @param  array<string, array{starts_after: string, starts_before: string, time_scope: string}>  $quickFilterRanges
     */
    public function mount(array $quickFilterRanges = [], bool $showNearbyButton = true): void
    {
        parent::mount();

        $this->quickFilterRanges = $quickFilterRanges;
        $this->showNearbyButton = $showNearbyButton;

        $this->applyHomepageDefaults();
    }

    public function getAttributes(): AttributeCollection
    {
        return parent::getAttributes()
            ->reject(fn (object $attribute): bool => $attribute instanceof BaseLayout || $attribute instanceof BaseTitle);
    }

    /**
     * Fill unset filters with the homepage defaults. Explicit state (own
     * selection, shared URL, saved search) always wins.
     *
     * The form state keeps the raw select values ('1'/'0') exactly as a user
     * selection would leave them: Filament renders a boolean false as empty.
     */
    private function applyHomepageDefaults(): void
    {
        $defaultLanguageCodes = $this->defaultHomepageLanguageCodes();

        if ($defaultLanguageCodes !== [] && $this->normalizeStringArray($this->filterData['language_codes'] ?? null) === []) {
            $this->filterData['language_codes'] = $defaultLanguageCodes;
            $this->language_codes = $defaultLanguageCodes;
        }

        if ($this->normalizeNullableString($this->filterData['gender'] ?? null) === null) {
            $this->filterData['gender'] = EventGenderRestriction::All->value;
            $this->gender = EventGenderRestriction::All->value;
        }

        if ($this->normalizeStringArray($this->filterData['age_group'] ?? null) === []) {
            $this->filterData['age_group'] = [EventAgeGroup::AllAges->value];
            $this->age_group = [EventAgeGroup::AllAges->value];
        }

        if ($this->normalizeNullableBoolean($this->filterData['children_allowed'] ?? null) === null) {
            $this->filterData['children_allowed'] = '1';
            $this->children_allowed = true;
        }

        if ($this->normalizeNullableBoolean($this->filterData['is_muslim_only'] ?? null) === null) {
            $this->filterData['is_muslim_only'] = '0';
            $this->is_muslim_only = false;
        }
    }

    /**
     * @return list<string>
     */
    private function defaultHomepageLanguageCodes(): array
    {
        if (app(VisitorCountryResolver::class)->resolveCode() !== 'MY') {
            return [];
        }

        return ['ms'];
    }

    /**
     * Whether a defaulted list filter holds a narrowing selection: a
     * non-empty value that differs from the homepage default. Both the
     * default and the fully-cleared state read as inactive.
     */
    private function listIsActiveFilter(string $key, mixed $current): bool
    {
        if ($this->normalizeStringArray($current) === []) {
            return false;
        }

        return $this->listDiffersFromHomepageDefault($key, $current);
    }

    private function listDiffersFromHomepageDefault(string $key, mixed $current): bool
    {
        $currentList = $this->normalizeStringArray($current);
        sort($currentList);

        $defaultList = match ($key) {
            'language_codes' => $this->defaultHomepageLanguageCodes(),
            'age_group' => [EventAgeGroup::AllAges->value],
            default => [],
        };
        sort($defaultList);

        return $currentList !== $defaultList;
    }

    public function activeFilterCount(): int
    {
        $areaAssignments = $this->area_assignments;
        $defaultCountryId = $this->defaultCountryId();
        $hasCountryScope = filled($this->country_id) && $this->country_id !== $defaultCountryId;
        $gender = $this->normalizeNullableString($this->gender);
        $childrenAllowed = $this->normalizeNullableBoolean($this->children_allowed);
        $isMuslimOnly = $this->normalizeNullableBoolean($this->is_muslim_only);

        return collect([
            filled($this->search),
            $hasCountryScope,
            filled($this->state_id),
            filled($areaAssignments['administrative_division'] ?? null),
            filled($areaAssignments['administrative_district'] ?? null),
            filled($areaAssignments['administrative_subdivision'] ?? null),
            filled($areaAssignments['postal_locality'] ?? null),
            filled($this->institution_id),
            $this->listIsActiveFilter('language_codes', $this->language_codes),
            count(array_filter((array) $this->event_category_ids)) > 0,
            count(array_filter((array) $this->event_format)) > 0,
            $gender !== null && $gender !== EventGenderRestriction::All->value,
            $this->listIsActiveFilter('age_group', $this->age_group),
            $childrenAllowed !== null && $childrenAllowed !== true,
            $isMuslimOnly !== null && $isMuslimOnly !== false,
            count(array_filter($this->person_ids)) > 0,
            count(array_filter($this->key_person_roles)) > 0,
            count(array_filter($this->person_in_charge_ids)) > 0,
            filled($this->person_in_charge_search),
            filled($this->person_name_search),
            count(array_filter($this->moderator_ids)) > 0,
            count(array_filter($this->imam_ids)) > 0,
            count(array_filter($this->khatib_ids)) > 0,
            count(array_filter($this->bilal_ids)) > 0,
            count(array_filter($this->discipline_tag_ids)) > 0,
            count(array_filter($this->domain_tag_ids)) > 0,
            count(array_filter($this->source_tag_ids)) > 0,
            count(array_filter($this->issue_tag_ids)) > 0,
            count(array_filter($this->reference_ids)) > 0,
            filled($this->starts_after),
            filled($this->starts_before),
            filled($this->prayer_time),
            filled($this->timing_mode),
            filled($this->starts_time_from),
            filled($this->starts_time_until),
            $this->has_event_url !== null,
            $this->has_live_url !== null,
            $this->has_end_time !== null,
            ($this->time_scope ?? 'upcoming') !== 'upcoming',
            filled($this->lat),
            $this->sort !== 'time',
        ])->filter()->count();
    }

    public function updatedFilterData(mixed $value = null, ?string $key = null): void
    {
        if (! $this->applyingHomeQuickFilter) {
            $this->selectedHomeQuickFilter = null;
        }

        parent::updatedFilterData($value, $key);

        $this->dispatchHomeQuickFilterState();
    }

    /** Apply a homepage shortcut delivered as a browser event. */
    #[On('home-quick-filter-selected')]
    public function receiveHomeQuickFilterSelection(string $quickFilterKey): void
    {
        $this->applyHomeQuickFilter($quickFilterKey);
    }

    /** Apply an approved homepage shortcut to the existing event filter state. */
    public function applyHomeQuickFilter(string $quickFilterKey): void
    {
        $this->applyingHomeQuickFilter = true;

        try {
            if ($quickFilterKey === 'malam_ini') {
                $this->selectedHomeQuickFilter = 'malam_ini';
                $this->filterData['date_shortcut'] = 'today';
                $this->filterData['starts_after'] = null;
                $this->filterData['starts_before'] = null;
                $this->filterData['time_scope'] = 'all';
                $this->filterData['timing_mode'] = TimingMode::PrayerRelative->value;
                $this->filterData['prayer_time'] = [
                    EventPrayerTime::SelepasMaghrib->value,
                    EventPrayerTime::SelepasIsyak->value,
                ];
                $this->filterData['starts_time_from'] = null;
                $this->filterData['starts_time_until'] = null;
                $this->updatedFilterData(null, 'date_shortcut');

                return;
            }

            if (in_array($quickFilterKey, ['today', 'tomorrow'], true)) {
                $this->clearMalamIniPrayerSelectionWhenLeavingToday();
                $this->selectedHomeQuickFilter = $quickFilterKey;
                $this->filterData['date_shortcut'] = $quickFilterKey;
                $this->filterData['starts_after'] = null;
                $this->filterData['starts_before'] = null;
                $this->filterData['time_scope'] = 'all';
                $this->updatedFilterData(null, 'date_shortcut');

                return;
            }

            if (in_array($quickFilterKey, ['this_week', 'weekend', 'this_month', 'next_week', 'next_month'], true)) {
                $this->clearMalamIniPrayerSelectionWhenLeavingToday();
                $this->selectedHomeQuickFilter = $quickFilterKey;
                $this->filterData['date_shortcut'] = $quickFilterKey === 'weekend'
                    ? 'this_weekend'
                    : $quickFilterKey;
                $this->filterData['starts_after'] = null;
                $this->filterData['starts_before'] = null;
                $this->filterData['time_scope'] = 'all';
                $this->updatedFilterData(null, 'date_shortcut');

                return;
            }

            if ($quickFilterKey === 'popular') {
                $this->selectedHomeQuickFilter = 'popular';
                $this->filterData['sort'] = 'popular';
                $this->updatedFilterData(null, 'sort');
            }
        } finally {
            $this->applyingHomeQuickFilter = false;
        }
    }

    /** @return list<string> */
    public function activeHomeQuickFilters(): array
    {
        $activeQuickFilters = [];

        if (filled($this->lat) && filled($this->lng)) {
            $activeQuickFilters[] = 'nearby';
        }

        if ($this->sort === 'popular') {
            $activeQuickFilters[] = 'popular';
        }

        if (($this->time_scope ?? 'upcoming') === 'all') {
            $startsAfter = (string) $this->starts_after;
            $startsBefore = (string) $this->starts_before;
            $selectedDateShortcut = $this->selectedHomeQuickFilter;
            $preferredDateShortcut = match ($this->date_shortcut ?? 'all') {
                'today' => $this->matchesMalamIniPrayerSelection() ? 'malam_ini' : 'today',
                'tomorrow' => 'tomorrow',
                'this_week' => 'this_week',
                'this_weekend' => 'weekend',
                'this_month' => 'this_month',
                'next_week' => 'next_week',
                'next_month' => 'next_month',
                default => null,
            };

            if ($selectedDateShortcut !== null) {
                if ($this->matchesQuickFilterDateRange($selectedDateShortcut, $startsAfter, $startsBefore)) {
                    $activeQuickFilters[] = $selectedDateShortcut;
                }
            } elseif ($preferredDateShortcut !== null) {
                if ($this->matchesQuickFilterDateRange($preferredDateShortcut, $startsAfter, $startsBefore)) {
                    $activeQuickFilters[] = $preferredDateShortcut;
                }
            } else {
                foreach ($this->quickFilterRanges as $quickFilterKey => $quickFilterRange) {
                    if (
                        $this->matchesQuickFilterDateRange($quickFilterKey, $startsAfter, $startsBefore)
                    ) {
                        $activeQuickFilters[] = $quickFilterKey;

                        break;
                    }
                }
            }
        }

        return $activeQuickFilters;
    }

    public function setLocation(float $lat, float $lng): void
    {
        parent::setLocation($lat, $lng);

        $this->dispatchHomeQuickFilterState();
    }

    public function clearLocation(): void
    {
        parent::clearLocation();

        $this->dispatchHomeQuickFilterState();
    }

    public function clearAllFilters(): void
    {
        parent::clearAllFilters();

        // "Set semula" fully clears the form: homepage defaults are not
        // restored. The country keeps the visitor default the parent
        // restored (how the page landed); only the sub-country geography
        // is released.
        $this->state_id = null;
        $this->area_assignments = [];
        $this->filterData['state_id'] = null;
        $this->filterData['area_assignments'] = array_map(
            static fn (): ?string => null,
            (array) ($this->filterData['area_assignments'] ?? [])
        );

        $this->selectedHomeQuickFilter = null;

        $this->dispatchHomeQuickFilterState();
    }

    public function hasActiveFilters(): bool
    {
        return $this->activeFilterCount() > 0;
    }

    public function render(): View
    {
        return view('livewire.components.event-filters');
    }

    private function dispatchHomeQuickFilterState(): void
    {
        $this->dispatch('home-quick-filter-state-updated', activeQuickFilters: $this->activeHomeQuickFilters());
    }

    private function matchesQuickFilterDateRange(string $quickFilterKey, string $startsAfter, string $startsBefore): bool
    {
        $quickFilterRange = $this->quickFilterRanges[$quickFilterKey] ?? null;

        $matchesDateRange = is_array($quickFilterRange)
            && $startsAfter === $quickFilterRange['starts_after']
            && $startsBefore === $quickFilterRange['starts_before'];

        return $matchesDateRange
            && ($quickFilterKey !== 'malam_ini' || $this->matchesMalamIniPrayerSelection());
    }

    private function matchesMalamIniPrayerSelection(): bool
    {
        if ($this->timing_mode !== TimingMode::PrayerRelative->value) {
            return false;
        }

        $selectedPrayerTimes = [];

        foreach ((array) $this->prayer_time as $prayerTime) {
            if (is_string($prayerTime) && $prayerTime !== '') {
                $selectedPrayerTimes[] = $prayerTime;
            }
        }

        $selectedPrayerTimes = array_values(array_unique($selectedPrayerTimes));
        sort($selectedPrayerTimes);

        $malamIniPrayerTimes = [
            EventPrayerTime::SelepasMaghrib->value,
            EventPrayerTime::SelepasIsyak->value,
        ];
        sort($malamIniPrayerTimes);

        return $selectedPrayerTimes === $malamIniPrayerTimes;
    }

    private function clearMalamIniPrayerSelectionWhenLeavingToday(): void
    {
        $isCurrentTodayShortcut = in_array($this->selectedHomeQuickFilter, ['today', 'malam_ini'], true)
            || ($this->date_shortcut ?? 'all') === 'today';

        if (! $isCurrentTodayShortcut || ! $this->matchesMalamIniPrayerSelection()) {
            return;
        }

        $this->filterData['timing_mode'] = null;
        $this->filterData['prayer_time'] = [];
    }
}
