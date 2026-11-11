<?php

declare(strict_types=1);

use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use App\Features\Registration\Actions\ConfirmOfflineAdmissionAction;
use App\Features\Registration\Actions\IssueOfflineAdmissionAction;
use App\Features\Registration\Actions\PrepareAdmissionCheckoutAction;
use App\Features\Registration\Actions\RecordEventParticipantCheckInAction;
use App\Features\Registration\Actions\RecordFreeCheckoutMetadataAction;
use App\Features\Registration\Actions\RecordSelfCheckInAction;
use App\Features\Registration\Actions\RegisterFreeAdmissionAction;
use App\Features\Registration\Actions\UpdateEventParticipantAttendanceAction;
use App\Features\Registration\Api\AdmissionCheckout;
use App\Features\Registration\Api\AdmissionFacts;
use App\Features\Registration\Api\AdmissionIssuance;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Features\Registration\Api\AttendanceCommands;
use App\Features\Registration\Api\AttendanceState;
use App\Features\Registration\FeatureServiceProvider as RegistrationFeatureServiceProvider;
use App\Features\Registration\Queries\DatabaseAdmissionFacts;
use App\Features\Registration\Queries\ResolveAttendanceState;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Platform\FeatureCatalog;
use App\Shared\Events\Enums\EventAttendanceStatus;
use App\Shared\Registration\CheckInNotifier;
use App\Shared\ShareTracking\ParticipationAttribution;
use App\Support\Registration\EventNotificationCheckInNotifier;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use App\Support\ShareTracking\ShareTrackingParticipationAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{event: Event, registration: Registration, participant_id: string, contact_method_ids: list<string>, answer_id: string, item_id: string, attendance_ids: list<string>, log_ids: list<string>}
 */
