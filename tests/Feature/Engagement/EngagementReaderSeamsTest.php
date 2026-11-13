<?php

declare(strict_types=1);

use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\SubjectFollows;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Shared\Engagement\EngagementReader;
use App\Shared\Engagement\EngagementSubjectType;

it('binds the engagement read contracts to the root reader', function (): void {
    expect(app(EventEngagement::class))->toBeInstanceOf(EngagementReader::class)
        ->and(app(SubjectFollows::class))->toBeInstanceOf(EngagementReader::class);
});

it('reads saved and going state with the card counts for one event', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $event = Event::factory()->create();

    $user->bookmark($event);
    $user->respond($event, 'going');
    $other->bookmark($event);
    $other->respond($event, 'going');

    $state = app(EventEngagement::class)->stateFor((string) $user->getKey(), (string) $event->getKey());

    expect($state->isSaved)->toBeTrue()
        ->and($state->isGoing)->toBeTrue()
        ->and($state->savesCount)->toBe(2)
        ->and($state->goingCount)->toBe(2);
});

it('batch reads saved and going state without changing the per-event counts', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $savedOnly = Event::factory()->create();
    $goingOnly = Event::factory()->create();
    $empty = Event::factory()->create();

    $user->bookmark($savedOnly);
    $user->respond($goingOnly, 'going');
    $other->bookmark($savedOnly);
    $other->respond($savedOnly, 'going');

    $states = app(EventEngagement::class)->statesFor((string) $user->getKey(), [
        (string) $savedOnly->getKey(),
        (string) $goingOnly->getKey(),
        (string) $empty->getKey(),
        '',
    ]);

    expect(array_keys($states))->toBe([
        (string) $savedOnly->getKey(),
        (string) $goingOnly->getKey(),
        (string) $empty->getKey(),
    ])
        ->and($states[(string) $savedOnly->getKey()]->isSaved)->toBeTrue()
        ->and($states[(string) $savedOnly->getKey()]->isGoing)->toBeFalse()
        ->and($states[(string) $savedOnly->getKey()]->savesCount)->toBe(2)
        ->and($states[(string) $savedOnly->getKey()]->goingCount)->toBe(1)
        ->and($states[(string) $goingOnly->getKey()]->isSaved)->toBeFalse()
        ->and($states[(string) $goingOnly->getKey()]->isGoing)->toBeTrue()
        ->and($states[(string) $empty->getKey()]->isSaved)->toBeFalse()
        ->and($states[(string) $empty->getKey()]->isGoing)->toBeFalse()
        ->and($states[(string) $empty->getKey()]->savesCount)->toBe(0)
        ->and($states[(string) $empty->getKey()]->goingCount)->toBe(0);
});

it('still returns the event id lists used by listing filters', function (): void {
    $user = User::factory()->create();
    $saved = Event::factory()->create();
    $going = Event::factory()->create();

    $user->bookmark($saved);
    $user->respond($going, 'going');

    $reader = app(EventEngagement::class);

    expect($reader->savedEventIds((string) $user->getKey()))->toBe([(string) $saved->getKey()])
        ->and($reader->goingEventIds((string) $user->getKey()))->toBe([(string) $going->getKey()]);
});

it('reads follow state and follower counts across directory subjects', function (string $subjectType): void {
    $subject = match ($subjectType) {
        'institution' => Institution::factory()->create(),
        'person' => Person::factory()->create(),
        'reference' => Reference::factory()->create(),
        'series' => Series::factory()->create(),
    };

    $user = User::factory()->create();
    $other = User::factory()->create();

    $user->follow($subject);
    $other->follow($subject);

    $reader = app(SubjectFollows::class);
    $type = EngagementSubjectType::from($subjectType);

    $state = $reader->followStateFor((string) $user->getKey(), $type, (string) $subject->getKey());

    expect($state->isFollowing)->toBeTrue()
        ->and($state->followersCount)->toBe(2)
        ->and($reader->followerCount($type, (string) $subject->getKey()))->toBe(2)
        ->and($reader->followedSubjectIds((string) $user->getKey(), $type))->toBe([(string) $subject->getKey()]);
})->with(['institution', 'person', 'reference', 'series']);

