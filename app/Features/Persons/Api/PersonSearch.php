<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

interface PersonSearch extends \App\Shared\Discovery\PublicDiscoveryAdapter
{
    /** @return list<string> */
    public function resolvedPublicSearchIds(string $search): array;
}
