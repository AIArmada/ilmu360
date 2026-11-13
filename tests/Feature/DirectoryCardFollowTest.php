<?php

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Engagement\Enums\FollowStatus;
use AIArmada\Engagement\Models\Follow;
use App\Livewire\Components\DirectoryCardFollow;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use Livewire\Livewire;
use Tests\TestCase;

function d4dCardShareCookie(TestCase $testCase, string $url, User $sharer): string
{
    app(AffiliatesShareTrackingService::class)->createOrReuseLink($sharer, $url, 'Follow Page', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    $link = AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();

    $response = $testCase->get($url.'?share='.$link->custom_slug);
    $response->assertOk();

    return (string) $response->getCookie((string) config('share-tracking.cookie.name'))?->getValue();
}

function d4dCardConversionStatus(AffiliateConversion $conversion): string
{
    $status = $conversion->status;

    return $status instanceof ConversionStatus
        ? (string) $status->getValue()
        : (string) $status;
}

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

    if ($type === 'institution') {
        $parent->dispatch('directory-follow-updated.institution')->assertSet($idsProperty, [(string) $subject->id]);
    } else {
        $parent->call('$refresh')->assertSet($idsProperty, [(string) $subject->id]);
    }

    $parent->set('followed_only', true);

    $parent->assertSee('data-follow-state="following"', false);
    $card->call('toggleFollow', (string) $subject->id)
        ->assertDispatched("directory-follow-updated.{$type}");
    $parent->dispatch("directory-follow-updated.{$type}")
        ->assertSet($idsProperty, [])
        ->assertDontSee('data-follow-icon=', false);
})->with([
    ['institution', 'pages.institutions.index', 'followingInstitutionIds'],
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

it('refreshes institution follow ids on the directory follow event, not on every request', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $user->follow($institution);

    $component = Livewire::actingAs($user)
        ->test('pages.institutions.index')
        ->assertSet('followingInstitutionIds', [(string) $institution->id]);

    $user->unfollow($institution);

    $component->call('$refresh')
        ->assertSet('followingInstitutionIds', [(string) $institution->id]);

    $component->dispatch('directory-follow-updated.institution')
        ->assertSet('followingInstitutionIds', []);
});

it('reverses a directory card follow outcome with the typed metadata shape', function (): void {
    $sharer = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $url = route('institutions.show', $institution);
    $cookieName = (string) config('share-tracking.cookie.name');
    $cookie = d4dCardShareCookie($this, $url, $sharer);

    $card = Livewire::withCookie($cookieName, $cookie)
        ->actingAs($visitor)
        ->test(DirectoryCardFollow::class, [
            'subjectType' => 'institution',
            'subjectId' => (string) $institution->getKey(),
            'returnUrl' => route('institutions.index'),
        ]);

    $card->call('toggleFollow', (string) $institution->getKey())
        ->assertSet('isFollowing', true);

    $conversion = AffiliateConversion::query()
        ->where('conversion_type', ShareOutcomeType::InstitutionFollow->value)
        ->sole();

    expect($conversion->metadata)->toBe(['institution_id' => $institution->getKey()]);

    $follow = Follow::query()
        ->where('follower_type', $visitor->getMorphClass())
        ->where('follower_id', $visitor->getKey())
        ->where('followable_id', $institution->getKey())
        ->firstOrFail();
    $businessKey = ShareOutcomeBusinessKey::follow(ShareOutcomeType::InstitutionFollow, (string) $follow->getKey());

    $card->call('toggleFollow', (string) $institution->getKey())
        ->assertSet('isFollowing', false);

    expect(d4dCardConversionStatus($conversion->fresh()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->count())->toBe(1)
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->value('reason'))->toBe('unfollowed');

    $card->call('toggleFollow', (string) $institution->getKey())
        ->assertSet('isFollowing', true);

    expect(AffiliateConversion::query()
        ->where('conversion_type', ShareOutcomeType::InstitutionFollow->value)
        ->count())->toBe(1)
        ->and(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1)
        ->and(Follow::query()->whereKey($follow->getKey())->value('status'))->toBe(FollowStatus::Active);
});
