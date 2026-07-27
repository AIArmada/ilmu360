<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Validation\ValidationException;

class AddressAreaObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        private readonly PublicListingsCache $publicListingsCache,
    ) {}

    public function saved(AddressArea $addressArea): void
    {
        if (! $addressArea->wasRecentlyCreated && ! $addressArea->wasChanged()) {
            return;
        }

        $this->flushLocationCaches();
    }

    public function deleted(AddressArea $addressArea): void
    {
        $this->flushLocationCaches();
    }

    public function deleting(AddressArea $addressArea): void
    {
        $childAreaMessage = $this->childAreaDeletionMessage($addressArea);

        if ($childAreaMessage !== null) {
            throw ValidationException::withMessages([
                'address_area' => $childAreaMessage,
            ]);
        }

        if (! $this->isReferencedByAddresses($addressArea)) {
            return;
        }

        throw ValidationException::withMessages([
            'address_area' => $this->addressReferenceMessage($addressArea),
        ]);
    }

    private function flushLocationCaches(): void
    {
        $this->publicDirectoryCacheVersion->bumpAll();
        $this->publicListingsCache->bustMajlisListing();
    }

    private function childAreaDeletionMessage(AddressArea $addressArea): ?string
    {
        $recordKey = (string) $addressArea->getKey();

        if (AddressAreaRelationship::query()
            ->where('parent_address_area_id', $recordKey)
            ->where('relationship_type', 'contains')
            ->exists()) {
            return 'Delete or reassign this address area\'s child areas before deleting it.';
        }

        return null;
    }

    private function isReferencedByAddresses(AddressArea $addressArea): bool
    {
        $recordKey = (string) $addressArea->getKey();

        return Address::query()
            ->whereHas('areaAssignments', static fn ($query) => $query->where('address_area_id', $recordKey))
            ->exists();
    }

    private function addressReferenceMessage(AddressArea $addressArea): string
    {
        $label = 'address area';

        return "This {$label} is still referenced by one or more addresses.";
    }
}
