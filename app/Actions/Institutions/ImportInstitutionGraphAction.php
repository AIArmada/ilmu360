<?php

declare(strict_types=1);

namespace App\Actions\Institutions;

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use AIArmada\Addressing\Models\AddressCountry;
use App\Actions\DonationChannels\SaveDonationChannelAction;
use App\Contracts\InstitutionSlugIntent;
use App\Contracts\SpaceEligibilityResolver;
use App\Data\InstitutionData;
use App\Data\Institutions\InstitutionAddressData;
use App\Data\Institutions\InstitutionContactMethodData;
use App\Data\Institutions\InstitutionDonationChannelData;
use App\Data\Institutions\InstitutionNameData;
use App\Data\Institutions\InstitutionSocialProfileData;
use App\Data\Institutions\InstitutionSpaceData;
use App\Models\Institution;
use App\Models\InstitutionImportExclusion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

/**
 * Narrow transactional writer for the validated institution graph.
 *
 * Writes InstitutionData atomically: per-call transaction with row locking,
 * so invalid nested values and mid-graph failures leave no partial state.
 * Omitted (null) graph parts preserve existing state; explicit [] clears
 * that part; explicit values replace it. Description and the primary
 * address are Optional: omitted preserves, explicit null clears
 * (description) or detaches the primary link (never the shared Address
 * row), an explicit value writes. New rows treat omitted description as
 * null, so feeds without a description column never erase manual text.
 *
 * Provenance is set-once: source/external_ref/imported_at are written on
 * create only and never updated. Slugs are never stolen: a slug owned by
 * another identity fails instead of being adopted, and the DTO slug is
 * opaque curated bytes protected in the current transaction record so
 * deferred same-name observer regenerations skip the row at commit.
 * Creation
 * for an excluded source identity is refused outright, so direct validated
 * calls cannot bypass deletion protection; explicit updates of an
 * existing live row stay supported.
 *
 * Deliberately has no actor/ownership, media, or public-submission side
 * effects: unlike SaveInstitutionAction it never adds members, syncs media,
 * or toggles submission locks.
 */
class ImportInstitutionGraphAction
{
    use AsAction;

    public function __construct(
        private SaveDonationChannelAction $saveDonationChannelAction,
        private InstitutionSlugIntent $slugIntent,
    ) {}

    public function handle(InstitutionData $data, ?Institution $institution = null): Institution
    {
        return DB::transaction(function () use ($data, $institution): Institution {
            if ($institution !== null && $institution->exists) {
                $institution = $this->lockExisting((string) $institution->getKey());
                $this->assertProvenanceCompatible($institution, $data);
                $this->assertSlugAvailable($data->slug, (string) $institution->getKey());

                $attributes = [
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'type' => $data->type,
                    'status' => $data->status,
                ];

                if (! $data->description instanceof Optional) {
                    $attributes['description'] = $data->description;
                }

                $institution->forceFill($attributes);

                if ($data->facilities !== null) {
                    $institution->forceFill(['facilities' => $data->facilities === [] ? null : $data->facilities]);
                }

                $institution->save();
            } else {
                $this->assertSlugAvailable($data->slug, null);
                $this->assertIdentityAvailable($data);
                $this->assertNotExcluded($data);

                $institution = new Institution;
                $attributes = [
                    'name' => $data->name,
                    'slug' => $data->slug,
                    'type' => $data->type,
                    'status' => $data->status,
                    'description' => $data->description instanceof Optional ? null : $data->description,
                    'facilities' => $data->facilities === [] ? null : $data->facilities,
                ];

                if ($data->source !== null && $data->external_ref !== null) {
                    $attributes['source'] = $data->source;
                    $attributes['external_ref'] = $data->external_ref;
                    $attributes['imported_at'] = now();
                }

                $institution->forceFill($attributes);
                $institution->save();
            }

            $this->syncAddress($institution, $data->address);
            $this->syncNames($institution, $data->names);
            $this->syncContactMethods($institution, $data->contact_methods);
            $this->syncSocialProfiles($institution, $data->social_profiles);
            $this->syncDonationChannels($institution, $data->donation_channels);
            $this->syncLanguages($institution, $data->language_ids);
            $this->syncSpaces($institution, $data->spaces);

            $result = $institution->fresh([
                'names',
                'addresses',
                'contactMethods',
                'socialProfiles',
                'donationChannels',
                'languages',
                'spaces',
                'media',
            ]) ?? $institution;

            $this->slugIntent->protect((string) $result->getKey());

            return $result;
        });
    }

    private function lockExisting(string $id): Institution
    {
        $institution = Institution::query()->whereKey($id)->lockForUpdate()->first();

        if (! $institution instanceof Institution) {
            throw ValidationException::withMessages([
                'institution' => 'The institution no longer exists.',
            ]);
        }

        return $institution;
    }

