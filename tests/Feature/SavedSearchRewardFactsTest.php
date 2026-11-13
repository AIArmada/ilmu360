<?php

declare(strict_types=1);

use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\User;
use App\Shared\ShareTracking\ActivityRewardBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use App\Support\Rewards\ActivityRewardSourceFactReader;
use App\Support\Rewards\ShareAttributionRewardSourceFactReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;

it('reads accepted unattributed saved-search activity for its authenticated owner', function (): void {
    $owner = User::factory()->create();
    $search = SavedSearch::factory()->create([
        'user_id' => $owner->getKey(),
        'created_at' => '2025-03-10 12:30:00',
    ]);
    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::SavedSearchCreated,
        'saved_search:'.$search->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->not->toBeNull()
        ->and($fact->sourceBusinessKey)->toBe($key)
        ->and($fact->outcome)->toBe('saved_search_created')
        ->and($fact->actorId)->toBe((string) $owner->getKey())
        ->and($fact->sharerId)->toBeNull()
        ->and($fact->category)->toBeNull()
        ->and($fact->occurredAt->toISOString())->toBe('2025-03-10T12:30:00.000000Z')
        ->and($fact->verification)->toBe(RewardVerificationLevel::Accepted)
        ->and($fact->actorRole)->toBe(RewardBeneficiaryRole::Participant)
        ->and($fact->isClaimedIdentity)->toBeTrue()
        ->and($fact->isAttributed)->toBeFalse()
        ->and($fact->isAmbiguousDevice)->toBeFalse()
        ->and($fact->isBot)->toBeFalse()
        ->and($fact->isCancelled)->toBeFalse()
        ->and($fact->isValid)->toBeTrue()
        ->and($fact->fraudStatus)->toBe(RewardSourceFraudStatus::Clear)
        ->and($fact->correctionStatus)->toBe(RewardSourceCorrectionStatus::Current)
        ->and($fact->correctionId)->toBeNull();
});

it('bases saved-search first-time activity only on strictly earlier records for the same owner', function (
    string $otherOwnerType,
    bool $sameOwnerId,
    int $dayOffset,
    bool $expectedFirstTime,
): void {
    $owner = User::factory()->create();
    $occurredAt = CarbonImmutable::parse('2025-03-10 12:30:00', 'UTC');
    $search = SavedSearch::factory()->create([
        'user_id' => $owner->getKey(),
        'created_at' => $occurredAt,
    ]);
    SavedSearch::factory()->create([
        'user_type' => $otherOwnerType === 'same' ? $owner->getMorphClass() : $otherOwnerType,
        'user_id' => $sameOwnerId ? $owner->getKey() : (string) Str::uuid(),
        'created_at' => $occurredAt->addDays($dayOffset),
    ]);
    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::SavedSearchCreated,
        'saved_search:'.$search->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->not->toBeNull()
        ->and($fact->isFirstTime)->toBe($expectedFirstTime);
})->with([
    'earlier search by the same owner' => ['same', true, -1, false],
    'earlier search by a different identity' => ['same', false, -1, true],
    'earlier search by a different owner type' => ['another-owner-type', true, -1, true],
    'search at the same instant' => ['same', true, 0, true],
    'later search' => ['same', true, 1, true],
]);

it('refuses saved-search activity when the user owner no longer exists', function (): void {
    $search = SavedSearch::factory()->create([
        'user_type' => (new User)->getMorphClass(),
        'user_id' => (string) Str::uuid(),
    ]);
    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::SavedSearchCreated,
        'saved_search:'.$search->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->toBeNull();
});

it('refuses saved-search activity owned by another morph type even when the identity matches a user', function (): void {
    $user = User::factory()->create();
    $search = SavedSearch::factory()->create([
        'user_type' => 'another-owner-type',
        'user_id' => $user->getKey(),
    ]);
    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::SavedSearchCreated,
        'saved_search:'.$search->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->toBeNull();
});

it('refuses saved-search activity when the source record is missing', function (): void {
    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::SavedSearchCreated,
        'saved_search:'.Str::uuid(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->toBeNull();
});

it('refuses saved-search activity without a creation instant', function (): void {
    $search = SavedSearch::factory()->create();
    SavedSearch::query()->whereKey($search->getKey())->update(['created_at' => null]);
    $key = ActivityRewardBusinessKey::for(
        ShareOutcomeType::SavedSearchCreated,
        'saved_search:'.$search->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->toBeNull();
});

it('invalidates attributed saved-search facts when the source is deleted while preserving receipt evidence', function (): void {
    $owner = User::factory()->create();
    $sharer = User::factory()->create();
    $search = SavedSearch::factory()->create(['user_id' => $owner->getKey()]);
    $key = ShareOutcomeBusinessKey::savedSearch((string) $search->getKey());
    $occurredAt = CarbonImmutable::parse('2025-03-10 12:30:00', 'UTC');
    RewardHostFixtures::receipt($key, 'saved_search:'.$search->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'saved_search_created',
        'occurred_at' => $occurredAt,
    ]);
    RewardHostFixtures::conversion($key, [
        'conversion_type' => 'saved_search_created',
        'actor_user_id' => $owner->getKey(),
        'sharer_user_id' => $sharer->getKey(),
        'occurred_at' => $occurredAt,
    ]);
    $reader = new ShareAttributionRewardSourceFactReader;

    $present = $reader->read($key);

    expect($present)->not->toBeNull()
        ->and($present->verification)->toBe(RewardVerificationLevel::Accepted)
        ->and($present->actorRole)->toBe(RewardBeneficiaryRole::Recipient)
        ->and($present->isValid)->toBeTrue()
        ->and($present->isCancelled)->toBeFalse();

    $search->delete();
    $deleted = $reader->read($key);

    expect($deleted)->not->toBeNull()
        ->and($deleted->verification)->toBe(RewardVerificationLevel::Unverified)
        ->and($deleted->isValid)->toBeFalse()
        ->and($deleted->isCancelled)->toBeTrue()
        ->and($deleted->actorRole)->toBe(RewardBeneficiaryRole::Recipient)
        ->and($deleted->actorId)->toBe((string) $owner->getKey())
        ->and($deleted->sharerId)->toBe((string) $sharer->getKey())
        ->and($deleted->occurredAt->toISOString())->toBe('2025-03-10T12:30:00.000000Z')
        ->and($deleted->isFirstTime)->toBeTrue()
        ->and($deleted->isAttributed)->toBeTrue()
        ->and($deleted->correctionStatus)->toBe(RewardSourceCorrectionStatus::Current);
});
