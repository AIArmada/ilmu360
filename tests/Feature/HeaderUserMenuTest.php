<?php

use App\Features\Identity\Api\SocialAccounts;
use App\Features\Identity\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the social profile avatar in the header when one is available', function () {
    $user = User::factory()->create(['name' => 'Saiffil Fariz']);
    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'avatar_url' => 'https://cdn.example.com/profile-avatar.jpg',
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertSuccessful()
        ->assertSee('https://cdn.example.com/profile-avatar.jpg', false);
});

it('falls back to the name initial in the header when no profile avatar is available', function () {
    $user = User::factory()->create(['name' => 'Saiffil Fariz']);

    expect(app(SocialAccounts::class)->avatarUrl((string) $user->getKey()))->toBeNull();

    $this->actingAs($user)
        ->get('/')
        ->assertSuccessful()
        ->assertSee('aria-label="Saiffil Fariz"', false)
        ->assertDontSee('referrerpolicy="no-referrer"', false);
});
