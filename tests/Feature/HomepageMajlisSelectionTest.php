<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\PublicScheduleDiscoveryService;

function homepageMajlis(array $attributes = []): Event
{
    return Event::factory()->create(array_merge([
        'status' => 'approved', 'visibility' => 'public', 'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(),
    ], $attributes));
}

it('shows only admin featured upcoming public schedules to guests', function () {
    $featured = homepageMajlis(['is_featured' => true]);
    homepageMajlis();
    homepageMajlis(['is_featured' => true, 'visibility' => 'private']);
    homepageMajlis(['is_featured' => true, 'status' => 'pending']);
    homepageMajlis(['is_featured' => true, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    homepageMajlis(['is_featured' => true, 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(21)]);

    $selection = app(PublicScheduleDiscoveryService::class)->homepageSelection(null);
    expect($selection->map(fn ($leaf) => $leaf->event->id)->all())->toBe([$featured->id]);
    $this->get('/')->assertSee($featured->title);
});

it('includes upcoming majlis related to each type of active follow', function (string $type) {
    $user = User::factory()->create();
    $subject = match ($type) {
        'person' => Person::factory()->create(),
        'institution' => Institution::factory()->create(),
        'reference' => Reference::factory()->create(),
    };
    $user->follow($subject);
    $event = homepageMajlis($type === 'institution' ? ['institution_id' => $subject->id] : []);
    if ($type === 'person') {
        $event->persons()->attach($subject->id);
    }
    if ($type === 'reference') {
        $event->references()->attach($subject->id);
    }
    homepageMajlis();

    $selection = app(PublicScheduleDiscoveryService::class)->homepageSelection($user);
    expect($selection->map(fn ($leaf) => $leaf->event->id)->all())->toBe([$event->id]);
    $user->unfollow($subject);
    expect(app(PublicScheduleDiscoveryService::class)->homepageSelection($user))->toBeEmpty();
})->with(['person', 'institution', 'reference']);

it('prioritizes featured cards and fills remaining slots without duplicates', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $user->follow($institution);
    $featured = homepageMajlis(['is_featured' => true, 'institution_id' => $institution->id]);
    $related = collect(range(1, 12))->map(fn () => homepageMajlis(['institution_id' => $institution->id]));

    $selection = app(PublicScheduleDiscoveryService::class)->homepageSelection($user);
    expect($selection)->toHaveCount(9)
        ->and($selection->first()->event->id)->toBe($featured->id)
        ->and($selection->map(fn ($leaf) => $leaf->id())->unique())->toHaveCount(9);
    foreach ($selection->skip(1) as $leaf) {
        expect($related->pluck('id')->all())->toContain($leaf->event->id);
    }
});

it('does not fall back to unrelated schedules for users with no follows', function () {
    homepageMajlis();
    expect(app(PublicScheduleDiscoveryService::class)->homepageSelection(User::factory()->create()))->toBeEmpty();
});

it('caps admin featured schedules at nine cards in upcoming order', function () {
    $events = collect(range(1, 11))->map(fn (int $day) => homepageMajlis([
        'is_featured' => true, 'starts_at' => now()->addDays($day),
        'ends_at' => now()->addDays($day)->addHour(),
    ]));
    $selection = app(PublicScheduleDiscoveryService::class)->homepageSelection(null);
    expect($selection->map(fn ($leaf) => $leaf->event->id)->all())->toBe($events->take(9)->pluck('id')->all());
});
