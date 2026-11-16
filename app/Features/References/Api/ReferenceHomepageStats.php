<?php

declare(strict_types=1);

namespace App\Features\References\Api;

interface ReferenceHomepageStats
{
    public function rootReferenceCount(): int;

    public function forgetCachedCount(): void;
}
