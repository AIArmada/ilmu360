<?php

declare(strict_types=1);

use App\Support\ApiDocumentation\ReconnectCachedDatabaseConnections;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

it('reconnects cached database connections that may be missing their PDO instances', function () {
    $sqliteConnection = $this->createMock(Connection::class);
    $pgsqlConnection = $this->createMock(Connection::class);

    $sqliteConnection->expects($this->once())
        ->method('reconnectIfMissingConnection');

    $pgsqlConnection->expects($this->once())
        ->method('reconnectIfMissingConnection');

    $databaseManager = $this->createMock(DatabaseManager::class);
    $databaseManager->expects($this->once())
        ->method('getConnections')
        ->willReturn([
            'sqlite' => $sqliteConnection,
            'pgsql' => $pgsqlConnection,
        ]);

    (new ReconnectCachedDatabaseConnections($databaseManager))();
});
