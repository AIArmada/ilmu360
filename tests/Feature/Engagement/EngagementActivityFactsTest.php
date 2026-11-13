<?php

declare(strict_types=1);

use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use App\Enums\ShareOutcomeType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Support\Rewards\ActivityRewardSourceFactReader;
use App\Support\ShareTracking\ActivityRewardBusinessKey;

it('reads saved event reward facts through the engagement reader', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $bookmark = $user->bookmark($event);

    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::EventSave,
        'bookmark:'.(string) $bookmark->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->not->toBeNull()
        ->and($fact->outcome)->toBe(ShareOutcomeType::EventSave->value)
        ->and($fact->actorId)->toBe((string) $user->getKey())
        ->and($fact->isValid)->toBeTrue()
        ->and($fact->isCancelled)->toBeFalse()
        ->and($fact->isFirstTime)->toBeTrue();
});

it('marks removed saves and cancelled going responses invalid', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $bookmark = $user->bookmark($event);
    $response = $user->respond($event, 'going');

    $bookmark->forceFill(['status' => 'removed'])->save();
    $user->cancelResponse($event);

    $bookmarkFact = (new ActivityRewardSourceFactReader)->read(ActivityRewardBusinessKey::for(
        ShareOutcomeType::EventSave,
        'bookmark:'.(string) $bookmark->getKey(),
    ));
    $responseFact = (new ActivityRewardSourceFactReader)->read(ActivityRewardBusinessKey::for(
        ShareOutcomeType::EventGoing,
        'response:'.(string) $response->getKey(),
    ));

    expect($bookmarkFact?->isValid)->toBeFalse()
        ->and($bookmarkFact?->isCancelled)->toBeTrue()
        ->and($responseFact?->isValid)->toBeFalse()
        ->and($responseFact?->isCancelled)->toBeTrue();
});

it('reads follow reward facts and invalidates unfollowed rows', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $follow = $user->follow($institution);

    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::InstitutionFollow,
        'follow:'.(string) $follow->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->not->toBeNull()
        ->and($fact->outcome)->toBe(ShareOutcomeType::InstitutionFollow->value)
        ->and($fact->actorId)->toBe((string) $user->getKey())
        ->and($fact->isValid)->toBeTrue();

    $user->unfollow($institution);

    $invalidFact = (new ActivityRewardSourceFactReader)->read($key);

    expect($invalidFact?->isValid)->toBeFalse()
        ->and($invalidFact?->isCancelled)->toBeTrue();
});

it('preserves first-time detection for bookmark activity', function (): void {
    $user = User::factory()->create();
    $firstEvent = Event::factory()->create();
    $secondEvent = Event::factory()->create();

    $earlier = $user->bookmark($firstEvent);
    $earlier->forceFill(['bookmarked_at' => now()->subDay()])->save();

    $later = $user->bookmark($secondEvent);
    $later->forceFill(['bookmarked_at' => now()])->save();

    $fact = (new ActivityRewardSourceFactReader)->read(ActivityRewardBusinessKey::for(
        ShareOutcomeType::EventSave,
        'bookmark:'.(string) $later->getKey(),
    ));

    expect($fact?->isFirstTime)->toBeFalse();
});

it('reads reward fraud and bot signals from the engagement metadata', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $bookmark = $user->bookmark($event);
    $bookmark->forceFill([
        'metadata' => [
            'is_bot' => true,
            'is_ambiguous_device' => '1',
            'fraud_status' => 'suspect',
        ],
    ])->save();

    $fact = (new ActivityRewardSourceFactReader)->read(ActivityRewardBusinessKey::for(
        ShareOutcomeType::EventSave,
        'bookmark:'.(string) $bookmark->getKey(),
    ));

    expect($fact?->isBot)->toBeTrue()
        ->and($fact?->isAmbiguousDevice)->toBeTrue()
        ->and($fact?->fraudStatus)->toBe(RewardSourceFraudStatus::Suspect);
});
