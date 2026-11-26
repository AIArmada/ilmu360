<?php

namespace App\Platform\Api\Frontend;

use AIArmada\Addressing\Features\AddressLifecycle\Models\Address;
use AIArmada\Addressing\Features\AddressLifecycle\Support\AddressAssignments;
use AIArmada\Addressing\Features\AddressLifecycle\Support\AddressHierarchyFormatter;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use App\Platform\Api\Frontend\Data\CountryData;
use BackedEnum;
use Illuminate\Support\Str;

class SearchPayloadTransformer
{
    /**
     * @return array{country_id: ?string, state_id: ?string, city_id: ?string, area_assignments: array<string, string>}|null
     */
    public function addressFilterData(?Address $address): ?array
    {
        if (! $address instanceof Address) {
            return null;
        }

        return [
            'country_id' => $this->optionalUuid($address->country_id),
            'state_id' => $this->optionalUuid($address->state_id),
            'city_id' => $this->optionalUuid($address->city_id),
            'area_assignments' => AddressAssignments::forAddress($address),
        ];
    }

    /**
     * @return array{id: string, name: string, iso2: string, key: ?string}|null
     */
    public function countryData(?Address $address): ?array
    {
        return CountryData::fromAddress($address)?->toArray();
    }

    /**
     * @param  iterable<mixed>  $contacts
     * @return list<array<string, mixed>>
     */
    public function contactData(iterable $contacts): array
    {
        $items = [];

        foreach ($contacts as $contact) {
            $typeValue = $this->enumValue($contact instanceof ContactMethod ? $contact->type : data_get($contact, 'type'));
            $isPublic = (bool) data_get($contact, 'is_public', false);

            if (! $isPublic || $typeValue === '') {
                continue;
            }

            $items[] = [
                'type' => $typeValue,
                'label' => ContactMethodType::tryFrom($typeValue)?->label() ?? Str::headline($typeValue),
                'value' => (string) data_get($contact, 'value', ''),
                'purpose' => $this->enumValue(data_get($contact, 'purpose')),
                'is_public' => $isPublic,
            ];
        }

        return $items;
    }

    /**
     * @param  iterable<mixed>  $socialMediaItems
     * @return list<array<string, mixed>>
     */
    public function socialMediaData(iterable $socialMediaItems): array
    {
        $items = [];

        foreach ($socialMediaItems as $socialMedia) {
            $platformValue = $this->enumValue($socialMedia instanceof SocialProfile ? $socialMedia->platform : data_get($socialMedia, 'platform'));
            $platformEnum = SocialPlatform::tryFrom($platformValue);
            $resolvedUrl = $socialMedia instanceof SocialProfile
                ? ($socialMedia->profileUrl() ?? '')
                : (string) data_get($socialMedia, 'normalized_url', data_get($socialMedia, 'url', ''));

            if ($platformValue === '' || $resolvedUrl === '') {
                continue;
            }

            $items[] = [
                'platform' => $platformValue,
                'platform_label' => $platformEnum?->label() ?? Str::headline($platformValue),
                'url' => (string) data_get($socialMedia, 'url', ''),
                'resolved_url' => $resolvedUrl,
                'handle' => (string) data_get($socialMedia, 'handle', ''),
                'display_name' => (string) data_get($socialMedia, 'display_name', ''),
            ];
        }

        return $items;
    }

    public function addressLocation(?Address $address): ?string
    {
        $location = AddressHierarchyFormatter::format($address);

        return $location !== '' ? $location : null;
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function optionalUuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
