<?php

declare(strict_types=1);

namespace App\Host\Typesense;

use App\Shared\Contracts\SearchHealthProbe;
use Typesense\Client;

final readonly class TypesenseHealthProbe implements SearchHealthProbe
{
    public function isHealthy(array $settings): bool
    {
        $client = new Client($settings);
        $client->health->retrieve();

        return true;
    }
}
