<?php

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Engagement\Models\Follow;
use App\Contracts\ShareTrackingContract;
use App\Shared\ShareTracking\ShareOutcomeType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(RefreshDatabase::class);

function d4dApiFollowShareCookie(TestCase $testCase, string $url, User $sharer): string
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

function d4dApiFollowConversionStatus(AffiliateConversion $conversion): string
{
    $status = $conversion->status;

    return $status instanceof ConversionStatus
        ? (string) $status->getValue()
        : (string) $status;
}

it('serializes follow state payloads for each followable type', function (string $type, string $subject, array $attributes): void {
    $user = User::factory()->create();

    $record = match ($type) {
        'institution' => Institution::factory()->create($attributes),
        'person' => Person::factory()->create($attributes),
        'reference' => Reference::factory()->create($attributes),
        'series' => Series::factory()->create($attributes),
    };

    $user->follow($record);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.client.follows.show', ['type' => $type, 'subject' => $subject === 'slug' ? $record->slug : $record->getKey()]));

    $response->assertOk()
        ->assertJsonPath('data.type', $type)
        ->assertJsonPath('data.id', $record->getKey())
        ->assertJsonPath('data.slug', $record->slug)
        ->assertJsonPath('data.is_following', true)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
})->with([
    'institution by slug' => ['institution', 'slug', ['status' => 'verified']],
    'person by slug' => ['person', 'slug', ['status' => 'verified']],
    'reference by slug' => ['reference', 'slug', ['status' => 'verified']],
    'reference by uuid' => ['reference', 'id', ['status' => 'verified']],
    'series by slug' => ['series', 'slug', ['visibility' => 'public', 'status' => 'active']],
]);

it('returns the same follow payload shape across store and destroy', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    Sanctum::actingAs($user);

    $storeResponse = $this->postJson(route('api.client.follows.store', ['type' => 'person', 'subject' => $person->slug]));

    $storeResponse->assertCreated()
        ->assertJsonPath('data.type', 'person')
        ->assertJsonPath('data.id', $person->id)
        ->assertJsonPath('data.slug', $person->slug)
        ->assertJsonPath('data.is_following', true)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    expect($user->fresh()->isFollowing($person))->toBeTrue();

    $destroyResponse = $this->deleteJson(route('api.client.follows.destroy', ['type' => 'person', 'subject' => $person->slug]));

    $destroyResponse->assertOk()
        ->assertJsonPath('data.type', 'person')
        ->assertJsonPath('data.id', $person->id)
        ->assertJsonPath('data.slug', $person->slug)
        ->assertJsonPath('data.is_following', false)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    expect($user->fresh()->isFollowing($person))->toBeFalse();
});

it('accepts pending and verified public follow subjects through the api', function (string $type, string $status): void {
    $user = User::factory()->create();
    $record = match ($type) {
        'institution' => Institution::factory()->create(['status' => $status]),
        'person' => Person::factory()->create(['status' => $status]),
    };

    Sanctum::actingAs($user);

    $this->postJson(route('api.client.follows.store', [
        'type' => $type,
        'subject' => $record->slug,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.type', $type)
        ->assertJsonPath('data.is_following', true);

    expect($user->fresh()->isFollowing($record))->toBeTrue();
})->with([
    'pending institution' => ['institution', 'pending'],
    'verified institution' => ['institution', 'verified'],
    'pending person' => ['person', 'pending'],
    'verified person' => ['person', 'verified'],
]);

it('rejects non-public follow subjects with the existing 404', function (string $type, string $status): void {
    $user = User::factory()->create();
    $record = match ($type) {
        'institution' => Institution::factory()->create(['status' => $status]),
        'person' => Person::factory()->create(['status' => $status]),
    };

    Sanctum::actingAs($user);

    $this->postJson(route('api.client.follows.store', [
        'type' => $type,
        'subject' => $record->slug,
    ]))->assertNotFound();

    expect($user->fresh()->isFollowing($record))->toBeFalse();
})->with([
    'rejected institution' => ['institution', 'rejected'],
    'inactive institution' => ['institution', 'inactive'],
    'draft person' => ['person', 'draft'],
    'rejected person' => ['person', 'rejected'],
]);

it('records one api follow outcome and reverses it once', function (): void {
    $sharer = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'pending']);
    $url = route('institutions.show', $institution);
    $cookieName = (string) config('share-tracking.cookie.name');
    $cookie = d4dApiFollowShareCookie($this, $url, $sharer);

    Sanctum::actingAs($visitor);

    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->postJson(route('api.client.follows.store', ['type' => 'institution', 'subject' => $institution->slug]))
        ->assertCreated()
        ->assertJsonPath('data.is_following', true);
    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->postJson(route('api.client.follows.store', ['type' => 'institution', 'subject' => $institution->slug]))
        ->assertCreated()
        ->assertJsonPath('data.is_following', true);

    $conversion = AffiliateConversion::query()
        ->where('conversion_type', ShareOutcomeType::InstitutionFollow->value)
        ->sole();

    $follow = Follow::query()
        ->where('follower_type', $visitor->getMorphClass())
        ->where('follower_id', $visitor->getKey())
        ->where('followable_id', $institution->getKey())
        ->firstOrFail();
    $businessKey = ShareOutcomeBusinessKey::follow(ShareOutcomeType::InstitutionFollow, (string) $follow->getKey());

    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->deleteJson(route('api.client.follows.destroy', ['type' => 'institution', 'subject' => $institution->slug]))
        ->assertOk()
        ->assertJsonPath('data.is_following', false);
    $this->withCredentials()->withCookie($cookieName, $cookie)
        ->deleteJson(route('api.client.follows.destroy', ['type' => 'institution', 'subject' => $institution->slug]))
        ->assertOk()
        ->assertJsonPath('data.is_following', false);

    expect(d4dApiFollowConversionStatus($conversion->fresh()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->count())->toBe(1)
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->value('reason'))->toBe('unfollowed');
});

it('passes the subject metadata shape from the follow api to the recorder', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'pending']);
    $received = null;

    $tracking = Mockery::mock(ShareTrackingContract::class);
    $tracking->shouldReceive('recordOutcome')
        ->once()
        ->withArgs(function (mixed ...$arguments) use (&$received): bool {
            $received = $arguments[5];

            return true;
        })
        ->andReturnNull();

    $this->app->instance(ShareTrackingContract::class, $tracking);

    Sanctum::actingAs($user);

    $this->postJson(route('api.client.follows.store', [
        'type' => 'institution',
        'subject' => $institution->slug,
    ]))->assertCreated();

    expect($received)->toBe([
        'subject_id' => $institution->getKey(),
        'subject_type' => $institution->getMorphClass(),
    ]);
});
