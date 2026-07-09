<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Location\FederalTerritoryLocation;
use Illuminate\Validation\ValidationException;

class AddressAreaObserver
{
    public function __construct(
        private readonly PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        private readonly PublicListingsCache $publicListingsCache,
    ) {}

    public function saved(AddressArea $addressArea): void
    {
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
        FederalTerritoryLocation::flushStateIdCache();
        $this->publicDirectoryCacheVersion->bumpAll();
        $this->publicListingsCache->bustMajlisListing();
    }

    private function childAreaDeletionMessage(AddressArea $addressArea): ?string
    {
        $recordKey = (string) $addressArea->getKey();

        if ($addressArea->level === 1) {
            if (AddressArea::query()->where('parent_id', $recordKey)->where('level', 2)->exists()) {
                return 'Delete or reassign this state\'s districts before deleting it.';
            }

            if (AddressArea::query()->where('parent_id', $recordKey)->where('level', 3)->exists()) {
                return 'Delete or reassign this state\'s subdistricts before deleting it.';
            }
        }

        if ($addressArea->level === 2 && AddressArea::query()->where('parent_id', $recordKey)->exists()) {
            return 'Delete or reassign this district\'s subdistricts before deleting it.';
        }

        return null;
    }

    private function isReferencedByAddresses(AddressArea $addressArea): bool
    {
        $recordKey = (string) $addressArea->getKey();

        // Product storage: admin_area_1 = district (level 2), admin_area_2 = subdistrict (level 3).
        // Level-1 state tree nodes are hierarchy parents only (State table owns state_id).
        return match ((int) $addressArea->level) {
            1 => Address::query()
                ->where(function ($query) use ($recordKey): void {
                    $query
                        ->where('admin_area_1_id', $recordKey)
                        ->orWhere('admin_area_2_id', $recordKey)
                        ->orWhere('admin_area_3_id', $recordKey)
                        ->orWhere('admin_area_4_id', $recordKey);
                })
                ->exists(),
            2 => Address::query()->where('admin_area_1_id', $recordKey)->exists(),
            3 => Address::query()->where('admin_area_2_id', $recordKey)->exists(),
            4 => Address::query()
                ->where(function ($query) use ($recordKey): void {
                    $query
                        ->where('admin_area_3_id', $recordKey)
                        ->orWhere('admin_area_4_id', $recordKey);
                })
                ->exists(),
            default => Address::query()
                ->where(function ($query) use ($recordKey): void {
                    $query
                        ->where('admin_area_1_id', $recordKey)
                        ->orWhere('admin_area_2_id', $recordKey)
                        ->orWhere('admin_area_3_id', $recordKey)
                        ->orWhere('admin_area_4_id', $recordKey);
                })
                ->exists(),
        };
    }

    private function addressReferenceMessage(AddressArea $addressArea): string
    {
        $label = match ((int) $addressArea->level) {
            1 => 'region area',
            2 => 'district',
            3 => 'subdistrict',
            4 => 'local area',
            default => 'address area',
        };

        return "This {$label} is still referenced by one or more addresses.";
    }
}
