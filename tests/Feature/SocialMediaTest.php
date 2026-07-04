<?php

use AIArmada\Contacting\Models\SocialProfile;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('institution can have social media', function () {
    $institution = Institution::factory()->create();

    $institution->socialMedia()->create([
        'platform' => 'facebook',
        'handle' => 'masjid_official',
    ]);

    expect($institution->socialMedia)->toHaveCount(1);
    expect($institution->socialMedia->first()->platform)->toBe('facebook');
    expect($institution->socialMedia->first()->handle)->toBe('masjid_official');
    expect($institution->socialMedia->first()?->profileUrl())->toBe('https://www.facebook.com/masjid_official');
});

test('speaker can have social media', function () {
    $speaker = Speaker::factory()->create();

    $speaker->socialMedia()->create([
        'platform' => 'x',
        'url' => 'https://x.com/ustaz',
    ]);

    expect($speaker->socialMedia)->toHaveCount(1);
    expect($speaker->socialMedia->first()->platform)->toBe('x');
});

test('venue can have social media', function () {
    $venue = Venue::factory()->create();

    $venue->socialMedia()->create([
        'platform' => 'instagram',
        'url' => 'https://instagram.com/hall',
    ]);

    expect($venue->socialMedia)->toHaveCount(1);
    expect($venue->socialMedia->first()->platform)->toBe('instagram');
});

test('social media is polymorphic', function () {
    $institution = Institution::factory()->create();
    $social = $institution->socialMedia()->create([
        'platform' => 'website',
        'url' => 'https://example.com',
    ]);

    expect($social->socialable)->toBeInstanceOf(Institution::class);
    expect($social->socialable->id)->toBe($institution->id);
    expect($social)->toBeInstanceOf(SocialProfile::class);
});

test('social media resolves canonical telegram profile urls', function () {
    $institution = Institution::factory()->create();

    $social = $institution->socialMedia()->create([
        'platform' => 'telegram',
        'handle' => 'ilmu360',
    ]);

    expect($social->profileUrl())->toBe('https://t.me/ilmu360');
});
