<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

interface PersonHomepageStats
{
    public function activePersonCount(): int;

    public function forgetCachedCount(): void;
}
