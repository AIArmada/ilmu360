<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

/** Index maintenance operations exposed to the Persons console entrypoint. */
interface PersonSearchMaintenance
{
    public function searchIndexSchemaReady(): bool;

    public function reindexAll(int $chunkSize = 100): int;
}
