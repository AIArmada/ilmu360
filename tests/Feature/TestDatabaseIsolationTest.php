<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('migrates only a disposable test database', function (): void {
    $connection = DB::connection();

    expect(app()->environment())->toBe('testing')
        ->and(config('database.connections'))->toHaveCount(1)
        ->and($connection->getConfig('url'))->toBeNull()
        ->and(Schema::hasTable('migrations'))->toBeTrue();

    if (getenv('GITHUB_ACTIONS') === 'true' && getenv('ILMU360_TEST_POSTGRES') === '1') {
        expect($connection->getDriverName())->toBe('pgsql')
            ->and($connection->getConfig('host'))->toBe('127.0.0.1')
            ->and($connection->getDatabaseName())->toMatch('/^test(?:_test_[0-9]+)?$/');

        return;
    }

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:');
});
