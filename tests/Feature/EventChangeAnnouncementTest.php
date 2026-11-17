<?php

use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Engagement\Contracts\EngagementManager;
use App\Features\Communications\Api\NotificationPriority;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Events\Api\EventChangeSeverity;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Registration;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Services\Notifications\EventNotificationService;
use App\Shared\Events\Enums\EventChangeType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\EventKeyPersonFactory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

it('publishes cancellation announcements and notifies committed users only once', function () {
    $administrator = eventChangeAdministrator();
    $institution = Institution::factory()->create();
    $committedUser = User::factory()->create();
    $follower = User::factory()->create();

    $event = eventChangeApprovedEvent([
        'institution_id' => $institution->id,
        'title' => 'Kuliah Dibatalkan',
    ]);

    app(EngagementManager::class)->bookmark($committedUser, $event);
    $committedUser->respond($event, 'going');
    Registration::factory()->for($event)->forRegistrant($committedUser)->create([
        'status' => 'confirmed',
    ]);
    $follower->follow($institution);

    $announcement = publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::Cancelled,
        publicMessage: 'Majlis ini dibatalkan oleh pihak penganjur.',
    );

    $event->refresh();

    expect((string) $event->status)->toBe('cancelled')
        ->and($event->cancelled_at)->not->toBeNull()
        ->and($event->last_state_change_at)->not->toBeNull()
        ->and($announcement->published_at)->not->toBeNull()
        ->and(data_get($announcement->metadata, 'status'))->toBeNull()
        ->and($announcement->severity)->toBe(EventChangeSeverity::Urgent)
        ->and(data_get($announcement->metadata, 'changed_fields'))->toContain('status', 'occurrence_status');

    $notification = NotificationInbox::query()
        ->where('recipient_id', $committedUser->id)
        ->where('recipient_type', $committedUser->getMorphClass())
        ->get()
        ->first(fn (NotificationInbox $inbox): bool => ($inbox->data['meta']['fingerprint'] ?? null) === 'event-change:'.$announcement->id);

    expect($notification)->not->toBeNull()
        ->and($notification->trigger->value)->toBe(NotificationTrigger::EventCancelled->value)
        ->and($notification->priority->value)->toBe(NotificationPriority::Urgent->value)
        ->and($notification->data['meta']['event_change_announcement_id'] ?? null)->toBe($announcement->id)
        ->and($notification->data['meta']['replacement_event_id'] ?? null)->toBeNull()
        ->and(NotificationInbox::query()
            ->where('recipient_id', $follower->id)
            ->where('recipient_type', $follower->getMorphClass())
            ->get()->contains(fn (NotificationInbox $inbox): bool => ($inbox->data['meta']['fingerprint'] ?? null) === 'event-change:'.$announcement->id))->toBeFalse();
});

it('publishes repeat cancellation notices without reapplying occurrence transitions', function () {
    $administrator = eventChangeAdministrator();
    $event = eventChangeApprovedEvent(['title' => 'Kuliah Dibatalkan']);

    publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::Cancelled,
        publicMessage: 'Majlis ini dibatalkan oleh pihak penganjur.',
        notify: false,
    );

    $event->refresh();
    $occurrence = $event->primaryOccurrence;
    $cancelledAt = $event->cancelled_at?->toIso8601String();
    $lastStateChangeAt = $event->last_state_change_at?->toIso8601String();
    $occurrenceUpdatedAt = $occurrence?->updated_at?->toIso8601String();

    $this->travelTo(now()->addMinute());

    $repeatAnnouncement = publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::Cancelled,
        publicMessage: 'Majlis ini dibatalkan oleh pihak penganjur. Maklumat lanjut menyusul.',
        notify: false,
    );

    $freshEvent = $event->fresh();
    $freshOccurrence = $occurrence?->fresh();
    $this->travelBack();

    expect((string) $freshEvent->status)->toBe('cancelled')
        ->and($freshEvent->cancelled_at?->toIso8601String())->toBe($cancelledAt)
        ->and($freshEvent->last_state_change_at?->toIso8601String())->toBe($lastStateChangeAt)
        ->and((string) $freshOccurrence?->status)->toBe('cancelled')
        ->and($freshOccurrence?->updated_at?->toIso8601String())->toBe($occurrenceUpdatedAt)
        ->and($repeatAnnouncement->exists)->toBeTrue()
        ->and(EventChangeAnnouncement::query()
            ->where('event_id', $event->getKey())
            ->where('update_type', EventChangeType::Cancelled->value)
            ->count())->toBe(2);
});

