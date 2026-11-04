<?php

use App\Livewire\Components\DirectoryCardFollow;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use Livewire\Livewire;

function directoryFollowSubject(string $type): Institution|Person|Reference
{
    return match ($type) {
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'person' => Person::factory()->create(['status' => 'verified']),
        'reference' => Reference::factory()->create(['status' => 'verified']),
    };
}

it('toggles an isolated follow without rebuilding directory searches', function (string $type) {
    $user = User::factory()->create();
    $subject = directoryFollowSubject($type);

    foreach ([InstitutionSearchService::class, PersonSearchService::class, ReferenceSearchService::class] as $service) {
        $this->app->bind($service, fn () => throw new LogicException('Following must not rebuild directory search.'));
    }

    $card = Livewire::actingAs($user)->test(DirectoryCardFollow::class, [
        'subjectType' => $type, 'subjectId' => (string) $subject->id,
        'returnUrl' => '/',
    ]);
    $card->call('toggleFollow', (string) $subject->id)
        ->assertSet('isFollowing', true)
        ->assertSee('data-follow-state="following"', false)
        ->assertNotDispatched("directory-follow-updated.{$type}");
    expect($user->isFollowing($subject))->toBeTrue();
    $card->call('toggleFollow', (string) $subject->id)
        ->assertSet('isFollowing', false)
        ->assertSee('data-follow-state="not-following"', false);
    expect($user->isFollowing($subject))->toBeFalse();
})->with(['institution', 'person', 'reference']);

it('refreshes authoritative parent IDs after isolated clicks and updates followed-only results', function (string $type, string $page, string $idsProperty) {
    $user = User::factory()->create();
    $subject = directoryFollowSubject($type);
    $parent = Livewire::actingAs($user)->test($page)->assertSet($idsProperty, []);

    $card = Livewire::actingAs($user)->test(DirectoryCardFollow::class, [
        'subjectType' => $type, 'subjectId' => (string) $subject->id,
        'returnUrl' => '/', 'refreshListing' => true,
    ])->call('toggleFollow', (string) $subject->id);

    $parent->call('$refresh')->assertSet($idsProperty, [(string) $subject->id]);
    if ($type === 'institution') {
        $parent->dispatch('institution-filters-updated', filters: ['followed_only' => true]);
    } else {
        $parent->set('followed_only', true);
    }

    $parent->assertSee('data-follow-state="following"', false);
    $card->call('toggleFollow', (string) $subject->id)
        ->assertDispatched("directory-follow-updated.{$type}");
    $parent->dispatch("directory-follow-updated.{$type}")
        ->assertSet($idsProperty, [])
        ->assertDontSee('data-follow-icon=', false);
})->with([
    ['institution', 'pages.institutions.results', 'followingInstitutionIds'],
    ['person', 'pages.persons.index', 'followingPersonIds'],
    ['reference', 'pages.references.index', 'followingReferenceIds'],
]);

it('preserves a guest follow return destination', function () {
    $subject = directoryFollowSubject('person');
    $returnUrl = route('persons.index', ['search' => 'ustaz'], absolute: false);
    Livewire::test(DirectoryCardFollow::class, [
        'subjectType' => 'person', 'subjectId' => (string) $subject->id,
        'returnUrl' => $returnUrl,
    ])->call('toggleFollow', (string) $subject->id)
        ->assertRedirect(route('login', ['redirect' => $returnUrl]));
});

it('rejects following a different subject through a card', function () {
    $subject = directoryFollowSubject('institution');
    Livewire::actingAs(User::factory()->create())->test(DirectoryCardFollow::class, [
        'subjectType' => 'institution', 'subjectId' => (string) $subject->id, 'returnUrl' => '/',
    ])->call('toggleFollow', 'different-subject')->assertForbidden();
});

it('retains directory eligibility checks', function (string $type) {
    $subject = directoryFollowSubject($type);
    $subject->update(['status' => 'rejected']);
    $user = User::factory()->create();
    Livewire::actingAs($user)->test(DirectoryCardFollow::class, [
        'subjectType' => $type, 'subjectId' => (string) $subject->id, 'returnUrl' => '/',
    ])->call('toggleFollow', (string) $subject->id)->assertSet('isFollowing', false);
    expect($user->isFollowing($subject))->toBeFalse();
})->with(['institution', 'person', 'reference']);
