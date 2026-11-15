<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Api;

interface SlugRedirectCommands
{
    public function record(string $redirectableType, string $redirectableId, string $currentSlug, ?string $previousSlug): bool;

    public function purge(string $redirectableType, string $redirectableId): void;

    public function markUsed(string $redirectId): void;
}