it('applies venue and space changes through the event location boundary', function () {
    $administrator = eventChangeAdministrator();
    $originalInstitution = Institution::factory()->create();
    $event = eventChangeApprovedEvent(['institution_id' => (string) $originalInstitution->getKey()]);
    $venue = Venue::factory()->create();
    $space = Space::factory()->create();

    $announcement = publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::LocationChanged,
        publicMessage: 'The event location changed.',
        changes: [
            'venue_id' => (string) $venue->getKey(),
            'space_ids' => [(string) $space->getKey()],
        ],
        notify: false,
    );

    $event->refresh();
    $primaryLocation = $event->primaryLocation;

    expect($event->institution_id)->toBeNull()
        ->and($event->default_venue_id)->toBe((string) $venue->getKey())
        ->and($primaryLocation?->venue_id)->toBe((string) $venue->getKey())
        ->and($primaryLocation?->venue_space_id)->toBe((string) $space->getKey())
        ->and(data_get($announcement->metadata, 'changed_fields'))->toContain('institution_id', 'venue_id', 'space_ids');

    $newInstitution = Institution::factory()->create();

    $institutionAnnouncement = publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::LocationChanged,
        publicMessage: 'The event moved back to an institution location.',
        changes: [
            'institution_id' => (string) $newInstitution->getKey(),
            'space_ids' => [],
        ],
        notify: false,
    );

    $event->refresh();

    expect($event->institution_id)->toBe((string) $newInstitution->getKey())
        ->and($event->default_venue_id)->toBeNull()
        ->and($event->primaryLocation)->toBeNull()
        ->and(data_get($institutionAnnouncement->metadata, 'changed_fields'))->toContain('institution_id', 'venue_id', 'space_ids');
});

it('rejects invalid venue and space selections in change announcements', function () {
    $administrator = eventChangeAdministrator();
    $event = eventChangeApprovedEvent();
    $targetVenue = Venue::factory()->create();
    $otherVenue = Venue::factory()->create();
    $foreignSpace = Space::factory()->create(['venue_id' => $otherVenue->getKey()]);
    $existingLocationIds = $event->locations()->pluck('id')->all();

    expect(fn () => publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::LocationChanged,
        publicMessage: 'The event location changed.',
        changes: [
            'venue_id' => (string) Str::uuid(),
            'space_ids' => [],
        ],
        notify: false,
    ))->toThrow(ValidationException::class)
        ->and(fn () => publishEventChangeForTest(
            event: $event,
            actor: $administrator,
            type: EventChangeType::LocationChanged,
            publicMessage: 'The event location changed.',
            changes: [
                'venue_id' => (string) $targetVenue->getKey(),
                'space_ids' => [(string) $foreignSpace->getKey()],
            ],
            notify: false,
        ))->toThrow(ValidationException::class)
        ->and(fn () => publishEventChangeForTest(
            event: $event,
            actor: $administrator,
            type: EventChangeType::LocationChanged,
            publicMessage: 'The event location changed.',
            changes: ['space_ids' => [123]],
            notify: false,
        ))->toThrow(ValidationException::class)
        ->and(EventChangeAnnouncement::query()->where('event_id', $event->getKey())->exists())->toBeFalse()
        ->and($event->fresh()->institution_id)->toBe($event->institution_id)
        ->and($event->fresh()->default_venue_id)->toBeNull()
        ->and($event->fresh()->locations()->pluck('id')->all())->toBe($existingLocationIds);
});

it('keeps ordinary edits out of the change announcement workflow', function () {
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Biasa',
    ]);

    $event->update([
        'title' => 'Kuliah Biasa Dikemas Kini',
    ]);

    $event->refresh();

    expect((string) $event->status)->toBe('approved')
        ->and(EventChangeAnnouncement::query()->where('event_id', $event->id)->exists())->toBeFalse()
        ->and(NotificationInbox::query()
            ->get()
            ->contains(fn (NotificationInbox $inbox): bool => ($inbox->data['entity_id'] ?? null) === $event->id))->toBeFalse();
});

