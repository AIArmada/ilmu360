<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Engagement\Enums\FollowStatus;
use AIArmada\Engagement\Models\Follow;
use App\Actions\Engagement\FollowSubjectAction;
use App\Actions\Engagement\UnfollowSubjectAction;
use App\Contracts\ShareTrackingContract;
use App\Enums\ShareOutcomeType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Series;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Shared\Engagement\EngagementReader;
use App\Shared\Engagement\EngagementShareOutcomes;
use App\Shared\Engagement\EngagementSubjectType;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tests\TestCase;

function d4dActionShareCookie(TestCase $testCase, string $url, User $sharer): string
{
    app(ShareTrackingService::class)->createOrReuseLink($sharer, $url, 'Follow Page', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    $link = AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();

    $response = $testCase->get($url.'?share='.$link->custom_slug);
    $response->assertOk();

    return (string) $response->getCookie((string) config('share-tracking.cookie.name'))?->getValue();
}

it('creates one follow row and outcome for the first follow and replays idempotently', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $metadata = ['institution_id' => $institution->getKey()];

    $first = app(FollowSubjectAction::class)->handle($institution, $user, $metadata);
    $second = app(FollowSubjectAction::class)->handle($institution, $user, $metadata);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->follow->getKey())->toBe($first->follow->getKey())
        ->and($second->follow->status)->toBe(FollowStatus::Active)
        ->and(Follow::query()->whereKey($first->follow->getKey())->count())->toBe(1)
        ->and($institution->followersCount())->toBe(1)
        ->and(app(EngagementReader::class)->followedSubjectIds(
            (string) $user->getKey(),
            EngagementSubjectType::Institution,
        ))->toContain((string) $institution->getKey())
        ->and(app(SubjectFollows::class)->followerCount(
            EngagementSubjectType::Institution,
            (string) $institution->getKey(),
        ))->toBe(1);

    $businessKey = ShareOutcomeBusinessKey::follow(ShareOutcomeType::InstitutionFollow, (string) $first->follow->getKey());

    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1);
});

it('reverses an attributed follow only once and keeps reactivation free of a second outcome', function (): void {
    $sharer = User::factory()->create();
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $url = route('institutions.show', $institution);
    $cookie = d4dActionShareCookie($this, $url, $sharer);
    $cookieName = (string) config('share-tracking.cookie.name');
    $request = Request::create($url);
    $request->cookies->set($cookieName, $cookie);

    $follow = app(FollowSubjectAction::class)->handle(
        subject: $institution,
        user: $user,
        metadata: ['institution_id' => $institution->getKey()],
        request: $request,
    );

    $businessKey = ShareOutcomeBusinessKey::follow(ShareOutcomeType::InstitutionFollow, (string) $follow->follow->getKey());

    expect(AffiliateConversion::query()
        ->where('conversion_type', ShareOutcomeType::InstitutionFollow->value)
        ->count())->toBe(1);

    $first = app(UnfollowSubjectAction::class)->handle($institution, $user);
    $second = app(UnfollowSubjectAction::class)->handle($institution, $user);

    expect($first->removed)->toBeTrue()
        ->and($second->removed)->toBeFalse()
        ->and(Follow::query()->whereKey($follow->follow->getKey())->value('status'))->toBe(FollowStatus::Unfollowed)
        ->and($institution->followersCount())->toBe(0)
        ->and(app(EngagementReader::class)->followedSubjectIds(
            (string) $user->getKey(),
            EngagementSubjectType::Institution,
        ))->not->toContain((string) $institution->getKey())
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->count())->toBe(1)
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->value('reason'))->toBe('unfollowed');

    $reactivated = app(FollowSubjectAction::class)->handle(
        subject: $institution,
        user: $user,
        metadata: ['institution_id' => $institution->getKey()],
        request: $request,
    );

    expect($reactivated->created)->toBeFalse()
        ->and($reactivated->follow->getKey())->toBe($follow->follow->getKey())
        ->and($reactivated->follow->status)->toBe(FollowStatus::Active)
        ->and(AffiliateConversion::query()->where('conversion_type', ShareOutcomeType::InstitutionFollow->value)->count())->toBe(1)
        ->and(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1);
});

