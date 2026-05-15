<?php

use App\Models\Institution;
use App\Models\SocialMedia;
use App\Models\Speaker;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('institution can have social media', function () {
    $institution = Institution::factory()->create();

    $institution->socialMedia()->create([
        'platform' => 'facebook',
        'url' => 'https://facebook.com/masjid',
        'username' => 'masjid_official',
    ]);

    expect($institution->socialMedia)->toHaveCount(1);
    expect($institution->socialMedia->first()->platform)->toBe('facebook');
    expect($institution->socialMedia->first()->username)->toBe('masjid_official');
});

test('speaker can have social media', function () {
    $speaker = Speaker::factory()->create();

    $speaker->socialMedia()->create([
        'platform' => 'twitter',
        'url' => 'https://twitter.com/ustaz',
    ]);

    expect($speaker->socialMedia)->toHaveCount(1);
    expect($speaker->socialMedia->first()->platform)->toBe('twitter');
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
    $social = SocialMedia::create([
        'socialable_type' => 'institution',
        'socialable_id' => $institution->id,
        'platform' => 'website',
        'url' => 'https://example.com',
    ]);

    expect($social->socialable)->toBeInstanceOf(Institution::class);
    expect($social->socialable->id)->toBe($institution->id);
});

test('social media icon urls use public storage assets', function () {
    $institution = Institution::factory()->create();

    $social = $institution->socialMedia()->create([
        'platform' => 'telegram',
        'url' => 'https://t.me/ilmu360',
    ]);

    expect($social->icon_file)->toBe('telegram.svg')
        ->and($social->icon_url)->toEndWith('/storage/social-media-icons/telegram.svg');
});
