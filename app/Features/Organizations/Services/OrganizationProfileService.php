<?php

declare(strict_types=1);

namespace App\Features\Organizations\Services;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use App\Platform\Models\MorphModelResolver;

/**
 * Canonical organization profile reads over the addressing package.
 *
 * Organization is a package-owned aggregate without the addressable trait, so
 * this service owns the canonical primary-address attachment queries for the
 * `organization` morph target. Writes live in SaveOrganizationAddressAction.
 */
final readonly class OrganizationProfileService
{
    public function addressableType(): string
    {
        $modelClass = MorphModelResolver::resolve('organization');

        return (string) (new $modelClass)->getMorphClass();
    }

    public function countryIdFor(string $organizationId): ?string
    {
        $countryId = $this->primaryAddressFor($organizationId)?->getAttribute('country_id');

        return is_string($countryId) && $countryId !== '' ? $countryId : null;
    }

    public function primaryAddressFor(string $organizationId): ?Address
    {
        $address = $this->primaryAttachmentFor($organizationId)?->address;

        return $address instanceof Address ? $address : null;
    }

    public function primaryAttachmentFor(string $organizationId): ?Addressable
    {
        $attachment = Addressable::query()
            ->where('addressable_type', $this->addressableType())
            ->where('addressable_id', $organizationId)
            ->where('type', 'primary')
            ->where('is_primary', true)
            ->validNow()
            ->first();

        return $attachment instanceof Addressable ? $attachment : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function addressStateFor(string $organizationId): ?array
    {
        $address = $this->primaryAddressFor($organizationId);

        if (! $address instanceof Address) {
            return null;
        }

        return [
            'country_id' => $address->getAttribute('country_id'),
            'state_id' => $address->getAttribute('state_id'),
            'city_id' => $address->getAttribute('city_id'),
            'line1' => $address->getAttribute('line1'),
            'line2' => $address->getAttribute('line2'),
            'postcode' => $address->getAttribute('postcode'),
            'country' => $address->getAttribute('country'),
            'state' => $address->getAttribute('state'),
            'city' => $address->getAttribute('city'),
        ];
    }
}
