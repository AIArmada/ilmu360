<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Follow;
use App\Models\Inspiration;
use App\Models\Person;
use App\Models\User;
use Livewire\Livewire;

it('allows an authenticated user to follow a person', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee(__('Ikuti'));

    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($person)))->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages.persons.show', ['person' => $person])
        ->assertSet('isFollowing', false)
        ->call('toggleFollow')
        ->assertSet('isFollowing', true);

    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($person)))->toBeTrue();
});

it('allows an authenticated user to unfollow a person', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    OwnerContext::withOwner(null, fn () => $user->follow($person));
    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($person)))->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages.persons.show', ['person' => $person])
        ->assertSet('isFollowing', true)
        ->call('toggleFollow')
        ->assertSet('isFollowing', false);

    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($person)))->toBeFalse();
});

it('keeps the person detail sections revealed after following', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    Inspiration::factory()->locale(app()->getLocale())->create();

    Livewire::actingAs($user)
        ->test('pages.persons.show', ['person' => $person])
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
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    Livewire::test('pages.persons.show', ['person' => $person])
        ->call('toggleFollow')
        ->assertRedirect(route('login', ['redirect' => route('persons.show', $person, false)]));
});

it('returns correct followingPersons relationship', function () {
    $user = User::factory()->create();
    $person1 = Person::factory()->create(['status' => 'verified']);
    $person2 = Person::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, fn () => $user->follow($person1));
    OwnerContext::withOwner(null, fn () => $user->follow($person2));

    expect($user->followingPersons)->toHaveCount(2);
    expect($user->followingPersons->pluck('id')->toArray())->toContain($person1->id, $person2->id);
});

it('returns correct followers relationship on person', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, fn () => $user1->follow($person));
    OwnerContext::withOwner(null, fn () => $user2->follow($person));

    expect($person->followers)->toHaveCount(2);
    expect(OwnerContext::withOwner(null, fn () => $person->isFollowedBy($user1)))->toBeTrue();
    expect(OwnerContext::withOwner(null, fn () => $person->isFollowedBy($user2)))->toBeTrue();
    expect(OwnerContext::withOwner(null, fn () => $person->isFollowedBy(null)))->toBeFalse();
});

it('cleans up followings when user is deleted', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, fn () => $user->follow($person));
    expect(OwnerContext::withOwner(null, fn () => $user->isFollowing($person)))->toBeTrue();

    $user->delete();

    expect(
        OwnerContext::withOwner(null, fn () => Follow::forFollower($user)->exists())
    )->toBeFalse();
});
