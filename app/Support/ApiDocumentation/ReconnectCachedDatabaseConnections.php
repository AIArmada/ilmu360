<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Illuminate\Database\DatabaseManager;

class ReconnectCachedDatabaseConnections
{
    public function __construct(private readonly DatabaseManager $databaseManager) {}

    public function __invoke(): void
    {
        foreach ($this->databaseManager->getConnections() as $connection) {
            $connection->reconnectIfMissingConnection();
        }
    }
}
