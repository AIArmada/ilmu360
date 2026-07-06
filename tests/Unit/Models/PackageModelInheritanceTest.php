<?php

use App\Models\Event;
use App\Models\MemberInvitation;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Venue;
use Tests\TestCase;

uses(TestCase::class);

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
