<?php

use AIArmada\Communications\Enums\NotificationFamily as PackageNotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority as PackageNotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger as PackageNotificationTrigger;
use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Engagement\Contracts\EngagementManager;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Person;
use App\Models\Registration;
use App\Models\User;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Shared\Auth\AccountDirectory;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Shared\Engagement\EngagementReader;
use App\Shared\Engagement\EngagementSubjectType;
use App\Shared\Events\AccountEventFacts;
use App\Shared\Membership\MembershipApplicationReader;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use App\Support\Communications\AccountCommunications;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves membership, engagement and event facts without user reverse relations', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $person = Person::factory()->create();
    $event = Event::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->id,
    ]);
    $sharedEvent = Event::factory()->create();
    $registration = Registration::factory()->forRegistrant($user)->create(['event_id' => $sharedEvent->id]);

    $institution->members()->attach($user->id, ['role' => 'admin']);
    $person->members()->attach($user->id);
    $event->members()->attach($user->id);

    $user->follow($institution);
    app(EngagementManager::class)->bookmark($user, $sharedEvent);
    $user->respond($sharedEvent, 'going');

    $memberships = app(MembershipDirectory::class);
    expect($memberships->subjectIdsFor((string) $user->getKey(), MembershipSubjectType::Institution))->toBe([(string) $institution->id])
        ->and($memberships->subjectIdsFor((string) $user->getKey(), MembershipSubjectType::Person))->toBe([(string) $person->id])
        ->and($memberships->subjectIdsFor((string) $user->getKey(), MembershipSubjectType::Event))->toBe([(string) $event->id])
        ->and($memberships->hasRole((string) $user->getKey(), MembershipSubjectType::Institution, (string) $institution->id, 'admin'))->toBeTrue();

    $engagement = app(EngagementReader::class);
    expect($engagement->followedSubjectIds((string) $user->getKey(), EngagementSubjectType::Institution))->toBe([(string) $institution->id])
        ->and($engagement->savedEventIds((string) $user->getKey()))->toBe([(string) $sharedEvent->id])
        ->and($engagement->goingEventIds((string) $user->getKey()))->toBe([(string) $sharedEvent->id])
        ->and($engagement->hasSavedEvent((string) $user->getKey(), (string) $sharedEvent->id))->toBeTrue()
        ->and($engagement->isGoingToEvent((string) $user->getKey(), (string) $sharedEvent->id))->toBeTrue();

    $facts = app(AccountEventFacts::class);
    expect($facts->ownedEventIds((string) $user->getKey()))->toBe([(string) $event->id])
        ->and($facts->registrationIds((string) $user->getKey()))->toBe([(string) $registration->id]);

    expect(method_exists(User::class, 'institutions'))->toBeFalse()
        ->and(method_exists(User::class, 'savedEvents'))->toBeFalse()
        ->and(method_exists(User::class, 'goingEvents'))->toBeFalse()
        ->and(method_exists(User::class, 'registrations'))->toBeFalse()
        ->and(method_exists(User::class, 'notificationInboxes'))->toBeFalse();
});

it('reads membership applications and contribution requests for their owner', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $application = MembershipApplication::factory()->create(['applicant_id' => $user->id]);
    $otherApplication = MembershipApplication::factory()->create(['applicant_id' => $other->id]);
    $contribution = ContributionRequest::factory()->create(['proposer_id' => $user->id]);

    $applications = app(MembershipApplicationReader::class);
    expect($applications->applicationIdsForApplicant((string) $user->getKey()))->toBe([(string) $application->id])
        ->and($applications->findApplicationIdForApplicant((string) $user->getKey(), (string) $application->id))->toBe((string) $application->id)
        ->and($applications->findApplicationIdForApplicant((string) $user->getKey(), (string) $otherApplication->id))->toBeNull();

    $contributions = app(ContributionRequestReader::class);
    expect($contributions->requestIdsForProposer((string) $user->getKey()))->toBe([(string) $contribution->id])
        ->and($contributions->findRequestIdForProposer((string) $user->getKey(), (string) $contribution->id))->toBe((string) $contribution->id);
});

it('reads account communication settings, destinations and inbox counts', function (): void {
    $user = User::factory()->create();

    CommunicationPreference::query()->forceCreate([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => null,
        'category' => null,
        'scope_type' => null,
        'locale' => 'en',
        'timezone' => 'Asia/Kuala_Lumpur',
        'enabled_at' => now(),
    ]);
    createTestRecord(CommunicationDestination::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => 'push',
        'address' => 'account-communications-test',
        'status' => 'active',
        'is_primary' => true,
    ]);
    createTestRecord(NotificationInbox::class, [
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'family' => PackageNotificationFamily::EventUpdate->value,
        'priority' => PackageNotificationPriority::Normal->value,
        'trigger' => PackageNotificationTrigger::EventUpdated->value,
        'title' => 'Unread',
        'body' => 'Body',
        'data' => [],
        'read_at' => null,
    ]);

    $communications = app(AccountCommunications::class);
    $notificationPreferences = app(AccountNotificationPreferences::class);
    $profile = app(AccountDirectory::class)->profile((string) $user->getKey());

    expect($notificationPreferences->preferredLocale((string) $user->getKey()))->toBe('en')
        ->and($notificationPreferences->preferredTimezone((string) $user->getKey()))->toBe('Asia/Kuala_Lumpur')
        ->and($communications->unreadInboxCount((string) $user->getKey()))->toBe(1)
        ->and($communications->destinationsQuery((string) $user->getKey())->count())->toBe(1)
        ->and($profile?->locale)->toBe('en')
        ->and($profile?->timezone)->toBe('Asia/Kuala_Lumpur');
});
