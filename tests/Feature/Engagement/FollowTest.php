<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Follow;
use App\Models\Speaker;
use App\Models\User;

it('writes to engagement_follows when following via package', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, function () use ($user, $speaker): void {
        $follow = app(EngagementManager::class)->follow($user, $speaker);
    });

    expect(Follow::query()
        ->where('follower_type', $user->getMorphClass())
        ->where('follower_id', $user->getKey())
        ->where('followable_type', $speaker->getMorphClass())
        ->where('followable_id', $speaker->getKey())
        ->exists()
    )->toBeTrue();
});

it('marks follow as unfollowed when unfollowing via package', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, function () use ($user, $speaker): void {
        app(EngagementManager::class)->follow($user, $speaker);
    });

    OwnerContext::withOwner(null, function () use ($user, $speaker): void {
        app(EngagementManager::class)->unfollow($user, $speaker);
    });

    $follow = Follow::query()
        ->where('follower_type', $user->getMorphClass())
        ->where('follower_id', $user->getKey())
        ->where('followable_type', $speaker->getMorphClass())
        ->where('followable_id', $speaker->getKey())
        ->first();

    expect($follow)->not->toBeNull();
    expect($follow->status->value)->toBe('unfollowed');
    expect($follow->unfollowed_at)->not->toBeNull();
});

it('follow is idempotent via package', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, function () use ($user, $speaker): void {
        app(EngagementManager::class)->follow($user, $speaker);
        app(EngagementManager::class)->follow($user, $speaker);
    });

    expect(Follow::query()
        ->where('follower_type', $user->getMorphClass())
        ->where('follower_id', $user->getKey())
        ->where('followable_type', $speaker->getMorphClass())
        ->where('followable_id', $speaker->getKey())
        ->where('status', 'active')
        ->count()
    )->toBe(1);
});
