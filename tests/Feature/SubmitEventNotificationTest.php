<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\EventSubmittedNotification;
use App\States\EventStatus\Pending;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
    $this->seed(RoleSeeder::class);
    Notification::fake();
});

/**
 * @return array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, speaker: Speaker}
 */
function submitEventNotificationFixtures(): array
{
    return [
        'domain_tag' => Tag::factory()->domain()->create(),
        'discipline_tag' => Tag::factory()->discipline()->create(),
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'speaker' => Speaker::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: Tag, discipline_tag: Tag, institution: Institution, speaker: Speaker}  $fixtures
 * @return array<string, mixed>
 */
function submitEventNotificationFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Notification Test Event',
        'domain_tags' => [$fixtures['domain_tag']->id],
        'discipline_tags' => [$fixtures['discipline_tag']->id],
        'event_type' => [EventType::KuliahCeramah->value],
        'event_date' => now()->addDays(5)->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'description' => 'Test description for notification',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'organizer_type' => 'institution',
        'organizer_institution_id' => $fixtures['institution']->id,
        'speakers' => [$fixtures['speaker']->id],
        'submitter_name' => 'Test User',
        'submitter_email' => 'test@example.com',
    ], $overrides);
}

it('notifies moderators when a guest submits an event', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    // Regular user should NOT receive the notification
    $regularUser = User::factory()->create();

    $fixtures = submitEventNotificationFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventNotificationFormData($fixtures, [
            'submitter_name' => 'Ahmad bin Abdullah',
            'submitter_email' => 'ahmad@example.com',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    // Verify the event was created with pending status via the transition
    $event = Event::where('title', 'Notification Test Event')->firstOrFail();
    expect($event->status)->toBeInstanceOf(Pending::class);

    // Verify moderator and super_admin received the notification
    Notification::assertSentTo($moderator, EventSubmittedNotification::class);
    Notification::assertSentTo($superAdmin, EventSubmittedNotification::class);

    // Verify regular user did NOT receive it
    Notification::assertNotSentTo($regularUser, EventSubmittedNotification::class);
});

it('transitions event from draft to pending on submission', function () {
    $fixtures = submitEventNotificationFixtures();

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventNotificationFormData($fixtures, [
            'title' => 'Draft to Pending Event',
            'description' => 'Testing state transition',
        ]),
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Draft to Pending Event')->firstOrFail();

    // Event should be in Pending state (transitioned from Draft)
    expect($event->status)->toBeInstanceOf(Pending::class);

    // published_at should still be null (only set on approval)
    expect($event->published_at)->toBeNull();
});