function makeRegistrationDeletionFixture(): array
{
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $registration = Registration::factory()->forRegistrant($user)->create([
        'event_id' => $event->id,
    ]);
    $participant = $registration->participants()->where('is_primary', true)->sole();

    $answer = EventRegistrationAnswer::query()->create([
        'event_registration_id' => $registration->id,
        'event_registration_participant_id' => $participant->id,
        'event_id' => $event->id,
        'field_key' => 'diet',
        'question' => 'Dietary requirement',
        'answer' => 'Vegetarian',
    ]);

    $item = EventRegistrationItem::query()->create([
        'event_registration_id' => $registration->id,
        'event_id' => $event->id,
        'ticket_type_id' => (string) Str::uuid(),
        'quantity' => 1,
        'status' => 'paid',
    ]);

    $checkin = EventCheckin::factory()->create([
        'event_id' => $event->id,
        'event_registration_id' => $registration->id,
        'event_registration_participant_id' => $participant->id,
        'attendee_type' => $user->getMorphClass(),
        'attendee_id' => $user->id,
    ]);

    $logs = collect([$checkin])
        ->map(fn (EventCheckin $attendance): EventAttendanceLog => EventAttendanceLog::query()->create([
            'event_attendance_id' => $attendance->id,
            'action' => 'check_in',
            'source' => 'test',
            'occurred_at' => now(),
        ]));

    $contactMethodIds = DB::table('contact_methods')
        ->where('contactable_type', $participant->getMorphClass())
        ->where('contactable_id', $participant->getKey())
        ->pluck('id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->values()
        ->all();

    return [
        'event' => $event,
        'registration' => $registration,
        'participant_id' => (string) $participant->getKey(),
        'contact_method_ids' => $contactMethodIds,
        'answer_id' => (string) $answer->getKey(),
        'item_id' => (string) $item->getKey(),
        'attendance_ids' => [(string) $checkin->getKey()],
        'log_ids' => $logs->map(static fn (EventAttendanceLog $log): string => (string) $log->getKey())->all(),
    ];
}

/**
 * @param  array{registration: Registration, participant_id: string, contact_method_ids: list<string>, answer_id: string, item_id: string, attendance_ids: list<string>, log_ids: list<string>}  $fixture
 */
function assertRegistrationSubtreeDeleted(array $fixture): void
{
    expect(Registration::query()->whereKey($fixture['registration']->getKey())->exists())->toBeFalse()
        ->and(DB::table('event_registration_participants')->where('id', $fixture['participant_id'])->exists())->toBeFalse()
        ->and(DB::table('contact_methods')->whereIn('id', $fixture['contact_method_ids'])->exists())->toBeFalse()
        ->and(DB::table('event_registration_answers')->where('id', $fixture['answer_id'])->exists())->toBeFalse()
        ->and(DB::table('event_registration_items')->where('id', $fixture['item_id'])->exists())->toBeFalse()
        ->and(DB::table('event_attendances')->whereIn('id', $fixture['attendance_ids'])->exists())->toBeFalse()
        ->and(DB::table('event_attendance_logs')->whereIn('id', $fixture['log_ids'])->exists())->toBeFalse();
}

it('discovers the registration feature manifest and provider exactly once', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $providers = require base_path('bootstrap/providers.php');

    expect($catalog['features'])->toHaveKey('Registration')
        ->and($catalog['owners'])->toHaveKey('table:event_registrations')
        ->and($catalog['owners']['table:event_registrations'])->toBe('Registration')
        ->and($catalog['owners']['table:event_registration_participants'])->toBe('Registration')
        ->and($catalog['owners']['table:event_registration_answers'])->toBe('Registration')
        ->and($catalog['owners']['table:event_registration_items'])->toBe('Registration')
        ->and($catalog['owners'])->not->toHaveKey('table:event_registration_questions')
        ->and($catalog['owners'])->toHaveKey('table:event_attendances')
        ->and($catalog['owners']['table:event_attendances'])->toBe('Registration')
        ->and($catalog['owners'])->toHaveKey('table:event_attendance_logs')
        ->and($catalog['owners']['table:event_attendance_logs'])->toBe('Registration')
        ->and($catalog['writes'][RegisterFreeAdmissionAction::class])->toEqualCanonicalizing([
            'table:event_registrations',
            'table:event_registration_participants',
            'table:event_registration_answers',
            'table:event_registration_items',
        ])
        ->and($catalog['writes'][IssueOfflineAdmissionAction::class])->toEqualCanonicalizing([
            'table:event_registrations',
            'table:event_registration_participants',
            'table:event_registration_answers',
            'table:event_registration_items',
        ])
        ->and($catalog['writes'][RecordFreeCheckoutMetadataAction::class])->toEqualCanonicalizing([
            'table:event_registrations',
        ])
        ->and($catalog['writes'])->not->toHaveKey(ConfirmOfflineAdmissionAction::class)
        ->and($catalog['writes'][RecordSelfCheckInAction::class])->toEqualCanonicalizing([
            'table:event_attendances',
            'table:event_attendance_logs',
        ])
        ->and($catalog['writes'][RecordEventParticipantCheckInAction::class])->toEqualCanonicalizing([
            'table:event_attendances',
            'table:event_attendance_logs',
        ])
        ->and($catalog['writes'][UpdateEventParticipantAttendanceAction::class])->toEqualCanonicalizing([
            'table:event_attendances',
            'table:event_attendance_logs',
        ])
        ->and(array_keys($providers, RegistrationFeatureServiceProvider::class, true))->toHaveCount(1)
        ->and(app()->getLoadedProviders())->toHaveKey(RegistrationFeatureServiceProvider::class);
});

it('binds the admission registration boundary to the free admission action', function (): void {
    expect(app(AdmissionRegistration::class))->toBeInstanceOf(RegisterFreeAdmissionAction::class)
        ->and(app(ParticipationAttribution::class))->toBeInstanceOf(ShareTrackingParticipationAttribution::class);
});

it('binds the event checkout boundary to its feature action', function (): void {
    expect(app(AdmissionCheckout::class))->toBeInstanceOf(PrepareAdmissionCheckoutAction::class);
});

