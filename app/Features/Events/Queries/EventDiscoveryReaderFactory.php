<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Platform\Search\EventDiscoveryReader;
use App\Platform\Search\EventDiscoveryReaders;

final readonly class EventDiscoveryReaderFactory implements EventDiscoveryReaders
{
    public function __construct(
        private PostgresEventDiscovery $database,
        private TypesenseEventDiscovery $typesense,
    ) {}

    public function database(): EventDiscoveryReader
    {
        return $this->database;
    }

    public function typesense(): EventDiscoveryReader
    {
        return $this->typesense;
    }
}
