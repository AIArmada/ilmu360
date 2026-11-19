<?php

use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Events\Models\Series;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Features\References\Models\Reference;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Models\User;
use App\Platform\Moderation\Models\ModerationReview;

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
