<?php

namespace App\Support\Api\Frontend;

use App\Data\Api\Frontend\Search\CountryData;
use App\Enums\ContactCategory;
use App\Enums\EventKeyPersonRole;
use App\Enums\SocialMediaPlatform;
use App\Models\Address;
use App\Models\Contact;
use App\Models\SocialMedia;
use App\Support\Location\AddressHierarchyFormatter;
use BackedEnum;
use Illuminate\Support\Str;

class SearchPayloadTransformer
{
    /**
     * @return array{country_id: ?int, state_id: ?int, district_id: ?int, subdistrict_id: ?int}|null
     */
    public function addressFilterData(?Address $address): ?array
    {
        if (! $address instanceof Address) {
            return null;
        }

        return [
            'country_id' => is_numeric($address->country_id) ? (int) $address->country_id : null,
            'state_id' => is_numeric($address->state_id) ? (int) $address->state_id : null,
            'district_id' => is_numeric($address->district_id) ? (int) $address->district_id : null,
            'subdistrict_id' => is_numeric($address->subdistrict_id) ? (int) $address->subdistrict_id : null,
        ];
    }

    /**
     * @return array{id: int, name: string, iso2: string, key: ?string}|null
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
            $category = $contact instanceof Contact ? $contact->category : data_get($contact, 'category');
            $categoryValue = $this->enumValue($category);
            $categoryEnum = ContactCategory::tryFrom($categoryValue);
            $isPublic = (bool) data_get($contact, 'is_public', false);

            if (! $isPublic) {
                continue;
            }

            $items[] = [
                'category' => $categoryValue,
                'label' => $categoryEnum?->getLabel() ?? Str::headline($categoryValue),
                'value' => (string) data_get($contact, 'value', ''),
                'type' => $this->enumValue(data_get($contact, 'type')),
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
            $platformValue = $this->enumValue($socialMedia instanceof SocialMedia ? $socialMedia->platform : data_get($socialMedia, 'platform'));
            $platformEnum = SocialMediaPlatform::tryFrom($platformValue);
            $resolvedUrl = (string) data_get($socialMedia, 'resolved_url', data_get($socialMedia, 'url', ''));

            if ($platformValue === '' || $resolvedUrl === '') {
                continue;
            }

            $items[] = [
                'platform' => $platformValue,
                'platform_label' => $platformEnum?->getLabel() ?? Str::headline($platformValue),
                'url' => (string) data_get($socialMedia, 'url', ''),
                'resolved_url' => $resolvedUrl,
                'username' => (string) data_get($socialMedia, 'username', ''),
                'display_username' => (string) data_get($socialMedia, 'display_username', ''),
                'icon_url' => (string) data_get($socialMedia, 'icon_url', ''),
            ];
        }

        return $items;
    }

    public function addressLocation(?Address $address): ?string
    {
        $location = AddressHierarchyFormatter::format($address);

        return $location !== '' ? $location : null;
    }

    public function keyPersonRoleLabel(mixed $role): string
    {
        if ($role instanceof EventKeyPersonRole) {
            return $role->getLabel();
        }

        if ($role instanceof BackedEnum && is_string($role->value)) {
            return EventKeyPersonRole::tryFrom($role->value)?->getLabel() ?? Str::headline($role->value);
        }

        if (is_string($role) && $role !== '') {
            return EventKeyPersonRole::tryFrom($role)?->getLabel() ?? Str::headline($role);
        }

        return '';
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
