<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface SearchHealthCheck
{
    public function isAvailable(): bool;

    public function bust(): void;
}