it('batch reads follow state with counts and without inactive rows', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $followed = Institution::factory()->create();
    $notFollowed = Institution::factory()->create();
    $inactive = Person::factory()->create();

    $user->follow($followed);
    $other->follow($followed);
    $other->follow($notFollowed);
    $user->follow($inactive);
    $user->unfollow($inactive);

    $reader = app(SubjectFollows::class);

    $states = $reader->followStatesFor((string) $user->getKey(), EngagementSubjectType::Institution, [
        (string) $followed->getKey(),
        (string) $notFollowed->getKey(),
        '',
    ]);

    expect(array_keys($states))->toBe([
        (string) $followed->getKey(),
        (string) $notFollowed->getKey(),
    ])
        ->and($states[(string) $followed->getKey()]->isFollowing)->toBeTrue()
        ->and($states[(string) $followed->getKey()]->followersCount)->toBe(2)
        ->and($states[(string) $notFollowed->getKey()]->isFollowing)->toBeFalse()
        ->and($states[(string) $notFollowed->getKey()]->followersCount)->toBe(1)
        ->and($reader->followStateFor((string) $user->getKey(), EngagementSubjectType::Person, (string) $inactive->getKey())->isFollowing)->toBeFalse()
        ->and($reader->followerCount(EngagementSubjectType::Person, (string) $inactive->getKey()))->toBe(0);
});

it('returns only active follower account ids for notification reads', function (): void {
    $institution = Institution::factory()->create();
    $active = User::factory()->create();
    $inactive = User::factory()->create();

    $active->follow($institution);
    $inactive->follow($institution);
    $inactive->unfollow($institution);

    expect(app(EngagementReader::class)->followerAccountIds(
        EngagementSubjectType::Institution,
        (string) $institution->getKey(),
    ))->toBe([(string) $active->getKey()]);
});

it('projects package activity records without exposing package models', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $institution = Institution::factory()->create();

    $bookmark = $user->bookmark($event);
    $response = $user->respond($event, 'going');
    $follow = $user->follow($institution);

    $reader = app(EngagementReader::class);

    $bookmarkActivity = $reader->bookmarkActivity((string) $bookmark->getKey());
    $responseActivity = $reader->responseActivity((string) $response->getKey());
    $followActivity = $reader->followActivity((string) $follow->getKey());

    expect($bookmarkActivity?->id)->toBe((string) $bookmark->getKey())
        ->and($bookmarkActivity?->actorType)->toBe($user->getMorphClass())
        ->and($bookmarkActivity?->actorId)->toBe((string) $user->getKey())
        ->and($bookmarkActivity?->status)->toBe('active')
        ->and($bookmarkActivity?->occurredAt)->not->toBeNull()
        ->and($responseActivity?->status)->toBe('active')
        ->and($followActivity?->status)->toBe('active');
});

it('detects earlier bookmark and follow activity for the same actor', function (): void {
    $user = User::factory()->create();
    $firstEvent = Event::factory()->create();
    $secondEvent = Event::factory()->create();
    $institution = Institution::factory()->create();

    $earlierBookmark = $user->bookmark($firstEvent);
    $earlierBookmark->forceFill(['bookmarked_at' => now()->subDay()])->save();

    $laterBookmark = $user->bookmark($secondEvent);
    $laterBookmark->forceFill(['bookmarked_at' => now()])->save();

    $earlierFollow = $user->follow($institution);
    $earlierFollow->forceFill(['followed_at' => now()->subDay()])->save();

    $reader = app(EngagementReader::class);

    expect($reader->hasEarlierBookmarkActivity(
        $user->getMorphClass(),
        (string) $user->getKey(),
        $laterBookmark->bookmarked_at,
        (string) $laterBookmark->getKey(),
    ))->toBeTrue()
        ->and($reader->hasEarlierBookmarkActivity(
            $user->getMorphClass(),
            (string) $user->getKey(),
            $earlierBookmark->bookmarked_at,
            (string) $earlierBookmark->getKey(),
        ))->toBeFalse()
        ->and($reader->hasEarlierFollowActivity(
            $user->getMorphClass(),
            (string) $user->getKey(),
            now()->addMinute(),
            (string) $earlierFollow->getKey(),
        ))->toBeFalse();
});
