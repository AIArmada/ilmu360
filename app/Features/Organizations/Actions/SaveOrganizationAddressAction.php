<?php

declare(strict_types=1);

namespace App\Features\Organizations\Actions;

use AIArmada\Addressing\Features\AddressLifecycle\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Features\AddressLifecycle\Models\Address;
use AIArmada\Addressing\Features\AddressLifecycle\Models\Addressable;
use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use AIArmada\Addressing\Features\Geography\Models\City;
use AIArmada\Addressing\Features\Geography\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Organizations\Contracts\OrganizationAuthorization;
use AIArmada\Organizations\Models\Organization;
use App\Features\DonationChannels\Api\DonationChannelCountryPolicy;
use App\Features\Organizations\Services\OrganizationProfileService;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Authorized write of the organization's canonical primary address.
 *
 * The package Organization model cannot carry the addressing trait, so the
 * attachment is written through the addressing models under the package
 * guards. A null payload clears the stored address.
 */
final readonly class SaveOrganizationAddressAction
{
    public function __construct(private OrganizationProfileService $profiles) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function handle(string $actorId, string $organizationId, ?array $payload): void
    {
        $organization = Organization::query()->findOrFail($organizationId);

        app(OrganizationAuthorization::class)->authorize(
            $this->actor($actorId),
            $organization,
            'organization.update',
        );

        OwnerContext::withOwner(null, function () use ($organizationId, $payload): void {
            DB::transaction(function () use ($organizationId, $payload): void {
                $this->save($organizationId, $payload);
            });
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function save(string $organizationId, ?array $payload): void
    {
        $attachment = $this->profiles->primaryAttachmentFor($organizationId);
        $existing = $attachment?->address;

        if ($payload === null) {
            if ($attachment instanceof Addressable) {
                $attachment->delete();
            }

            if ($existing instanceof Address) {
                $existing->delete();
            }

            return;
        }

        $countryId = $this->uuid($payload['country_id'] ?? null);
        $country = $countryId !== null ? AddressCountry::query()->find($countryId) : null;

        if (! $country instanceof AddressCountry) {
            throw ValidationException::withMessages([
                'address.country_id' => $countryId === null
                    ? __('The address country is required.')
                    : __('The selected country is invalid.'),
            ]);
        }

        $stateId = $this->uuid($payload['state_id'] ?? null);
        $cityId = $this->uuid($payload['city_id'] ?? null);
        $cityName = $cityId !== null
            ? City::query()->whereKey($cityId)->where('state_id', $stateId)->value('name')
            : null;

        if ($cityId !== null && $cityName === null) {
            $cityId = null;
        }

        $attributes = [
            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'line1' => $this->string($payload['line1'] ?? null),
            'line2' => $this->string($payload['line2'] ?? null),
            'postcode' => $this->string($payload['postcode'] ?? null),
            'country' => $country->getAttribute('name'),
            'country_code' => $country->getAttribute('iso2'),
            'state' => $stateId !== null ? State::query()->whereKey($stateId)->value('name') : null,
            'city' => $cityName,
        ];

        app(DonationChannelCountryPolicy::class)->assertOwnerChannelsAllowedForCountryId(
            $this->profiles->addressableType(),
            $organizationId,
            $countryId,
        );

        if ($existing instanceof Address) {
            $regionChanged = $existing->getAttribute('country_id') !== $countryId
                || $existing->getAttribute('state_id') !== $stateId;

            $existing->fill($attributes)->save();

            if ($regionChanged) {
                app(SyncAddressAreaAssignmentsAction::class)->execute((string) $existing->getKey(), [], $stateId, ['source' => 'ilmu360']);
            }

            return;
        }

        $address = Address::query()->create($attributes);

        $this->demotePrimaryAttachments($organizationId);

        Addressable::query()->create([
            'id' => (string) Str::orderedUuid(),
            'address_id' => $address->getKey(),
            'addressable_type' => $this->profiles->addressableType(),
            'addressable_id' => $organizationId,
            'type' => 'primary',
            'is_primary' => true,
        ]);

        app(SyncAddressAreaAssignmentsAction::class)->execute((string) $address->getKey(), [], $stateId, ['source' => 'ilmu360']);
    }

    private function demotePrimaryAttachments(string $organizationId): void
    {
        Addressable::query()
            ->where('addressable_type', $this->profiles->addressableType())
            ->where('addressable_id', $organizationId)
            ->where('type', 'primary')
            ->where('is_primary', true)
            ->update([
                'is_primary' => false,
                'updated_at' => now(),
            ]);
    }

    private function actor(string $actorId): Model
    {
        $modelClass = MorphModelResolver::resolve('user');
        $actor = $modelClass::query()->find($actorId);

        if (! $actor instanceof Model) {
            throw ValidationException::withMessages([
                'actor' => __('The acting account is invalid.'),
            ]);
        }

        return $actor;
    }

    private function uuid(mixed $value): ?string
    {
        $normalized = $this->string($value);

        return $normalized !== null && Str::isUuid($normalized) ? $normalized : null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
