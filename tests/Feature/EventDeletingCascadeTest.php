<?php

use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventReference;
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

uses(RefreshDatabase::class);

it('deletes all related records when an event is deleted', function () {
    $event = Event::factory()->create();
    $person = Person::factory()->create();
    $user = User::factory()->create();

    EventAccessPolicy::factory()->create(['event_id' => $event->id]);
    EventKeyPerson::factory()->create([
        'event_id' => $event->id,
        'involveable_type' => 'speaker',
        'involveable_id' => $person->id,
        'role_code' => 'speaker',
    ]);
    EventReference::factory()->create(['event_id' => $event->id]);
    EventCheckin::factory()->create(['event_id' => $event->id]);
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
    Registration::factory()->create(['event_id' => $event->id]);

    $eventId = $event->id;

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
        ->and(Registration::query()->where('event_id', $eventId)->exists())->toBeFalse();
});

it('detaches members when event is deleted', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    $event->members()->attach($user);

    expect(DB::table('event_members')->where('event_id', $event->id)->exists())->toBeTrue();

    $event->delete();

    expect(DB::table('event_members')->where('event_id', $event->id)->exists())->toBeFalse();
});
