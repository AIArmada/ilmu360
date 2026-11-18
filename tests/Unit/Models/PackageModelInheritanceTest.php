<?php

declare(strict_types=1);

use App\Features\Events\Models\Event;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\References\Models\Reference;
use App\Features\Registration\Models\Registration;
use App\Features\Places\Models\Venue;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('Registration resolves to event_registrations table', function () {
    expect((new Registration)->getTable())->toBe('event_registrations');
});

it('MemberInvitation resolves to membership_invitations table', function () {
    expect((new MemberInvitation)->getTable())->toBe('membership_invitations');
});

it('Reference resolves to references table', function () {
    expect((new Reference)->getTable())->toBe('references');
});

it('Event resolves to events table', function () {
    expect((new Event)->getTable())->toBe('events');
});

it('Venue resolves to venues table', function () {
    expect((new Venue)->getTable())->toBe('venues');
});
