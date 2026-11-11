<?php

use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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

    $registrationId = $registration->id;
    $participantId = $participant->id;
    $answerId = $answer->id;
    $itemId = $item->id;
    $checkinId = $checkin->id;
    $logId = $log->id;
    $contactMethodIds = DB::table('contact_methods')
        ->where('contactable_type', $participant->getMorphClass())
        ->where('contactable_id', $participant->id)
        ->pluck('id')
        ->all();

    $registration->delete();

    expect(DB::table('event_registrations')->where('id', $registrationId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_participants')->where('id', $participantId)->exists())->toBeFalse()
        ->and(DB::table('contact_methods')->whereIn('id', $contactMethodIds)->exists())->toBeFalse()
        ->and(DB::table('event_registration_answers')->where('id', $answerId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_items')->where('id', $itemId)->exists())->toBeFalse()
        ->and(DB::table('event_attendances')->where('id', $checkinId)->exists())->toBeFalse()
        ->and(DB::table('event_attendance_logs')->where('id', $logId)->exists())->toBeFalse();
});
