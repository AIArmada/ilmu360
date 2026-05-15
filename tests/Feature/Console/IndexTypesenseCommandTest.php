<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the scout import wrappers for the supported search drivers', function (string $command) {
    config()->set('scout.driver', 'database');
    config()->set('scout.queue', false);

    $this->artisan($command)
        ->assertSuccessful();
})->with([
    'events' => 'search:index-events',
    'speakers' => 'search:index-speakers',
    'institutions' => 'search:index-institutions',
    'references' => 'search:index-references',
]);

it('rejects unsupported scout drivers in the wrapper commands', function (string $command) {
    config()->set('scout.driver', 'collection');

    $this->artisan($command)
        ->assertFailed();
})->with([
    'events' => 'search:index-events',
    'speakers' => 'search:index-speakers',
    'institutions' => 'search:index-institutions',
    'references' => 'search:index-references',
]);
