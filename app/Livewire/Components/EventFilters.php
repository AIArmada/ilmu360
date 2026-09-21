<?php

namespace App\Livewire\Components;

use App\Livewire\Pages\Events\Index as EventsIndex;
use Illuminate\Contracts\View\View;
use Livewire\Features\SupportPageComponents\BaseLayout;
use Livewire\Features\SupportPageComponents\BaseTitle;

class EventFilters extends EventsIndex
{
    public function getAttributes()
    {
        return parent::getAttributes()
            ->reject(fn (object $attribute): bool => $attribute instanceof BaseLayout || $attribute instanceof BaseTitle);
    }

    public function activeFilterCount(): int
    {
        $areaAssignments = $this->area_assignments;
        $defaultCountryId = $this->defaultCountryId();
        $hasCountryScope = filled($this->country_id) && $this->country_id !== $defaultCountryId;

        return collect([
            filled($this->search),
            $hasCountryScope,
            filled($this->state_id),
            filled($areaAssignments['administrative_division'] ?? null),
            filled($areaAssignments['administrative_district'] ?? null),
            filled($areaAssignments['administrative_subdivision'] ?? null),
            filled($areaAssignments['postal_locality'] ?? null),
            filled($this->institution_id),
            count(array_filter((array) $this->language_codes)) > 0,
            count(array_filter((array) $this->event_category_ids)) > 0,
            count(array_filter((array) $this->event_format)) > 0,
            filled($this->gender),
            count(array_filter($this->age_group)) > 0,
            $this->children_allowed !== null,
            $this->is_muslim_only !== null,
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
        ])->filter()->count();
    }

    public function hasActiveFilters(): bool
    {
        return $this->activeFilterCount() > 0;
    }

    public function render(): View
    {
        return view('livewire.components.event-filters');
    }
}
