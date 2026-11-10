<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\LocationSlugSegments;
use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Features\Directory\Support\DirectoryModel;
use App\Platform\Routing\SyncSlugRedirectAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Directory-owned person slug generation and redirect synchronization.
 */
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

        $persons = DirectoryModel::query('person')
            ->where('persons.name', $normalizedName)
            ->with(['addresses'])
            ->get();

        return StableModelOrder::sync($persons, fn (Model $person): bool => $this->syncPersonSlug($person));
    }

    public function syncPersonSlug(Model $person): bool
    {
        $slug = $this->forPerson($person);

        return CanonicalSlug::persist($person, $slug, $this->syncSlugRedirectAction);
    }

    /**
     * Recompute a person's slug after one of their addresses was deleted.
     * When no addresses remain, the deleted address's location is reused so
     * the public slug keeps its country suffix instead of collapsing to the
     * bare name.
     *
     * @param  array<string, mixed>  $deletedAddress
     */
    public function syncPersonSlugAfterAddressDeleted(Model $person, array $deletedAddress): bool
    {
        $person->unsetRelation('addresses');
        $person->load(['addresses']);

        if ($this->primaryAddress($person) instanceof Address) {
            return $this->syncPersonSlug($person);
        }

        $slug = $this->handle(
            (string) $person->getAttribute('name'),
            [
                'city' => $deletedAddress['city'] ?? null,
                'state' => $deletedAddress['state'] ?? null,
                'country_id' => $deletedAddress['country_id'] ?? null,
                'country_code' => $deletedAddress['country_code'] ?? null,
                'family_name' => $person->getAttribute('family_name'),
                'middle_name' => $person->getAttribute('middle_name'),
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
            DirectoryModel::person(),
            $nameSlug,
            [],
            $locationSuffix,
            $ignorePersonId,
        );
    }

    public function forPerson(Model $person): string
    {
        $person->unsetRelation('addresses');
        $person->load(['addresses']);

        $address = $this->primaryAddress($person);

        return $this->handle(
            (string) $person->getAttribute('name'),
            [
                'city' => $address?->getAttribute('city'),
                'state' => $address?->getAttribute('state'),
                'country_id' => $address?->getAttribute('country_id'),
                'country_code' => $address?->getAttribute('country_code'),
                'family_name' => $person->getAttribute('family_name'),
                'middle_name' => $person->getAttribute('middle_name'),
            ],
            (string) $person->getKey(),
        );
    }

    private function primaryAddress(Model $person): ?Address
    {
        $addresses = $person->getRelation('addresses');

        if (! $addresses instanceof Collection) {
            return null;
        }

        $address = $addresses->first(
            static fn (mixed $address): bool => $address instanceof Address
                && (bool) $address->getRelationValue('pivot')?->getAttribute('is_primary'),
        );

        return $address instanceof Address ? $address : null;
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
        return trim(implode(' ', array_filter([
            trim($name),
            is_string($middleName) ? trim($middleName) : '',
            is_string($familyName) ? trim($familyName) : '',
        ], static fn (string $part): bool => $part !== '')));
    }
}
