<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Reads public directory cache versions and invalidates their response caches.
 */
interface PublicDirectoryCacheVersions
{
    /**
     * @return array{version: string}
     */
    public function institution(): array;

    /**
     * @return array{version: string}
     */
    public function person(): array;

    public function bumpInstitution(): void;

    public function bumpPerson(): void;

    public function bumpAll(): void;

    /**
     * @param  list<string>  $addressableMorphTypes
     */
    public function bumpForAddressableTypes(array $addressableMorphTypes): void;

    public function bumpForMediaType(string $mediaMorphType): void;

    public function bumpForEvent(): void;

    public function bumpForEventKeyPerson(): void;
}
