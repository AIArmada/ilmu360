<?php

namespace App\Actions\Persons;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\LocationSlugSegments;
use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Person;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GeneratePersonSlugAction
{
    use AsAction;

    public function __construct(
        private readonly SyncSlugRedirectAction $syncSlugRedirectAction,
    ) {}

    public function syncPersonSlugsForName(string $name): bool
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            return false;
        }

        $persons = Person::query()
            ->where('persons.name', $normalizedName)
            ->with(['addresses'])
            ->get();

        return StableModelOrder::sync($persons, fn (Person $person): bool => $this->syncPersonSlug($person));
    }

    public function syncPersonSlug(Person $person): bool
    {
        $slug = $this->forPerson($person);

        return CanonicalSlug::persist($person, $slug, $this->syncSlugRedirectAction);
    }

    /**
     * Recompute a person's slug after one of their addresses was deleted.
     * When no addresses remain, the deleted address's location is reused so
     * the public slug keeps its country suffix instead of collapsing to the
     * bare name.
     */
    public function syncPersonSlugAfterAddressDeleted(Person $person, Address $deletedAddress): bool
    {
        $person->unsetRelation('addresses');
        $person->load(['addresses']);

        if ($person->primaryAddress() !== null) {
            return $this->syncPersonSlug($person);
        }

        $slug = $this->handle(
            $person->name,
            [
                'city' => $deletedAddress->city,
                'state' => $deletedAddress->state,
                'country_id' => $deletedAddress->country_id,
                'country_code' => $deletedAddress->country_code,
                'family_name' => $person->family_name,
                'middle_name' => $person->middle_name,
            ],
            (string) $person->getKey(),
        );

        return CanonicalSlug::persist($person, $slug, $this->syncSlugRedirectAction);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $name, array $payload = [], ?string $ignorePersonId = null): string
    {
        $normalizedName = trim($name);
        $displayName = $this->displayName(
            $normalizedName,
            $payload['middle_name'] ?? null,
            $payload['family_name'] ?? null,
        );
        $nameSlug = Str::slug($displayName !== '' ? $displayName : $normalizedName);

        if ($nameSlug === '') {
            $nameSlug = 'person';
        }

        $locationSuffix = $this->locationSuffix($payload);

        return UniqueSlug::build(
            Person::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignorePersonId,
        );
    }

    public function forPerson(Person $person): string
    {
        $person->unsetRelation('addresses');
        $person->load(['addresses']);

        $address = $person->primaryAddress();

        return $this->handle(
            $person->name,
            [
                'city' => $address?->city,
                'state' => $address?->state,
                'country_id' => $address?->country_id,
                'country_code' => $address?->country_code,
                'family_name' => $person->family_name,
                'middle_name' => $person->middle_name,
            ],
            (string) $person->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function locationSuffix(array $payload): string
    {
        return LocationSlugSegments::suffix($payload, preferLiteralCountry: false);
    }

    private function displayName(string $name, mixed $middleName = null, mixed $familyName = null): string
    {
        return Person::formatDisplayedName(
            $name,
            is_string($middleName) ? $middleName : null,
            is_string($familyName) ? $familyName : null,
        );
    }
}
