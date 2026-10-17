<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Location\FetchGooglePlaceDetailsAction;
use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use App\Forms\SharedFormSchema;
use App\Support\Location\GooglePlaceIdentity;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

trait InteractsWithLocationPickerSelection
{
    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyLocationPickerSelection(
        string $statePath,
        array $selection,
        FetchGooglePlaceDetailsAction $fetchGooglePlaceDetailsAction,
        ResolveGooglePlaceSelectionAction $resolveGooglePlaceSelectionAction,
    ): array {
        $currentAddress = data_get($this, $statePath);
        $currentAddress = is_array($currentAddress) ? $currentAddress : [];

        $placeId = $selection['placeId'] ?? null;

        if (! is_string($placeId) || trim($placeId) === '') {
            throw ValidationException::withMessages([
                $statePath.'.google_place_id' => __('Select a location from the Google Maps search.'),
            ]);
        }

        $place = $fetchGooglePlaceDetailsAction->handle($placeId, $statePath.'.google_place_id');
        $identity = GooglePlaceIdentity::fromPlace($place);
        $location = is_array($place['location'] ?? null) ? $place['location'] : [];
        $resolvedAddress = $resolveGooglePlaceSelectionAction->handle([
            'placeId' => $identity['google_place_id'],
            'displayName' => $place['displayName'] ?? null,
            'formattedAddress' => $identity['formatted_address'],
            'googleMapsURI' => $place['googleMapsUri'] ?? null,
            'googleFeatureId' => $identity['google_feature_id'],
            'googleCid' => $identity['google_cid'],
            'googleEntityId' => $identity['google_entity_id'],
            'location' => [
                'lat' => $location['latitude'] ?? null,
                'lng' => $location['longitude'] ?? null,
            ],
            'addressComponents' => $identity['components'],
            'fallbackCountryId' => $currentAddress['country_id'] ?? null,
        ]);
        $resolvedAddress = array_merge($resolvedAddress, Arr::only($identity, [
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'components',
            'formatted_address',
            'metadata',
            'navigation_links',
        ]));

        $resolvedAddress['area_assignments'] = array_merge(
            array_fill_keys(SharedFormSchema::entryAreaRoles(), null),
            $resolvedAddress['area_assignments'] ?? [],
        );

        data_set($this, $statePath, array_merge($currentAddress, $resolvedAddress, [
            'cascade_reset_guard' => SharedFormSchema::publicLocationPickerCascadeResetGuard(),
        ]));

        return $resolvedAddress;
    }
}
