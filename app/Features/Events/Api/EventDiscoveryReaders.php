<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventDiscoveryReaders
{
    public function database(): EventDiscoveryReader;

    public function typesense(): EventDiscoveryReader;
}
