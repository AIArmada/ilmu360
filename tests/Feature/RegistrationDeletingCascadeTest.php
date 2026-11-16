<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;

it('deletes the registration subtree when a registration is deleted', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $registration = Registration::factory()->for($event)->forRegistrant($user)->create();
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
    $checkin = EventCheckin::factory()->for($event)->create([
        'attendee_type' => $user->getMorphClass(),
        'attendee_id' => $user->id,
        'event_registration_id' => $registration->id,
        'event_registration_participant_id' => $participant->id,
    ]);
    $log = EventAttendanceLog::query()->create([
        'event_attendance_id' => $checkin->id,
        'action' => 'check_in',
        'source' => 'test',
        'occurred_at' => now(),
    ]);
    $unlinkedCheckin = EventCheckin::factory()->for($event)->create([
        'event_registration_id' => $registration->id,
        'event_registration_participant_id' => null,
    ]);
    $child = Registration::factory()->for($event)->withPrimaryParticipant('Child Participant')->create([
        'parent_registration_id' => $registration->id,
    ]);
    $childParticipant = $child->participants()->where('is_primary', true)->sole();
    $grandchild = Registration::factory()->for($event)->withPrimaryParticipant('Grandchild Participant')->create([
        'parent_registration_id' => $child->id,
    ]);
    $grandchildParticipant = $grandchild->participants()->where('is_primary', true)->sole();
    $childUnlinkedCheckin = EventCheckin::factory()->for($event)->create([
        'event_registration_id' => null,
        'event_registration_participant_id' => $childParticipant->id,
    ]);
    $childUnlinkedLog = EventAttendanceLog::query()->create([
        'event_attendance_id' => $childUnlinkedCheckin->id,
        'action' => 'check_in',
        'source' => 'test',
        'occurred_at' => now(),
    ]);

    $registrationId = $registration->id;
    $participantId = $participant->id;
    $answerId = $answer->id;
    $itemId = $item->id;
    $checkinId = $checkin->id;
    $unlinkedCheckinId = $unlinkedCheckin->id;
    $childId = $child->id;
    $grandchildId = $grandchild->id;
    $logId = $log->id;
    $childParticipantId = $childParticipant->id;
    $grandchildParticipantId = $grandchildParticipant->id;
    $childUnlinkedCheckinId = $childUnlinkedCheckin->id;
    $childUnlinkedLogId = $childUnlinkedLog->id;
    $contactMethodIds = DB::table('contact_methods')
        ->where('contactable_type', $participant->getMorphClass())
        ->where('contactable_id', $participant->id)
        ->pluck('id')
        ->all();

    $deletingOwnerContext = null;
    $deletingRegistrationIds = [];
    EventFacade::listen('eloquent.deleting: '.Registration::class, function (Registration $deletingRegistration) use (&$deletingOwnerContext, &$deletingRegistrationIds): void {
        $deletingOwnerContext = OwnerContext::resolve();
        $deletingRegistrationIds[] = (string) $deletingRegistration->getKey();
    });

    OwnerContext::withOwner($event, function () use ($registration): void {
        $registration->delete();
    });

    expect(DB::table('event_registrations')->where('id', $registrationId)->exists())->toBeFalse()
        ->and($deletingOwnerContext)->toBe($event)
        ->and($deletingRegistrationIds)->toBe([$grandchildId, $childId, $registrationId])
        ->and(DB::table('event_registrations')->where('id', $childId)->exists())->toBeFalse()
        ->and(DB::table('event_registrations')->where('id', $grandchildId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_participants')->where('id', $participantId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_participants')->where('id', $childParticipantId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_participants')->where('id', $grandchildParticipantId)->exists())->toBeFalse()
        ->and(DB::table('contact_methods')->whereIn('id', $contactMethodIds)->exists())->toBeFalse()
        ->and(DB::table('event_registration_answers')->where('id', $answerId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_items')->where('id', $itemId)->exists())->toBeFalse()
        ->and(DB::table('event_attendances')->where('id', $checkinId)->exists())->toBeFalse()
        ->and(DB::table('event_attendances')->where('id', $unlinkedCheckinId)->exists())->toBeFalse()
        ->and(DB::table('event_attendances')->where('id', $childUnlinkedCheckinId)->exists())->toBeFalse()
        ->and(DB::table('event_attendance_logs')->where('id', $logId)->exists())->toBeFalse()
        ->and(DB::table('event_attendance_logs')->where('id', $childUnlinkedLogId)->exists())->toBeFalse();
});

it('refuses to delete a registration subtree that crosses event boundaries', function (): void {
    $event = Event::factory()->create();
    $otherEvent = Event::factory()->create();
    $registration = Registration::factory()->for($event)->withPrimaryParticipant('Parent')->create();
    $foreignChild = Registration::factory()->for($otherEvent)->withPrimaryParticipant('Foreign Child')->create([
        'parent_registration_id' => $registration->getKey(),
    ]);
    $registrationIds = [(string) $registration->getKey(), (string) $foreignChild->getKey()];

    expect(fn () => $registration->delete())->toThrow(LogicException::class)
        ->and(DB::table('event_registrations')->whereIn('id', $registrationIds)->count())->toBe(2)
        ->and(DB::table('event_registration_participants')->whereIn('event_registration_id', $registrationIds)->count())->toBe(2);
});

it('refuses to delete a registration subtree with a parent cycle', function (): void {
    $event = Event::factory()->create();
    $registration = Registration::factory()->for($event)->withPrimaryParticipant('Parent')->create();
    $child = Registration::factory()->for($event)->withPrimaryParticipant('Child')->create([
        'parent_registration_id' => $registration->getKey(),
    ]);
    $registrationIds = [(string) $registration->getKey(), (string) $child->getKey()];

    DB::table('event_registrations')->where('id', $registration->getKey())->update([
        'parent_registration_id' => $child->getKey(),
    ]);

    expect(fn () => $registration->delete())->toThrow(LogicException::class)
        ->and(DB::table('event_registrations')->whereIn('id', $registrationIds)->count())->toBe(2)
        ->and(DB::table('event_registration_participants')->whereIn('event_registration_id', $registrationIds)->count())->toBe(2);
});

it('deletes participant-owned attendance rows without a registration link', function () {
    $event = Event::factory()->create();
    $registration = Registration::factory()->for($event)->withPrimaryParticipant('Participant Only')->create();
    $participant = $registration->participants()->where('is_primary', true)->sole();
    $attendance = EventCheckin::factory()->for($event)->create([
        'event_registration_id' => null,
        'event_registration_participant_id' => $participant->id,
    ]);
    $log = EventAttendanceLog::query()->create([
        'event_attendance_id' => $attendance->id,
        'action' => 'check_in',
        'source' => 'test',
        'occurred_at' => now(),
    ]);

    $registration->delete();

    expect(DB::table('event_registration_participants')->where('id', $participant->id)->exists())->toBeFalse()
        ->and(DB::table('event_attendances')->where('id', $attendance->id)->exists())->toBeFalse()
        ->and(DB::table('event_attendance_logs')->where('id', $log->id)->exists())->toBeFalse();
});
