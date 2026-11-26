<?php

declare(strict_types=1);

namespace App\Features\Discovery\Queries;

use AIArmada\Addressing\Features\Geography\Models\City;
use AIArmada\Addressing\Features\Geography\Models\State;
use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use App\Features\Discovery\Api\SearchFilterLabels;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\References\Api\ReferenceLookup;
use App\Shared\Cache\SelectionCatalog;
use Illuminate\Support\Str;

final class DatabaseSearchFilterLabels implements SearchFilterLabels
{
    public function __construct(
        private readonly InstitutionLookup $institutions,
        private readonly PersonLookup $persons,
        private readonly PlaceCatalog $places,
        private readonly ReferenceLookup $references,
        private readonly SelectionCatalog $selectionCatalog,
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
            'domain_tag_ids' => $this->tagName('domain', $id),
            'discipline_tag_ids' => $this->tagName('discipline', $id),
            'source_tag_ids' => $this->tagName('source', $id),
            'issue_tag_ids' => $this->tagName('issue', $id),
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
            $this->countryNames[$id] = $this->selectionCatalog->countryOptions()[$id] ?? null;
        }

        return $this->countryNames[$id];
    }

    private function stateName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->stateNames)) {
            $this->stateNames[$id] = $this->selectionCatalog->rememberAddressValue(
                "state-name:{$id}",
                static function () use ($id): ?string {
                    $name = State::query()->whereKey($id)->value('name');

                    return is_string($name) ? $name : null;
                },
            );
        }

        return $this->stateNames[$id];
    }

    private function cityName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->cityNames)) {
            $this->cityNames[$id] = $this->selectionCatalog->rememberAddressValue(
                "city-name:{$id}",
                static function () use ($id): ?string {
                    $name = City::query()->whereKey($id)->value('name');

                    return is_string($name) ? $name : null;
                },
            );
        }

        return $this->cityNames[$id];
    }

    private function adminAreaName(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if (! array_key_exists($id, $this->adminAreaNames)) {
            $this->adminAreaNames[$id] = $this->selectionCatalog->addressAreaNamesByIds([$id])[$id] ?? null;
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

    private function tagName(string $taxonomyCode, string $id): ?string
    {
        if (! array_key_exists($id, $this->tagNames)) {
            $cached = $this->selectionCatalog->taxonomyTermOptions($taxonomyCode)[$id] ?? null;

            if ($cached !== null) {
                $this->tagNames[$id] = $cached;
            } else {
                $term = EventTerm::query()->whereKey($id)->first(['id', 'name']);

                $this->tagNames[$id] = $term instanceof EventTerm ? (string) $term->name : null;
            }
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