it('blocks registration and check-in surfaces for unknown postponements', function () {
    $administrator = eventChangeAdministrator();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Ditangguhkan',
    ]);

    $event->accessPolicy()->updateOrCreate(['event_id' => $event->id], [
        'registration_required' => true,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::Postponed,
        publicMessage: 'Tarikh baharu belum disahkan.',
        notify: false,
    );

    $event->refresh();

    expect((string) $event->primaryOccurrence?->status)->toBe('postponed');

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('Ditangguhkan')
        ->assertSee('Tarikh baharu belum disahkan');
});

it('keeps replacement event URLs separate from the original source of truth notice', function () {
    $administrator = eventChangeAdministrator();
    $original = eventChangeApprovedEvent([
        'title' => 'Kuliah Asal',
        'slug' => 'kuliah-asal',
    ]);
    $replacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Pengganti',
        'slug' => 'kuliah-pengganti',
        'starts_at' => now()->addDays(8),
        'ends_at' => now()->addDays(8)->addHours(2),
    ]);

    $announcement = publishEventChangeForTest(
        event: $original,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Sila hadir ke majlis pengganti.',
        replacementEvent: $replacement,
        notify: false,
    );

    expect(data_get($announcement->metadata, 'changed_fields', []))->not->toContain('replacement_event_id');

    $this->get(route('events.show', $original))
        ->assertOk()
        ->assertSee('Lihat Majlis Pengganti');
});

it('keeps replacement CTAs after later notices and resolves replacement chains', function () {
    $administrator = eventChangeAdministrator();
    $original = eventChangeApprovedEvent([
        'title' => 'Kuliah Asal Berantai',
        'slug' => 'kuliah-asal-berantai',
    ]);
    $firstReplacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Pengganti Pertama',
        'slug' => 'kuliah-pengganti-pertama',
    ]);
    $finalReplacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Pengganti Terkini',
        'slug' => 'kuliah-pengganti-terkini',
    ]);

    publishEventChangeForTest(
        event: $original,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Sila rujuk majlis pengganti pertama.',
        replacementEvent: $firstReplacement,
        notify: false,
    );

    publishEventChangeForTest(
        event: $firstReplacement,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Majlis pengganti pertama diganti pula.',
        replacementEvent: $finalReplacement,
        notify: false,
    );

    publishEventChangeForTest(
        event: $original,
        actor: $administrator,
        type: EventChangeType::Other,
        publicMessage: 'Nota terkini untuk pautan lama.',
        notify: false,
    );

    expect(EventChangeAnnouncement::query()
        ->where('event_id', $original->id)
        ->whereNotNull('replacement_event_id')
        ->count())->toBe(1)
        ->and($original->fresh()->latestPublishedReplacementAnnouncement?->replacement_event_id)
        ->toBe($firstReplacement->id);

    $this->get(route('events.show', $original))
        ->assertOk()
        ->assertSee('Nota terkini untuk pautan lama.')
        ->assertSee('Lihat Majlis Pengganti')
        ->assertSee(route('events.show', $finalReplacement), false);
});

it('keeps the latest reachable replacement CTA when later chain targets become unreachable', function () {
    $administrator = eventChangeAdministrator();
    $original = eventChangeApprovedEvent([
        'title' => 'Kuliah Asal Ganti Boleh Capai',
        'slug' => 'kuliah-asal-ganti-boleh-capai',
    ]);
    $firstReplacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Ganti Masih Boleh Capai',
        'slug' => 'kuliah-ganti-masih-boleh-capai',
    ]);
    $finalReplacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Ganti Tidak Boleh Capai',
        'slug' => 'kuliah-ganti-tidak-boleh-capai',
    ]);

    publishEventChangeForTest(
        event: $original,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Sila rujuk majlis pengganti pertama.',
        replacementEvent: $firstReplacement,
        notify: false,
    );

    publishEventChangeForTest(
        event: $firstReplacement,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Majlis pengganti pertama diganti pula.',
        replacementEvent: $finalReplacement,
        notify: false,
    );

    $finalReplacement->update([
        'visibility' => 'private',
    ]);

    $this->get(route('events.show', $original))
        ->assertOk()
        ->assertSee(route('events.show', $firstReplacement), false)
        ->assertDontSee(route('events.show', $finalReplacement), false);
});

