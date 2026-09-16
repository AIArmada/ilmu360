<?php

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\States\EventModerationStatus\EventModerationStatus;
use AIArmada\Events\States\OccurrenceStatus\Draft;
use AIArmada\Events\States\OccurrenceStatus\Live;
use AIArmada\Events\States\OccurrenceStatus\OccurrenceStatus;
use AIArmada\Events\States\OccurrenceStatus\Published;
use AIArmada\Events\States\RegistrationStatus\RegistrationStatus;
use Tests\TestCase;

uses(TestCase::class);

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
