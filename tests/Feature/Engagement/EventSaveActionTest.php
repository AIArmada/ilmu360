<?php

declare(strict_types=1);

use AIArmada\Affiliates\Features\Attribution\Models\AffiliateLink;
use AIArmada\Affiliates\Features\Conversions\Models\AffiliateConversion;
use AIArmada\Affiliates\Features\Conversions\States\ConversionStatus;
use AIArmada\Affiliates\Features\Identity\Models\Affiliate;
use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Models\Bookmark;
use App\Features\Engagement\Actions\SaveEventAction;
use App\Features\Engagement\Actions\UnsaveEventAction;
use App\Features\Engagement\Api\EngagementShareOutcomes;
use App\Features\Events\Models\Event;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Features\ShareTracking\Models\ShareOutcomeCorrection;
use App\Features\ShareTracking\Models\ShareSourceAttributionReceipt;
use App\Features\ShareTracking\Services\AffiliatesShareTrackingService;
use App\Models\User;
use App\Shared\Auth\AccountActor;
use App\Shared\ShareTracking\ShareRequestContext;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

function d4bSaveShareCookie(TestCase $testCase, Event $event, User $sharer): string
{
    app(AffiliatesShareTrackingService::class)->createOrReuseLink($sharer, route('events.show', $event), 'Majlis', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    $link = AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();

    $response = $testCase->get(route('events.show', $event).'?share='.$link->custom_slug);
    $response->assertOk();

    return (string) $response->getCookie((string) config('share-tracking.cookie.name'))?->getValue();
}

function d4bSaveConversionStatus(AffiliateConversion $conversion): string
{
    $status = $conversion->status;

    return $status instanceof ConversionStatus
        ? $status->getValue()
        : (string) $status;
}

it('reports created only for the first save and records one share outcome', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $first = app(SaveEventAction::class)->handle($event, $user);
    $second = app(SaveEventAction::class)->handle($event, $user);

    expect($first->created)->toBeTrue()
        ->and($first->bookmark)->not->toBeNull()
        ->and($second->created)->toBeFalse()
        ->and($second->bookmark?->getKey())->toBe($first->bookmark?->getKey())
        ->and(Bookmark::query()->whereKey($first->bookmark?->getKey())->active()->count())->toBe(1)
        ->and(app(EngagementCounterService::class)->countBookmarks($event))->toBe(1);

    $businessKey = ShareOutcomeBusinessKey::eventSave((string) $first->bookmark?->getKey());

    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1);
});

it('removes only an active save and reports idempotent replays', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    app(SaveEventAction::class)->handle($event, $user);

    $first = app(UnsaveEventAction::class)->handle($event, $user);
    $second = app(UnsaveEventAction::class)->handle($event, $user);

    expect($first->removed)->toBeTrue()
        ->and($second->removed)->toBeFalse()
        ->and(Bookmark::forBookmarker($user)->forBookmarkable($event)->active()->exists())->toBeFalse()
        ->and(Bookmark::forBookmarker($user)->forBookmarkable($event)->where('status', 'removed')->count())->toBe(1)
        ->and(app(EngagementCounterService::class)->countBookmarks($event))->toBe(0);
});

it('records one save outcome and reverses it only across real api transitions', function (): void {
    $sharer = User::factory()->create();
    $visitor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $cookieName = (string) config('share-tracking.cookie.name');
    $cookie = d4bSaveShareCookie($this, $event, $sharer);

    Sanctum::actingAs($visitor);

    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->putJson(route('api.events.saved.update', $event))
        ->assertCreated();
    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->putJson(route('api.events.saved.update', $event))
        ->assertOk();

    expect(AffiliateConversion::query()->where('conversion_type', ShareOutcomeType::EventSave->value)->count())->toBe(1);

    $bookmark = Bookmark::query()
        ->where('bookmarker_type', $visitor->getMorphClass())
        ->where('bookmarker_id', $visitor->getKey())
        ->where('bookmarkable_id', $event->getKey())
        ->firstOrFail();

    $businessKey = ShareOutcomeBusinessKey::eventSave((string) $bookmark->getKey());

    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->deleteJson(route('api.events.saved.destroy', $event))
        ->assertOk();
    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->deleteJson(route('api.events.saved.destroy', $event))
        ->assertOk();

    $conversion = AffiliateConversion::query()->where('conversion_type', ShareOutcomeType::EventSave->value)->sole();

    expect(d4bSaveConversionStatus($conversion))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->count())->toBe(1)
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->value('reason'))->toBe('save_removed');
});

it('records and reverses the bookmark through the engagement share outcomes contract', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);
    $request = Request::create(route('events.show', $event));
    $recorded = null;
    $reversed = null;

    $outcomes = Mockery::mock(EngagementShareOutcomes::class);
    $outcomes->shouldReceive('recordBookmark')
        ->once()
        ->withArgs(function (
            string $bookmarkId,
            ?ShareSubject $subject,
            ?AccountActor $actor,
            ?ShareRequestContext $receivedRequest,
            array $metadata,
            ?CarbonInterface $occurredAt,
        ) use (&$recorded): bool {
            $recorded = ['bookmarkId' => $bookmarkId, 'subject' => $subject, 'actor' => $actor, 'receivedRequest' => $receivedRequest, 'metadata' => $metadata, 'occurredAt' => $occurredAt];

            return true;
        });
    $outcomes->shouldReceive('reverseBookmark')
        ->once()
        ->withArgs(function (
            string $bookmarkId,
            string $reason,
            ?AccountActor $actor,
        ) use (&$reversed): bool {
            $reversed = ['bookmarkId' => $bookmarkId, 'reason' => $reason, 'actor' => $actor];

            return true;
        });

    $this->app->instance(EngagementShareOutcomes::class, $outcomes);

    $saved = app(SaveEventAction::class)->handle($event, $user, $request);
    app(SaveEventAction::class)->handle($event, $user, $request);

    $unsaved = app(UnsaveEventAction::class)->handle($event, $user);
    app(UnsaveEventAction::class)->handle($event, $user);

    expect($recorded['bookmarkId'])->toBe((string) $saved->bookmark?->getKey())
        ->and($recorded['subject'])->toBeInstanceOf(ShareSubject::class)
        ->and($recorded['subject']?->type)->toBe('event')
        ->and($recorded['subject']?->id)->toBe((string) $event->getKey())
        ->and($recorded['subject']?->title)->toBe($event->title)
        ->and($recorded['actor']?->actorId())->toBe((string) $user->getKey())
        ->and($recorded['receivedRequest']?->toRequest())->toBe($request)
        ->and($recorded['metadata'])->toBe([
            'subject_id' => $event->getKey(),
            'subject_type' => $event->getMorphClass(),
        ])
        ->and($recorded['occurredAt']?->equalTo($saved->bookmark?->created_at))->toBeTrue()
        ->and($unsaved->removed)->toBeTrue()
        ->and($reversed['bookmarkId'])->toBe((string) $saved->bookmark?->getKey())
        ->and($reversed['reason'])->toBe('save_removed')
        ->and($reversed['actor']?->actorId())->toBe((string) $user->getKey());
});
