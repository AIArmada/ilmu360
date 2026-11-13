<?php

declare(strict_types=1);

use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Contracts\ShareTrackingContract;
use App\Enums\ShareOutcomeType;
use App\Features\Events\Actions\Delete\CleanupEventAction;
use App\Models\Event;
use App\Models\Institution;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Shared\Engagement\EngagementShareOutcomes;
use App\Support\Engagement\EngagementAccountLifecycleContributor;
use App\Support\ShareTracking\EngagementOutcomeRecorder;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * @return array{0: EngagementOutcomeRecorder, 1: Mockery\MockInterface&ShareTrackingContract}
 */
function d4aEngagementOutcomeRecorder(): array
{
    $tracking = Mockery::mock(ShareTrackingContract::class);

    return [
        new EngagementOutcomeRecorder($tracking, app(ReverseShareOutcomeAction::class)),
        $tracking,
    ];
}

it('binds the engagement share outcomes contract to the root recorder', function (): void {
    expect(app(EngagementShareOutcomes::class))->toBeInstanceOf(EngagementOutcomeRecorder::class);
});

it('records bookmark outcomes with the event save business key and the caller metadata shape', function (): void {
    [$recorder, $tracking] = d4aEngagementOutcomeRecorder();

    $bookmarkId = (string) Str::uuid();
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $request = Request::create('/events');
    $occurredAt = CarbonImmutable::parse('2026-11-12 09:00:00', 'UTC');
    $metadata = ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()];

    $tracking->shouldReceive('recordOutcome')
        ->once()
        ->withArgs(function (
            ShareOutcomeType $type,
            string $businessKey,
            ?Model $subject,
            ?User $actor,
            ?Request $receivedRequest,
            array $receivedMetadata,
            ?CarbonImmutable $receivedOccurredAt,
        ) use ($bookmarkId, $event, $user, $request, $metadata, $occurredAt): bool {
            return $type === ShareOutcomeType::EventSave
                && $businessKey === ShareOutcomeBusinessKey::eventSave($bookmarkId)
                && $subject instanceof Event
                && $subject->is($event)
                && $actor?->is($user) === true
                && $receivedRequest === $request
                && $receivedMetadata === $metadata
                && $receivedOccurredAt?->equalTo($occurredAt) === true;
        })
        ->andReturnNull();

    $recorder->recordBookmark($bookmarkId, $event, $user, $request, $metadata, $occurredAt);
});

it('records going responses with the event going business key', function (): void {
    [$recorder, $tracking] = d4aEngagementOutcomeRecorder();

    $responseId = (string) Str::uuid();
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $metadata = ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()];

    $tracking->shouldReceive('recordOutcome')
        ->once()
        ->withArgs(fn (ShareOutcomeType $type, string $businessKey): bool => $type === ShareOutcomeType::EventGoing
            && $businessKey === ShareOutcomeBusinessKey::eventGoing($responseId))
        ->andReturnNull();

    $recorder->recordResponse($responseId, $event, $user, null, $metadata);
});

it('records follow outcomes with the per-surface metadata shape unchanged', function (): void {
    [$recorder, $tracking] = d4aEngagementOutcomeRecorder();

    $followId = (string) Str::uuid();
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $cardMetadata = ['institution_id' => $event->getKey()];
    $apiMetadata = ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()];

    $tracking->shouldReceive('recordOutcome')
        ->once()
        ->withArgs(fn (ShareOutcomeType $type, string $businessKey, ?Model $subject, ?User $actor, ?Request $request, array $metadata): bool => $type === ShareOutcomeType::InstitutionFollow
            && $businessKey === ShareOutcomeBusinessKey::follow(ShareOutcomeType::InstitutionFollow, $followId)
            && $metadata === $cardMetadata)
        ->andReturnNull();

    $tracking->shouldReceive('recordOutcome')
        ->once()
        ->withArgs(fn (ShareOutcomeType $type, string $businessKey, ?Model $subject, ?User $actor, ?Request $request, array $metadata): bool => $type === ShareOutcomeType::InstitutionFollow
            && $businessKey === ShareOutcomeBusinessKey::follow(ShareOutcomeType::InstitutionFollow, $followId)
            && $metadata === $apiMetadata)
        ->andReturnNull();

    $recorder->recordFollow(ShareOutcomeType::InstitutionFollow, $followId, $event, $user, null, $cardMetadata);
    $recorder->recordFollow(ShareOutcomeType::InstitutionFollow, $followId, $event, $user, null, $apiMetadata);
});

