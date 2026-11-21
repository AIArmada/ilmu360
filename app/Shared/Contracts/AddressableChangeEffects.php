<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Domain effects of an addressing link changing, without a feature dependency
 * in the Platform addressing observer.
 */
interface AddressableChangeEffects
{
    /** @param list<string> $morphTypes */
    public function bumpAffectedDirectoryVersions(array $morphTypes): void;

    /**
     * @param list<string> $eventIds
     * @param array<string, mixed>|null $deletedAddress
     */
    public function syncAddressable(
        string $morphType,
        string $id,
        string $name,
        array $eventIds,
        ?array $deletedAddress = null,
    ): void;
}
