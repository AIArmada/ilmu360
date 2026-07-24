<?php

/**
 * Package-native notification delivery coverage.
 *
 * Replaces dual-store PendingNotification/NotificationEngine tests after
 * communications cutover (P9-B). Product orchestration still uses
 * EventNotificationService → CommunicationManager → InboxChannel → NotificationInbox.
 */

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger as PackageNotificationTrigger;
use AIArmada\Communications\Models\NotificationInbox;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('writes package notification inboxes when a public approved future event is published for followers', function () {
    Http::fake();

    $user = User::factory()->create([
        'email' => 'follower@example.test',
    ]);
    $person = Person::factory()->create([
        'name' => 'Ustaz Aiman',
    ]);
    $institution = Institution::factory()->create();
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tafsir Malam Jumaat',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(5),
        'published_at' => now()->subHour(),
    ]);

    $event->persons()->attach($person->id);
    $user->follow($person);

    app(EventNotificationService::class)->notifyPublication($event->fresh('persons'));

    $inboxes = OwnerContext::withOwner(null, fn () => NotificationInbox::query()
        ->where('recipient_id', $user->id)
        ->where('recipient_type', $user->getMorphClass())
        ->get());

    expect($inboxes)->toHaveCount(1)
        // App FollowedSpeakerEvent maps to package follow_activity via EnumMapper.
        ->and($inboxes->first()->trigger)->toBe(PackageNotificationTrigger::FollowActivity)
        ->and($inboxes->first()->title)->toContain('Majlis Tafsir Malam Jumaat');
});

it('localizes followed-content inbox notifications per recipient locale', function () {
    Http::fake();

    $person = Person::factory()->create(['name' => 'Ustaz Aiman']);
    $institution = Institution::factory()->create();
    $startsAt = CarbonImmutable::now('UTC')->addDays(10)->setTime(12, 0);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tafsir Malam Jumaat',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => $startsAt,
        'published_at' => now()->subHour(),
    ]);

    $event->persons()->attach($person->id);

    $englishUser = User::factory()->create(['email' => 'english@example.test']);
    $malayUser = User::factory()->create(['email' => 'malay@example.test']);

    $englishUser->follow($person);
    $malayUser->follow($person);

    app(NotificationSettingsManager::class)->save($englishUser, [
        'settings' => [
            'locale' => 'en',
            'timezone' => 'UTC',
        ],
    ]);

    app(NotificationSettingsManager::class)->save($malayUser, [
        'settings' => [
            'locale' => 'ms',
            'timezone' => 'Asia/Kuala_Lumpur',
        ],
    ]);

    app(EventNotificationService::class)->notifyPublication($event->fresh('persons'));

    $englishInbox = OwnerContext::withOwner(null, fn () => NotificationInbox::query()
        ->where('recipient_id', $englishUser->id)
        ->first());
    $malayInbox = OwnerContext::withOwner(null, fn () => NotificationInbox::query()
        ->where('recipient_id', $malayUser->id)
        ->first());

    expect($englishInbox)->not->toBeNull()
        ->and($malayInbox)->not->toBeNull()
        ->and($englishInbox->title)->not->toBe($malayInbox->title);
});

it('marks package inbox messages read via HasInbox helpers', function () {
    $user = User::factory()->create();

    $inbox = OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => PackageNotificationTrigger::EventUpdated->value,
        'title' => 'Schedule changed',
        'body' => 'Details updated',
        'data' => [],
        'read_at' => null,
    ]));

    expect($user->unreadCount())->toBe(1);

    $user->markAsRead($inbox->id);

    expect($user->fresh()->unreadCount())->toBe(0)
        ->and($inbox->fresh()->read_at)->not->toBeNull();
});
