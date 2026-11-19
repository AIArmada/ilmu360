<?php

declare(strict_types=1);

namespace App\Platform\Search;

interface EventDiscoveryReaders
{
    public function database(): EventDiscoveryReader;

    public function typesense(): EventDiscoveryReader;
}
