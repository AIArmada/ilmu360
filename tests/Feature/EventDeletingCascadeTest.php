<?php

use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Response;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventReference;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use App\Features\Events\Actions\Delete\DeleteEventAction;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventCheckin;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\MediaLink;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('deletes all related records when an event is deleted', function () {
    $event = Event::factory()->create();
    $person = Person::factory()->create();
    $user = User::factory()->create();

    EventAccessPolicy::factory()->create(['event_id' => $event->id]);
    EventKeyPerson::factory()->create([
        'event_id' => $event->id,
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => 'person',
    ]);
    EventReference::factory()->create(['event_id' => $event->id]);
    $registration = Registration::factory()->forRegistrant($user)->create(['event_id' => $event->id]);
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
    $log = EventAttendanceLog::query()->create([
        'event_attendance_id' => $checkin->id,
        'action' => 'check_in',
        'source' => 'test',
        'occurred_at' => now(),
    ]);
    EventSubmission::factory()->create(['event_id' => $event->id, 'submitter_type' => $user->getMorphClass(), 'submitter_id' => $user->id]);
    EventChangeAnnouncement::factory()->create(['event_id' => $event->id]);

    MediaLink::factory()->create([
        'mediable_id' => $event->id,
        'mediable_type' => $event->getMorphClass(),
    ]);
    ModerationReview::factory()->create([
        'actionable_type' => Event::class,
        'actionable_id' => $event->id,
        'actioned_by_type' => $user->getMorphClass(),
        'actioned_by_id' => $user->id,
    ]);

    Bookmark::factory()->create([
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
    ]);
    Response::factory()->create([
        'responder_type' => $user->getMorphClass(),
        'responder_id' => $user->id,
        'respondable_type' => $event->getMorphClass(),
        'respondable_id' => $event->id,
        'response_type' => 'going',
    ]);

    $eventId = $event->id;
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

    $event->delete();

    expect(EventAccessPolicy::query()->where('event_id', $eventId)->exists())->toBeFalse()
        ->and(EventKeyPerson::query()->where('event_id', $eventId)->exists())->toBeFalse()
        ->and(EventReference::query()->where('event_id', $eventId)->exists())->toBeFalse()
        ->and(EventCheckin::query()->where('event_id', $eventId)->exists())->toBeFalse()
        ->and(EventSubmission::query()->where('event_id', $eventId)->exists())->toBeFalse()
        ->and(EventChangeAnnouncement::query()->where('event_id', $eventId)->exists())->toBeFalse()
        ->and(MediaLink::query()
            ->where('mediable_type', $event->getMorphClass())
            ->where('mediable_id', $eventId)
            ->exists()
        )->toBeFalse()
        ->and(ModerationReview::query()
            ->where('actionable_type', Event::class)
            ->where('actionable_id', $eventId)
            ->exists()
        )->toBeFalse()
        ->and(DB::table('event_registrations')->where('id', $registrationId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_participants')->where('id', $participantId)->exists())->toBeFalse()
        ->and(DB::table('contact_methods')->whereIn('id', $contactMethodIds)->exists())->toBeFalse()
        ->and(DB::table('event_registration_answers')->where('id', $answerId)->exists())->toBeFalse()
        ->and(DB::table('event_registration_items')->where('id', $itemId)->exists())->toBeFalse()
        ->and(DB::table('event_attendances')->where('id', $checkinId)->exists())->toBeFalse()
        ->and(DB::table('event_attendance_logs')->where('id', $logId)->exists())->toBeFalse()
        ->and(Bookmark::query()
            ->where('bookmarkable_type', $event->getMorphClass())
            ->where('bookmarkable_id', $eventId)
            ->exists()
        )->toBeFalse()
        ->and(Response::query()
            ->where('respondable_type', $event->getMorphClass())
            ->where('respondable_id', $eventId)
            ->exists()
        )->toBeFalse();
});

it('deletes an event through the named delete action', function () {
    $event = Event::factory()->create();
    $eventId = (string) $event->getKey();

    $deleted = app(DeleteEventAction::class)->handle($eventId);

    expect($deleted)->toBeTrue()
        ->and(Event::query()->whereKey($eventId)->exists())->toBeFalse()
        ->and(app(DeleteEventAction::class)->handle($eventId))->toBeFalse();
});

it('detaches members when event is deleted', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    $event->members()->attach($user);

    expect(DB::table('event_members')->where('event_id', $event->id)->exists())->toBeTrue();

    $event->delete();

    expect(DB::table('event_members')->where('event_id', $event->id)->exists())->toBeFalse();
});
