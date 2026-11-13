<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Models\Response;
use App\Actions\Engagement\MarkEventGoingAction;
use App\Actions\Engagement\RemoveEventGoingAction;
use App\Enums\ShareOutcomeType;
use App\Models\Event;
use App\Models\Registration;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Shared\Engagement\EngagementShareOutcomes;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

function d4cGoingShareCookie(TestCase $testCase, Event $event, User $sharer): string
{
    app(ShareTrackingService::class)->createOrReuseLink($sharer, route('events.show', $event), 'Majlis', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    $link = AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();

    $response = $testCase->get(route('events.show', $event).'?share='.$link->custom_slug);
    $response->assertOk();

    return (string) $response->getCookie((string) config('share-tracking.cookie.name'))?->getValue();
}

function d4cGoingConversionStatus(AffiliateConversion $conversion): string
{
    $status = $conversion->status;

    return $status instanceof ConversionStatus
        ? (string) $status->getValue()
        : (string) $status;
}

function d4cGoingRegistrationEvent(): Event
{
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    $event->accessPolicy()->updateOrCreate([], [
        'registration_required' => true,
        'capacity' => 50,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    return $event;
}

it('reports created only for the first going and records one share outcome', function (): void {
    $user = User::factory()->create();
    $event = d4cGoingRegistrationEvent();

    $first = app(MarkEventGoingAction::class)->handle($event, $user);
    $second = app(MarkEventGoingAction::class)->handle($event, $user);

    expect($first['status'])->toBe('created')
        ->and($first['response_created'])->toBeTrue()
        ->and($first['going_count'])->toBe(1)
        ->and($second['status'])->toBe('existing')
        ->and($second['response_created'])->toBeFalse()
        ->and($second['going_count'])->toBe(1)
        ->and(Response::query()->where('response_type', 'going')->active()->count())->toBe(1)
        ->and(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(1)
        ->and(Registration::query()->where('event_id', $event->getKey())->forUser($user)->active()->count())->toBe(1);

    $response = Response::query()->where('response_type', 'going')->active()->sole();
    $businessKey = ShareOutcomeBusinessKey::eventGoing((string) $response->getKey());

    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1);
});

it('removes only an active going row and reports idempotent replays', function (): void {
    $user = User::factory()->create();
    $event = d4cGoingRegistrationEvent();

    app(MarkEventGoingAction::class)->handle($event, $user);

    $first = app(RemoveEventGoingAction::class)->handle($event, $user);
    $second = app(RemoveEventGoingAction::class)->handle($event, $user);

    expect($first['deleted'])->toBeTrue()
        ->and($first['going_count'])->toBe(0)
        ->and($second['deleted'])->toBeFalse()
        ->and($second['going_count'])->toBe(0)
        ->and($event->goingBy()->forResponder($user)->active()->exists())->toBeFalse()
        ->and(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(0)
        ->and(ShareOutcomeCorrection::query()->count())->toBe(0);
});

it('records one going outcome and reverses it only across real api transitions', function (): void {
    $sharer = User::factory()->create();
    $visitor = User::factory()->create();
    $event = d4cGoingRegistrationEvent();

    $cookieName = (string) config('share-tracking.cookie.name');
    $cookie = d4cGoingShareCookie($this, $event, $sharer);

    Sanctum::actingAs($visitor);

    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->putJson(route('api.events.going.update', $event))
        ->assertCreated();
    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->putJson(route('api.events.going.update', $event))
        ->assertOk();

    expect(AffiliateConversion::query()->where('conversion_type', ShareOutcomeType::EventGoing->value)->count())->toBe(1);

    $response = Response::query()
        ->where('responder_type', $visitor->getMorphClass())
        ->where('responder_id', $visitor->getKey())
        ->where('respondable_id', $event->getKey())
        ->where('response_type', 'going')
        ->firstOrFail();

    $businessKey = ShareOutcomeBusinessKey::eventGoing((string) $response->getKey());

    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->deleteJson(route('api.events.going.destroy', $event))
        ->assertOk();
    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->deleteJson(route('api.events.going.destroy', $event))
        ->assertOk();

    $conversion = AffiliateConversion::query()->where('conversion_type', ShareOutcomeType::EventGoing->value)->sole();

    expect(d4cGoingConversionStatus($conversion))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->count())->toBe(1)
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->value('reason'))->toBe('going_removed');
});

it('records and reverses the going response through the engagement share outcomes contract', function (): void {
    $user = User::factory()->create();
    $event = d4cGoingRegistrationEvent();
    $request = Request::create(route('events.show', $event));
    $recorded = null;
    $reversed = null;

    $outcomes = Mockery::mock(EngagementShareOutcomes::class);
    $outcomes->shouldReceive('recordResponse')
        ->once()
        ->withArgs(function (
            string $responseId,
            ?Model $subject,
            ?Authenticatable $actor,
            ?Request $receivedRequest,
            array $metadata,
            ?CarbonInterface $occurredAt,
        ) use (&$recorded): bool {
            $recorded = compact('responseId', 'subject', 'actor', 'receivedRequest', 'metadata', 'occurredAt');

            return true;
        });
    $outcomes->shouldReceive('reverseResponse')
        ->once()
        ->withArgs(function (
            string $responseId,
            string $reason,
            ?Authenticatable $actor,
        ) use (&$reversed): bool {
            $reversed = compact('responseId', 'reason', 'actor');

            return true;
        });

    $this->app->instance(EngagementShareOutcomes::class, $outcomes);

    app(MarkEventGoingAction::class)->handle($event, $user, $request);
    app(MarkEventGoingAction::class)->handle($event, $user, $request);

    $removed = app(RemoveEventGoingAction::class)->handle($event, $user);
    app(RemoveEventGoingAction::class)->handle($event, $user);

    $response = Response::query()->where('response_type', 'going')->firstOrFail();

    expect($recorded['responseId'])->toBe((string) $response->getKey())
        ->and($recorded['subject']?->is($event))->toBeTrue()
        ->and($recorded['actor']?->is($user))->toBeTrue()
        ->and($recorded['receivedRequest'])->toBe($request)
        ->and($recorded['metadata'])->toBe([
            'subject_id' => $event->getKey(),
            'subject_type' => $event->getMorphClass(),
        ])
        ->and($recorded['occurredAt']?->equalTo($response->created_at))->toBeTrue()
        ->and($removed['deleted'])->toBeTrue()
        ->and($reversed['responseId'])->toBe((string) $response->getKey())
        ->and($reversed['reason'])->toBe('going_removed')
        ->and($reversed['actor']?->is($user))->toBeTrue();
});