    private function assertSlugAvailable(string $slug, ?string $exceptId): void
    {
        $taken = Institution::query()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => 'The slug is already owned by another institution.',
            ]);
        }
    }

    private function assertIdentityAvailable(InstitutionData $data): void
    {
        if ($data->source === null || $data->external_ref === null) {
            return;
        }

        $exists = Institution::query()
            ->where('source', $data->source)
            ->where('external_ref', $data->external_ref)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'external_ref' => 'An institution with this source identity already exists.',
            ]);
        }
    }

    private function assertNotExcluded(InstitutionData $data): void
    {
        if ($data->source === null || $data->external_ref === null) {
            return;
        }

        if (InstitutionImportExclusion::excludes($data->source, $data->external_ref)) {
            throw ValidationException::withMessages([
                'external_ref' => 'This source identity was deleted and is excluded from re-import.',
            ]);
        }
    }

    private function assertProvenanceCompatible(Institution $institution, InstitutionData $data): void
    {
        $storedSource = $institution->getAttribute('source');
        $storedRef = $institution->getAttribute('external_ref');

        if ($data->source !== null && $data->source !== $storedSource) {
            throw ValidationException::withMessages([
                'source' => 'Institution provenance is immutable and cannot be changed.',
            ]);
        }

        if ($data->external_ref !== null && $data->external_ref !== $storedRef) {
            throw ValidationException::withMessages([
                'external_ref' => 'Institution provenance is immutable and cannot be changed.',
            ]);
        }
    }

    private function syncAddress(Institution $institution, InstitutionAddressData|Optional|null $address): void
    {
        if ($address instanceof Optional) {
            return;
        }

        if (! $address instanceof InstitutionAddressData) {
            Addressable::query()
                ->where('addressable_type', $institution->getMorphClass())
                ->where('addressable_id', (string) $institution->getKey())
                ->where('is_primary', true)
                ->delete();

            $institution->unsetRelation('addresses');

            return;
        }

        $primary = $institution->primaryAddress();
        $attributes = $this->addressAttributes($address, $primary);

        if ($primary instanceof Address) {
            $primary->fill($attributes);
            $primary->save();
        } else {
            $primary = Address::query()->create($attributes);
            $institution->attachAddress($primary, 'primary', true);
            $primary = $primary->fresh() ?? $primary;
        }

        if ($address->area_assignments !== null) {
            app(SyncAddressAreaAssignmentsAction::class)->execute(
                $primary,
                $address->toLocationData()->assignments(),
                $primary->state_id,
                ['source' => 'institution-graph-import'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function addressAttributes(InstitutionAddressData $address, ?Address $existing = null): array
    {
        $attributes = $address->toAddressData()->toModelAttributes();
        $country = AddressCountry::query()->find($address->country_id);

        $attributes['country'] ??= $country?->name;
        $attributes['country_code'] ??= $country?->iso2;
        $attributes['formatted_address'] = collect([
            $attributes['line1'] ?? null,
            $attributes['line2'] ?? null,
            $attributes['postcode'] ?? null,
            $attributes['city'] ?? null,
            $attributes['state'] ?? null,
            $attributes['country'] ?? null,
        ])->filter(fn (mixed $value): bool => filled($value))->implode(', ');

        $metadata = $attributes['metadata'] ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        if ($existing instanceof Address && is_array($existing->metadata)) {
            $metadata = array_merge($existing->metadata, $metadata);
        }

        $attributes['metadata'] = $metadata === [] ? null : $metadata;

        return Arr::only($attributes, [
            'country_id',
            'state_id',
            'city_id',
            'line1',
            'line2',
            'line3',
            'city',
            'state',
            'postcode',
            'country',
            'country_code',
            'formatted_address',
            'latitude',
            'longitude',
            'metadata',
        ]);
    }

    /**
     * @param  DataCollection<int, InstitutionNameData>|null  $names
     */
    private function syncNames(Institution $institution, ?DataCollection $names): void
    {
        if (! $names instanceof DataCollection) {
            return;
        }

        $institution->names()->delete();

        $explicitPrimary = false;

        /** @var InstitutionNameData $name */
        foreach ($names as $name) {
            if ($name->is_primary) {
                $explicitPrimary = true;

                break;
            }
        }

        $position = 0;

        /** @var InstitutionNameData $name */
        foreach ($names as $name) {
            // The DTO boundary rejects multiple primaries; here an explicit
            // selection wins wherever it sits, and the first name becomes
            // primary only when none was selected.
            $institution->names()->create([
                'name_type' => $name->name_type,
                'full_name' => $name->full_name,
                'language_code' => $name->language_code,
                'is_primary' => $explicitPrimary ? $name->is_primary : $position === 0,
            ]);

            $position++;
        }

        $institution->unsetRelation('names');
    }

    /**
     * @param  DataCollection<int, InstitutionContactMethodData>|null  $contacts
     */
    private function syncContactMethods(Institution $institution, ?DataCollection $contacts): void
    {
        if (! $contacts instanceof DataCollection) {
            return;
        }

        $institution->contactMethods()->delete();

        /** @var InstitutionContactMethodData $contact */
        foreach ($contacts as $contact) {
            $institution->contactMethods()->create([
                'type' => $contact->type->value,
                'purpose' => $contact->purpose->value,
                'label' => $contact->label,
                'value' => $contact->value,
                'is_primary' => $contact->is_primary,
                'is_public' => $contact->is_public,
            ]);
        }

        $institution->unsetRelation('contactMethods');
    }

    /**
     * @param  DataCollection<int, InstitutionSocialProfileData>|null  $socials
     */
    private function syncSocialProfiles(Institution $institution, ?DataCollection $socials): void
    {
        if (! $socials instanceof DataCollection) {
            return;
        }

        $institution->socialProfiles()->delete();

        /** @var InstitutionSocialProfileData $social */
        foreach ($socials as $social) {
            $institution->socialProfiles()->create([
                'platform' => $social->platform->value,
                'purpose' => $social->purpose->value,
                'label' => $social->label,
                'handle' => $social->handle,
                'url' => $social->url,
                'is_primary' => $social->is_primary,
                'is_public' => $social->is_public,
            ]);
        }

        $institution->unsetRelation('socialProfiles');
    }

    /**
     * @param  DataCollection<int, InstitutionDonationChannelData>|null  $channels
     */
    private function syncDonationChannels(Institution $institution, ?DataCollection $channels): void
    {
        if (! $channels instanceof DataCollection) {
            return;
        }

        $institution->donationChannels()->get()->each->delete();

        /** @var InstitutionDonationChannelData $channel */
        foreach ($channels as $channel) {
            $this->saveDonationChannelAction->handle([
                'donatable_type' => $institution->getMorphClass(),
                'donatable_id' => (string) $institution->getKey(),
                'label' => $channel->label,
                'recipient' => $channel->recipient,
                'method' => $channel->method,
                'bank_code' => $channel->bank_code,
                'bank_name' => $channel->bank_name,
                'account_number' => $channel->account_number,
                'duitnow_type' => $channel->duitnow_type,
                'duitnow_value' => $channel->duitnow_value,
                'ewallet_provider' => $channel->ewallet_provider,
                'ewallet_handle' => $channel->ewallet_handle,
                'ewallet_qr_payload' => $channel->ewallet_qr_payload,
                'reference_note' => $channel->reference_note,
                'is_default' => $channel->is_default,
                'status' => $channel->status->value,
            ]);
        }

        $institution->unsetRelation('donationChannels');
    }

    /**
     * @param  list<string>|null  $languageIds
     */
    private function syncLanguages(Institution $institution, ?array $languageIds): void
    {
        if (! is_array($languageIds)) {
            return;
        }

        $institution->languages()->sync($languageIds);
        $institution->unsetRelation('languages');
    }

    /**
     * @param  DataCollection<int, InstitutionSpaceData>|null  $spaces
     */
    private function syncSpaces(Institution $institution, ?DataCollection $spaces): void
    {
        if (! $spaces instanceof DataCollection) {
            return;
        }

        $pivot = [];

        /** @var InstitutionSpaceData $space */
        foreach ($spaces as $space) {
            $pivot[$space->id] = ['capacity' => $space->capacity];
        }

        if ($pivot !== []) {
            $this->assertSpacesStillCatalog(array_keys($pivot));
        }

        $institution->spaces()->sync($pivot);
        $institution->unsetRelation('spaces');
    }

    /**
     * Recheck the selected spaces are still catalog rows under the graph
     * transaction: a valid DTO may be held while a space becomes
     * venue-owned, and venue-owned spaces can never link to institutions.
     *
     * @param  list<string>  $spaceIds
     */
    private function assertSpacesStillCatalog(array $spaceIds): void
    {
        $catalogIds = app(SpaceEligibilityResolver::class)->catalogQuery()
            ->whereKey($spaceIds)
            ->lockForUpdate()
            ->pluck('id')
            ->map(strval(...))
            ->all();

        if (array_diff($spaceIds, $catalogIds) !== []) {
            throw ValidationException::withMessages([
                'spaces' => __('Venue-owned spaces cannot be linked to institutions.'),
            ]);
        }
    }
}
