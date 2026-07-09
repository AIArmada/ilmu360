<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Follow;
use App\Models\Inspiration;
use App\Models\Speaker;
use App\Models\User;
use Livewire\Livewire;

it('allows an authenticated user to follow a speaker', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee(__('Ikuti'));

    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($speaker)))->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages.speakers.show', ['speaker' => $speaker])
        ->assertSet('isFollowing', false)
        ->call('toggleFollow')
        ->assertSet('isFollowing', true);

    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($speaker)))->toBeTrue();
});

it('allows an authenticated user to unfollow a speaker', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    OwnerContext::withOwner(null, fn () => $user->follow($speaker));
    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($speaker)))->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages.speakers.show', ['speaker' => $speaker])
        ->assertSet('isFollowing', true)
        ->call('toggleFollow')
        ->assertSet('isFollowing', false);

    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($speaker)))->toBeFalse();
});

it('keeps the speaker detail sections revealed after following', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    Inspiration::factory()->locale(app()->getLocale())->create();

    Livewire::actingAs($user)
        ->test('pages.speakers.show', ['speaker' => $speaker])
        ->assertSee('scroll-reveal reveal-up revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed" x-data="{ showComicModal: false, showMediaModal: false }"', false)
        ->call('toggleFollow')
        ->assertSet('isFollowing', true)
        ->assertSee('scroll-reveal reveal-up revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed', false)
        ->assertSee('scroll-reveal reveal-right revealed" x-data="{ showComicModal: false, showMediaModal: false }"', false);
});

it('redirects guest to login when trying to follow', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    Livewire::test('pages.speakers.show', ['speaker' => $speaker])
        ->call('toggleFollow')
        ->assertRedirect(route('login', ['redirect' => route('speakers.show', $speaker, absolute: false)]));
});

it('returns correct followingSpeakers relationship', function () {
    $user = User::factory()->create();
    $speaker1 = Speaker::factory()->create(['status' => 'verified']);
    $speaker2 = Speaker::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, fn () => $user->follow($speaker1));
    OwnerContext::withOwner(null, fn () => $user->follow($speaker2));

    expect($user->followingSpeakers)->toHaveCount(2);
    expect($user->followingSpeakers->pluck('id')->toArray())->toContain($speaker1->id, $speaker2->id);
});

it('returns correct followers relationship on speaker', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, fn () => $user1->follow($speaker));
    OwnerContext::withOwner(null, fn () => $user2->follow($speaker));

    expect($speaker->followers)->toHaveCount(2);
    expect(OwnerContext::withOwner(null, fn () => $speaker->isFollowedBy($user1)))->toBeTrue();
    expect(OwnerContext::withOwner(null, fn () => $speaker->isFollowedBy($user2)))->toBeTrue();
    expect(OwnerContext::withOwner(null, fn () => $speaker->isFollowedBy(null)))->toBeFalse();
});

it('cleans up followings when user is deleted', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, fn () => $user->follow($speaker));
    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($speaker)))->toBeTrue();

    $user->delete();

    expect(
        OwnerContext::withOwner(null, fn () => Follow::forFollower($user)->exists())
    )->toBeFalse();
});
