<?php

declare(strict_types=1);

namespace App\Shared\Discovery;

interface PublicDiscoveryAdapter
{
    /** @return list<string> */
    public function publicSearchIds(string $search): array;

    /** @return list<string> */
    public function publicFuzzySearchIds(string $search): array;
}