it('hides replacement links when the linked replacement event is no longer publicly reachable', function () {
    $administrator = eventChangeAdministrator();
    $original = eventChangeApprovedEvent([
        'title' => 'Kuliah Asal Pautan Pengganti',
        'slug' => 'kuliah-asal-pautan-pengganti',
    ]);
    $replacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Pengganti Tidak Lagi Umum',
        'slug' => 'kuliah-pengganti-tidak-lagi-umum',
    ]);

    publishEventChangeForTest(
        event: $original,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Sila rujuk majlis pengganti.',
        replacementEvent: $replacement,
        notify: false,
    );

    $replacement->update([
        'visibility' => 'private',
    ]);

    $this->get(route('events.show', $original))
        ->assertOk()
        ->assertDontSee(route('events.show', $replacement), false);
});

it('rejects self replacement announcements', function () {
    $administrator = eventChangeAdministrator();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Tidak Boleh Ganti Diri',
    ]);

    expect(fn () => publishEventChangeForTest(
        event: $event,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Ganti diri sendiri.',
        replacementEvent: $event,
        notify: false,
    ))->toThrow(ValidationException::class)
        ->and(EventChangeAnnouncement::query()->where('event_id', $event->id)->exists())->toBeFalse();
});

it('rejects replacement chains that would loop back to the original event', function () {
    $administrator = eventChangeAdministrator();
    $original = eventChangeApprovedEvent([
        'title' => 'Kuliah Asal Gelung',
    ]);
    $replacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Pengganti Gelung',
    ]);

    publishEventChangeForTest(
        event: $original,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Sila rujuk majlis pengganti.',
        replacementEvent: $replacement,
        notify: false,
    );

    expect(fn () => publishEventChangeForTest(
        event: $replacement,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Pautan balik tidak dibenarkan.',
        replacementEvent: $original,
        notify: false,
    ))->toThrow(ValidationException::class)
        ->and(EventChangeAnnouncement::query()
            ->where('event_id', $replacement->id)
            ->where('replacement_event_id', $original->id)->exists())->toBeFalse();
});

it('rejects replacement events that are not publicly reachable', function () {
    $administrator = eventChangeAdministrator();
    $original = eventChangeApprovedEvent([
        'title' => 'Kuliah Asal Umum',
    ]);
    $privateReplacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Ganti Peribadi',
        'visibility' => 'private',
    ]);

    expect(fn () => publishEventChangeForTest(
        event: $original,
        actor: $administrator,
        type: EventChangeType::ReplacementLinked,
        publicMessage: 'Majlis ganti tidak boleh dicapai umum.',
        replacementEvent: $privateReplacement,
        notify: false,
    ))->toThrow(ValidationException::class)
        ->and(EventChangeAnnouncement::query()->where('event_id', $original->id)->exists())->toBeFalse();
});

it('allows person members for listed event persons to publish change announcements', function () {
    eventChangeSeedScopedRoles();

    $editor = User::factory()->create();
    $person = Person::factory()->create();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Penceramah Ahli',
    ]);

    EventKeyPersonFactory::new()
        ->for($event)
        ->for($person)
        ->create();

    $person->members()->syncWithoutDetaching([$editor->id]);
    $person->members()->updateExistingPivot($editor->id, ['role' => 'admin']);

    $announcement = publishEventChangeForTest(
        event: $event,
        actor: $editor,
        type: EventChangeType::TopicChanged,
        publicMessage: 'Tajuk majlis dikemas kini.',
        notify: false,
    );

    expect($announcement->event_id)->toBe($event->id)
        ->and($announcement->update_type)->toBe(EventChangeType::TopicChanged);
});

it('rejects unauthorized actors from publishing change announcements', function () {
    eventChangeSeedScopedRoles();

    $actor = User::factory()->create();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Tanpa Kebenaran',
    ]);

    expect(fn () => publishEventChangeForTest(
        event: $event,
        actor: $actor,
        type: EventChangeType::TopicChanged,
        publicMessage: 'Perubahan tanpa kebenaran.',
        notify: false,
    ))->toThrow(AuthorizationException::class)
        ->and(EventChangeAnnouncement::query()->where('event_id', $event->id)->exists())->toBeFalse();
});