it('forwards the api subject metadata shape and the directory typed shape unchanged', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $reference = Reference::factory()->create(['status' => 'verified']);
    $received = [];

    $tracking = Mockery::mock(ShareTrackingContract::class);
    $tracking->shouldReceive('recordOutcome')
        ->twice()
        ->withArgs(function (mixed ...$arguments) use (&$received): bool {
            $received[] = $arguments[5];

            return true;
        })
        ->andReturnNull();

    $this->app->instance(ShareTrackingContract::class, $tracking);

    app(FollowSubjectAction::class)->handle(
        subject: $institution,
        user: $user,
        metadata: ['subject_id' => $institution->getKey(), 'subject_type' => $institution->getMorphClass()],
    );
    app(FollowSubjectAction::class)->handle(
        subject: $reference,
        user: $user,
        metadata: ['reference_id' => $reference->getKey()],
    );

    expect($received)->toBe([
        [
            'subject_id' => $institution->getKey(),
            'subject_type' => $institution->getMorphClass(),
        ],
        [
            'reference_id' => $reference->getKey(),
        ],
    ]);
});

it('supports series follow and unfollow with reader and counter state', function (): void {
    $user = User::factory()->create();
    $series = Series::factory()->create(['visibility' => 'public']);

    $follow = app(FollowSubjectAction::class)->handle(
        subject: $series,
        user: $user,
        metadata: ['series_id' => $series->getKey()],
    );

    expect($follow->created)->toBeTrue()
        ->and($series->followersCount())->toBe(1)
        ->and(app(EngagementReader::class)->followedSubjectIds(
            (string) $user->getKey(),
            EngagementSubjectType::Series,
        ))->toContain((string) $series->getKey());

    $unfollow = app(UnfollowSubjectAction::class)->handle($series, $user);

    expect($unfollow->removed)->toBeTrue()
        ->and($series->followersCount())->toBe(0)
        ->and(app(EngagementReader::class)->followedSubjectIds(
            (string) $user->getKey(),
            EngagementSubjectType::Series,
        ))->not->toContain((string) $series->getKey());
});

it('records and reverses the series follow through the engagement share outcomes contract', function (): void {
    $user = User::factory()->create();
    $series = Series::factory()->create(['visibility' => 'public']);
    $request = Request::create(route('series.show', $series));
    $recorded = null;
    $reversed = null;

    $outcomes = Mockery::mock(EngagementShareOutcomes::class);
    $outcomes->shouldReceive('recordFollow')
        ->once()
        ->withArgs(function (
            ShareOutcomeType $type,
            string $followId,
            ?Model $subject,
            ?Authenticatable $actor,
            ?Request $receivedRequest,
            array $metadata,
            ?CarbonInterface $occurredAt,
        ) use (&$recorded): bool {
            $recorded = compact('type', 'followId', 'subject', 'actor', 'receivedRequest', 'metadata', 'occurredAt');

            return true;
        });
    $outcomes->shouldReceive('reverseFollow')
        ->once()
        ->withArgs(function (
            ShareOutcomeType $type,
            string $followId,
            string $reason,
            ?Authenticatable $actor,
        ) use (&$reversed): bool {
            $reversed = compact('type', 'followId', 'reason', 'actor');

            return true;
        });

    $this->app->instance(EngagementShareOutcomes::class, $outcomes);

    $follow = app(FollowSubjectAction::class)->handle(
        subject: $series,
        user: $user,
        metadata: ['series_id' => $series->getKey()],
        request: $request,
    );
    app(FollowSubjectAction::class)->handle(
        subject: $series,
        user: $user,
        metadata: ['series_id' => $series->getKey()],
        request: $request,
    );

    $unfollow = app(UnfollowSubjectAction::class)->handle($series, $user);
    app(UnfollowSubjectAction::class)->handle($series, $user);

    expect($recorded['type'])->toBe(ShareOutcomeType::SeriesFollow)
        ->and($recorded['followId'])->toBe((string) $follow->follow->getKey())
        ->and($recorded['subject']?->is($series))->toBeTrue()
        ->and($recorded['actor']?->is($user))->toBeTrue()
        ->and($recorded['receivedRequest'])->toBe($request)
        ->and($recorded['metadata'])->toBe(['series_id' => $series->getKey()])
        ->and($recorded['occurredAt']?->equalTo($follow->follow->created_at))->toBeTrue()
        ->and($unfollow->removed)->toBeTrue()
        ->and($reversed['type'])->toBe(ShareOutcomeType::SeriesFollow)
        ->and($reversed['followId'])->toBe((string) $follow->follow->getKey())
        ->and($reversed['reason'])->toBe('unfollowed')
        ->and($reversed['actor']?->is($user))->toBeTrue();
});
