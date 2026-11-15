<?php

namespace App\Features\SlugRedirects\Api;

interface SlugRedirectLookup
{
    public function findBySourcePath(string $sourcePath): ?SlugRedirectMatch;
}
