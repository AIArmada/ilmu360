<?php

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use AIArmada\Addressing\Support\AddressCountryResolver;
use App\Shared\Contracts\AddressChangeEffects;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AddressObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected AddressChangeEffects $effects,
        protected AddressCountryResolver $addressingCountryResolver,
    ) {}

    public function saving(Address $address): void
    {
        if (filled($address->country_id)) {
            return;
        }

        $defaultCountryCode = config('addressing.defaults.country_code');

        if (is_string($defaultCountryCode) && trim($defaultCountryCode) !== '') {
            $address->country_id = $this->addressingCountryResolver->resolveId($defaultCountryCode);
        }
    }

    public function saved(Address $address): void
    {
        if (! $address->wasRecentlyCreated && ! $address->wasChanged()) {
            return;
        }

        $this->dispatchChanges($address);
    }

    public function deleted(Address $address): void
    {
        $this->dispatchChanges($address);
    }

    private function dispatchChanges(Address $address): void
    {
        $address->loadMissing('addressableLinks.addressable');

        $subjects = [];
        foreach ($address->addressableLinks as $link) {
            $addressable = $link->addressable;
            if (! $addressable instanceof Model) {
                continue;
            }

            $subjects[] = [
                'morph_type' => (string) $addressable->getMorphClass(),
                'id' => (string) $addressable->getKey(),
                'name' => (string) ($addressable->name ?? ''),
                'event_ids' => $this->eventIds($addressable),
            ];
        }

        $morphTypes = $address->addressableLinks
            ->map(static fn (Addressable $link): string => (string) $link->addressable_type)
            ->values()
            ->all();

        $this->effects->apply($subjects, $morphTypes);
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
}
