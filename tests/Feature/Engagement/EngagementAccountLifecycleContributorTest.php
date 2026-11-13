<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Enums\FollowStatus;
use AIArmada\Engagement\Models\Follow;
use App\Models\User;
use App\Models\Venue;
use App\Support\Engagement\EngagementAccountLifecycleContributor;

it('snapshots, detaches and restores venue follows with the venue morph alias', function (): void {
    $user = User::factory()->create();
    $venue = Venue::factory()->create();

    $follow = OwnerContext::withOwner(null, fn (): Follow => Follow::query()->create([
        'follower_type' => $user->getMorphClass(),
        'follower_id' => $user->getKey(),
        'followable_type' => $venue->getMorphClass(),
        'followable_id' => $venue->getKey(),
        'status' => 'active',
        'followed_at' => now()->subDay()->startOfSecond(),
    ]));

    expect($follow->followable_type)->toBe('venue');

    $contributor = app(EngagementAccountLifecycleContributor::class);
    $snapshot = $contributor->snapshot((string) $user->getKey());

    expect($snapshot['user_venue'])->toHaveCount(1)
        ->and($snapshot['user_venue'][0])
        ->toMatchArray([
            'follower_type' => 'user',
            'follower_id' => (string) $user->getKey(),
            'followable_type' => 'venue',
            'followable_id' => (string) $venue->getKey(),
        ])
        ->and($snapshot['user_venue'][0]['status'])->toBe(FollowStatus::Active);

    $contributor->detach((string) $user->getKey());

    expect(Follow::query()->whereKey($follow->getKey())->exists())->toBeFalse();

    $contributor->restore((string) $user->getKey(), $snapshot);

    $restored = Follow::query()
        ->where('follower_type', $user->getMorphClass())
        ->where('follower_id', $user->getKey())
        ->where('followable_type', 'venue')
        ->where('followable_id', $venue->getKey())
        ->sole();

    expect($restored->status)->toBe(FollowStatus::Active)
        ->and($restored->followable_type)->toBe('venue')
        ->and(Follow::query()
            ->where('follower_id', $user->getKey())
            ->where('followable_id', $venue->getKey())
            ->count())->toBe(1);
});