it('prefers the newest replacement announcement when published timestamps tie', function () {
    $administrator = eventChangeAdministrator();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Ikatan Masa Sama',
    ]);
    $firstReplacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Ganti Pertama Ikatan Masa',
    ]);
    $secondReplacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Ganti Kedua Ikatan Masa',
    ]);
    $publishedAt = CarbonImmutable::parse('2026-05-05 12:00:00', 'UTC');

    EventChangeAnnouncement::unguarded(function () use ($administrator, $event, $firstReplacement, $secondReplacement, $publishedAt): void {
        EventChangeAnnouncement::query()->create([
            'id' => '00000000-0000-0000-0000-000000000101',
            'event_id' => $event->id,
            'replacement_event_id' => $firstReplacement->id,
            'created_by_type' => User::class,
            'created_by_id' => $administrator->id,
            'update_type' => EventChangeType::ReplacementLinked,
            'severity' => EventChangeSeverity::High,
            'message' => 'Pengganti pertama.',
            'metadata' => [
                'status' => 'published',
            ],

            'published_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);

        EventChangeAnnouncement::query()->create([
            'id' => '00000000-0000-0000-0000-000000000102',
            'event_id' => $event->id,
            'replacement_event_id' => $secondReplacement->id,
            'created_by_type' => User::class,
            'created_by_id' => $administrator->id,
            'update_type' => EventChangeType::ReplacementLinked,
            'severity' => EventChangeSeverity::High,
            'message' => 'Pengganti kedua.',
            'metadata' => [
                'status' => 'published',
            ],

            'published_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);
    });

    expect($event->fresh()->latestPublishedReplacementAnnouncement?->replacement_event_id)
        ->toBe($secondReplacement->id);
});

it('eager loads latest published announcement relations without aggregating uuid ids', function () {
    $administrator = eventChangeAdministrator();
    $original = eventChangeApprovedEvent([
        'title' => 'Kuliah Asal Eager',
    ]);
    $replacement = eventChangeApprovedEvent([
        'title' => 'Kuliah Ganti Eager',
    ]);
    $incomingSource = eventChangeApprovedEvent([
        'title' => 'Kuliah Sumber Incoming',
    ]);
    $incomingTarget = eventChangeApprovedEvent([
        'title' => 'Kuliah Sasaran Incoming',
    ]);
    $publishedAt = CarbonImmutable::parse('2026-05-05 12:00:00', 'UTC');

    EventChangeAnnouncement::unguarded(function () use ($administrator, $original, $replacement, $incomingSource, $incomingTarget, $publishedAt): void {
        EventChangeAnnouncement::query()->create([
            'id' => '00000000-0000-0000-0000-000000000111',
            'event_id' => $original->id,
            'created_by_type' => User::class,
            'created_by_id' => $administrator->id,
            'update_type' => EventChangeType::ScheduleChanged,
            'severity' => EventChangeSeverity::High,
            'message' => 'Versi awal.',
            'metadata' => [
                'status' => 'published',
            ],

            'published_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);

        EventChangeAnnouncement::query()->create([
            'id' => '00000000-0000-0000-0000-000000000112',
            'event_id' => $original->id,
            'replacement_event_id' => $replacement->id,
            'created_by_type' => User::class,
            'created_by_id' => $administrator->id,
            'update_type' => EventChangeType::ReplacementLinked,
            'severity' => EventChangeSeverity::High,
            'message' => 'Versi pengganti terkini.',
            'metadata' => [
                'status' => 'published',
            ],

            'published_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);

        EventChangeAnnouncement::query()->create([
            'id' => '00000000-0000-0000-0000-000000000121',
            'event_id' => $original->id,
            'replacement_event_id' => $incomingTarget->id,
            'created_by_type' => User::class,
            'created_by_id' => $administrator->id,
            'update_type' => EventChangeType::ReplacementLinked,
            'severity' => EventChangeSeverity::High,
            'message' => 'Incoming awal.',
            'metadata' => [
                'status' => 'published',
            ],

            'published_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);

        EventChangeAnnouncement::query()->create([
            'id' => '00000000-0000-0000-0000-000000000122',
            'event_id' => $incomingSource->id,
            'replacement_event_id' => $incomingTarget->id,
            'created_by_type' => User::class,
            'created_by_id' => $administrator->id,
            'update_type' => EventChangeType::ReplacementLinked,
            'severity' => EventChangeSeverity::High,
            'message' => 'Incoming terkini.',
            'metadata' => [
                'status' => 'published',
            ],

            'published_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);
    });

    $events = Event::query()
        ->with([
            'latestPublishedChangeAnnouncement',
            'latestPublishedReplacementAnnouncement',
            'latestIncomingReplacementAnnouncement',
        ])
        ->whereKey([$original->id, $incomingTarget->id])
        ->get()
        ->keyBy(fn (Event $event): string => $event->id);

    expect($events->get($original->id)?->latestPublishedChangeAnnouncement?->id)
        ->toBe('00000000-0000-0000-0000-000000000121')
        ->and($events->get($original->id)?->latestPublishedReplacementAnnouncement?->id)
        ->toBe('00000000-0000-0000-0000-000000000121')
        ->and($events->get($incomingTarget->id)?->latestIncomingReplacementAnnouncement?->id)
        ->toBe('00000000-0000-0000-0000-000000000122');
});

it('loads the public events index when listed events have published change announcements', function () {
    $administrator = eventChangeAdministrator();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Indeks Perubahan',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);
    $publishedAt = CarbonImmutable::parse('2026-05-06 12:00:00', 'UTC');

    EventChangeAnnouncement::unguarded(function () use ($administrator, $event, $publishedAt): void {
        EventChangeAnnouncement::query()->create([
            'id' => '00000000-0000-0000-0000-000000000131',
            'event_id' => $event->id,
            'created_by_type' => User::class,
            'created_by_id' => $administrator->id,
            'update_type' => EventChangeType::ScheduleChanged,
            'severity' => EventChangeSeverity::High,
            'message' => 'Masa majlis dikemas kini.',
            'metadata' => [
                'status' => 'published',
            ],

            'published_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);
    });

    $this->get(route('events.index', ['search' => 'Kuliah Indeks Perubahan']))
        ->assertOk()
        ->assertSee('Kuliah Indeks Perubahan');
});

it('marks schedule changes into the next 24 hours as urgent', function () {
    $now = Carbon::parse('2026-05-01 00:00:00', 'UTC');
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC'));

    $administrator = eventChangeAdministrator();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Hampir',
        'starts_at' => $now->copy()->addDays(3),
        'ends_at' => $now->copy()->addDays(3)->addHours(2),
    ]);

    try {
        $announcement = publishEventChangeForTest(
            event: $event,
            actor: $administrator,
            type: EventChangeType::ScheduleChanged,
            publicMessage: 'Masa baharu dalam 24 jam.',
            changes: [
                'starts_at' => $now->copy()->addHours(12),
                'ends_at' => $now->copy()->addHours(14),
            ],
            notify: false,
        );

        $event->refresh();

        expect($announcement->severity)->toBe(EventChangeSeverity::Urgent)
            ->and($event->primaryOccurrence?->starts_at?->equalTo($now->copy()->addHours(12)))->toBeTrue()
            ->and($event->primaryOccurrence?->ends_at?->equalTo($now->copy()->addHours(14)))->toBeTrue();
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

it('does not send reminders for unknown postponed events using their last known time', function () {
    $now = CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC');
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
    CarbonImmutable::setTestNow($now);

    $goingUser = User::factory()->create();
    $event = eventChangeApprovedEvent([
        'title' => 'Kuliah Ditangguh Hampir',
        'starts_at' => $now->addHours(2),
        'ends_at' => $now->addHours(4),
    ]);

    $goingUser->respond($event, 'going');

    try {
        app(EventNotificationService::class)->dispatchDueReminderNotifications($now);

        expect(NotificationInbox::query()
            ->where('recipient_id', $goingUser->id)
            ->where('recipient_type', $goingUser->getMorphClass())
            ->whereIn('trigger', [
                NotificationTrigger::Reminder2Hours->value,
                NotificationTrigger::CheckinOpen->value,
            ])
            ->get()
            ->contains(fn (NotificationInbox $inbox): bool => ($inbox->data['entity_id'] ?? null) === $event->id))->toBeFalse();
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

function eventChangeAdministrator(): User
{
    eventChangeSeedScopedRoles();

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    return $administrator;
}

function eventChangeSeedScopedRoles(): void
{
    test()->seed(RoleSeeder::class);
    test()->seed(PermissionSeeder::class);
    test()->seed(ScopedMemberRolesSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function eventChangeApprovedEvent(array $attributes = []): Event
{
    return Event::factory()->create(array_replace([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHours(2),
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $attributes));
}
