<?php

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use App\Shared\Contracts\AddressableChangeEffects;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AddressableObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly AddressableChangeEffects $effects) {}

    public function created(Addressable $addressable): void
    {
        $addressable->loadMissing('address');

        $address = $addressable->address;

        if ($address instanceof Address) {
            $this->bumpDirectoryVersionsForAddress($address);
        }

        $this->syncAddressable($addressable);
    }

    public function deleted(Addressable $addressable): void
    {
        $addressable->loadMissing('address');

        $address = $addressable->address;

        if ($address instanceof Address) {
            $this->bumpDirectoryVersionsForAddress($address);
        }

        $this->syncAddressable($addressable, $address instanceof Address ? $address : null);
    }

    private function syncAddressable(Addressable $addressable, ?Address $deletedAddress = null): void
    {
        $addressable->loadMissing('addressable');
        $subject = $addressable->addressable;

        if (! $subject instanceof Model) {
            return;
        }

        $deletedAddressSnapshot = $deletedAddress instanceof Address ? [
            'city' => $deletedAddress->city,
            'state' => $deletedAddress->state,
            'country_id' => $deletedAddress->country_id,
            'country_code' => $deletedAddress->country_code,
        ] : null;

        $this->effects->syncAddressable(
            (string) $subject->getMorphClass(),
            (string) $subject->getKey(),
            (string) ($subject->name ?? ''),
            $this->eventIds($subject),
            $deletedAddressSnapshot,
        );
    }

    /**
     * @return list<string>
     */
    private function eventIds(Model $model): array
    {
        $events = $model->getRelationValue('events');

        if (! $events instanceof Collection) {
            return [];
        }

        return $events->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    private function bumpDirectoryVersionsForAddress(Address $address): void
    {
        $address->loadMissing('addressableLinks');
        $types = $address->addressableLinks
            ->map(static fn (Addressable $link): string => (string) $link->addressable_type)
            ->values()->all();

        $this->effects->bumpAffectedDirectoryVersions($types);
    }
}
