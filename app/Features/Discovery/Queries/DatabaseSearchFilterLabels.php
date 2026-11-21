<?php

declare(strict_types=1);

namespace App\Features\Discovery\Queries;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Events\Models\EventTerm;
use App\Features\Discovery\Api\SearchFilterLabels;
use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\Orm\PlaceLookup;
use App\Features\References\Api\ReferenceLookup;
use Illuminate\Support\Str;

final class DatabaseSearchFilterLabels implements SearchFilterLabels
{
    public function __construct(
        private readonly InstitutionLookup $institutions,
        private readonly PersonLookup $persons,
        private readonly PlaceLookup $places,
        private readonly ReferenceLookup $references,
    ) {}

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
            $this->institutionNames[$id] = $this->institutions->displayName($id);
        }

        return $this->institutionNames[$id];
    }

    private function venueName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->venueNames)) {
            $this->venueNames[$id] = $this->places->venueName($id);
        }

        return $this->venueNames[$id];
    }

    private function personName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->personNames)) {
            $this->personNames[$id] = $this->persons->namesByIds([$id])[$id] ?? null;
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
            $this->referenceTitles[$id] = $this->references->title($id);
        }

        return $this->referenceTitles[$id];
    }
}
