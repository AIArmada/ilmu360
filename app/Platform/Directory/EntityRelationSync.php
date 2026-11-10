<?php

declare(strict_types=1);

namespace App\Platform\Directory;

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use App\Platform\Addressing\AddressFormState;
use App\Platform\Location\AddressAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared address/contact/social relation synchronization for directory
 * entities (institutions, speakers, references, venues).
 *
 * The implementation was lifted from the legacy contribution mutation service
 * so the owning feature and the remaining legacy callers share one behavior
 * instead of duplicating relation persistence.
 */
final readonly class EntityRelationSync
{
    public function __construct(
        private AddressCountryResolver $addressingCountryResolver,
    ) {}

    public function syncAddress(Model $model, mixed $addressPayload, bool $allowCountryOnly = false): void
    {
        if (! method_exists($model, 'primaryAddress') || ! method_exists($model, 'attachAddress')) {
            return;
        }

        $payload = is_array($addressPayload) ? $addressPayload : [];
        $hasContent = collect([
            $payload['line1'] ?? null,
            $payload['line2'] ?? null,
            $payload['postcode'] ?? null,
            $payload['area_assignments'] ?? [],
            $payload['latitude'] ?? null,
            $payload['longitude'] ?? null,
            $payload['google_maps_url'] ?? null,
            $payload['google_place_id'] ?? null,
            $payload['waze_url'] ?? null,
            $allowCountryOnly ? ($payload['country_id'] ?? $payload['country_code'] ?? $payload['country_key'] ?? null) : null,
        ])->contains(fn (mixed $value): bool => filled($value));

        /** @var Address|null $existingAddress */
        $existingAddress = $model->primaryAddress();

        if (! $hasContent) {
            if ($existingAddress instanceof Address && method_exists($model, 'addresses')) {
                $model->addresses()->detach($existingAddress->getKey());
                $existingAddress->delete();
            }

            return;
        }

        $countryProvided = AddressFormState::countrySelectionProvided($payload);
        $payload = AddressFormState::prepareAddressPersistenceData($payload);

        $payload = $this->syncCanonicalRegionText($payload);

        $countryId = $this->normalizeUuid($payload['country_id'] ?? null);

        if ($countryId === null && ! $countryProvided) {
            $countryId = $this->normalizeUuid($existingAddress?->country_id)
                ?? $this->addressingCountryResolver->resolveId($existingAddress?->country_code);
        }

        if ($countryId === null) {
            throw ValidationException::withMessages([
                'address.country_id' => $countryProvided
                    ? __('The selected country is invalid.')
                    : __('The address country is required.'),
            ]);
        }

        $stateChanged = $existingAddress instanceof Address
            && $this->normalizeUuid($payload['state_id'] ?? null) !== null
            && $this->normalizeUuid($payload['state_id'] ?? null) !== $this->normalizeUuid($existingAddress->state_id);

        $countryChanged = $existingAddress instanceof Address
            && $countryId !== $this->normalizeUuid($existingAddress->country_id);

        if ($stateChanged && ! array_key_exists('city_id', $payload)) {
            $payload['city_id'] = null;
            $payload['city'] = null;
        }

        if ($existingAddress instanceof Address) {
            foreach ([
                'line1',
                'line2',
                'postcode',
                'state_id',
                'city_id',
                'state',
                'city',
                'latitude',
                'longitude',
                'google_maps_url',
                'google_place_id',
                'google_feature_id',
                'google_cid',
                'google_entity_id',
                'formatted_address',
                'components',
                'metadata',
                'navigation_links',
                'waze_url',
            ] as $field) {
                if (! array_key_exists($field, $payload)) {
                    $payload[$field] = $existingAddress->{$field};
                }
            }
        }

        $assignmentsProvided = array_key_exists('area_assignments', $payload);
        $assignments = AddressAssignments::normalize((array) ($payload['area_assignments'] ?? []));
        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;
        $googlePlaceId = $payload['google_place_id'] ?? null;
        $googleFeatureId = $payload['google_feature_id'] ?? null;
        $googleCid = $payload['google_cid'] ?? null;
        $googleEntityId = $payload['google_entity_id'] ?? null;
        $addressMetadata = $this->resolveAddressMetadata(
            $countryId,
            $assignments,
        );

        $attributes = [
            'country_id' => $countryId,
            'state_id' => $this->normalizeUuid($payload['state_id'] ?? null),
            'city_id' => $this->normalizeUuid($payload['city_id'] ?? null),
            'line1' => $payload['line1'] ?? null,
            'line2' => $payload['line2'] ?? null,
            'postcode' => $payload['postcode'] ?? null,
            'country' => $addressMetadata['country'],
            'country_code' => $addressMetadata['country_code'],
            'state' => $payload['state'] ?? $addressMetadata['state'],
            'city' => $payload['city'] ?? $addressMetadata['city'],
            'latitude' => $latitude !== null && $latitude !== '' ? (float) $latitude : null,
            'longitude' => $longitude !== null && $longitude !== '' ? (float) $longitude : null,
            'google_maps_url' => $payload['google_maps_url'] ?? null,
            'google_place_id' => $googlePlaceId,
            'google_feature_id' => $googleFeatureId,
            'google_cid' => $googleCid,
            'google_entity_id' => $googleEntityId,
            'formatted_address' => $payload['formatted_address'] ?? null,
            'components' => $payload['components'] ?? [],
            'metadata' => $payload['metadata'] ?? [],
            'navigation_links' => $payload['navigation_links'] ?? [],
            'waze_url' => $payload['waze_url'] ?? null,
        ];

        if ($existingAddress instanceof Address) {
            $existingAddress->fill($attributes)->save();

            if ($assignmentsProvided || $stateChanged || $countryChanged) {
                app(SyncAddressAreaAssignmentsAction::class)->execute(
                    $existingAddress,
                    $assignmentsProvided ? $assignments : AddressAssignments::forAddress($existingAddress),
                    $attributes['state_id'],
                    ['source' => 'ilmu360'],
                );
            }

            return;
        }

        $address = Address::query()->create($attributes);

        $model->attachAddress($address, type: 'primary', isPrimary: true);
        app(SyncAddressAreaAssignmentsAction::class)->execute($address, $assignments, $attributes['state_id'], ['source' => 'ilmu360']);
    }

    public function syncContactMethods(Model $model, mixed $contactPayload): void
    {
        if (! method_exists($model, 'contactMethods')) {
            return;
        }

        $contacts = collect(is_array($contactPayload) ? $contactPayload : [])
            ->values()
            ->map(function (mixed $contact, int $index): ?array {
                if (! is_array($contact)) {
                    return null;
                }

                $type = $this->normalizeContactMethodType($contact['type'] ?? $contact['category'] ?? null);
                $value = match ($type) {
                    ContactMethodType::Phone, ContactMethodType::Whatsapp, ContactMethodType::Mobile => is_string($contact['phone_value'] ?? null)
                        ? trim($contact['phone_value'])
                        : (is_string($contact['value'] ?? null) ? trim($contact['value']) : null),
                    default => is_string($contact['value'] ?? null)
                        ? trim($contact['value'])
                        : null,
                };
                $purpose = $this->normalizeContactPurpose($contact['purpose'] ?? null);

                if (! $type instanceof ContactMethodType || $value === null || $value === '') {
                    return null;
                }

                return [
                    'type' => $type->value,
                    'purpose' => $purpose->value,
                    'value' => $value,
                    'is_primary' => (bool) ($contact['is_primary'] ?? false),
                    'is_public' => (bool) ($contact['is_public'] ?? true),
                    'sort_order' => is_numeric($contact['sort_order'] ?? $contact['order_column'] ?? null)
                        ? (int) ($contact['sort_order'] ?? $contact['order_column'])
                        : $index + 1,
                ];
            })
            ->filter()
            ->values();

        $model->contactMethods()->delete();

        $contacts->each(fn (array $contact): ContactMethod => $model->contactMethods()->create($contact));
    }

    public function syncSocialMedia(Model $model, mixed $socialMediaPayload): void
    {
        if (! method_exists($model, 'socialProfiles')) {
            return;
        }

        $entries = collect(is_array($socialMediaPayload) ? $socialMediaPayload : [])
            ->values()
            ->map(function (mixed $entry, int $index): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $platform = is_string($entry['platform'] ?? null) ? trim($entry['platform']) : null;
                $handle = is_string($entry['handle'] ?? $entry['username'] ?? null) ? trim($entry['handle'] ?? $entry['username']) : null;
                $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : null;

                if ($platform === null || $platform === '' || ($handle === null || $handle === '') && ($url === null || $url === '')) {
                    return null;
                }

                return [
                    'platform' => $platform,
                    'purpose' => ContactPurpose::General->value,
                    'handle' => $handle !== '' ? $handle : null,
                    'url' => $url !== '' ? $url : null,
                    'is_primary' => (bool) ($entry['is_primary'] ?? false),
                    'is_public' => (bool) ($entry['is_public'] ?? true),
                    'sort_order' => is_numeric($entry['sort_order'] ?? $entry['order_column'] ?? null)
                        ? (int) ($entry['sort_order'] ?? $entry['order_column'])
                        : $index + 1,
                ];
            })
            ->filter()
            ->values();

        $model->socialProfiles()->delete();

        $entries->each(fn (array $entry): SocialProfile => $model->socialProfiles()->create($entry));
    }

    private function normalizeContactMethodType(mixed $value): ?ContactMethodType
    {
        if ($value instanceof ContactMethodType) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return ContactMethodType::tryFrom(trim($value));
    }

    private function normalizeContactPurpose(mixed $value): ContactPurpose
    {
        if ($value instanceof ContactPurpose) {
            return $value;
        }

        if (! is_string($value)) {
            return ContactPurpose::General;
        }

        return ContactPurpose::tryFrom(trim($value)) ?? ContactPurpose::General;
    }

    /**
     * @param  array<string, string>  $assignments
     * @return array{country: ?string, country_code: ?string, state: ?string, city: ?string}
     */
    private function resolveAddressMetadata(
        ?string $countryId,
        array $assignments,
    ): array {
        $country = $countryId !== null
            ? AddressCountry::query()->find($countryId)
            : null;
        $areaIds = array_values(array_unique(array_filter($assignments, is_string(...))));
        $areasById = $areaIds === []
            ? collect()
            : AddressArea::query()->whereIn('id', $areaIds)->get()->keyBy(fn (AddressArea $area): string => (string) $area->getKey());

        while (true) {
            $pendingParentIds = $areasById
                ->pluck('parent_id')
                ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                ->unique()
                ->values()
                ->all();
            $missingParentIds = array_values(array_diff($pendingParentIds, $areasById->keys()->all()));

            if ($missingParentIds === []) {
                break;
            }

            $parents = AddressArea::query()->whereIn('id', $missingParentIds)->get();

            if ($parents->isEmpty()) {
                break;
            }

            $areasById = $areasById->union($parents->keyBy(fn (AddressArea $area): string => (string) $area->getKey()));
        }

        $areas = collect($areaIds)
            ->map(fn (string $areaId): ?AddressArea => $areasById->get($areaId))
            ->filter(fn (?AddressArea $area): bool => $area instanceof AddressArea)
            ->values();

        $stateName = null;
        $stateNames = [];

        foreach ($areas->reverse() as $area) {
            $stateId = AddressAreaStateBridge::stateIdForArea((string) $area->getKey());

            if ($stateId !== null) {
                if (! array_key_exists($stateId, $stateNames)) {
                    $stateNames[$stateId] = State::query()->whereKey($stateId)->value('name');
                }

                $stateName = is_string($stateNames[$stateId] ?? null) ? $stateNames[$stateId] : null;

                if ($stateName !== null && $stateName !== '') {
                    break;
                }
            }

            $parent = $area;

            while (is_string($parent->parent_id) && $parent->parent_id !== '') {
                $parent = $areasById->get($parent->parent_id);

                if (! $parent instanceof AddressArea) {
                    break;
                }

                if (in_array($parent->type, ['state', 'wilayah_persekutuan'], true)) {
                    $stateName = $parent->name;
                    break 2;
                }
            }
        }

        $locality = $areas->reverse()->first();

        return [
            'country' => $country?->name,
            'country_code' => $country?->iso2,
            'state' => is_string($stateName) && $stateName !== '' ? $stateName : null,
            'city' => $locality?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function syncCanonicalRegionText(array $payload): array
    {
        $stateId = $this->normalizeUuid($payload['state_id'] ?? null);
        $cityId = $this->normalizeUuid($payload['city_id'] ?? null);

        if ($stateId !== null) {
            $payload['state'] = State::query()->whereKey($stateId)->value('name');

            if ($cityId !== null) {
                $payload['city'] = City::query()
                    ->whereKey($cityId)
                    ->where('state_id', $stateId)
                    ->value('name');
            } else {
                $payload['city'] = null;
            }
        } elseif ($cityId !== null) {
            $payload['city'] = City::query()->whereKey($cityId)->value('name');
        }

        return $payload;
    }

    private function normalizeUuid(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        return Str::isUuid($trimmed) || ctype_digit($trimmed) ? $trimmed : null;
    }
}
