<?php

declare(strict_types=1);

use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use App\Features\Registration\Actions\RegisterFreeAdmissionAction;
use App\Features\Registration\Api\AdmissionFacts;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Features\Registration\FeatureServiceProvider as RegistrationFeatureServiceProvider;
use App\Features\Registration\Queries\DatabaseAdmissionFacts;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Platform\FeatureCatalog;
use App\Shared\ShareTracking\ParticipationAttribution;
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
        ->and($catalog['owners'])->not->toHaveKey('table:event_attendances')
        ->and($catalog['owners'])->not->toHaveKey('table:event_attendance_logs')
        ->and($catalog['writes'][RegisterFreeAdmissionAction::class])->toEqualCanonicalizing([
            'table:event_registrations',
            'table:event_registration_participants',
            'table:event_registration_answers',
            'table:event_registration_items',
        ])
        ->and(array_keys($providers, RegistrationFeatureServiceProvider::class, true))->toHaveCount(1)
        ->and(app()->getLoadedProviders())->toHaveKey(RegistrationFeatureServiceProvider::class);
});

it('binds the admission registration boundary to the free admission action', function (): void {
    expect(app(AdmissionRegistration::class))->toBeInstanceOf(RegisterFreeAdmissionAction::class)
        ->and(app(ParticipationAttribution::class))->toBeInstanceOf(ShareTrackingParticipationAttribution::class);
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
