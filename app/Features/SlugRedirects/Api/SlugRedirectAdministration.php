<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Api;

interface SlugRedirectAdministration
{
    public function create(
        ?string $redirectableType,
        ?string $redirectableId,
        ?string $sourceSlug
    ): string;

    public function update(
        string $redirectId,
        ?string $redirectableType,
        ?string $redirectableId,
        ?string $sourceSlug
    ): void;

    public function delete(string $redirectId): bool;

    /** @param list<string> $redirectIds */
    public function deleteMany(array $redirectIds): int;
}