it('rejects non-follow outcome types on follow recording', function (): void {
    [$recorder, $tracking] = d4aEngagementOutcomeRecorder();

    $tracking->shouldNotReceive('recordOutcome');

    $recorder->recordFollow(
        ShareOutcomeType::EventSave,
        (string) Str::uuid(),
        null,
        null,
        null,
        [],
    );
})->throws(InvalidArgumentException::class);

it('does not reverse outcomes when account restore or event cleanup deletes engagement rows', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    $bookmark = $user->bookmark($event);
    $response = $user->respond($event, 'going');

    $recorder = app(EngagementShareOutcomes::class);
    $recorder->recordBookmark(
        (string) $bookmark->getKey(),
        $event,
        $user,
        null,
        ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
        $bookmark->bookmarked_at,
    );
    $recorder->recordResponse(
        (string) $response->getKey(),
        $event,
        $user,
        null,
        ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
        $response->responded_at,
    );

    $bookmarkKey = ShareOutcomeBusinessKey::eventSave((string) $bookmark->getKey());
    $responseKey = ShareOutcomeBusinessKey::eventGoing((string) $response->getKey());

    expect(ShareSourceAttributionReceipt::query()->whereIn('business_key', [$bookmarkKey, $responseKey])->count())->toBe(2);

    $contributor = app(EngagementAccountLifecycleContributor::class);
    $snapshot = $contributor->snapshot((string) $user->getKey());
    $contributor->detach((string) $user->getKey());
    $contributor->restore((string) $user->getKey(), $snapshot);

    expect(ShareOutcomeCorrection::query()->whereIn('business_key', [$bookmarkKey, $responseKey])->count())->toBe(0);

    app(CleanupEventAction::class)->cleanup((string) $event->getKey());

    expect(ShareOutcomeCorrection::query()->whereIn('business_key', [$bookmarkKey, $responseKey])->count())->toBe(0)
        ->and(ShareSourceAttributionReceipt::query()->whereIn('business_key', [$bookmarkKey, $responseKey])->count())->toBe(2);
});

it('never calls the engagement outcomes contract during account restore or event cleanup', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create();

    $user->bookmark($event);
    $user->respond($event, 'going');
    $user->follow($institution);

    $outcomes = Mockery::mock(EngagementShareOutcomes::class);
    $outcomes->shouldNotReceive('recordBookmark');
    $outcomes->shouldNotReceive('reverseBookmark');
    $outcomes->shouldNotReceive('recordResponse');
    $outcomes->shouldNotReceive('reverseResponse');
    $outcomes->shouldNotReceive('recordFollow');
    $outcomes->shouldNotReceive('reverseFollow');

    $this->app->instance(EngagementShareOutcomes::class, $outcomes);

    $contributor = app(EngagementAccountLifecycleContributor::class);
    $snapshot = $contributor->snapshot((string) $user->getKey());
    $contributor->detach((string) $user->getKey());
    $contributor->restore((string) $user->getKey(), $snapshot);

    expect(Follow::query()
        ->where('follower_id', $user->getKey())
        ->where('followable_id', $institution->getKey())
        ->where('status', 'active')
        ->exists())->toBeTrue()
        ->and(Bookmark::query()
            ->where('bookmarker_id', $user->getKey())
            ->where('bookmarkable_id', $event->getKey())
            ->where('status', 'active')
            ->exists())->toBeTrue()
        ->and(Response::query()
            ->where('responder_id', $user->getKey())
            ->where('respondable_id', $event->getKey())
            ->where('response_type', 'going')
            ->where('status', 'active')
            ->exists())->toBeTrue();

    app(CleanupEventAction::class)->cleanup((string) $event->getKey());

    expect(Bookmark::query()->where('bookmarkable_id', $event->getKey())->exists())->toBeFalse()
        ->and(Response::query()->where('respondable_id', $event->getKey())->exists())->toBeFalse();
});
