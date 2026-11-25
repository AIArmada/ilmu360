<?php

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\States\OccurrenceStatus\Draft;
use AIArmada\Events\Features\Catalog\States\OccurrenceStatus\Live;
use AIArmada\Events\Features\Catalog\States\OccurrenceStatus\OccurrenceStatus;
use AIArmada\Events\Features\Catalog\States\OccurrenceStatus\Published;
use AIArmada\Events\Features\Governance\States\EventModerationStatus\EventModerationStatus;
use AIArmada\Events\Features\Registrations\States\RegistrationStatus\RegistrationStatus;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('memoizes directory-scanned state configs', function (string $state) {
    expect($state::config())->toBe($state::config());
})->with([
    OccurrenceStatus::class,
    EventModerationStatus::class,
    RegistrationStatus::class,
]);

it('keeps transitions working on the memoized occurrence config', function () {
    $occurrence = new EventOccurrence;
    $occurrence->setRawAttributes(['status' => 'published']);

    expect($occurrence->status)->toBeInstanceOf(Published::class)
        ->and($occurrence->status->canTransitionTo(Live::class))->toBeTrue()
        ->and($occurrence->status->canTransitionTo(Draft::class))->toBeFalse();
});
