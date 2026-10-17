<?php

declare(strict_types=1);

namespace App\Actions\Institutions;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Actions\Location\DiscoverWebsiteSocialProfilesAction;
use App\Actions\Location\FetchGooglePlaceDetailsAction;
use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use App\Forms\SharedFormSchema;
use App\Support\Location\GooglePlaceIdentity;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveInstitutionMapsPlaceSelectionAction
{
    use AsAction;

    public function __construct(
        private readonly FetchGooglePlaceDetailsAction $fetchGooglePlaceDetailsAction,
        private readonly InferInstitutionTypeAction $inferInstitutionTypeAction,
        private readonly ResolveGooglePlaceSelectionAction $resolveGooglePlaceSelectionAction,
        private readonly DiscoverWebsiteSocialProfilesAction $discoverWebsiteSocialProfilesAction,
    ) {}

    /**
     * Resolve one Places result into the data shared by contribution and quick-add forms.
     *
     * @return array{
     *     place_id: string,
     *     address: array<string, mixed>,
     *     display_name: string|null,
     *     inferred_type: string|null,
     *     website_url: string|null,
     *     autofilled_contacts: list<array<string, mixed>>,
     *     autofilled_social_profiles: list<array<string, mixed>>
     * }
     */
    public function handle(string $placeId, string $validationKey): array
    {
        $place = $this->fetchGooglePlaceDetailsAction->handle($placeId, $validationKey);
        $identity = GooglePlaceIdentity::fromPlace($place);
        $location = is_array($place['location'] ?? null) ? $place['location'] : [];
        $resolvedAddress = $this->resolveGooglePlaceSelectionAction->handle([
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
        $resolvedAddress['cascade_reset_guard'] = SharedFormSchema::publicLocationPickerCascadeResetGuard();

        $websiteUrl = $this->webUrl($place['websiteUri'] ?? null);
        $phone = $this->webText($place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? null);
        $autofilledContacts = [];

        if ($websiteUrl !== null) {
            $autofilledContacts[] = [
                'type' => ContactMethodType::Website->value,
                'value' => $websiteUrl,
                'label' => __('Official website'),
                'purpose' => ContactPurpose::General->value,
                'is_primary' => false,
                'is_public' => true,
            ];
        }

        if ($phone !== null) {
            $autofilledContacts[] = [
                'type' => ContactMethodType::Phone->value,
                'phone_value' => $phone,
                'purpose' => ContactPurpose::General->value,
                'is_primary' => false,
                'is_public' => true,
            ];
        }

        return [
            'place_id' => $identity['google_place_id'],
            'address' => $resolvedAddress,
            'display_name' => $this->webText(data_get($place, 'displayName.text'))
                ?? $this->webText($place['displayName'] ?? null),
            'inferred_type' => $this->inferInstitutionTypeAction->handle($place)?->value,
            'website_url' => $websiteUrl,
            'autofilled_contacts' => $autofilledContacts,
            'autofilled_social_profiles' => $websiteUrl !== null
                ? $this->discoverWebsiteSocialProfilesAction->handle($websiteUrl)
                : [],
        ];
    }

    private function webUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $value
            : null;
    }

    private function webText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
