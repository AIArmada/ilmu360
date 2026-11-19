<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Support;

use AIArmada\Addressing\Models\Address;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use App\Platform\Location\AddressAssignments;
use App\Platform\Ui\Forms\SharedFormSchema;
use BackedEnum;
use Illuminate\Support\Collection;

trait BuildsContributionSubjectState
{
    /** @return array<string, mixed> */
    private function addressState(?Address $address): array
    {
        if (! $address instanceof Address) {
            return SharedFormSchema::hydrateAddressFormState([
                'country_id' => null,
                'area_assignments' => [],
                'line1' => null,
                'line2' => null,
                'postcode' => null,
                'latitude' => null,
                'longitude' => null,
                'google_maps_url' => null,
                'google_place_id' => null,
                'google_feature_id' => null,
                'google_cid' => null,
                'google_entity_id' => null,
                'waze_url' => null,
            ]);
        }

        return SharedFormSchema::hydrateAddressFormState([
            'country_id' => $address->country_id,
            'state_id' => $address->state_id,
            'city_id' => $address->city_id,
            'state' => $address->state,
            'city' => $address->city,
            'area_assignments' => AddressAssignments::forAddress($address),
            'line1' => $address->line1,
            'line2' => $address->line2,
            'postcode' => $address->postcode,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'google_maps_url' => $address->google_maps_url,
            'google_place_id' => $address->google_place_id,
            'google_feature_id' => $address->google_feature_id,
            'google_cid' => $address->google_cid,
            'google_entity_id' => $address->google_entity_id,
            'waze_url' => $address->waze_url,
        ]);
    }

    /**
     * @param  Collection<int, ContactMethod>  $contacts
     * @return list<array<string, mixed>>
     */
    private function contactMethodsState(Collection $contacts): array
    {
        return $contacts
            ->sortBy('created_at')
            ->map(fn (ContactMethod $contact): array => [
                'type' => $contact->type instanceof BackedEnum ? $contact->type->value : (string) $contact->type,
                'purpose' => $contact->purpose instanceof BackedEnum ? $contact->purpose->value : (string) $contact->purpose,
                'value' => $contact->value,
                'is_primary' => (bool) $contact->is_primary,
                'is_public' => (bool) $contact->is_public,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SocialProfile>  $entries
     * @return list<array<string, mixed>>
     */
    private function socialMediaState(Collection $entries): array
    {
        return $entries
            ->sortBy('created_at')
            ->map(fn (SocialProfile $entry): array => [
                'platform' => $entry->platform instanceof BackedEnum ? $entry->platform->value : (string) $entry->platform,
                'handle' => $entry->handle,
                'url' => $entry->url,
                'is_primary' => (bool) $entry->is_primary,
                'is_public' => (bool) $entry->is_public,
            ])
            ->values()
            ->all();
    }
}
