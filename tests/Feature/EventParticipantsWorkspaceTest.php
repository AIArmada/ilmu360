<?php

use AIArmada\Events\Models\EventAttendanceLog;
use App\Enums\EventAttendanceStatus;
use App\Livewire\Pages\Dashboard\Events\Participants;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use App\Support\Events\EventCommercePolicy;
use App\Support\Events\ParticipantIdentity;
use Livewire\Livewire;

it('honours the event setting when live self check-in is disabled', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'metadata' => ['registration' => ['check_in_enabled' => false]],
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
    ]);
    $event->accessPolicy()->delete();

    Livewire::actingAs($user)
        ->test('pages.events.show', ['event' => $event])
        ->call('checkIn')
        ->assertSet('isCheckedIn', false);

    expect(EventCheckin::query()->where('event_id', $event->getKey())->where('attendee_id', $user->getKey())->exists())->toBeFalse();
});

it('lets a permissioned staff member check in a participant at event scope', function () {
    $staff = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'metadata' => [
            'registration' => [
                'check_in_enabled' => true,
            ],
        ],
    ]);
    addTestMember($event, $staff, 'viewer');

    $registration = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Nadia Participant', 'nadia@example.test')
        ->create(['status' => 'confirmed']);
    $participant = $registration->participants()->firstOrFail();

    Livewire::actingAs($staff)
        ->test(Participants::class, ['event' => $event])
        ->call('checkInParticipant', (string) $participant->getKey())
        ->assertDispatched('app-toast');

    $checkin = EventCheckin::query()
        ->where('event_registration_participant_id', $participant->getKey())
        ->whereNull('event_occurrence_id')
        ->first();

    expect($checkin)->not->toBeNull()
        ->and($checkin?->attendance_type)->toBe(EventAttendanceStatus::Attended->value)
        ->and($checkin?->check_in_source)->toBe('staff_manual')
        ->and($checkin?->verified_by_user_id)->toBe((string) $staff->getKey())
        ->and(EventAttendanceLog::query()
            ->where('event_attendance_id', $checkin?->getKey())
            ->where('performed_by_id', $staff->getKey())
            ->exists())->toBeTrue();
});

it('reports a repeated event-scope check-in as a duplicate', function () {
    $staff = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'metadata' => ['registration' => ['check_in_enabled' => true]],
    ]);
    addTestMember($event, $staff, 'viewer');

    $registration = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Repeat Participant', 'repeat@example.test')
        ->create(['status' => 'confirmed']);
    $participant = $registration->participants()->firstOrFail();

    $component = Livewire::actingAs($staff)->test(Participants::class, ['event' => $event]);
    $component->call('checkInParticipant', (string) $participant->getKey());
    $component->call('checkInParticipant', (string) $participant->getKey());

    $checkin = EventCheckin::query()
        ->where('event_registration_participant_id', $participant->getKey())
        ->whereNull('event_occurrence_id')
        ->whereNull('event_session_id')
        ->firstOrFail();

    expect(EventAttendanceLog::query()->where('event_attendance_id', $checkin->getKey())->count())->toBe(1);
});

it('clears attendance evidence when an attended participant is marked absent', function () {
    $staff = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(7),
        'metadata' => [
            'registration' => [
                'check_in_enabled' => true,
                'refunds_enabled' => true,
            ],
        ],
    ]);
    addTestMember($event, $staff, 'viewer');

    $registration = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Corrected Participant', 'corrected@example.test')
        ->create([
            'status' => 'confirmed',
            'total_amount' => 5000,
        ]);
    $participant = $registration->participants()->firstOrFail();

    $component = Livewire::actingAs($staff)->test(Participants::class, ['event' => $event]);
    $component->call('checkInParticipant', (string) $participant->getKey());
    $component->call('markParticipantAttendance', (string) $participant->getKey(), EventAttendanceStatus::DidNotAttend->value);

    $attendance = EventCheckin::query()
        ->where('event_registration_participant_id', $participant->getKey())
        ->whereNull('cancelled_at')
        ->firstOrFail();

    expect($attendance->attendance_type)->toBe(EventAttendanceStatus::DidNotAttend->value)
        ->and($attendance->checked_in_at)->toBeNull()
        ->and(data_get($attendance->metadata, 'attendance.original_checked_in_at'))->toBeString()
        ->and(app(EventCommercePolicy::class)->hasAttendanceEvidence($registration->fresh()))->toBeFalse();
});

it('keeps occurrence check-in separate from event attendance', function () {
    $staff = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'metadata' => ['registration' => ['check_in_enabled' => true]],
    ]);
    addTestMember($event, $staff, 'viewer');

    $registration = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Farid Participant', 'farid@example.test')
        ->create(['status' => 'confirmed']);
    $participant = $registration->participants()->firstOrFail();
    $occurrenceId = (string) $event->primaryOccurrence()->value('id');

    Livewire::actingAs($staff)
        ->test(Participants::class, ['event' => $event])
        ->set('scope', 'occurrence')
        ->set('occurrenceId', $occurrenceId)
        ->call('checkInParticipant', (string) $participant->getKey());

    expect(EventCheckin::query()
        ->where('event_registration_participant_id', $participant->getKey())
        ->where('event_occurrence_id', $occurrenceId)
        ->whereNull('event_session_id')
        ->exists())->toBeTrue()
        ->and(EventCheckin::query()
            ->where('event_registration_participant_id', $participant->getKey())
            ->whereNull('event_occurrence_id')
            ->exists())->toBeFalse();
});

it('allows later attendance correction and protects identity lookup', function () {
    $staff = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'metadata' => [
            'registration' => [
                'check_in_enabled' => false,
                'participant_identity' => 'ic',
            ],
        ],
    ]);
    addTestMember($event, $staff, 'viewer');

    $registration = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Hana Identity', 'hana@example.test')
        ->create(['status' => 'confirmed']);
    $participant = $registration->participants()->firstOrFail();
    $participant->forceFill([
        'metadata' => [
            'event_checkout' => [
                'identity_document' => ParticipantIdentity::protect('ic', '900101-14-5678'),
            ],
        ],
    ])->save();

    $component = Livewire::actingAs($staff)
        ->test(Participants::class, ['event' => $event])
        ->set('search', '900101145678')
        ->assertSee('Hana Identity')
        ->call('markParticipantAttendance', (string) $participant->getKey(), EventAttendanceStatus::DidNotAttend->value)
        ->call('markParticipantAttendance', (string) $participant->getKey(), EventAttendanceStatus::NotRecorded->value);

    $component->assertSee('Hana Identity');

    $attendance = EventCheckin::query()
        ->where('event_registration_participant_id', $participant->getKey())
        ->latest('created_at')
        ->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance?->cancelled_at)->not->toBeNull()
        ->and(EventAttendanceLog::query()
            ->where('event_attendance_id', $attendance?->getKey())
            ->where('action', 'attendance_cleared')
            ->exists())->toBeTrue();
});

it('does not allow an unrelated user to open the participant workspace', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.events.participants', $event))
        ->assertForbidden();
});
