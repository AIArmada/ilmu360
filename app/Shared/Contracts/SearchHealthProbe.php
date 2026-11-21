<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface SearchHealthProbe
{
    /** @param array<string, mixed> $settings */
    public function isHealthy(array $settings): bool;
}
