<?php

use AIArmada\Contacting\Models\SocialProfile;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

test('institution can have social media', function () {
    $institution = Institution::factory()->create();

    $institution->socialProfiles()->create([
        'platform' => 'facebook',
        'handle' => 'masjid_official',
    ]);

    expect($institution->socialProfiles)->toHaveCount(1)
        ->and($institution->socialProfiles->first()->platform)->toBe('facebook')
        ->and($institution->socialProfiles->first()->handle)->toBe('masjid_official')
        ->and($institution->socialProfiles->first()?->profileUrl())->toBe('https://www.facebook.com/masjid_official');
});

test('person can have social media', function () {
    $person = Person::factory()->create();

    $person->socialProfiles()->create([
        'platform' => 'x',
        'url' => 'https://x.com/ustaz',
    ]);

    expect($person->socialProfiles)->toHaveCount(1)
        ->and($person->socialProfiles->first()->platform)->toBe('x');
});

test('venue can have social media', function () {
    $venue = Venue::factory()->create();

    $venue->socialProfiles()->create([
        'platform' => 'instagram',
        'url' => 'https://instagram.com/hall',
    ]);

    expect($venue->socialProfiles)->toHaveCount(1)
        ->and($venue->socialProfiles->first()->platform)->toBe('instagram');
});

test('social media is polymorphic', function () {
    $institution = Institution::factory()->create();
    $social = $institution->socialProfiles()->create([
        'platform' => 'website',
        'url' => 'https://example.com',
    ]);

    expect($social->socialable)->toBeInstanceOf(Institution::class)
        ->and($social->socialable->id)->toBe($institution->id)
        ->and($social)->toBeInstanceOf(SocialProfile::class);
});

test('social media resolves canonical telegram profile urls', function () {
    $institution = Institution::factory()->create();

    $social = $institution->socialProfiles()->create([
        'platform' => 'telegram',
        'handle' => 'ilmu360',
    ]);

    expect($social->profileUrl())->toBe('https://t.me/ilmu360');
});
