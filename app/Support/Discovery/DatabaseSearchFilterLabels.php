<?php

declare(strict_types=1);

namespace App\Support\Discovery;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Events\Models\EventTerm;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Shared\Discovery\SearchFilterLabels;
use Illuminate\Support\Str;

final class DatabaseSearchFilterLabels implements SearchFilterLabels
{
    /**
     * @var array<string, string|null>
     */
    private array $countryNames = [];

    /**
     * @var array<string, string|null>
     */
    private array $stateNames = [];

    /**
     * @var array<string, string|null>
     */
    private array $cityNames = [];

    /**
     * @var array<string, string|null>
     */
    private array $adminAreaNames = [];

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
    private array $personNames = [];

    public function label(string $filterKey, string $id): ?string
    {
        return match ($filterKey) {
            'country_id' => $this->countryName($id),
            'state_id' => $this->stateName($id),
            'city_id' => $this->cityName($id),
            'area_assignments' => $this->adminAreaName($id),
            'institution_id' => $this->institutionName($id),
            'venue_id' => $this->venueName($id),
            'person_ids', 'person_in_charge_ids', 'moderator_ids', 'imam_ids', 'khatib_ids', 'bilal_ids' => $this->personName($id),
            'domain_tag_ids', 'discipline_tag_ids', 'source_tag_ids', 'issue_tag_ids' => $this->tagName($id),
            'reference_ids' => $this->referenceTitle($id),
            default => null,
        };
    }

    private function countryName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->countryNames)) {
            $this->countryNames[$id] = AddressCountry::query()->whereKey($id)->value('name');
        }

        return $this->countryNames[$id];
    }

    private function stateName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->stateNames)) {
            $this->stateNames[$id] = State::query()->whereKey($id)->value('name');
        }

        return $this->stateNames[$id];
    }

    private function cityName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->cityNames)) {
            $this->cityNames[$id] = City::query()->whereKey($id)->value('name');
        }

        return $this->cityNames[$id];
    }

    private function adminAreaName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->adminAreaNames)) {
            $this->adminAreaNames[$id] = AddressArea::query()->whereKey($id)->value('name');
        }

        return $this->adminAreaNames[$id];
    }

    private function institutionName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->institutionNames)) {
            $this->institutionNames[$id] = Institution::query()
                ->whereKey($id)
                ->with('names')->first(['id', 'name'])
                ?->display_name;
        }

        return $this->institutionNames[$id];
    }

    private function venueName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->venueNames)) {
            $this->venueNames[$id] = Venue::query()->whereKey($id)->value('name');
        }

        return $this->venueNames[$id];
    }

    private function personName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->personNames)) {
            $this->personNames[$id] = Person::query()->whereKey($id)->value('name');
        }

        return $this->personNames[$id];
    }

    private function tagName(string $id): ?string
    {
        if (! array_key_exists($id, $this->tagNames)) {
            $term = EventTerm::query()->whereKey($id)->first(['id', 'name']);

            $this->tagNames[$id] = $term instanceof EventTerm ? (string) $term->name : null;
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
}
