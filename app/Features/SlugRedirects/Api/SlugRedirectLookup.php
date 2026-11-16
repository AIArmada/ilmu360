<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Api;

interface SlugRedirectLookup
{
    public function findBySourcePath(string $sourcePath): ?SlugRedirectMatch;
}