it('binds the admission issuance boundary to the offline admission action', function (): void {
    expect(app(AdmissionIssuance::class))->toBeInstanceOf(IssueOfflineAdmissionAction::class);
});

it('binds the attendance boundaries to their feature implementations', function (): void {
    expect(app(AttendanceCommands::class))->toBeInstanceOf(RecordSelfCheckInAction::class)
        ->and(app(AttendanceState::class))->toBeInstanceOf(ResolveAttendanceState::class)
        ->and(app(CheckInNotifier::class))->toBeInstanceOf(EventNotificationCheckInNotifier::class);
});

it('records self check-in through the scalar attendance boundary', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->accessPolicy()->delete();

    $state = app(AttendanceState::class)->forUser(
        (string) $event->getKey(),
        (string) $user->getKey(),
    );

    expect($state)->toMatchArray([
        'available' => true,
        'reason' => null,
        'method' => 'self_reported',
        'registration_id' => null,
    ]);

    $created = app(AttendanceCommands::class)->recordSelfCheckIn(
        eventId: (string) $event->getKey(),
        userId: (string) $user->getKey(),
        registrationId: $state['registration_id'],
        method: $state['method'],
    );

    expect($created['status'])->toBe('created')
        ->and($created['checkin_id'])->not->toBe('')
        ->and(EventCheckin::query()->whereKey($created['checkin_id'])->exists())->toBeTrue();

    $duplicate = app(AttendanceCommands::class)->recordSelfCheckIn(
        eventId: (string) $event->getKey(),
        userId: (string) $user->getKey(),
        registrationId: $state['registration_id'],
        method: $state['method'],
    );

    expect($duplicate['status'])->toBe('duplicate')
        ->and($duplicate['checkin_id'])->toBe($created['checkin_id']);
});

it('records staff participant attendance through the scalar boundary', function (): void {
    $staff = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ]);
    addTestMember($event, $staff, 'viewer');

    $registration = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Seam Participant', 'seam-participant@example.test')
        ->create(['status' => 'confirmed']);
    $participant = $registration->participants()->firstOrFail();
    $commands = app(AttendanceCommands::class);

    $created = $commands->recordParticipantCheckIn(
        eventId: (string) $event->getKey(),
        participantId: (string) $participant->getKey(),
        staffId: (string) $staff->getKey(),
    );

    expect($created['status'])->toBe('created')
        ->and(EventCheckin::query()->whereKey($created['checkin_id'])->where('check_in_source', 'staff_manual')->exists())->toBeTrue();

    $duplicate = $commands->recordParticipantCheckIn(
        eventId: (string) $event->getKey(),
        participantId: (string) $participant->getKey(),
        staffId: (string) $staff->getKey(),
    );

    expect($duplicate['status'])->toBe('duplicate')
        ->and($duplicate['checkin_id'])->toBe($created['checkin_id']);

    $attendanceId = $commands->setParticipantAttendance(
        eventId: (string) $event->getKey(),
        participantId: (string) $participant->getKey(),
        status: EventAttendanceStatus::DidNotAttend->value,
        actorId: (string) $staff->getKey(),
        note: 'Seam correction',
    );

    expect($attendanceId)->toBe($created['checkin_id'])
        ->and(EventCheckin::query()->whereKey($attendanceId)->value('attendance_type'))
        ->toBe(EventAttendanceStatus::DidNotAttend->value);

    $commands->clearAttendance(
        eventId: (string) $event->getKey(),
        participantId: (string) $participant->getKey(),
        actorId: (string) $staff->getKey(),
    );

    expect(EventCheckin::query()->whereKey($attendanceId)->whereNotNull('cancelled_at')->exists())->toBeTrue()
        ->and(EventAttendanceLog::query()
            ->where('event_attendance_id', $attendanceId)
            ->where('action', 'attendance_cleared')
            ->exists())->toBeTrue();
});

