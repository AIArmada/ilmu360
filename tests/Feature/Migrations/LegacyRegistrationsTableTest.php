<?php

use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Series;
use App\Models\User;
use App\Models\Venue;

it('Registration resolves to event_registrations, not registrations', function () {
    expect((new Registration)->getTable())->toBe('event_registrations');
});

it('no model resolves to the registrations table', function () {
    $models = [
        Event::class,
        Venue::class,
        Reference::class,
        Registration::class,
        MemberInvitation::class,
        Institution::class,
        Person::class,
        User::class,
        Series::class,
        EventCheckin::class,
        EventSubmission::class,
        ModerationReview::class,
    ];

    foreach ($models as $modelClass) {
        expect((new $modelClass)->getTable())->not->toBe('registrations');
    }
});
