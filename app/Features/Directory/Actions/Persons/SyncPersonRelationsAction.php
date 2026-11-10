<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Addressing\Models\Address;
use App\Platform\Directory\EntityRelationSync;
use App\Shared\Directory\SyncsLanguages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Synchronize all person-owned and person-related relations from a
 * contribution or admin payload.
 */
final readonly class SyncPersonRelationsAction
{
    public function __construct(
        private SyncPersonNamesAction $names,
        private SyncPersonAffiliationsAction $affiliations,
        private SyncPersonTitleAssignmentsAction $titleAssignments,
        private EntityRelationSync $relations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Model $person, array $payload): void
    {
        if (array_key_exists('names', $payload)) {
            $this->names->handle($person, $payload['names']);
        }

        if (array_key_exists('institutions', $payload)) {
            $this->affiliations->handle($person, $payload['institutions']);
        } elseif (array_key_exists('institution_id', $payload) || array_key_exists('institution_position', $payload)) {
            $this->affiliations->syncSingle($person, $payload);
        }

        if (array_key_exists('title_ids', $payload)) {
            $this->titleAssignments->handleIds($person, $payload['title_ids']);
        } elseif (array_key_exists('title_assignments', $payload)) {
            $this->titleAssignments->handle($person, $payload['title_assignments']);
        }

        $addressPayload = $this->personAddressPayload($payload);

        if (is_array($addressPayload)) {
            $addressPayload = $this->preserveHiddenAddressFields($person, $addressPayload);
            $this->relations->syncAddress($person, $addressPayload, allowCountryOnly: true);
        }

        if (array_key_exists('contactMethods', $payload)) {
            $this->relations->syncContactMethods($person, $payload['contactMethods']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->relations->syncSocialMedia($person, $payload['social_media']);
        }

        if (array_key_exists('language_ids', $payload)) {
            $languageIds = $this->normalizeStringArray(
                is_iterable($payload['language_ids']) ? $payload['language_ids'] : [],
            );

            if ($person instanceof SyncsLanguages) {
                $person->syncLanguages($languageIds);
            }
        }

        $this->affiliations->syncSingle($person, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function personAddressPayload(array $payload): ?array
    {
        if (array_key_exists('address', $payload)) {
            return is_array($payload['address']) ? $payload['address'] : [];
        }

        $addressKeys = [
            'country_id',
            'country_code',
            'country_key',
            'area_assignments',
            'line1',
            'line2',
            'postcode',
            'latitude',
            'longitude',
            'google_maps_url',
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'waze_url',
        ];

        if (! collect($addressKeys)->contains(fn (string $key): bool => array_key_exists($key, $payload))) {
            return null;
        }

        $address = [];

        foreach ($addressKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $address[$key] = $payload[$key];
            }
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $addressPayload
     * @return array<string, mixed>
     */
    private function preserveHiddenAddressFields(Model $person, array $addressPayload): array
    {
        $existingAddress = $this->primaryAddress($person);

        if (! $existingAddress instanceof Address) {
            return $addressPayload;
        }

        foreach ([
            'line1',
            'line2',
            'postcode',
            'latitude',
            'longitude',
            'google_maps_url',
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'waze_url',
        ] as $field) {
            if (! array_key_exists($field, $addressPayload) || $addressPayload[$field] === null) {
                $addressPayload[$field] = $existingAddress->getAttribute($field);
            }
        }

        return $addressPayload;
    }

    private function primaryAddress(Model $person): ?Address
    {
        $person->loadMissing('addresses');
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
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_iterable($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = trim($item);

            if ($trimmed === '' || in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }
}