it('registers free admission through the scalar boundary', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    $result = app(AdmissionRegistration::class)->registerFree(
        eventId: (string) $event->getKey(),
        participants: [[
            'name' => 'Seam Guest',
            'email' => 'seam-guest@example.test',
            'is_primary' => true,
            'is_purchaser' => true,
            'answers' => [[
                'field_key' => 'diet',
                'question' => 'Dietary requirement',
                'answer' => 'Vegetarian',
            ]],
        ]],
        registrantId: null,
        options: ['with_pass' => true],
    );

    $registrationId = (string) $result['primary_registration_id'];

    expect($registrationId)->not->toBe('')
        ->and($result['registrations'])->toBe([
            ['registration_id' => $registrationId, 'created' => true],
        ]);

    $registration = Registration::query()->findOrFail($registrationId);
    $receipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', ShareOutcomeBusinessKey::eventRegistration($registrationId))
        ->firstOrFail();

    expect($registration->event_id)->toBe($event->id)
        ->and($registration->statusValue())->toBe('confirmed')
        ->and($registration->participants()->count())->toBe(1)
        ->and(DB::table('event_registration_answers')->where('event_registration_id', $registrationId)->count())->toBe(1)
        ->and($receipt->isAttributed())->toBeFalse();
});

it('records free checkout metadata through the scalar checkout boundary', function (): void {
    $event = Event::factory()->create();
    $registration = Registration::factory()->create([
        'event_id' => $event->id,
        'metadata' => [
            'unrelated' => ['keep' => true],
            'event_checkout' => [
                'identity_document' => ['type' => 'ic'],
                'agreement' => ['locale' => 'ms'],
            ],
        ],
    ]);
    $second = Registration::factory()->create(['event_id' => $event->id]);

    app(AdmissionCheckout::class)->recordCheckoutMetadata(
        registrationIds: [(string) $registration->getKey(), (string) $second->getKey()],
        batchId: 'batch-seam',
        agreement: [
            'version' => 'event-participation-v1',
            'accepted_by_id' => 'user-seam',
        ],
    );

    foreach ([$registration, $second] as $row) {
        $row->refresh();

        expect(data_get($row->metadata, 'event_checkout.batch_id'))->toBe('batch-seam')
            ->and(data_get($row->metadata, 'event_checkout.agreement.version'))->toBe('event-participation-v1')
            ->and(data_get($row->metadata, 'event_checkout.agreement.accepted_by_id'))->toBe('user-seam');
    }

    $metadata = $registration->fresh()->metadata;

    expect(data_get($metadata, 'unrelated.keep'))->toBeTrue()
        ->and(data_get($metadata, 'event_checkout.identity_document.type'))->toBe('ic')
        ->and(data_get($metadata, 'event_checkout.agreement.locale'))->toBe('ms');
});

it('binds admission facts to the registration feature query', function (): void {
    $event = Event::factory()->create();
    $facts = app(AdmissionFacts::class);

    expect($facts)->toBeInstanceOf(DatabaseAdmissionFacts::class)
        ->and($facts->hasRegistrations((string) $event->getKey()))->toBeFalse();

    Registration::factory()->create(['event_id' => $event->id]);

    expect($facts->hasRegistrations((string) $event->getKey()))->toBeTrue()
        ->and($facts->hasRegistrations((string) Str::uuid()))->toBeFalse();
});

it('leaves no registration orphans when an event is deleted', function (): void {
    $fixture = makeRegistrationDeletionFixture();
    $eventId = (string) $fixture['event']->getKey();

    $fixture['event']->delete();

    expect(Event::query()->whereKey($eventId)->exists())->toBeFalse();

    assertRegistrationSubtreeDeleted($fixture);
});

it('leaves no registration orphans when a single registration is deleted', function (): void {
    $fixture = makeRegistrationDeletionFixture();
    $eventId = (string) $fixture['event']->getKey();

    $fixture['registration']->delete();

    assertRegistrationSubtreeDeleted($fixture);

    expect(Event::query()->whereKey($eventId)->exists())->toBeTrue();
});
