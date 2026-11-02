<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the social profile avatar in the header when one is available', function () {
    $user = User::factory()->create(['name' => 'Saiffil Fariz']);
    SocialAccount::factory()->for($user)->create([
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

    expect($user->profile_avatar_url)->toBeNull();

    $this->actingAs($user)
        ->get('/')
        ->assertSuccessful()
        ->assertSee('aria-label="Saiffil Fariz"', false)
        ->assertDontSee('referrerpolicy="no-referrer"', false);
});
